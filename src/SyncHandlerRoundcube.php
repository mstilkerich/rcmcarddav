<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Synchronization handler that stores changes to roundcube database.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCard;
use Sabre\VObject;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavClient\Services\SyncHandler;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use carddav;

/**
 * @psalm-type ContactDbInfo = array{id: string, etag: string, uri: string, cuid: string} DB data on a contact
 * @psalm-type GroupDbInfo = array{id: string, uri: ?string, etag: ?string} DB data on a group
 * @psalm-type CardGroupDbInfo = array{id: string, uri: string, etag: string} DB data on a VCard-type group
 * @psalm-type ReceivedGroupInfo = array{vcard: VCard, etag: string, uri: string} Data of a retrieved group card
 */
class SyncHandlerRoundcube implements SyncHandler
{
    /** @var bool hadErrors If true, errors that did not cause the sync to be aborted occurred. */
    public $hadErrors = false;

    /** @var Addressbook Object of the addressbook that is being synchronized */
    private $rcAbook;

    /** @var array<string,string> Maps UIDs of locally available contact cards to (database) id */
    private $localCardsByUID = [];

    /** @var array<string, ContactDbInfo>  Maps URIs to the contact info */
    private $localCards = [];

    /** @var array<string, CardGroupDbInfo> Maps URIs of KIND=group cards to the group info */
    private $localGrpCards = [];

    /** @var list<string> List of DB ids of CATEGORIES-type groups at the time the sync is started.
     *                            Note: If a contact's existing memberships to such groups are determined, this is
     *                            sufficient and we do not have to add new CATEGORIES-type groups created during the
     *                            sync to this list.
     */
    private $localCatGrpIds = [];

    /** @var list<ReceivedGroupInfo> records VCard-type groups that need to be updated */
    private $grpCardsToUpdate = [];

    /** @var list<string> a list of group IDs that may be cleared from the DB if empty and CATEGORIES-type */
    private $clearGroupCandidates = [];

    /** @var DataConversion $dataConverter to convert between VCard and roundcube's representation of contacts. */
    private $dataConverter;

    /** @var AddressbookCollection $davAbook */
    private $davAbook;

    public function __construct(
        Addressbook $rcAbook,
        DataConversion $dataConverter,
        AddressbookCollection $davAbook
    ) {
        $this->rcAbook = $rcAbook;
        $this->dataConverter = $dataConverter;
        $this->davAbook = $davAbook;
        $db = Config::inst()->db();

        $abookId = $this->rcAbook->getId();

        /**
         * determine existing local contact URIs and ETAGs
         * @var list<ContactDbInfo> $contacts
         */
        $contacts = $db->get(['abook_id' => $abookId], ['id', 'etag', 'uri', 'cuid']);
        foreach ($contacts as $contact) {
            $this->localCards[$contact['uri']] = $contact;
            $this->localCardsByUID[$contact['cuid']] = $contact['id'];
        }

        /**
         * determine existing local group URIs and ETAGs
         * @var list<GroupDbInfo> $groups
         */
        $groups = $db->get(['abook_id' => $abookId], ['id', 'uri', 'etag'], 'groups');
        foreach ($groups as $group) {
            if (isset($group['uri'])) { // these are groups defined by a KIND=group VCard
                /** @var CardGroupDbInfo $group */
                $this->localGrpCards[$group['uri']] = $group;
            } else { // these are groups derived from CATEGORIES in the contact VCards
                $this->localCatGrpIds[] = $group['id'];
            }
        }
    }

