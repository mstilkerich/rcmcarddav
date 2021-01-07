<?php

/*
    RCM CardDAV Plugin
    Copyright (C) 2011-2016 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
                            Michael Stilkerich <ms@mike2k.de>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with this program; if not, write to the Free Software Foundation, Inc.,
    51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */


namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use rcube_addressbook;
use rcube_result_set;
use rcube_utils;
use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use MStilkerich\CardDavClient\Services\{Discovery, Sync};
use MStilkerich\CardDavAddressbook4Roundcube\Db\{AbstractDatabase,DbAndCondition,DbOrCondition};
use carddav;

class Addressbook extends rcube_addressbook
{
    /** @var string SEPARATOR Separator character used by roundcube to encode multiple values in a single string. */
    private const SEPARATOR = ',';

    /** @var AbstractDatabase $db Database access object */
    private $db;

    /** @var \rcube_cache $cache */
    private $cache;

    /** @var LoggerInterface $logger Log object */
    private $logger;

    /** @var ?AddressbookCollection $davAbook the DAV AddressbookCollection Object */
    private $davAbook = null;

    /** @var DataConversion $dataConverter to convert between VCard and roundcube's representation of contacts. */
    private $dataConverter;

    /** @var string $id database ID of the addressbook */
    private $id;

    /** @var DbAndCondition[] An additional filter to limit contact searches */
    private $filter = [];

    /** @var string[] $requiredProps A list of addressobject fields that must not be empty, otherwise the addressobject
     *                               will be hidden.
     */
    private $requiredProps;

    /** @var ?rcube_result_set $result */
    private $result = null;

    /** @var string[] configuration of the addressbook */
    private $config;

    /** @var int total number of contacts in address book. Negative if not computed yet. */
    private $total_cards = -1;

    /** @var array $table_cols
     * attributes that are redundantly stored in the contact table and need
     * not be parsed from the vcard
     */
    private $table_cols = ['id', 'name', 'email', 'firstname', 'surname', 'organization'];

    /**
     * @param string[] $config
     * @param list<string> $requiredProps
     */
    public function __construct(
        string $dbid,
        AbstractDatabase $db,
        \rcube_cache $cache,
        LoggerInterface $logger,
        array $config,
        bool $readonly,
        array $requiredProps
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->db = $db;
        $this->cache = $cache;

        $this->primary_key = 'id';
        $this->groups   = true;
        $this->readonly = $readonly;
        $this->date_cols = ['birthday', 'anniversary'];
        $this->requiredProps = $requiredProps;
        $this->id       = $dbid;

        $this->dataConverter = new DataConversion($dbid, $db, $cache, $logger);
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
     * Save a search string for future listings
     *
     * @param mixed $filter Search params to use in listing method, obtained by get_search_set()
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function set_search_set($filter): void
    {
        if (is_array($filter) && (empty($filter) || $filter[0] instanceof DbAndCondition)) {
            $this->filter = $filter;
        } else {
            throw new \Exception(__METHOD__ . " requires a DbAndCondition[] type filter");
        }
        $this->total_cards = -1;
    }

    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
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
        $this->total_cards = -1;
    }

