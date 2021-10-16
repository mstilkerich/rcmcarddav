<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
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

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use rcube_addressbook;
use rcube_result_set;
use rcube_utils;
use rcube;
use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use MStilkerich\CardDavClient\Services\{Discovery, Sync};
use MStilkerich\CardDavAddressbook4Roundcube\Db\{AbstractDatabase,DbAndCondition,DbOrCondition};

/**
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type SaveData from DataConversion
 *
 * @psalm-type GroupSaveData = array{
 *   ID: string,
 *   id: string,
 *   name: string
 * }
 */
class Addressbook extends rcube_addressbook
{
    /** @var string Separator character used by roundcube to encode multiple values in a single string. */
    private const SEPARATOR = ',';

    /** @var ?AddressbookCollection The DAV AddressbookCollection object */
    private $davAbook = null;

    /** @var DataConversion Converter between VCard and roundcube's representation of contacts. */
    private $dataConverter;

    /** @var string Database ID of the addressbook */
    private $id;

    /** @var list<DbAndCondition> An additional filter to limit contact searches */
    private $filter = [];

    /** @var list<string> A list of contact fields that must not be empty, otherwise the contact will be hidden. */
    private $requiredProps;

    /** @var ?rcube_result_set The result of the last get_record(), list_records() or search() operation */
    private $result = null;

    /** @var FullAbookRow Database row of the addressbook containing its configuration */
    private $config;

    /** @var array $table_cols
     * attributes that are redundantly stored in the contact table and need
     * not be parsed from the vcard
     */
    private $table_cols = ['id', 'name', 'email', 'firstname', 'surname', 'organization'];

    /**
     * Constructs an addressbook object.
     *
     * @param string $dbid The addressbook's database ID
     * @param FullAbookRow $config The database row of the addressbook
     * @param bool $readonly If true, the addressbook is readonly and change operations are disabled.
     * @param list<string> $requiredProps A list of address object columns that must not be empty. If any of the fields
     *                                    is empty, the contact will be hidden.
     */
    public function __construct(
        string $dbid,
        array $config,
        bool $readonly,
        array $requiredProps
    ) {
        $this->config = $config;
        $this->primary_key = 'id';
        $this->groups   = true;
        $this->readonly = $readonly;
        $this->date_cols = ['birthday', 'anniversary'];
        $this->requiredProps = $requiredProps;
        $this->id       = $dbid;

        $this->dataConverter = new DataConversion($dbid);
        $this->coltypes = $this->dataConverter->getColtypes();

        $this->ready = true;
    }

    /************************************************************************
     *                          rcube_addressbook API
     ***********************************************************************/

    /**
     * Returns addressbook name (e.g. for addressbooks listing).
     *
     * @return string name of this addressbook
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_name(): string
    {
        return $this->config['name'];
    }

    /**
     * Sets a search filter.
     *
     * This affects the contact set considered when using the count() and list_records() operations to those
     * contacts that match the filter conditions. If no search filter is set, all contacts in the addressbook are
     * considered.
     *
     * This filter mechanism is applied in addition to other filter mechanisms, see the description of the count()
     * operation.
     *
     * @param mixed $filter Search params to use in listing method, obtained by get_search_set()
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function set_search_set($filter): void
    {
        if (is_array($filter)) {
            $ftyped = [];
            foreach (array_keys($filter) as $k) {
                if ($filter[$k] instanceof DbAndCondition) {
                    $ftyped[] = $filter[$k];
                } else {
                    throw new \InvalidArgumentException(__METHOD__ . " requires a DbAndCondition[] type filter");
                }
            }
            $this->filter = $ftyped;
        } else {
            throw new \InvalidArgumentException(__METHOD__ . " requires a DbAndCondition[] type filter");
        }
    }

    /**
     * Returns the current search filter.
     *
     * @return list<DbAndCondition> Search properties used by this class
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_search_set()
    {
        return $this->filter;
    }

    /**
     * Reset saved results and search parameters
     */
    public function reset(): void
    {
        $this->result = null;
        $this->filter = [];
    }

    /**
     * Lists the current set of contact records.
     *
     * See the description of count() for the criteria determining which contacts are considered for the listing.
     *
     * The actual records returned may be fewer, as only the records for the current page are returned. The returned
     * records may be further limited by the $subset parameter, which means that only the first or last $subset records
     * of the page are returned, depending on whether $subset is positive or negative. If $subset is 0, all records of
     * the page are returned. The returned records are found in the $records property of the returned result set.
     *
     * Finally, the $first property of the returned result set contains the index into the total set of filtered records
     * (i.e. not considering the segmentation into pages) of the first returned record before applying the $subset
     * parameter (i.e., $first is always a multiple of the page size).
     *
     * The $nocount parameter is an optimization that allows to skip querying the total amount of records of the
     * filtered set if the caller is only interested in the records. In this case, the $count property of the returned
     * result set will simply contain the number of returned records, but the filtered set may contain more records than
     * this.
     *
     * The result of the operation is internally cached for later retrieval using get_result().
     *
     * @param ?array $cols   List of columns to include in the returned records (null means all)
     * @param int    $subset Only return this number of records of the current page, use negative values for tail
     * @param bool   $nocount True to skip the count query (select only)
     *
     * @return rcube_result_set Indexed list of contact records, each a hash array
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $first = ($this->list_page - 1) * $this->page_size;
        $result = new rcube_result_set(0, $first);

        /** @var ?list<string> $cols */
        try {
            $numRecords = $this->listRecordsReadDB($cols, $subset, $result);

            if ($nocount) {
                $result->count = $numRecords;
            } elseif ($this->list_page <= 1 && $numRecords < $this->page_size && $subset == 0) {
                // If we are on the first page, no subset was requested and the number of records is smaller than the
                // page size, there are no more records so we can skip the COUNT query
                $result->count = $numRecords;
            } else {
                $result->count = $this->doCount();
            }
        } catch (\Exception $e) {
            $logger = Config::inst()->logger();
            $logger->error(__METHOD__ . " exception: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
        }

        $this->result = $result;
        return $result;
    }