    /**
     * Process a card reported as changed by the server (includes new cards).
     *
     * Cards of individuals are processed immediately, updating the database. Cards of KIND=group are recorded and
     * processed after all individuals have been processed in finalizeSync(). This is because these group cards may
     * reference individuals, and we need to have all of them in the local DB before processing the groups.
     *
     * @param string $uri URI of the card
     * @param string $etag ETag of the card as given
     * @param ?VCard $card The card as a Sabre VCard object. Null if the address data could not be retrieved/parsed.
     */
    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void
    {
        $logger = Config::inst()->logger();

        // in case a card has an error, we continue syncing the rest
        if (!isset($card)) {
            $this->hadErrors = true;
            $logger->error("Card $uri changed, but error in retrieving address data (card ignored)");
            return;
        }

        if (strcasecmp((string) $card->{"X-ADDRESSBOOKSERVER-KIND"}, "group") === 0) {
            $this->grpCardsToUpdate[] = [ "vcard" => $card, "etag" => $etag, "uri" => $uri ];
        } else {
            $this->updateContactCard($uri, $etag, $card);
        }
    }

    /**
     * Process a card reported as deleted the server.
     *
     * This function immediately updates the state in the database for both contact and group cards, deleting all
     * membership relations between contacts/groups if either side is deleted. If a CATEGORIES-type groups loses a
     * member during this process, we record it as a candidate that is deleted by finalizeSync() in case the group is
     * empty at the end of the sync.
     *
     * It is quite common for servers to report cards as deleted that were never seen by this client, for example when a
     * card was added and deleted again between two syncs. Thus, we must not react hard on such situations (we log a
     * notice).
     *
     * It is also possible that a URI is both reported as deleted and changed. This can happen if a URI was deleted and
     * a new object was created at the same URI. The Sync service will report all deleted objects first for this reason,
     * so we don't have to care about it here. However, we must clean up the local state before the
     * addressObjectChanged() function is called with a URI that was deleted, so it does not wrongfully assume a
     * connection between the deleted and the new card (and try to update the deleted card that no longer exists in the
     * DB).
     *
     * @param string $uri URI of the card
     */
    public function addressObjectDeleted(string $uri): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        $logger->info("Deleted card $uri");