    /**
     * List the current set of contact records
     *
     * @param array $cols   List of cols to show (null means all)
     * @param int   $subset Only return this number of records, use negative values for tail
     * @param bool  $nocount True to skip the count query (select only)
     *
     * @return rcube_result_set Indexed list of contact records, each a hash array
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        if ($nocount) {
            $this->result = new rcube_result_set();
        } else {
            $this->result = $this->count();
        }

        try {
            $records = $this->listRecordsReadDB($cols, $subset, $this->result);

            if ($nocount) {
                $this->result->count = $records;
            } elseif ($this->list_page <= 1) {
                if ($records < $this->page_size && $subset == 0) {
                    $this->result->count = $records;
                } else {
                    $this->result->count = $this->doCount();
                }
            }
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . " exception: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
        }

        return $this->result;
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
     * The search settings are remembered until reset using the reset() function. They can be retrieved using
     * get_search_set(). The remembered search settings must be considered by list_records() and count().
     *
     * The search mode can be set by the admin via the config.inc.php setting addressbook_search_mode, which defaults to
     * 0. It is used as a bit mask, but the search modes are mostly exclusive; from the roundcube code, I take the
     * following interpretation:
     *   bits [1..0] = 0b00: Search all (*abc*)
     *   bits [1..0] = 0b01: Search strict (case insensitive =)
     *   bits [1..0] = 0b10: Prefix search (abc*)
     * The purpose of SEARCH_GROUPS is not clear to me and not considered.
     *
     * @param string|string[] $fields Field names to search in
     * @param string|string[] $value Search value, or array of values, one for each field in $fields
     * @param int $mode     Search mode. Sum of rcube_addressbook::SEARCH_*.
     * @param bool $select   True if results are requested, false if count only
     * @param bool $nocount  True to skip the count query (select only)
     * @param string|string[] $required Field or list of fields that cannot be empty
     *
     * @return rcube_result_set Contact records and 'count' value
     */
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        $mode = intval($mode);

        if (!is_array($required)) {
            $required = empty($required) ? [] : [$required];
        }