    /**
     * Search contacts
     *
     * Depending on the given parameters the search() function operates in different modes (in the order listed):
     *
     * Mode "Direct ID search" - $fields is either 'ID' or $this->primary_key:
     *       $values is either: a string of contact IDs separated by self::SEPARATOR (,)
     *                          an array of contact IDs
     *       - Any contact with one of the given IDs is returned
     *       - $mode is ignored in this case
     *
     * Mode "Advanced search" - $value is an array
     *       - Each value in $values is the search value for the field in $fields at the same index
     *       - All fields must match their value to be included in the result ("AND" semantics)
     *
     * Mode "Search all fields" - $fields is '*' (note: $value is a single string)
     *       - Any field must match the value to be included in the result ("OR" semantics)
     *
     * Mode "Search given fields"
     *       - Any of the given fields must match the value to be included in the result ("OR" semantics)
     *
     * All matching is done case insensitive.
     *
     * The search settings are remembered (see set_search_set()) until reset using the reset() function. They can be
     * retrieved using get_search_set(). The remembered search settings must be considered by list_records() and
     * count().
     *
     * The search mode can be set by the admin via the config.inc.php setting addressbook_search_mode, which defaults to
     * 0. It is used as a bit mask, but the search modes are mostly exclusive; from the roundcube code, I take the
     * following interpretation:
     *   bits [1..0] = 0b00: Search all (*abc*)
     *   bits [1..0] = 0b01: Search strict (case insensitive =)
     *   bits [1..0] = 0b10: Prefix search (abc*)
     * The purpose of SEARCH_GROUPS is not clear to me and not considered.
     *
     * When records are requested in the returned rcube_result_set ($select is true), the results will only include the
     * contacts of the current page (see list_page, page_size). The behavior is as described with the list_records
     * function, and search() can be thought of as a sequence of set_search_set() and list_records() under that filter.
     *
     * If $nocount is true, the count property of the returned rcube_result_set will contain the amount of records
     * contained within that set. Calling search() with $select=false and $nocount=true is not a meaningful use case and
     * will result in an empty result set without records and a count property of 0, which gives no indication on the
     * actual record set matching the given filter.
     *
     * The result of the operation is internally cached for later retrieval using get_result().
     *
     * @param string|string[] $fields Field names to search in
     * @param string|string[] $value Search value, or array of values, one for each field in $fields
     * @param int $mode     Search mode. Sum of rcube_addressbook::SEARCH_*.
     * @param bool $select   True if records are requested in the result, false if count only
     * @param bool $nocount  True to skip the count query (select only)
     * @param string|string[] $required Field or list of fields that cannot be empty
     *
     * @return rcube_result_set Contact records and 'count' value
     */
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        $logger = Config::inst()->logger();
        $mode = intval($mode);

        if (!is_array($required)) {
            $required = empty($required) ? [] : [$required];
        }

        $logger->debug(
            "search("
            . "FIELDS=[" . (is_array($fields) ? implode(", ", $fields) : $fields) . "], "
            . "VAL=" . (is_array($value) ? "[" . implode(", ", $value) . "]" : $value) . ", "
            . "MODE=$mode, SEL=$select, NOCNT=$nocount, "
            . "REQ=[" . implode(", ", $required) . "]"
            . ")"
        );

        // (1) build the SQL WHERE clause for the fields to check against specific values
        ['filter' => $filter, 'postSearchMode' => $allMustMatch, 'postSearchFilter' => $postSearchFilter] =
            $this->buildDatabaseSearchFilter($fields, $value, $mode);

        // (2) Additionally, the search may request some fields not to be empty.
        // Compute the corresponding search clause and append to the existing one from (1)

        // this is an optional filter configured by the administrator that requires the given fields be not empty
        $required = array_unique(array_merge($required, $this->requiredProps));

        foreach (array_intersect($required, $this->table_cols) as $col) {
            $filter[] = new DbAndCondition(new DbOrCondition("!{$col}", ""));
        }
        $required = array_diff($required, $this->table_cols);

        // Post-searching in vCard data fields
        // we will search in all records and then build a where clause for their IDs
        if (!empty($postSearchFilter) || !empty($required)) {
            $ids = [ "0" ]; // 0 is never a valid ID - this is used to make sure the match values are a non-empty set

            // make sure we get all records - disable page constraint for list_records
            $pageBackup = $this->list_page;
            $pageSizeBackup = $this->page_size;

            try {
                $this->set_page(1);
                $this->set_pagesize(99999);
                // use initial filter to limit records number if possible
                $this->set_search_set($filter);
                $result = $this->list_records();
            } finally {
                $this->list_page = $pageBackup;
                $this->page_size = $pageSizeBackup;
            }

            while (
                /** @var ?SaveData $save_data */
                $save_data = $result->next()
            ) {
                if ($this->checkPostSearchFilter($save_data, $required, $allMustMatch, $postSearchFilter, $mode)) {
                    /** @var array{ID: string} $save_data */
                    $ids[] = $save_data["ID"];
                }
            }

            $filter = [ new DbAndCondition(new DbOrCondition($this->primary_key, $ids)) ];

            // when we know we have an empty result
            if (count($ids) < 2) {
                $this->set_search_set($filter);
                $result = new rcube_result_set(0, 0);
                $this->result = $result;
                return $result;
            }
        }

        $this->set_search_set($filter);
        if ($select) {
            $result = $this->list_records(null, 0, $nocount);
        } else {
            $result = $this->count();
            $this->result = $result;
        }