        if (isset($this->localCards[$uri]["id"])) {
            // delete contact
            $dbid = $this->localCards[$uri]["id"];
            $cardUID = $this->localCards[$uri]["cuid"];

            // CATEGORIES-type groups may become empty as a user is deleted and should then be deleted as well. Record
            // what groups the user belonged to.
            if (!empty($this->localCatGrpIds)) {
                /** @var list<numeric-string> */
                $group_ids = array_column(
                    $db->get(["contact_id" => $dbid, "group_id" => $this->localCatGrpIds], ["group_id"], "group_user"),
                    "group_id"
                );
                $this->clearGroupCandidates = array_merge($this->clearGroupCandidates, $group_ids);

                $db->delete(["contact_id" => $dbid], "group_user");
            }
            $db->delete($dbid);

            // important: URI may be reported as deleted and then reused for new card.
            unset($this->localCardsByUID[$cardUID]);
            unset($this->localCards[$uri]);
        } elseif (isset($this->localGrpCards[$uri]["id"])) {
            // delete VCard-type group
            $dbid = $this->localGrpCards[$uri]["id"];
            $db->delete(["group_id" => $dbid], "group_user");
            $db->delete($dbid, "groups");

            // important: URI may be reported as deleted and then reused for new card.
            unset($this->localGrpCards[$uri]);
        } else {
            $logger->notice("Server reported deleted card $uri for that no DB entry exists");
        }
    }

    /**
     * Provides the current local cards and ETags to the Sync service.
     *
     * This is only requested by the Sync service in case it has to fall back to PROPFIND-based synchronization,
     * i.e. if sync-collection REPORT is not supported by the server or did not work.
     *
     * @return array<string,string> Maps card URIs to ETags
     */
    public function getExistingVCardETags(): array
    {
        return array_column(
            array_merge($this->localCards, $this->localGrpCards),
            "etag",
            "uri"
        );
    }

    /**
     * Finalize the sychronization process.
     *
     * This function is called last by the Sync service after all changes have been reported. We use it to perform
     * delayed actions, namely the processing of changed group vcards and deletion of CATEGORIES-type groups that became
     * empty during this sync.
     */
    public function finalizeSync(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();
        $abookId = $this->rcAbook->getId();

        // Now process all KIND=group type VCards that the server reported as changed
        foreach ($this->grpCardsToUpdate as $g) {
            $this->updateGroupCard($g["uri"], $g["etag"], $g["vcard"]);
        }

        // Delete all CATEGORIES-TYPE groups that had their last contacts deleted during this sync
        $group_ids = array_unique($this->clearGroupCandidates);
        if (!empty($group_ids)) {
            try {
                $db->startTransaction(false);
                $group_ids_nonempty = array_column(
                    $db->get(["group_id" => $group_ids], ["group_id"], "group_user"),
                    "group_id"
                );

                $group_ids_empty = array_diff($group_ids, $group_ids_nonempty);
                if (!empty($group_ids_empty)) {
                    $logger->info("Delete empty CATEGORIES-type groups: " . implode(",", $group_ids_empty));
                    $db->delete(["id" => $group_ids_empty, "uri" => null, "abook_id" => $abookId], "groups");
                }
                $db->endTransaction();
            } catch (\Exception $e) {
                $this->hadErrors = true;
                $logger->error("Failed to delete emptied CATEGORIES-type groups: " . $e->getMessage());
                $db->rollbackTransaction();
            }
        }
    }

    /**
     * This function determines the group IDs of CATEGORIES-type groups the individual of the
     * given VCard belongs to. Groups are created if needed.
     *
     * @param VCard $card The VCard that describes the individual, including the CATEGORIES attribute
     * @return list<string> An array of DB ids of the CATEGORIES-type groups the user belongs to.
     */
    private function getCategoryTypeGroupsForUser(VCard $card): array
    {
        $abookId = $this->rcAbook->getId();
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        // Determine CATEGORIES-type group ID (and create if needed) of the user
        $categories = [];
        if (isset($card->CATEGORIES)) {
            /** @var list<string> */
            $categories = $card->CATEGORIES->getParts();
            // remove all whitespace categories
            Addressbook::stringsAddRemove($categories);
        }

        if (empty($categories)) {
            return [];
        }

        try {
            $db->startTransaction(false);
            /** @var array<string,numeric-string> $group_ids_by_name */
            $group_ids_by_name = array_column(
                $db->get(["abook_id" => $abookId, "uri" => null, "name" => $categories], ["id", "name"], "groups"),
                "id",
                "name"
            );

            foreach ($categories as $category) {
                if (!isset($group_ids_by_name[$category])) {
                    $gsave_data = [
                        'name' => $category,
                        'kind' => 'group'
                    ];
                    $dbid = $db->storeGroup($abookId, $gsave_data);
                    $group_ids_by_name[$category] = $dbid;
                }
            }
            $db->endTransaction();

            return array_values($group_ids_by_name);
        } catch (\Exception $e) {
            $this->hadErrors = true;
            $logger->error("Failed to determine CATEGORIES-type groups for contact: " . $e->getMessage());
            $db->rollbackTransaction();
        }

        return [];
    }

    /**
     * Updates a KIND=individual VCard in the local DB.
     *
     * @param string $uri URI of the card
     * @param string $etag ETag of the card as given
     * @param VCard $card The card as a Sabre VCard object.
     */
    private function updateContactCard(string $uri, string $etag, VCard $card): void
    {
        $abookId = $this->rcAbook->getId();
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        $save_data = $this->dataConverter->toRoundcube($card, $this->davAbook);
        $logger->info("Changed Individual $uri");

        $dbid = $this->localCards[$uri]["id"] ?? null;

        try {
            $db->startTransaction(false);
            $dbid = $db->storeContact($abookId, $etag, $uri, $card->serialize(), $save_data, $dbid);
            $db->endTransaction();

            // remember in the local cache - might be needed in finalizeSync to map UID to DB ID without DB query
            if (!isset($save_data["cuid"])) {
                throw new \Exception("VCard $uri has not UID property");
            }

            if (!isset($this->localCardsByUID[$save_data["cuid"]])) {
                $this->localCardsByUID[$save_data["cuid"]] = $dbid;
            }

            // determine current assignments to CATGEGORIES-type groups
            $cur_group_ids = $this->getCategoryTypeGroupsForUser($card);

            $db->startTransaction(false);

            // Update membership to CATEGORIES-type groups
            /** @var list<numeric-string> $old_group_ids */
            $old_group_ids = empty($this->localCatGrpIds)
                ? []
                : array_column(
                    $db->get(["contact_id" => $dbid, "group_id" => $this->localCatGrpIds ], ["group_id"], "group_user"),
                    "group_id"
                );

            $del_group_ids = array_diff($old_group_ids, $cur_group_ids);
            if (!empty($del_group_ids)) {
                // CATEGORIES-type groups may become empty when members are removed. Record those the user belonged to.
                $this->clearGroupCandidates = array_merge($this->clearGroupCandidates, $del_group_ids);
                // remove contact from CATEGORIES-type groups he no longer belongs to
                $db->delete(["contact_id" => $dbid, "group_id" => $del_group_ids], 'group_user');
            }

            // add contact to CATEGORIES-type groups he newly belongs to
            $add_group_ids = array_values(array_diff($cur_group_ids, $old_group_ids));
            if (!empty($add_group_ids)) {
                $db->insert(
                    "group_user",
                    ["contact_id", "group_id"],
                    array_map(
                        function (string $groupId) use ($dbid) {
                            return [$dbid, $groupId];
                        },
                        $add_group_ids
                    )
                );
            }

            $db->endTransaction();
        } catch (\Exception $e) {
            $this->hadErrors = true;
            $logger->error("Failed to process changed card $uri: " . $e->getMessage());
            $db->rollbackTransaction();
        }
    }

    /**
     * Updates a KIND=group VCard in the local DB.
     *
     * @param string $uri URI of the card
     * @param string $etag ETag of the card as given
     * @param VCard $card The card as a Sabre VCard object.
     */
    private function updateGroupCard(string $uri, string $etag, VCard $card): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();
        $abookId = $this->rcAbook->getId();

        $save_data = $this->dataConverter->toRoundcube($card, $this->davAbook);

        $dbid = $this->localGrpCards[$uri]["id"] ?? null;
        $logger->info("Changed Group $uri " . $save_data['name']);

        // X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:51A7211B-358B-4996-90AD-016D25E77A6E
        $members = $card->{'X-ADDRESSBOOKSERVER-MEMBER'} ?? [];
        $memberIds = [];

        $logger->debug("Group $uri has " . count($members) . " members");
        /** @var VObject\Property $mbr */
        foreach ($members as $mbr) {
            $mbrc = explode(':', (string) $mbr);
            if (count($mbrc) != 3 || $mbrc[0] !== 'urn' || $mbrc[1] !== 'uuid') {
                $logger->warning("don't know how to interpret group membership: $mbr");
            } else {
                $memberId = $this->localCardsByUID[$mbrc[2]] ?? null;
                if (isset($memberId)) {
                    if (in_array($memberId, $memberIds)) {
                        // remove duplicate from vcard
                        $card->remove($mbr);
                    } else {
                        $memberIds[] = $memberId;
                    }
                } else {
                    $logger->warning("cannot find DB ID for group member: $mbrc[2]");
                }
            }
        }

        try {
            $db->startTransaction(false);
            // store group card
            $dbid = $db->storeGroup($abookId, $save_data, $dbid, $etag, $uri, $card->serialize());

            // delete current group members (will be reinserted if needed below)
            $db->delete(["group_id" => $dbid], 'group_user');

            // Update member assignments
            if (count($memberIds) > 0) {
                $db->insert(
                    'group_user',
                    ['group_id', 'contact_id'],
                    array_map(
                        function (string $contactId) use ($dbid): array {
                            return [ $dbid, $contactId ];
                        },
                        $memberIds
                    )
                );

                $logger->debug("Added " . count($memberIds) . " contacts to group $dbid");
            }
            $db->endTransaction();
        } catch (\Exception $e) {
            $this->hadErrors = true;
            $logger->error("Failed to update group $dbid: " . $e->getMessage());
            $db->rollbackTransaction();
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
