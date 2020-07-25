<?php

/**
 * Synchronization handler that stores changes to roundcube database.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavClient\Services\SyncHandler;
use rcmail;
use carddav;

class SyncHandlerRoundcube implements SyncHandler
{
    /** @var bool hadErrors If true, errors that did not cause the sync to be aborted occurred. */
    public $hadErrors = false;

    /** @var Addressbook */
    private $rcAbook;

    /** @var array Maps URIs to an associative array containing etag and (database) id */
    private $existing_card_cache = [];
    /** @var array Maps URIs of KIND=group cards to an associative array containing etag and (database) id */
    private $existing_grpcard_cache = [];
    /** @var array used to record which users need to be added to which groups (group DB-Id => [Contact UIDs]) */
    private $users_to_add = [];

    /** @var string[] maps group names to database ids */
    private $existing_category_groupids = [];

    /** @var string[] a list of group IDs that may be cleared from the DB if empty and CATEGORY-type */
    private $clearGroupCandidates = [];

    public function __construct(Addressbook $rcAbook)
    {
        $this->rcAbook = $rcAbook;
        $abookId = $this->rcAbook->getId();

        // determine existing local contact URIs and ETAGs
        $contacts = Database::get($abookId, 'id,uri,etag', 'contacts', false, 'abook_id');
        foreach ($contacts as $contact) {
            $this->existing_card_cache[$contact['uri']] = $contact;
        }

        // determine existing local group URIs and ETAGs
        $groups = Database::get($abookId, 'id,uri,etag,name', 'groups', false, 'abook_id');
        foreach ($groups as $group) {
            if (isset($group['uri'])) { // these are groups defined by a KIND=group VCard
                $this->existing_grpcard_cache[$group['uri']] = $group;
            } else { // these are groups derived from CATEGORIES in the contact VCards
                $this->existing_category_groupids[$group['name']] = (string) $group['id'];
            }
        }
    }

    public function addressObjectChanged(string $uri, string $etag, ?VCard $card): void
    {
        // in case an individual card has an error, we continue syncing the rest
        if (!isset($card)) {
            carddav::$logger->error("Card $uri changed, but error in retrieving address data (card ignored)");
            $this->hadErrors = true;
            return;
        }

        $abookId = $this->rcAbook->getId();
        $dbh = Database::getDbHandle();

        // card may be changed during conversion, in particular inlining of the PHOTO
        [ 'save_data' => $save_data, 'vcf' => $card ] = $this->rcAbook->convVCard2Rcube($card);
        $vcf = $card->serialize();

        if ($save_data['kind'] === 'group') {
            $dbid = $this->existing_grpcard_cache[$uri]["id"] ?? null;
            if (isset($dbid)) {
                $dbid = (string) $dbid;
            }

            carddav::$logger->debug("Changed Group $uri " . $save_data['name']);
            // delete current group members (will be reinserted if needed below)
            if (isset($dbid)) {
                Database::delete($dbid, 'group_user', 'group_id');
            }

            // store group card
            $dbid = Database::storeGroup($abookId, $save_data, $dbid, $etag, $uri, $vcf);

            // record group members for deferred store
            $this->users_to_add[$dbid] = [];

            // X-ADDRESSBOOKSERVER-MEMBER:urn:uuid:51A7211B-358B-4996-90AD-016D25E77A6E
            $members = $card->{'X-ADDRESSBOOKSERVER-MEMBER'} ?? [];

            carddav::$logger->debug("Group $dbid has " . count($members) . " members");
            foreach ($members as $mbr) {
                $mbrc = explode(':', (string) $mbr);
                if (count($mbrc) != 3 || $mbrc[0] !== 'urn' || $mbrc[1] !== 'uuid') {
                    carddav::$logger->warning("don't know how to interpret group membership: $mbr");
                    continue;
                }
                $this->users_to_add[$dbid][] = $dbh->quote($mbrc[2]);
            }
        } else { // individual/other
            $dbid = $this->existing_card_cache[$uri]["id"] ?? null;
            if (isset($dbid)) {
                $dbid = (string) $dbid;
            }

            if (trim($save_data['name']) == '') { // roundcube display fix for contacts that don't have first/last names
                if (trim($save_data['nickname'] ?? "") !== '') {
                    $save_data['name'] = $save_data['nickname'];
                } else {
                    foreach ($save_data as $key => $val) {
                        if (strpos($key, 'email') !== false) {
                            $save_data['name'] = $val[0];
                            break;
                        }
                    }
                }
            }

            // delete current member from category groups (will be reinserted if needed below)
            // Unless: this is a new contact (dbid not set), or we do not have any CATEGORIES-based groups in this
            // addressbook ($this->existing_category_groupids empty)
            if (isset($dbid) && (!empty($this->existing_category_groupids))) {
                /* CATEGORY-type groups may become empty when members are removed. Record what CATEGORIES-type groups
                 * the user belonged to.
                 */
                $group_ids = array_column(
                    Database::get(
                        $dbid,
                        "group_id",
                        "group_user",
                        false,
                        "contact_id",
                        [ "group_id" => array_values($this->existing_category_groupids) ]
                    ),
                    "group_id"
                );
                $this->clearGroupCandidates = array_merge($this->clearGroupCandidates, $group_ids);
                Database::delete(
                    $dbid,
                    'group_user',
                    'contact_id',
                    [ "group_id" => array_values($this->existing_category_groupids) ]
                );
            }

            $categories = [];
            if (isset($card->CATEGORIES)) {
                // remove all whitespace categories
                $categories = $card->CATEGORIES->getParts();
                Addressbook::stringsAddRemove($categories);
            }

            foreach ($categories as $category) {
                if (isset($this->existing_category_groupids[$category])) {
                    $group_dbid = $this->existing_category_groupids[$category];
                } else {
                    $gsave_data = [
                        'name' => $category,
                        'kind' => 'group'
                    ];
                    $group_dbid = Database::storeGroup($abookId, $gsave_data);
                    $this->existing_category_groupids[$category] = $group_dbid;
                }

                if (!isset($this->users_to_add[$group_dbid])) {
                    $this->users_to_add[$group_dbid] = [];
                }
                $this->users_to_add[$group_dbid][] = $dbh->quote($save_data['cuid']);
            }

            carddav::$logger->debug("Changed Individual $uri " . $save_data['name']);
            $this->rcAbook->preprocessRcubeData($save_data);
            Database::storeContact($abookId, $etag, $uri, $vcf, $save_data, $dbid);
        }
    }

    public function addressObjectDeleted(string $uri): void
    {
        carddav::$logger->debug("Deleted card $uri");

        if (isset($this->existing_card_cache[$uri]["id"])) {
            // delete contact
            $dbid = $this->existing_card_cache[$uri]["id"];

            /* CATEGORY-type groups may become empty as a user is deleted and should then be deleted as well. Record
             * what groups the user belonged to.
             */
            $group_ids = array_column(
                Database::get(
                    $dbid,
                    "group_id",
                    "group_user",
                    false,
                    "contact_id",
                    [ "group_id" => array_values($this->existing_category_groupids) ]
                ),
                "group_id"
            );
            $this->clearGroupCandidates = array_merge($this->clearGroupCandidates, $group_ids);

            Database::delete($dbid, "group_user", "contact_id");
            Database::delete($dbid);
        } elseif (isset($this->existing_grpcard_cache[$uri]["id"])) {
            // delete VCard-type group
            $dbid = $this->existing_grpcard_cache[$uri]["id"];
            Database::delete($dbid, "group_user", "group_id");
            Database::delete($dbid, "groups");
        } else {
            carddav::$logger->notice("Server reported deleted card $uri for that no DB entry exists");
        }
    }

    public function getExistingVCardETags(): array
    {
        $cache = [];

        foreach ($this->existing_card_cache as $uri => $cacheEntry) {
            $cache[$uri] = $cacheEntry["etag"];
        }

        foreach ($this->existing_grpcard_cache as $uri => $cacheEntry) {
            $cache[$uri] = $cacheEntry["etag"];
        }

        return $cache;
    }

    public function finalizeSync(): void
    {
        $dbh = Database::getDbHandle();
        $abookId = $this->rcAbook->getId();
        foreach ($this->users_to_add as $dbid => $cuids) {
            if (count($cuids) > 0) {
                $sql_result = $dbh->query('INSERT INTO ' .
                    $dbh->table_name('carddav_group_user') .
                    ' (group_id,contact_id) SELECT ?,id from ' .
                    $dbh->table_name('carddav_contacts') .
                    ' WHERE abook_id=? AND cuid IN (' . implode(',', $cuids) . ')', $dbid, $abookId);
                carddav::$logger->debug("Added " . $dbh->affected_rows($sql_result) . " contacts to group $dbid");
            }
        }

        // Delete all CATEGORY-TYPE groups that had their last contacts deleted during this sync
        $group_ids = array_unique($this->clearGroupCandidates);
        if (!empty($group_ids)) {
            $group_ids_nonempty = array_column(
                Database::get($group_ids, "group_id", "group_user", false, "group_id"),
                "group_id"
            );

            $group_ids_empty = array_diff($group_ids, $group_ids_nonempty);
            if (!empty($group_ids_empty)) {
                carddav::$logger->debug("Delete empty CATEGORY-type groups: " . implode(",", $group_ids_empty));
                Database::delete($group_ids_empty, "groups", "id", [ "uri" => null, "abook_id" => $abookId ]);
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