        return $result;
    }

    /**
     * Count the number of contacts in the database matching the current filter criteria.
     *
     * The current filter criteria are defined by the search filter (see search()/set_search_set()), the currently
     * active group (see set_group()), and the required contact properties (see $requiredProps), if applicable.
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count(): rcube_result_set
    {
        try {
            $numCards = $this->doCount();
            return new rcube_result_set($numCards, ($this->list_page - 1) * $this->page_size);
        } catch (\Exception $e) {
            $logger = Config::inst()->logger();
            $logger->error(__METHOD__ . " exception: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return new rcube_result_set();
        }
    }

    /**
     * Return the last result set
     *
     * @return ?rcube_result_set Current result set or NULL if nothing selected yet
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_result(): ?rcube_result_set
    {
        return $this->result;
    }

    /**
     * Get a specific contact record
     *
     * The result of the operation is internally cached for later retrieval using get_result().
     *
     * @param mixed  $id    Record identifier(s)
     * @param bool $assoc True to return record as associative array, otherwise a result set is returned
     *
     * @return rcube_result_set|SaveData Result object with all record fields
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_record($id, $assoc = false)
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $db = $infra->db();
            $id = (string) $id;
            $logger->debug("get_record($id, $assoc)");

            $davAbook = $this->getCardDavObj();
            /** @var array{vcard: string} $contact */
            $contact = $db->lookup(['id' => $id, "abook_id" => $this->id], ['vcard'], 'contacts');
            $vcard = $this->parseVCard($contact['vcard']);
            $save_data = $this->dataConverter->toRoundcube($vcard, $davAbook);
            $save_data['ID'] = $id;

            $this->result = new rcube_result_set(1);
            $this->result->add($save_data);

            return $assoc ? $save_data : $this->result;
        } catch (\Exception $e) {
            $logger->error("Could not get contact $id: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, "Could not get contact $id");
            if ($assoc) {
                /** @var SaveData $ret Psalm does not consider the empty array a subtype */
                $ret = [];
                return $ret;
            } else {
                return new rcube_result_set();
            }
        }
    }

    /**
     * Set internal sort settings
     *
     * @param ?string $sort_col   Sort column
     * @param ?string $sort_order Sort order
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function set_sort_order($sort_col, $sort_order = null): void
    {
        if (isset($sort_col) && key_exists($sort_col, $this->coltypes)) {
            $this->sort_col = $sort_col;
        }

        if (isset($sort_order)) {
            $this->sort_order = strtoupper($sort_order) == 'DESC' ? 'DESC' : 'ASC';
        }
    }

    /**
     * Create a new contact record
     *
     * @param array $save_data Associative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @param bool $check True to check for duplicates first
     *
     * @return string|false The created record ID on success, false on error
     */
    public function insert($save_data, $check = false)
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        /** @var SaveData $save_data */
        try {
            $logger->info("insert(" . ($save_data["name"] ?? "no name") . ", $check)");
            $db = $infra->db();

            $vcard = $this->dataConverter->fromRoundcube($save_data);

            $davAbook = $this->getCardDavObj();
            [ 'uri' => $uri ] = $davAbook->createCard($vcard);

            $this->resync();

            /**
             * We preferably check the UID. But as some CardDAV services (i.e. Google) change the UID in the VCard to a
             * server-side one, we fall back to searching by URL if the UID search returned no results.
             * @var ?array{id: string} $contact
             */
            [ $contact ] = $db->get(['cuid' => (string) $vcard->UID, "abook_id" => $this->id], ['id'], 'contacts');
            if (!isset($contact)) {
                /** @var array{id: string} */
                $contact = $db->lookup(['uri' => $uri, "abook_id" => $this->id], ['id'], 'contacts');
            }

            if (isset($contact["id"])) {
                return $contact["id"];
            }
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
        }
        return false;
    }

    /**
     * Update a specific contact record
     *
     * @param mixed $id        Record identifier
     * @param array $save_cols Associative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     *
     * @return string|bool On success if ID has been changed returns ID, otherwise True, False on error
     */
    public function update($id, $save_cols)
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        $id = (string) $id;
        /** @var SaveData $save_cols */
        try {
            $logger->info("update(" . ($save_cols["name"] ?? "no name") . ", ID=$id)");
            $db = $infra->db();

            /**
             * get current DB data
             * @var array{etag: string, uri: string, showas: string, vcard: string} $contact
             */
            $contact = $db->lookup(["id" => $id, "abook_id" => $this->id], ["uri", "etag", "vcard", "showas"]);
            $save_cols['showas'] = $contact['showas'];

            // create vcard from current DB data to be updated with the new data
            $vcard = $this->parseVCard($contact['vcard']);
            $vcard = $this->dataConverter->fromRoundcube($save_cols, $vcard);

            $davAbook = $this->getCardDavObj();
            $etag = $davAbook->updateCard($contact['uri'], $vcard, $contact['etag']);

            $this->resync();

            // if the ETag is null, the update failed because of a precondition check; we performed a resync above, so a
            // subsequent update is more likely to succeed
            if ($etag === null) {
                $this->set_error(rcube_addressbook::ERROR_SAVING, $this->gettext('cd_etagmismatch'));
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            $logger->error("Failed to update contact $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark one or more contact records as deleted
     *
     * @param array $ids   Record identifiers
     * @param bool  $force Remove records irreversible (see self::undelete)
     *
     * @return int|false Number of removed records, False on failure
     */
    public function delete($ids, $force = true)
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        /** @var list<string> $ids */
        $deleted = 0;
        $logger->info("delete([" . implode(",", $ids) . "])");

        try {
            $davAbook = $this->getCardDavObj();

            $db->startTransaction();
            /** @var list<array{id: numeric-string, uri: string, cuid: string}> $contacts */
            $contacts = $db->get(['id' => $ids, "abook_id" => $this->id], ['id', 'cuid', 'uri']);

            // make sure we only have contacts in $ids that belong to this addressbook
            $ids = array_column($contacts, "id");
            $contact_cuids = array_column($contacts, "cuid");

            /**
             * remove contacts from VCard based groups - get groups that the contacts are members of
             * @var list<string> $groupids
             */
            $groupids = array_column($db->get(['contact_id' => $ids], ['group_id'], 'group_user'), "group_id");

            if (!empty($groupids)) {
                /** @var list<array{id: numeric-string, uri: ?string, etag: ?string, vcard: ?string}> $groups */
                $groups = $db->get(['id' => $groupids], ["id", "etag", "uri", "vcard"], "groups");

                foreach ($groups as $group) {
                    if (isset($group["vcard"])) {
                        /** @var array{id: numeric-string, uri: string, etag: string, vcard: string} $group */
                        $this->removeContactsFromVCardBasedGroup($contact_cuids, $group);
                    }
                }
            }
            $db->endTransaction();

            // delete the contact cards from the server
            foreach ($contacts as $contact) {
                $davAbook->deleteCard($contact['uri']);
                ++$deleted;
            }

            // and sync back the changes to the cache
            $this->resync();
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            $logger->error("Failed to delete contacts [" . implode(",", $ids) . "]:" . $e->getMessage());
            $db->rollbackTransaction();
            return false;
        }

        return $deleted;
    }

    /**
     * Mark all records in database as deleted
     *
     * @param bool $with_groups Remove also groups
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function delete_all($with_groups = false): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $db = $infra->db();
            $logger->info("delete_all($with_groups)");
            $davAbook = $this->getCardDavObj();
            $abook_id = $this->id;

            /**
             * first remove / clear KIND=group vcard-based groups
             * @var list<array{uri: string, vcard: string, etag: string}> $vcard_groups
             */
            $vcard_groups = $db->get(["abook_id" => $abook_id, "!vcard" => null], ["uri", "vcard", "etag"], "groups");

            foreach ($vcard_groups as $vcard_group) {
                if ($with_groups) {
                    $davAbook->deleteCard($vcard_group["uri"]);
                } else {
                    // create vcard from current DB data to be updated with the new data
                    $vcard = $this->parseVCard($vcard_group['vcard']);
                    $vcard->remove('X-ADDRESSBOOKSERVER-MEMBER');
                    $davAbook->updateCard($vcard_group['uri'], $vcard, $vcard_group['etag']);
                }
            }

            /**
             * now delete all contact cards
             * @var list<array{uri: string}> $contacts
             */
            $contacts = $db->get(["abook_id" => $abook_id], ["uri"]);
            foreach ($contacts as $contact) {
                $davAbook->deleteCard($contact["uri"]);
            }

            // and sync the changes back
            $this->resync();

            // CATEGORIES-type groups are still inside the DB - remove if requested
            $db->delete(["abook_id" => $abook_id], "groups");
        } catch (\Exception $e) {
            $logger->error("delete_all: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());
        }
    }

    /**
     * Sets/clears the current group.
     *
     * This affects the contact set considered when using the count(), list_records() and search() operations to those
     * contacts that belong to the given group. If no current group is set, all contacts in the addressbook are
     * considered.
     *
     * This filter mechanism is applied in addition to other filter mechanisms, see the description of the count()
     * operation.
     *
     * @param null|0|string $group_id Database identifier of the group. 0/"0"/null to reset the group filter.
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function set_group($group_id): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->debug("set_group($group_id)");

            if ($group_id) {
                $db = $infra->db();
                // check for valid ID with the database - this throws an exception if the group cannot be found
                $db->lookup(["id" => $group_id, "abook_id" => $this->id], ["id"], "groups");
                $this->group_id = $group_id;
            } else {
                $this->group_id = null;
            }
        } catch (\Exception $e) {
            $logger->error("set_group($group_id): " . $e->getMessage());
        }
    }

    /**
     * List all active contact groups of this source
     *
     * @param ?string $search Optional search string to match group name
     * @param int     $mode   Search mode. Sum of self::SEARCH_*
     *
     * @return list<GroupSaveData> List of contact groups, each a hash array
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function list_groups($search = null, $mode = 0): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->debug("list_groups(" . ($search ?? 'null') . ", $mode)");
            $db = $infra->db();

            $conditions = ["abook_id" => $this->id];
            if ($search !== null) {
                if ($mode & rcube_addressbook::SEARCH_STRICT) {
                    $conditions['name'] = $search;
                } elseif ($mode & rcube_addressbook::SEARCH_PREFIX) {
                    $conditions['%name'] = "$search%";
                } else {
                    $conditions['%name'] = "%$search%";
                }
            }

            /** @var list<array{id: string, name: string}> $groups */
            $groups = $db->get($conditions, ["id", "name"], "groups");
            $groups = array_map(
                /**
                 * @param array{id: string, name: string} $grp
                 * @return GroupSaveData
                 */
                function (array $grp): array {
                    $grp['ID'] = $grp['id'];
                    return $grp;
                },
                $groups
            );

            usort(
                $groups,
                /**
                 * @param array{name: string} $g1
                 * @param array{name: string} $g2
                 */
                function (array $g1, array $g2): int {
                    return strcasecmp($g1["name"], $g2["name"]);
                }
            );

            return $groups;
        } catch (\Exception $e) {
            $logger->error(__METHOD__ . "(" . ($search ?? 'null') . ", $mode) exception: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return [];
        }
    }

    /**
     * Get group properties such as name and email address(es)
     *
     * @param string $group_id Group identifier
     *
     * @return ?GroupSaveData Group properties as hash array, null in case of error.
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_group($group_id): ?array
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->debug("get_group($group_id)");
            $db = $infra->db();

            // As of 1.4.6, roundcube is interested in name and email properties of a group,
            // i. e. if the group as a distribution list had an email address of its own. Otherwise, it will fall back
            // to getting the individual members' addresses
            /** @var array{id: numeric-string, name: string} $result */
            $result = $db->lookup(["id" => $group_id, "abook_id" => $this->id], ['id', 'name'], 'groups');
            $result['ID'] = $result['id'];
            return $result;
        } catch (\Exception $e) {
            $logger->error("get_group($group_id): Could not get group: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, "Could not get group $group_id");
        }

        return null;
    }

    /**
     * Create a contact group with the given name
     *
     * @param string $name The group name
     *
     * @return array|false False on error, array with record props in success
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function create_group($name)
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->info("create_group($name)");
            $db = $infra->db();

            $save_data = [ 'name' => $name, 'kind' => 'group' ];

            if ($this->config['use_categories']) {
                $groupid = $db->storeGroup($this->id, $save_data);
                return [ 'id' => $groupid, 'name' => $name ];
            } else {
                $davAbook = $this->getCardDavObj();
                $vcard = $this->dataConverter->fromRoundcube($save_data, null);
                $davAbook->createCard($vcard);

                $this->resync();

                return $db->lookup(['cuid' => (string) $vcard->UID], ['id', 'name'], 'groups');
            }
        } catch (\Exception $e) {
            $logger->error("create_group($name): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
        }

        return false;
    }

    /**
     * Delete the given group and all linked group members
     *
     * @param string $group_id Group identifier
     *
     * @return bool True on success, false if no data was changed
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function delete_group($group_id): bool
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $logger->info("delete_group($group_id)");
            $davAbook = $this->getCardDavObj();

            $db->startTransaction(false);
            /** @var array{name: string, uri: ?string} $group */
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], ['name', 'uri'], 'groups');

            if (isset($group["uri"])) { // KIND=group VCard-based group
                $davAbook->deleteCard($group["uri"]);
            } else { // CATEGORIES-type group
                $groupname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);

                if (empty($contact_ids)) {
                    // will not be deleted by sync, delete right now
                    $db->delete(["id" => $group_id, "abook_id" => $this->id], "groups");
                } else {
                    $this->adjustContactCategories(
                        $contact_ids,
                        /** @param list<string> $groups */
                        function (array &$groups, string $_contact_id) use ($groupname): bool {
                            return self::stringsAddRemove($groups, [], [$groupname]);
                        }
                    );
                }
            }

            $db->endTransaction();
            $this->resync();

            return true;
        } catch (\Exception $e) {
            $logger->error("delete_group($group_id): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());

            $db->rollbackTransaction();
        }

        return false;
    }

    /**
     * Rename a specific contact group
     *
     * @param string $group_id Group identifier
     * @param string $newname  New name to set for this group
     * @param string &$newid   New group identifier (if changed, otherwise don't set)
     *
     * @return string|false New name on success, false if no data was changed
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function rename_group($group_id, $newname, &$newid)
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $ret = $newname;

            $logger->info("rename_group($group_id, $newname)");
            $db = $infra->db();
            $davAbook = $this->getCardDavObj();
            /** @var array{uri: ?string, name: string, etag: ?string, vcard: ?string} $group */
            $group = $db->lookup(
                ["id" => $group_id, "abook_id" => $this->id],
                ['uri', 'name', 'etag', 'vcard'],
                'groups'
            );

            if (isset($group["uri"])) { // KIND=group VCard-based group
                /** @var array{uri: string, name: string, etag: string, vcard: string} $group */
                $vcard = $this->parseVCard($group["vcard"]);
                $vcard->FN = $newname;
                $vcard->N  = [$newname,"","","",""];

                if ($davAbook->updateCard($group["uri"], $vcard, $group["etag"]) === null) {
                    $this->set_error(rcube_addressbook::ERROR_SAVING, $this->gettext('cd_etagmismatch'));
                    $ret = false;
                }
            } else { // CATEGORIES-type group
                $oldname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);

                if (empty($contact_ids)) {
                    // rename empty group in DB
                    $db->update(["id" => $group_id, "abook_id" => $this->id], ["name"], [$newname], "groups");
                } else {
                    $this->adjustContactCategories(
                        $contact_ids,
                        /** @param list<string> $groups */
                        function (array &$groups, string $_contact_id) use ($oldname, $newname): bool {
                            return self::stringsAddRemove($groups, [ $newname ], [ $oldname ]);
                        }
                    );
                    // resync will insert the contact assignments as a new group
                }
            }

            $this->resync();
            return $ret;
        } catch (\Exception $e) {
            $logger->error("rename_group($group_id, $newname): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
        }

        return false;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string       $group_id Group identifier
     * @param array|string $ids      List of contact identifiers to be added
     *
     * @return int Number of contacts added
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function add_to_group($group_id, $ids): int
    {
        /** @var list<string>|string $ids */
        $added = 0;

        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $davAbook = $this->getCardDavObj();

            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }

            $db->startTransaction();

            /**
             * get current DB data
             * @var array{uri: ?string, name: string, etag: ?string, vcard: ?string} $group
             */
            $group = $db->lookup(
                ["id" => $group_id, "abook_id" => $this->id],
                ['name', 'uri', 'etag', 'vcard'],
                'groups'
            );

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                /** @var list<array{id: numeric-string, cuid: string}> $contacts */
                $contacts = $db->get(["id" => $ids, "abook_id" => $this->id], ["id", "cuid"]);
                $db->endTransaction();

                /** @var array{uri: string, name: string, etag: string, vcard: string} $group */

                // create vcard from current DB data to be updated with the new data
                $vcard = $this->parseVCard($group['vcard']);

                foreach ($contacts as $contact) {
                    try {
                        $vcard->add('X-ADDRESSBOOKSERVER-MEMBER', "urn:uuid:" . $contact['cuid']);
                        ++$added;
                    } catch (\Exception $e) {
                        $logger->warning("add_to_group: Contact with ID {$contact['cuid']} not found in DB");
                    }
                }

                if ($davAbook->updateCard($group['uri'], $vcard, $group['etag']) === null) {
                    $added = 0;
                    $this->set_error(rcube_addressbook::ERROR_SAVING, $this->gettext('cd_etagmismatch'));
                }

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $db->endTransaction();
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids, // unfiltered ids allowed in adjustContactCategories()
                    /** @param list<string> $groups */
                    function (array &$groups, string $contact_id) use ($logger, $groupname, &$added): bool {
                        /** @var int $added */
                        if (self::stringsAddRemove($groups, [ $groupname ])) {
                            $logger->debug("Adding contact $contact_id to category $groupname");
                            ++$added;
                            return true;
                        } else {
                            $logger->debug("Contact $contact_id already belongs to category $groupname");
                        }
                        return false;
                    }
                );
                /** @var int $added Reference from the closure appears to confuse psalm */
            }

            $this->resync();
        } catch (\Exception $e) {
            $logger->error("add_to_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());

            $db->rollbackTransaction();
        }

        return $added;
    }

    /**
     * Remove the given contact records from a certain group
     *
     * @param string       $group_id Group identifier
     * @param array|string $ids      List of contact identifiers to be removed
     *
     * @return int Number of deleted group members
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function remove_from_group($group_id, $ids): int
    {
        /** @var list<string>|string $ids */
        $deleted = 0;
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }
            $logger->info("remove_from_group($group_id, [" . implode(",", $ids) . "])");

            $db->startTransaction();

            /**
             * get current DB data
             * @var array{name: string, uri: ?string} $group
             */
            $group = $db->lookup(
                ["id" => $group_id, "abook_id" => $this->id],
                ['name', 'uri', 'etag', 'vcard'],
                'groups'
            );

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                /** @var list<array{id: numeric-string, cuid: string}> $contacts */
                $contacts = $db->get(["id" => $ids, "abook_id" => $this->id], ["id", "cuid"]);
                $db->endTransaction();

                /** @var array{name: string, uri: string, etag: string, vcard: string} $group */
                $deleted = $this->removeContactsFromVCardBasedGroup(array_column($contacts, "cuid"), $group);

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $db->endTransaction();
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids, // unfiltered ids allowed in adjustContactCategories()
                    /** @param list<string> $groups */
                    function (array &$groups, string $contact_id) use ($logger, $groupname, &$deleted): bool {
                        /** @var int $deleted */
                        if (self::stringsAddRemove($groups, [], [$groupname])) {
                            $logger->debug("Removing contact $contact_id from category $groupname");
                            ++$deleted;
                            return true;
                        } else {
                            $logger->debug("Contact $contact_id not a member category $groupname - skipped");
                        }
                        return false;
                    }
                );
                /** @psalm-var int $deleted Reference from the closure appears to confuse psalm */
            }

            $this->resync();
        } catch (\Exception $e) {
            $logger->error("remove_from_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());

            $db->rollbackTransaction();
            return 0;
        }

        return $deleted;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed $id Record identifier
     *
     * @return array<numeric-string, string> List of assigned groups as ID=>Name pairs
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_record_groups($id): array
    {
        $id = (string) $id;
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $logger->debug("get_record_groups($id)");
            /** @var list<string> $groupIds */
            $groupIds = array_column($db->get(['contact_id' => $id], ['group_id'], 'group_user'), 'group_id');
            if (empty($groupIds)) {
                $groups = [];
            } else {
                /** @var array<numeric-string, string> $groups */
                $groups = array_column(
                    $db->get(['id' => $groupIds, 'abook_id' => $this->id], ['id', 'name'], 'groups'),
                    'name',
                    'id'
                );
            }

            return $groups;
        } catch (\Exception $e) {
            $logger->error("get_record_groups($id): " . $e->getMessage());
            $this->set_error(self::ERROR_SEARCH, $e->getMessage());
            return [];
        }
    }


    /************************************************************************
     *                  PUBLIC PLUGIN INTERNAL FUNCTIONS
     ***********************************************************************/

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns addressbook's refresh time in seconds
     *
     * @return int refresh time in seconds
     */
    public function getRefreshTime(): int
    {
        return intval($this->config['refresh_time']);
    }

    /**
     * Synchronizes the local card store with the CardDAV server.
     *
     * @return int The duration in seconds that the sync took.
     */
    public function resync(): int
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();
        $duration = -1;

        try {
            $start_refresh = time();
            $davAbook = $this->getCardDavObj();
            $synchandler = new SyncHandlerRoundcube($this, $this->dataConverter, $davAbook);
            $syncmgr = new Sync();

            $sync_token = $syncmgr->synchronize($davAbook, $synchandler, [ ], $this->config['sync_token']);
            $this->config['sync_token'] = $sync_token;
            $this->config["last_updated"] = (string) time();
            $db->update(
                $this->id,
                ["last_updated", "sync_token"],
                [$this->config["last_updated"], $sync_token],
                "addressbooks"
            );

            $duration = time() - $start_refresh;
            $logger->info("sync of addressbook {$this->id} ({$this->get_name()}) took $duration seconds");

            if ($synchandler->hadErrors) {
                $this->set_error(rcube_addressbook::ERROR_SAVING, "Non-fatal errors occurred during sync");
            }
        } catch (\Exception $e) {
            $logger->error("Errors occurred during the refresh of addressbook " . $this->id . ": $e");
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
        }

        return $duration;
    }

    /**
     * Determines the due time for the next resync of this addressbook relative to the current time.
     *
     * @return int Seconds until next resync is due (negative if resync due time is in the past)
     */
    public function checkResyncDue(): int
    {
        $ts_now = time();
        $ts_nextupd = intval($this->config["last_updated"]) + intval($this->config["refresh_time"]);
        $ts_diff = ($ts_nextupd - $ts_now);
        return $ts_diff;
    }

    /**
     * Adds/removes strings from an array.
     *
     * The function removes all whitespace-only strings plus the strings in $rm from $in.
     * It then adds all the strings in $add to $in, except if they are already contained in $in.
     *
     * @param list<string> $in  The array to modify (passed by reference)
     * @param list<string> $add The list of strings to add to $in
     * @param list<string> $rm  The list of strings to remove from $in
     * @return bool True if changes were made to $in.
     */
    public static function stringsAddRemove(array &$in, array $add = [], array $rm = []): bool
    {
        $changes = false;
        $result = [];

        // first remove all whitespace entries and the entries in $rm
        foreach ($in as $v) {
            if ((!empty(trim($v))) && (!in_array($v, $rm, true))) {
                $result[] = $v;
            } else {
                $changes = true;
            }
        }

        foreach ($add as $a) {
            if (!in_array($a, $result, true)) {
                $result[] = $a;
                $changes = true;
            }
        }

        if ($changes) {
            $in = $result;
        }
        return $changes;
    }


    /************************************************************************
     *                            PRIVATE FUNCTIONS
     ***********************************************************************/

    /**
     * Queries the records (or a subset, if requested) of the current page of the total record set matching the current
     * filter conditions.
     *
     * The record sets are added to the given result set.
     *
     * @param ?list<string> $cols List of contact fields to provide, null means all.
     * @return int The number of records added to the result set.
     */
    private function listRecordsReadDB(?array $cols, int $subset, rcube_result_set $result): int
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        // Subset is a further narrows the records contained within the active page. It must therefore not exceed the
        // page size; it should not happen, but just in case it does we limit subset to the page size here
        if (abs($subset) > $this->page_size) {
            $subset = ($subset < 0) ? -$this->page_size : $this->page_size;
        }

        // determine result subset needed
        $firstrow = ($subset >= 0) ?
            $result->first : ($result->first + $this->page_size + $subset);
        $numrows  = $subset ? abs($subset) : $this->page_size;

        // determine whether we have to parse the vcard or if only db cols are requested
        if (isset($cols)) {
            if (count(array_intersect($cols, $this->table_cols)) < count($cols)) {
                $dbattr = ['vcard'];
            } else {
                $dbattr = $cols;
            }
        } else {
            $dbattr = ['vcard'];
        }

        $sort_column = $this->sort_col;
        if ($this->sort_order == "DESC") {
            $sort_column = "!$sort_column";
        }

        $logger->debug("listRecordsReadDB " . join(',', $dbattr) . " [$firstrow, $numrows] ORD($sort_column)");

        $conditions = $this->currentFilterConditions();

        if (isset($conditions)) {
            /** @var list<array{id: numeric-string, name: string, vcard?: string} & array<string,?string>> $contacts */
            $contacts = $db->get(
                $conditions,
                array_values(array_unique(array_merge(["id", "name"], $dbattr))),
                'contacts',
                [
                    'limit' => [ $firstrow, $numrows ],
                    'order' => [ $sort_column ]
                ]
            );
        } else {
            $contacts = [];
        }

        // FIXME ORDER BY (CASE WHEN showas='COMPANY' THEN organization ELSE " . $sort_column . " END)

        $dc = $this->dataConverter;
        $resultCount = 0;
        foreach ($contacts as $contact) {
            $save_data = [];

            if (isset($contact['vcard'])) {
                $vcf = $contact['vcard'];
                try {
                    $vcard = $this->parseVCard($vcf);
                    $davAbook = $this->getCardDavObj();
                    $save_data = $dc->toRoundcube($vcard, $davAbook);
                } catch (\Exception $e) {
                    $logger->warning("Couldn't parse vcard $vcf");
                    continue;
                }
            } elseif (isset($cols)) { // NOTE always true at this point, but difficult to know for psalm
                foreach ($cols as $col) {
                    $colval = $contact[$col] ?? "";
                    if (strlen($colval) > 0) {
                        if ($dc->isMultivalueProperty($col)) {
                            $save_data[$col] = explode(AbstractDatabase::MULTIVAL_SEP, $colval);
                        } else {
                            $save_data[$col] = $colval;
                        }
                    }
                }
            }

            /** @var SaveData $save_data */
            $save_data['ID'] = $contact['id'];
            ++$resultCount;
            $result->add($save_data);
        }

        return $resultCount;
    }

    /**
     * This function builds an array of search criteria for the Database search for matching the requested
     * contact search fields against fields of the carddav_contacts table according to the given search $mode.
     *
     * Only some fields are contained as individual columns in the carddav_contacts table, as indicated by
     * $this->table_cols. The remaining fields need to be searched for in the VCards, which are a single column in the
     * database. Therefore, the database search can only match against the entire VCard, but will not check if the
     * correct property of the VCard contained the value. Thus, if such search fields are queried, the DB result needs
     * to be post-filtered with a check for these particular fields against the VCard properties.
     *
     * This function returns an associative array with entries:
     *   "filter": An array of DbAndCondition for use with the AbstractDatabase::get() function.
     *   "postSearchMode": true if all conditions must match ("AND"), false if a single match is sufficient ("OR")
     *   "postSearchFilter": An array of two-element arrays, each with: [ column name, lower-cased search value ]
     *
     * @param string|string[] $fields Field names to search in
     * @param string|string[] $value Search value, or array of values, one for each field in $fields
     * @param int $mode Matching mode, see Addressbook::search() for extended description.
     * @return array{filter: list<DbAndCondition>, postSearchMode: bool, postSearchFilter: list<array{string,string}>}
     */
    private function buildDatabaseSearchFilter($fields, $value, int $mode): array
    {
        $conditions = [];
        $postSearchMode = false;
        $postSearchFilter = [];

        if (($fields == "ID") || ($fields == $this->primary_key)) {
            // direct ID search
            $ids     = is_array($value) ? $value : explode(self::SEPARATOR, $value);
            $conditions[] = new DbAndCondition(new DbOrCondition($this->primary_key, $ids));
        } elseif (is_array($value)) {
            $postSearchMode = true;

            // Advanced search
            foreach ((array) $fields as $idx => $col) {
                // the value to check this field for
                $fValue = $value[$idx];
                if (empty($fValue)) {
                    continue;
                }

                if (in_array($col, $this->table_cols)) { // table column
                    // Note: we don't need to add this columns to the post match filter, because it is already
                    // determined by the database search that the condition is fulfilled
                    $conditions[] = $this->rcSearchCondition($mode, $col, $fValue);
                } else { // vCard field
                    $conditions[] = $this->rcSearchCondition(rcube_addressbook::SEARCH_ALL, 'vcard', $fValue);
                    $postSearchFilter[] = [$col, mb_strtolower($fValue)];
                }
            }
        } elseif ($fields == '*') {
            // search all fields
            $conditions[] = $this->rcSearchCondition(rcube_addressbook::SEARCH_ALL, 'vcard', $value);
            $postSearchFilter[] = ['*', mb_strtolower($value)];
        } else {
            $andCond = new DbAndCondition();

            // search given fields
            foreach ((array) $fields as $col) {
                if (in_array($col, $this->table_cols)) { // table column
                    $andCond->append($this->rcSearchCondition($mode, $col, $value));
                } else { // vCard field
                    $andCond->append($this->rcSearchCondition(rcube_addressbook::SEARCH_ALL, 'vcard', $value));
                }

                // Note: because a match against any column is sufficient, we must add all columns to the post search
                // filter. Otherwise, cards that match at DB columns but none of the vcard columns would be discarded by
                // the post search filter
                $postSearchFilter[] = [$col, mb_strtolower($value)];
            }

            $conditions[] = $andCond;
        }

        return ['filter' => $conditions, 'postSearchMode' => $postSearchMode, 'postSearchFilter' => $postSearchFilter];
    }

    /**
     * Produces a DbAndCondition resembling a roundcube search condition passed to the search() function.
     *
     * @param int $mode The search mode as given to search()
     * @param string $col The database column so search in
     * @param string $val The value to match for, according to the search mode
     */
    private function rcSearchCondition(int $mode, string $col, string $val): DbAndCondition
    {
        $cond = new DbAndCondition();
        $multi = (($col == "vcard") ? false : $this->dataConverter->isMultivalueProperty($col));
        $SEP = AbstractDatabase::MULTIVAL_SEP;

        if ($mode & rcube_addressbook::SEARCH_STRICT) { // exact match
            $cond->add("%{$col}", $val);

            if ($multi) {
                $cond->add("%{$col}", "{$val}{$SEP}%")        // line beginning match 'name@domain.com, %'
                     ->add("%{$col}", "%{$SEP}{$val}{$SEP}%") // middle match '%, name@domain.com, %'
                     ->add("%{$col}", "%{$SEP}{$val}");       // line end match '%, name@domain.com'
            }
        } elseif ($mode & rcube_addressbook::SEARCH_PREFIX) { // prefix match (abc*)
            $cond->add("%{$col}", "{$val}%");
            if ($multi) {
                $cond->add("%{$col}", "%{$SEP}{$val}%"); // middle/end match '%, name%'
            }
        } else { // "contains" match (*abc*)
            $cond->add("%{$col}", "%{$val}%");
        }

        return $cond;
    }

    /**
     *  Determines and returns the number of cards matching the current search criteria.
     */
    private function doCount(): int
    {
        $numCards = 0;
        $conditions = $this->currentFilterConditions();

        if (isset($conditions)) {
            $db = Config::inst()->db();
            [$result] = $db->get($conditions, [], 'contacts', [ 'count' => true ]);
            $numCards = intval($result['*']);
        }

        return $numCards;
    }

    private function parseVCard(string $vcf): VCard
    {
        // create vcard from current DB data to be updated with the new data
        $vcard = VObject\Reader::read($vcf, VObject\Reader::OPTION_FORGIVING);
        if (!($vcard instanceof VCard)) {
            throw new \Exception("parseVCard: parsing of string did not result in a VCard object: $vcf");
        }
        return $vcard;
    }

    /**
     * Removes a list of contacts from a KIND=group VCard-based group and updates the group on the server.
     *
     * An update of the card on the server will only be performed if members have actually been removed from the VCard,
     * i. e. the function returns a value greater than 0.
     *
     * @param list<string> $contact_cuids The VCard UIDs of the contacts to remove from the group.
     * @param array{etag: string, uri: string, vcard: string} $group Save data for the group.
     *
     * @return int The number of members actually removed from the group.
     */
    private function removeContactsFromVCardBasedGroup(array $contact_cuids, array $group): int
    {
        $deleted = 0;

        // create vcard from current DB data to be updated with the new data
        $vcard = $this->parseVCard($group["vcard"]);

        foreach ($contact_cuids as $cuid) {
            $search_for = "urn:uuid:$cuid";
            $found = false;
            /** @var VObject\Property $member */
            foreach (($vcard->{'X-ADDRESSBOOKSERVER-MEMBER'} ?? []) as $member) {
                if ($member == $search_for) {
                    $vcard->remove($member);
                    $found = true;
                    // we don't break here - just in case the member is listed several times in the VCard
                }
            }
            if ($found) {
                ++$deleted;
            }
        }

        if ($deleted > 0) {
            $davAbook = $this->getCardDavObj();
            if ($davAbook->updateCard($group['uri'], $vcard, $group['etag']) === null) {
                $deleted = 0;
            }
        }

        return $deleted;
    }

    /**
     * Creates the AddressbookCollection object of the CardDavClient library.
     */
    private function getCardDavObj(): AddressbookCollection
    {
        if (!isset($this->davAbook)) {
            $url = $this->config["url"];

            // only the username and password are stored to DB before replacing placeholders
            $username = $this->config["username"];
            $password = $this->config["password"];

            $account = Config::makeAccount($url, $username, $password, $url);
            $this->davAbook = new AddressbookCollection($url, $account);
        }

        return $this->davAbook;
    }

    /**
     * Provides an array of the contact database ids that belong to the given group.
     *
     * @param string $groupid The database ID of the group whose contacts shall be queried.
     *
     * @return list<numeric-string> An array of the group's contacts' database IDs.
     */
    private function getContactIdsForGroup(string $groupid): array
    {
        $infra = Config::inst();
        $db = $infra->db();
        /** @var list<array{contact_id: numeric-string}> */
        $records = $db->get(['group_id' => $groupid], ['contact_id'], 'group_user');
        return array_column($records, "contact_id");
    }

    /**
     * Adjusts the CATEGORIES property of a list of contacts using a given callback function and, if changed, stores the
     * changed VCard to the server.
     *
     * @param list<string> $contact_ids A list of contact database IDs for that CATEGORIES should be adapted
     * @param callable(list<string>, string) $callback
     *                           A callback function, that performs the adjustment of the CATEGORIES values. It is
     *                           called for each contact with two parameters: The first is an array of the values of the
     *                           CATEGORIES property, which should be taken by reference and modified in place. The
     *                           second is the database id of the contact the callback is called for. The callback shall
     *                           return true if the array was modified and the VCard shall be updated on the server,
     *                           false if no change was done and no update is necessary.
     *
     */
    private function adjustContactCategories(array $contact_ids, callable $callback): void
    {
        $davAbook = $this->getCardDavObj();
        $infra = Config::inst();
        $db = $infra->db();

        /** @var list<array{id: numeric-string, etag: string, uri: string, vcard: string}> $contacts */
        $contacts = $db->get(["id" => $contact_ids, "abook_id" => $this->id], ["id", "etag", "uri", "vcard"]);

        foreach ($contacts as $contact) {
            $vcard = $this->parseVCard($contact['vcard']);
            $groups = [];
            if (isset($vcard->{"CATEGORIES"})) {
                /** @var list<string> */
                $groups = $vcard->CATEGORIES->getParts();
            }

            if ($callback($groups, $contact["id"]) !== false) {
                if (count($groups) > 0) {
                    $vcard->CATEGORIES = $groups;
                } else {
                    unset($vcard->CATEGORIES);
                }
                $davAbook->updateCard($contact['uri'], $vcard, $contact['etag']);
            }
        }
    }

    /**
     * Determines the AbstractDatabase::get() contact filter conditions.
     *
     * It must consider:
     *   - Always constrain list to current addressbook
     *   - The required non-empty fields configured by the admin ($this->requiredProps)
     *   - A search filter set by roundcube ($this->filter)
     *   - A currenty selected group ($this->group_id)
     *
     * @return ?list<DbAndCondition> Null if the current filter conditions result in an empty contact result.
     */
    private function currentFilterConditions(): ?array
    {
        $conditions = $this->filter;
        $conditions[] = new DbAndCondition(new DbOrCondition("abook_id", $this->id));
        foreach (array_intersect($this->requiredProps, $this->table_cols) as $col) {
            $conditions[] = new DbAndCondition(new DbOrCondition("!{$col}", ""));
            $conditions[] = new DbAndCondition(new DbOrCondition("!{$col}", null));
        }

        // TODO Better if we could handle this without a separate SQL query here, but requires join or subquery
        if ($this->group_id) {
            $contactsInGroup = $this->getContactIdsForGroup((string) $this->group_id);

            if (empty($contactsInGroup)) {
                $conditions = null;
            } else {
                $conditions[] = new DbAndCondition(new DbOrCondition("id", $contactsInGroup));
            }
        }

        return $conditions;
    }

    /**
     * Checks the post-filter conditions on a contact.
     *
     * Background: Some search conditions cannot be reliably filtered using the database query. This is the case if the
     * searched contact attribute is not stored as a separate database column but only found inside the vcard. In this
     * case, we will perform a prefiltering in the database query only to check if the vcard as a whole matches the
     * search condition, but post filtering is needed to check whether the match actually occurs in the correct
     * attribute.
     *
     * This function checks these post filter condition on a single contact that was provided by the database after
     * pre-filtering using the database query.
     *
     * @param SaveData $save_data The contact data to check
     * @param string[] $required A list of contact attributes that must not be empty
     * @param bool $allMustMatch Indicates if all post filter conditions must match, or if a single match is sufficient.
     * @param list<array{string,string}> $postSearchFilter The post filter conditions as pairs of attribute name and
     *                                                     match value
     * @param int $mode The roundcube search mode.
     *
     * @see search()
     */
    private function checkPostSearchFilter(
        array $save_data,
        array $required,
        bool $allMustMatch,
        array $postSearchFilter,
        int $mode
    ): bool {
        // normalize the attributes with subtype (e.g. email:home) to the generic attribute (e.g. email)
        foreach (array_keys($save_data) as $attr) {
            $colonPos = strpos($attr, ':');
            if ($colonPos !== false) {
                $genAttr = substr($attr, 0, $colonPos);
                if (isset($save_data[$genAttr])) {
                    $save_data[$genAttr] = array_merge((array) $save_data[$genAttr], (array) $save_data[$attr]);
                } else {
                    $save_data[$genAttr] = (array) $save_data[$attr];
                }

                unset($save_data[$attr]);
            }
        }

        // check that all the required fields are not empty
        foreach ($required as $requiredField) {
            if (!isset($save_data[$requiredField]) || $save_data[$requiredField] == "") {
                return false;
            }
        }

        $filterMatches = 0;
        foreach ($postSearchFilter as $psfilter) {
            [ $col, $val ] = $psfilter;
            $psFilterMatched = false;

            if ($col == '*') { // any contact attribute must match $val
                foreach ($save_data as $k => $v) {
                    // Skip photo/vcard to avoid download - matching against photo is no meaningful use case
                    if ($k !== "photo" && $k !== "vcard" && strpos($k, "_carddav_") !== 0) {
                        $v = is_array($v) ? $v : (string) $v;
                        if ($this->compare_search_value($k, $v, $val, $mode)) {
                            $psFilterMatched = true;
                            break;
                        }
                    }
                }
            } elseif (isset($save_data[$col])) {
                $sdVal = is_array($save_data[$col]) ? $save_data[$col] : (string) $save_data[$col];
                $psFilterMatched = $this->compare_search_value($col, $sdVal, $val, $mode);
            }

            if ($psFilterMatched) {
                ++$filterMatches;
            }

            if (!$allMustMatch && $psFilterMatched) {
                return true;
            } elseif ($allMustMatch && !$psFilterMatched) {
                return false;
            }
        }

        return $filterMatches > 0;
    }


    /**
     * Get a localized text from roundcube.
     *
     * This function is temporary in this class and meant to be removed with v5.
     */
    private function gettext(string $label): string
    {
        $rcube = rcube::get_instance();
        return rcube::Q($rcube->gettext($label, 'carddav'));
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