        $this->logger->debug(
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
            $ids = [ "0" ]; // 0 is never a valid ID
            // use initial filter to limit records number if possible
            $this->set_search_set($filter);

            $result = $this->list_records();

            while (
                /** @var ?string[] $save_data */
                $save_data = $result->next()
            ) {
                if ($this->checkPostSearchFilter($save_data, $required, $allMustMatch, $postSearchFilter, $mode)) {
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
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count(): rcube_result_set
    {
        try {
            if ($this->total_cards < 0) {
                $this->doCount();
            }

            $this->logger->debug("count() => {$this->total_cards}");
            return new rcube_result_set($this->total_cards, ($this->list_page - 1) * $this->page_size);
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . " exception: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return new rcube_result_set(0, 0);
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
     * @param mixed  $id    Record identifier(s)
     * @param bool $assoc True to return record as associative array, otherwise a result set is returned
     *
     * @return rcube_result_set|array Result object with all record fields
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_record($id, $assoc = false)
    {
        try {
            $this->logger->debug("get_record($id, $assoc)");
            $db = $this->db;

            $davAbook = $this->getCardDavObj();
            /** @var array{vcard: string} $contact */
            $contact = $db->lookup(['id' => $id, "abook_id" => $this->id], 'vcard', 'contacts');
            $vcard = $this->parseVCard($contact['vcard']);
            $save_data = $this->dataConverter->toRoundcube($vcard, $davAbook);
            $save_data['ID'] = $id;

            $this->result = new rcube_result_set(1);
            $this->result->add($save_data);

            return $assoc ? $save_data : $this->result;
        } catch (\Exception $e) {
            $this->logger->error("Could not get contact $id: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return $assoc ? [] : new rcube_result_set();
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
     * @return string|bool The created record ID on success, False on error
     */
    public function insert($save_data, $check = false)
    {
        try {
            $this->logger->info("insert(" . $save_data["name"] . ", $check)");
            $db = $this->db;

            $vcard = $this->dataConverter->fromRoundcube($save_data);

            $davAbook = $this->getCardDavObj();
            [ 'uri' => $uri ] = $davAbook->createCard($vcard);

            $this->resync();

            /**
             * We preferably check the UID. But as some CardDAV services (i.e. Google) change the UID in the VCard to a
             * server-side one, we fall back to searching by URL if the UID search returned no results.
             * @var ?array{id: string} $contact
             */
            [ $contact ] = $db->get(['cuid' => (string) $vcard->UID, "abook_id" => $this->id], 'id', 'contacts');
            if (!isset($contact)) {
                /** @var array{id: string} */
                $contact = $db->lookup(['uri' => $uri, "abook_id" => $this->id], 'id', 'contacts');
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
     * @return mixed On success if ID has been changed returns ID, otherwise True, False on error
     */
    public function update($id, $save_cols)
    {
        try {
            $db = $this->db;

            /**
             * get current DB data
             * @var array{etag: string, uri: string, showas: string, vcard: string} $contact
             */
            $contact = $db->lookup(["id" => $id, "abook_id" => $this->id], "uri,etag,vcard,showas", "contacts");
            $save_cols['showas'] = $contact['showas'];

            // create vcard from current DB data to be updated with the new data
            $vcard = $this->parseVCard($contact['vcard']);
            $vcard = $this->dataConverter->fromRoundcube($save_cols, $vcard);

            $davAbook = $this->getCardDavObj();
            $davAbook->updateCard($contact['uri'], $vcard, $contact['etag']);

            $this->resync();

            return true;
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            $this->logger->error("Failed to update contact $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark one or more contact records as deleted
     *
     * @param array $ids   Record identifiers
     * @param bool  $force Remove records irreversible (see self::undelete)
     *
     * @return int Number of contacts deleted
     */
    public function delete($ids, $force = true): int
    {
        $deleted = 0;
        $this->logger->info("delete([" . implode(",", $ids) . "])");
        $db = $this->db;

        try {
            $davAbook = $this->getCardDavObj();

            $db->startTransaction();
            /** @var list<array{id: numeric-string, uri: string, cuid: string}> $contacts */
            $contacts = $db->get(['id' => $ids, "abook_id" => $this->id], 'id,cuid,uri', 'contacts');

            // make sure we only have contacts in $ids that belong to this addressbook
            $ids = array_column($contacts, "id");
            $contact_cuids = array_column($contacts, "cuid");

            // remove contacts from VCard based groups - get groups that the contacts are members of
            $groupids = array_column($db->get(['contact_id' => $ids], 'group_id', 'group_user'), "group_id");

            if (!empty($groupids)) {
                /** @var list<array{id: numeric-string, uri: ?string, etag: ?string, vcard: ?string}> $groups */
                $groups = $db->get(['id' => $groupids], "id,etag,uri,vcard", "groups");

                foreach ($groups as $group) {
                    if (isset($group["vcard"])) {
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
            $this->logger->error("Failed to delete contacts [" . implode(",", $ids) . "]:" . $e->getMessage());
            $db->rollbackTransaction();
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
        try {
            $this->logger->info("delete_all($with_groups)");
            $db = $this->db;
            $davAbook = $this->getCardDavObj();
            $abook_id = $this->id;

            /**
             * first remove / clear KIND=group vcard-based groups
             * @var list<array{uri: string, vcard: string, etag: string}> $vcard_groups
             */
            $vcard_groups = $db->get(["abook_id" => $abook_id, "!vcard" => null], "uri,vcard,etag", "groups");

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
            $contacts = $db->get(["abook_id" => $abook_id], "uri", "contacts");
            foreach ($contacts as $contact) {
                $davAbook->deleteCard($contact["uri"]);
            }

            // and sync the changes back
            $this->resync();

            // CATEGORIES-type groups are still inside the DB - remove if requested
            $db->delete(["abook_id" => $abook_id], "groups");
        } catch (\Exception $e) {
            $this->logger->error("delete_all: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());
        }
    }

    /**
     * Setter for the current group
     *
     * @param string $gid Database identifier of the group. 0 to reset the group filter.
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function set_group($gid): void
    {
        try {
            $this->logger->debug("set_group($gid)");

            if ($gid) {
                $db = $this->db;
                // check for valid ID with the database - this throws an exception if the group cannot be found
                $db->lookup(["id" => $gid, "abook_id" => $this->id], "id", "groups");
            }

            $this->group_id = $gid;
        } catch (\Exception $e) {
            $this->logger->error("set_group($gid): " . $e->getMessage());
        }
    }

    /**
     * List all active contact groups of this source
     *
     * @param ?string $search Optional search string to match group name
     * @param int     $mode   Search mode. Sum of self::SEARCH_*
     *
     * @return array  Indexed list of contact groups, each a hash array
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function list_groups($search = null, $mode = 0): array
    {
        try {
            $this->logger->debug("list_groups(" . ($search ?? 'null') . ", $mode)");
            $db = $this->db;

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

            $groups = $db->get($conditions, "id,name", "groups");

            foreach ($groups as &$group) {
                $group['ID'] = $group['id']; // roundcube uses the ID uppercase for groups
            }

            usort(
                $groups,
                function (array $g1, array $g2): int {
                    return strcasecmp($g1["name"], $g2["name"]);
                }
            );

            return $groups;
        } catch (\Exception $e) {
            $this->logger->error(__METHOD__ . "(" . ($search ?? 'null') . ", $mode) exception: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return [];
        }
    }

    /**
     * Get group properties such as name and email address(es)
     *
     * @param string $group_id Group identifier
     *
     * @return array Group properties as hash array
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_group($group_id): array
    {
        try {
            $this->logger->debug("get_group($group_id)");
            $db = $this->db;

            // As of 1.4.6, roundcube is interested in name and email properties of a group,
            // i. e. if the group as a distribution list had an email address of its own. Otherwise, it will fall back
            // to getting the individual members' addresses
            $result = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'id,name', 'groups');
        } catch (\Exception $e) {
            $this->logger->error("get_group($group_id): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return [];
        }

        return $result;
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
        try {
            $this->logger->info("create_group($name)");
            $db = $this->db;
            $save_data = [ 'name' => $name, 'kind' => 'group' ];

            if ($this->config['use_categories']) {
                $groupid = $db->storeGroup($this->id, $save_data);
                return [ 'id' => $groupid, 'name' => $name ];
            } else {
                $davAbook = $this->getCardDavObj();
                $vcard = $this->dataConverter->fromRoundcube($save_data, null);
                $davAbook->createCard($vcard);

                $this->resync();

                return $db->lookup(['cuid' => (string) $vcard->UID], 'id,name', 'groups');
            }
        } catch (\Exception $e) {
            $this->logger->error("create_group($name): " . $e->getMessage());
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
        $db = $this->db;
        try {
            $this->logger->info("delete_group($group_id)");
            $davAbook = $this->getCardDavObj();

            $db->startTransaction(false);
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'name,uri', 'groups');

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
            $this->logger->error("delete_group($group_id): " . $e->getMessage());
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
        try {
            $this->logger->info("rename_group($group_id, $newname)");
            $db = $this->db;
            $davAbook = $this->getCardDavObj();
            /** @var array{uri: ?string, name: string, etag: ?string, vcard: ?string} $group */
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'uri,name,etag,vcard', 'groups');

            if (isset($group["uri"])) { // KIND=group VCard-based group
                /** @var array{uri: string, name: string, etag: string, vcard: string} $group */
                $vcard = $this->parseVCard($group["vcard"]);
                $vcard->FN = $newname;
                $vcard->N  = [$newname,"","","",""];

                $davAbook->updateCard($group["uri"], $vcard, $group["etag"]);
            } else { // CATEGORIES-type group
                $oldname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);

                if (empty($contact_ids)) {
                    // rename empty group in DB
                    $db->update(["id" => $group_id, "abook_id" => $this->id], ["name"], [$newname], "groups");
                } else {
                    $this->adjustContactCategories(
                        $contact_ids,
                        function (array &$groups, string $_contact_id) use ($oldname, $newname): bool {
                            return self::stringsAddRemove($groups, [ $newname ], [ $oldname ]);
                        }
                    );
                    // resync will insert the contact assignments as a new group
                }
            }

            $this->resync();
            return $newname;
        } catch (\Exception $e) {
            $this->logger->error("rename_group($group_id, $newname): " . $e->getMessage());
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
        $added = 0;
        $db = $this->db;

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
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'name,uri,etag,vcard', 'groups');

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                /** @var list<array{id: numeric-string, cuid: string}> $contacts */
                $contacts = $db->get(["id" => $ids, "abook_id" => $this->id], "id, cuid", "contacts");
                $db->endTransaction();

                /** @var array{uri: string, name: string, etag: string, vcard: string} $group */

                // create vcard from current DB data to be updated with the new data
                $vcard = $this->parseVCard($group['vcard']);

                foreach ($contacts as $contact) {
                    try {
                        $vcard->add('X-ADDRESSBOOKSERVER-MEMBER', "urn:uuid:" . $contact['cuid']);
                        ++$added;
                    } catch (\Exception $e) {
                        $this->logger->warning("add_to_group: Contact with ID {$contact['cuid']} not found in DB");
                    }
                }

                $davAbook->updateCard($group['uri'], $vcard, $group['etag']);

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $db->endTransaction();
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids, // unfiltered ids allowed in adjustContactCategories()
                    function (array &$groups, string $contact_id) use ($groupname, &$added): bool {
                        if (self::stringsAddRemove($groups, [ $groupname ])) {
                            $this->logger->debug("Adding contact $contact_id to category $groupname");
                            ++$added;
                            return true;
                        } else {
                            $this->logger->debug("Contact $contact_id already belongs to category $groupname");
                        }
                        return false;
                    }
                );
            }

            $this->resync();
        } catch (\Exception $e) {
            $this->logger->error("add_to_group: " . $e->getMessage());
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
        $deleted = 0;
        $db = $this->db;

        try {
            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }
            $this->logger->info("remove_from_group($group_id, [" . implode(",", $ids) . "])");

            $db->startTransaction();

            // get current DB data
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'name,uri,etag,vcard', 'groups');

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                $contacts = $db->get(["id" => $ids, "abook_id" => $this->id], "id, cuid", "contacts");
                $db->endTransaction();
                $deleted = $this->removeContactsFromVCardBasedGroup(array_column($contacts, "cuid"), $group);

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $db->endTransaction();
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids, // unfiltered ids allowed in adjustContactCategories()
                    function (array &$groups, string $contact_id) use ($groupname, &$deleted): bool {
                        if (self::stringsAddRemove($groups, [], [$groupname])) {
                            $this->logger->debug("Removing contact $contact_id from category $groupname");
                            ++$deleted;
                            return true;
                        } else {
                            $this->logger->debug("Contact $contact_id not a member category $groupname - skipped");
                        }
                        return false;
                    }
                );
            }

            $this->resync();
        } catch (\Exception $e) {
            $this->logger->error("remove_from_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());

            $db->rollbackTransaction();
        }

        return $deleted;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed $id Record identifier
     *
     * @return array List of assigned groups as ID=>Name pairs
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_record_groups($id): array
    {
        try {
            $this->logger->debug("get_record_groups($id)");
            $db = $this->db;
            $groupIds = array_column($db->get(['contact_id' => $id], 'group_id', 'group_user'), 'group_id');
            if (empty($groupIds)) {
                $groups = [];
            } else {
                $groups = array_column(
                    $db->get(['id' => $groupIds, 'abook_id' => $this->id], 'id,name', 'groups'),
                    'name',
                    'id'
                );
            }

            return $groups;
        } catch (\Exception $e) {
            $this->logger->error("get_record_groups($id): " . $e->getMessage());
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
        $db = $this->db;
        $duration = -1;

        try {
            $start_refresh = time();
            $davAbook = $this->getCardDavObj();
            $synchandler = new SyncHandlerRoundcube($this, $db, $this->logger, $this->dataConverter, $davAbook);
            $syncmgr = new Sync();

            $sync_token = $syncmgr->synchronize($davAbook, $synchandler, [ ], $this->config['sync_token'] ?? "");
            $this->config['sync_token'] = $sync_token;
            $this->config["last_updated"] = (string) time();
            $db->update(
                $this->id,
                ["last_updated", "sync_token"],
                [$this->config["last_updated"], $sync_token],
                "addressbooks"
            );

            $duration = time() - $start_refresh;
            $this->logger->info("sync of addressbook {$this->id} ({$this->get_name()}) took $duration seconds");

            if ($synchandler->hadErrors) {
                $this->set_error(rcube_addressbook::ERROR_SAVING, "Non-fatal errors occurred during sync");
            }
        } catch (\Exception $e) {
            $this->logger->error("Errors occurred during the refresh of addressbook " . $this->id . ": $e");
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());

            $db->rollbackTransaction();
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

    private function listRecordsReadDB(?array $cols, int $subset, rcube_result_set $result): int
    {
        // determine result subset needed
        $firstrow = ($subset >= 0) ?
            $result->first : ($result->first + $this->page_size + $subset);
        $numrows  = $subset ? abs($subset) : $this->page_size;

        // determine whether we have to parse the vcard or if only db cols are requested
        if (isset($cols)) {
            if (count(array_intersect($cols, $this->table_cols)) < count($cols)) {
                $dbattr = 'vcard';
            } else {
                $dbattr = implode(",", $cols);
            }
        } else {
            $dbattr = 'vcard';
        }

        $sort_column = $this->sort_col;
        if ($this->sort_order == "DESC") {
            $sort_column = "!$sort_column";
        }

        $this->logger->debug("listRecordsReadDB $dbattr [$firstrow, $numrows] ORD($sort_column)");
        /** @var list<array{id: numeric-string, name: string}> $contacts */
        $contacts = $this->db->get(
            $this->currentFilterConditions(),
            "id,name,$dbattr",
            'contacts',
            [
                'limit' => [ $firstrow, $numrows ],
                'order' => [ $sort_column ]
            ]
        );

        // FIXME ORDER BY (CASE WHEN showas='COMPANY' THEN organization ELSE " . $sort_column . " END)

        $dc = $this->dataConverter;
        $resultCount = 0;
        foreach ($contacts as $contact) {
            if (isset($contact['vcard'])) {
                try {
                    $vcf = $this->parseVCard($contact['vcard']);
                    $davAbook = $this->getCardDavObj();
                    $save_data = $dc->toRoundcube($vcf, $davAbook);
                } catch (\Exception $e) {
                    $this->logger->warning("Couldn't parse vcard " . $contact['vcard']);
                    continue;
                }
            } elseif (isset($cols)) { // NOTE always true at this point, but difficult to know for psalm
                $save_data = [];
                foreach (array_keys($contact) as $col) {
                    if ($dc->isMultivalueProperty($col)) {
                        $save_data[$col] = explode(AbstractDatabase::MULTIVAL_SEP, $contact[$col]);
                    } else {
                        $save_data[$col] = $contact[$col];
                    }
                }
            }

            if (isset($save_data)) {
                $save_data['ID'] = $contact['id'];
                ++$resultCount;
                $result->add($save_data);
            }
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
     * This function returns two values in an associative array with entries:
     *   "filter": An array of DbAndCondition for use with the AbstractDatabase::get() function.
     *   "postSearchMode": true if all conditions must match ("AND"), false if a single match is sufficient ("OR")
     *   "postSearchFilter": An array of two-element arrays, each with: [ column name, lower-cased search value ]
     *
     * @param string|string[] $fields Field names to search in
     * @param string|string[] $value Search value, or array of values, one for each field in $fields
     * @param int $mode Matching mode, see Addressbook::search() for extended description.
     */
    private function buildDatabaseSearchFilter($fields, $value, int $mode): array
    {
        /** @var DbAndCondition[] */
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
                    $postSearchFilter[] = [$col, mb_strtolower($value)];
                }
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
        if ($this->total_cards < 0) {
            [$result] = $this->db->get(
                $this->currentFilterConditions(),
                '*',
                'contacts',
                [ 'count' => true ]
            );

            $this->total_cards = intval($result['*']);
        }

        return $this->total_cards;
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
     * @param string[] $contact_cuids The VCard UIDs of the contacts to remove from the group.
     * @param array    $group         Array with keys etag, uri and vcard containing the corresponding fields of the
     *                                group, where vcard is the serialized string form of the VCard.
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
            $davAbook->updateCard($group['uri'], $vcard, $group['etag']);
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

            $account = new Account($url, $username, $password, $url);
            $this->davAbook = new AddressbookCollection($url, $account);
        }

        return $this->davAbook;
    }

    /**
     * Provides an array of the contact database ids that belong to the given group.
     *
     * @param string $groupid The database ID of the group whose contacts shall be queried.
     *
     * @return string[] An array of the group's contacts' database IDs.
     */
    private function getContactIdsForGroup(string $groupid): array
    {
        $db = $this->db;
        $records = $db->get(['group_id' => $groupid], 'contact_id', 'group_user');
        return array_column($records, "contact_id");
    }

    /**
     * Adjusts the CATEGORIES property of a list of contacts using a given callback function and, if changed, stores the
     * changed VCard to the server.
     *
     * @param string[] $contact_ids A list of contact database IDs for that CATEGORIES should be adapted
     * @param callable $callback A callback function, that performs the adjustment of the CATEGORIES values. It is
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
        $db = $this->db;

        /** @var list<array{id: numeric-string, etag: string, uri: string, vcard: string}> $contacts */
        $contacts = $db->get(["id" => $contact_ids, "abook_id" => $this->id], "id,etag,uri,vcard", "contacts");

        foreach ($contacts as $contact) {
            $vcard = $this->parseVCard($contact['vcard']);
            $groups = [];
            if (isset($vcard->{"CATEGORIES"})) {
                $groups =  $vcard->CATEGORIES->getParts();
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
     * @return DbAndCondition[]
     */
    private function currentFilterConditions(): array
    {
        $conditions = $this->filter;
        $conditions[] = new DbAndCondition(new DbOrCondition("abook_id", $this->id));
        foreach (array_intersect($this->requiredProps, $this->table_cols) as $col) {
            $conditions[] = new DbAndCondition(new DbOrCondition("!{$col}", ""));
            $conditions[] = new DbAndCondition(new DbOrCondition("!{$col}", null));
        }

        // TODO Better if we could handle this without a separate SQL query here, but requires join or subquery
        if ($this->group_id) {
            $contactsInGroup = array_column(
                $this->db->get(['group_id' => $this->group_id], 'contact_id', 'group_user'),
                'contact_id'
            );
            $conditions[] = new DbAndCondition(new DbOrCondition("id", $contactsInGroup));
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
     * @param string[] $save_data The contact data to check
     * @param string[] $required A list of contact attributes that must not be empty
     * @param bool $allMustMatch Indicates if all post filter conditions must match, or if a single match is sufficient.
     * @param string[][] $postSearchFilter The post filter conditions as pairs of attribute name and match value
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

        $contactMatches = true;
        foreach ($postSearchFilter as $psfilter) {
            [ $col, $val ] = $psfilter;
            $psFilterMatched = false;

            if ($col == '*') { // any contact attribute must match $val
                foreach ($save_data as $k => $v) {
                    if ($this->compare_search_value($k, $v, $val, $mode)) {
                        $psFilterMatched = true;
                        break;
                    }
                }
            } else {
                $psFilterMatched = $this->compare_search_value($col, $save_data[$col], $val, $mode);
            }

            if (!$allMustMatch && $psFilterMatched) {
                break; // single post filter match is sufficient -> done
            } elseif ($allMustMatch && !$psFilterMatched) {
                $contactMatches = false; // single post filter match failure suffices -> abort
                break;
            }
        }

        return $contactMatches;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
