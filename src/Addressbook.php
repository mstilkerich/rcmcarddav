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

    /** @var ?string An additional filter to limit contact searches (content: SQL WHERE clause on contacts table) */
    private $filter;

    /** @var string[] $requiredProps A list of addressobject fields that must not be empty, otherwise the addressobject
     *                               will be hidden.
     */
    private $requiredProps;

    /** @var ?rcube_result_set $result */
    private $result = null;

    /** @var array $config configuration of the addressbook */
    private $config;

    /** @var int total number of contacts in address book. Negative if not computed yet. */
    private $total_cards = -1;

    /** @var array $table_cols
     * attributes that are redundantly stored in the contact table and need
     * not be parsed from the vcard
     */
    private $table_cols = ['id', 'name', 'email', 'firstname', 'surname'];

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
        $this->filter = $filter;
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
        $this->filter = null;
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
     * This function can either perform a simple search, where several fields are matched on whether any of them
     * contains a specific value, or an advanced search, where several fields are checked to match with individual
     * values. The distinction is coded into the $value parameter: if it contains a single value only, the list of
     * fields is matched on whether any of them matches, if $value is an array, each value is matched against the
     * corresponding index in $fields and all fields must match for an entry to be found.
     *
     * @param mixed   $fields   The field name of array of field names to search in
     * @param mixed   $value    Search value (or array of values if $fields is array and advanced search shall be done)
     * @param int     $mode     Matching mode:
     *                          0 - partial (*abc*),
     *                          1 - strict (=),
     *                          2 - prefix (abc*)
     * @param boolean $select   True if results are requested, False if count only
     * @param boolean $nocount  True to skip the count query (select only)
     * @param string|array $required List of fields that cannot be empty
     *
     * @return object rcube_result_set Contact records and 'count' value
     */
    public function search(
        $fields,
        $value,
        $mode = 0,
        $select = true,
        $nocount = false,
        $required = []
    ) {
        $dbh = $this->db->getDbHandle();

        if (!is_array($fields)) {
            $fields = [$fields];
        }
        if (!is_array($required)) {
            $required = empty($required) ? [] : [$required];
        }

        $this->logger->debug(
            "search("
            . "[" . implode(", ", $fields) . "], "
            . (is_array($value) ? "[" . implode(", ", $value) . "]" : $value) . ", "
            . "$mode, $select, $nocount, "
            . "[" . implode(", ", $required) . "]"
            . ")"
        );

        $mode = intval($mode);

        // (1) build the SQL WHERE clause for the fields to check against specific values
        [$whereclause, $post_search] = $this->buildDatabaseSearchFilter($fields, $value, $mode);

        // (2) Additionally, the search may request some fields not to be empty.
        // Compute the corresponding search clause and append to the existing one from (1)

        // this is an optional filter configured by the administrator that requires the given fields be not empty
        $required = array_unique(array_merge($required, $this->requiredProps));

        $and_where = [];
        foreach (array_intersect($required, $this->table_cols) as $col) {
            $and_where[] = $dbh->quote_identifier($col) . ' <> ' . $dbh->quote('');
        }

        if (!empty($and_where)) {
            $and_whereclause = join(' AND ', $and_where);

            if (empty($whereclause)) {
                $whereclause = $and_whereclause;
            } else {
                $whereclause = "($whereclause) AND $and_whereclause";
            }
        }

        // Post-searching in vCard data fields
        // we will search in all records and then build a where clause for their IDs
        if (!empty($post_search)) {
            $ids = [0];
            // build key name regexp
            $regexp = '/^(' . implode('|', array_keys($post_search)) . ')(?:.*)$/';
            // use initial WHERE clause, to limit records number if possible
            if (!empty($whereclause)) {
                $this->set_search_set($whereclause);
            }

            // count result pages
            $cnt   = $this->count();
            $pages = ceil($cnt / $this->page_size);
            $scnt  = count($post_search);

            // get (paged) result
            for ($i = 0; $i < $pages; $i++) {
                $result = $this->list_records(null, $i, true);

                while (
                    /** @var ?array */
                    $row = $result->next()
                ) {
                    $id = $row[$this->primary_key];
                    $found = [];
                    foreach (preg_grep($regexp, array_keys($row)) as $col) {
                        $pos     = strpos($col, ':');
                        $colname = $pos ? substr($col, 0, $pos) : $col;
                        $search  = $post_search[$colname];
                        foreach ((array)$row[$col] as $value) {
                            // composite field, e.g. address
                            foreach ((array)$value as $val) {
                                $val = mb_strtolower($val);
                                if ($mode & 1) {
                                    $got = ($val == $search);
                                } elseif ($mode & 2) {
                                    $got = ($search == substr($val, 0, strlen($search)));
                                } else {
                                    $got = (strpos($val, $search) !== false);
                                }

                                if ($got) {
                                    $found[$colname] = true;
                                    break 2;
                                }
                            }
                        }
                    }
                    // all fields match
                    if (count($found) >= $scnt) {
                        $ids[] = $id;
                    }
                }
            }

            // build WHERE clause
            $ids = $dbh->array2list($ids, 'integer');
            $whereclause = $this->primary_key . ' IN (' . $ids . ')';

            // when we know we have an empty result
            if ($ids == '0') {
                $this->set_search_set($whereclause);
                $result = new rcube_result_set(0, 0);
                $this->result = $result;
                return $result;
            }
        }

        $this->set_search_set($whereclause);
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
        $this->logger->debug("count()");

        try {
            if ($this->total_cards < 0) {
                $this->doCount();
            }

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
     * @param boolean $assoc True to return record as associative array, otherwise a result set is returned
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
     * @param boolean $check True to check for duplicates first
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

            // We preferably check the UID. But as some CardDAV services (i.e. Google) change the UID in the VCard to a
            // server-side one, we fall back to searching by URL if the UID search returned no results.
            [ $contact ] = $db->get(['cuid' => (string) $vcard->UID, "abook_id" => $this->id], 'id', 'contacts');
            if (!isset($contact)) {
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

            // get current DB data
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
            $contacts = $db->get(['id' => $ids, "abook_id" => $this->id], 'id,cuid,uri', 'contacts');

            // make sure we only have contacts in $ids that belong to this addressbook
            $ids = array_column($contacts, "id");
            $contact_cuids = array_column($contacts, "cuid");

            // remove contacts from VCard based groups - get groups that the contacts are members of
            $groupids = array_column($db->get(['contact_id' => $ids], 'group_id', 'group_user'), "group_id");

            if (!empty($groupids)) {
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

            // first remove / clear KIND=group vcard-based groups
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

            // now delete all contact cards
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

                $dbh = $db->getDbHandle();
                $this->set_search_set(
                    "EXISTS(SELECT * FROM " . $dbh->table_name("carddav_group_user")
                    . " WHERE group_id='$gid' AND contact_id=" . $dbh->table_name("carddav_contacts") . ".id)"
                );
            } else {
                $this->reset();
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
     * @return boolean True on success, false if no data was changed
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
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'uri,name,etag,vcard', 'groups');

            if (isset($group["uri"])) { // KIND=group VCard-based group
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

            // get current DB data
            $group = $db->lookup(["id" => $group_id, "abook_id" => $this->id], 'name,uri,etag,vcard', 'groups');

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                $contacts = $db->get(["id" => $ids, "abook_id" => $this->id], "id, cuid", "contacts");
                $db->endTransaction();

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
     * @since 0.5-beta
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_record_groups($id): array
    {
        try {
            $this->logger->debug("get_record_groups($id)");
            $dbh = $this->db->getDbHandle();
            $sql_result = $dbh->query('SELECT id,name FROM ' .
                $dbh->table_name('carddav_group_user') . ',' .
                $dbh->table_name('carddav_groups') .
                ' WHERE contact_id=? AND id=group_id AND abook_id=?', $id, $this->id);

            $res = [];
            while ($row = $dbh->fetch_assoc($sql_result)) {
                $res[$row['id']] = $row['name'];
            }

            return $res;
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
        return $this->config['refresh_time'];
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
        $ts_nextupd = $this->config["last_updated"] + $this->config["refresh_time"];
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
        $dbh = $this->db->getDbHandle();

        // true if we can use DB filtering or no filtering is requested
        $filter = $this->get_search_set();

        // determine whether we have to parse the vcard or if only db cols are requested
        $read_vcard = (!isset($cols)) || (count(array_intersect($cols, $this->table_cols)) < count($cols));

        // determine result subset needed
        $firstrow = ($subset >= 0) ?
            $result->first : ($result->first + $this->page_size + $subset);
        $numrows  = $subset ? abs($subset) : $this->page_size;

        $this->logger->debug("listRecordsReadDB " . (isset($cols) ? implode(",", $cols) : "ALL") . " $read_vcard");

        $dbattr = $read_vcard ? 'vcard' : 'firstname,surname,email';

        $limit_index = $firstrow;
        $limit_rows  = $numrows;

        $xfrom = '';
        $xwhere = '';
        if ($this->group_id) {
            $xfrom = ',' . $dbh->table_name('carddav_group_user');
            $xwhere = ' AND id=contact_id AND group_id=' . $dbh->quote($this->group_id) . ' ';
        }

        foreach (array_intersect($this->requiredProps, $this->table_cols) as $col) {
            $xwhere .= " AND $col <> " . $dbh->quote('') . " ";
        }

        $sort_column = $this->sort_col ? $this->sort_col : 'surname';
        $sort_order  = $this->sort_order ? $this->sort_order : 'ASC';

        $sql_result = $dbh->limitquery(
            "SELECT id,name,$dbattr FROM " .
            $dbh->table_name('carddav_contacts') . $xfrom .
            ' WHERE abook_id=? ' . $xwhere .
            ($filter ? " AND (" . $filter . ")" : "") .
            " ORDER BY (CASE WHEN showas='COMPANY' THEN organization ELSE " . $sort_column . " END) "
            . $sort_order,
            $limit_index,
            $limit_rows,
            $this->id
        );

        $addresses = [];
        while ($contact = $dbh->fetch_assoc($sql_result)) {
            if ($read_vcard) {
                try {
                    $vcf = $this->parseVCard($contact['vcard']);
                    $davAbook = $this->getCardDavObj();
                    $save_data = $this->dataConverter->toRoundcube($vcf, $davAbook);
                } catch (\Exception $e) {
                    $this->logger->warning("Couldn't parse vcard " . $contact['vcard']);
                    continue;
                }
            } else {
                $save_data = [];
                $cols = $cols ?? []; // note: $cols is always an array at this point, this is for the static analyzer
                foreach ($cols as $col) {
                    if (strcmp($col, 'email') == 0) {
                        $save_data[$col] = preg_split('/,\s*/', $contact[$col]);
                    } else {
                        $save_data[$col] = $contact[$col];
                    }
                }
            }
            $addresses[] = ['ID' => $contact['id'], 'name' => $contact['name'], 'save_data' => $save_data];
        }

        // create results for roundcube
        foreach ($addresses as $a) {
            $a['save_data']['ID'] = $a['ID'];
            $result->add($a['save_data']);
        }

        return count($addresses);
    }

    /**
     * This function builds an array of search criteria (SQL WHERE clauses) for matching the requested
     * contact search fields against fields of the carddav_contacts table according to the given search $mode.
     *
     * Only some fields are contained as individual columns in the carddav_contacts table, as indicated by
     * $this->table_cols. The remaining fields need to be searched for in the VCards, which are a single column in the
     * database. Therefore, the SQL filter can only match against the VCard in its entirety, but will not check if the
     * correct property of the VCard contained the value. Thus, if such search fields are queried, the SQL result needs
     * to be post-filtered with a check for these particular fields against the VCard properties.
     *
     * This function returns two values in an indexed array:
     *   index 0: A string containing the WHERE clause for the SQL part of the search
     *   index 1: An associative array (fieldname => search value), listing the search fields that need to be checked
     *            in the VCard properties
     *
     * @param mixed   $fields   The field name of array of field names to search in
     * @param mixed   $value    Search value (or array of values if $fields is array and advanced search shall be done)
     * @param int     $mode     Matching mode, see search()
     */
    private function buildDatabaseSearchFilter($fields, $value, $mode): array
    {
        $dbh = $this->db->getDbHandle();
        $WS = ' ';
        $AS = self::SEPARATOR;

        $where = [];
        $post_search = [];

        foreach ($fields as $idx => $col) {
            // direct ID search
            if ($col == 'ID' || $col == $this->primary_key) {
                $ids     = !is_array($value) ? explode(self::SEPARATOR, $value) : $value;
                $ids     = $dbh->array2list($ids, 'integer');
                $where[] = $this->primary_key . ' IN (' . $ids . ')';
                continue;
            }

            $val = is_array($value) ? $value[$idx] : $value;

            if (in_array($col, $this->table_cols)) { // table column
                if ($mode & 1) {
                    // strict
                    $where[] =
                        // exact match 'name@domain.com'
                        '(' . $dbh->ilike($col, $val)
                        // line beginning match 'name@domain.com,%'
                        . ' OR ' . $dbh->ilike($col, $val . $AS . '%')
                        // middle match '%, name@domain.com,%'
                        . ' OR ' . $dbh->ilike($col, '%' . $AS . $WS . $val . $AS . '%')
                        // line end match '%, name@domain.com'
                        . ' OR ' . $dbh->ilike($col, '%' . $AS . $WS . $val) . ')';
                } elseif ($mode & 2) {
                    // prefix
                    $where[] = '(' . $dbh->ilike($col, $val . '%')
                        . ' OR ' . $dbh->ilike($col, $AS . $WS . $val . '%') . ')';
                } else {
                    // partial
                    $where[] = $dbh->ilike($col, '%' . $val . '%');
                }
            } else { // vCard field
                $words = [];
                /** @var string $search_val second parameter asks normalize_string to return a string */
                $search_val = rcube_utils::normalize_string($val, false);
                foreach (explode(" ", $search_val) as $word) {
                    if ($mode & 1) {
                        // strict
                        $words[] = '(' . $dbh->ilike('vcard', $word . $WS . '%')
                            . ' OR ' . $dbh->ilike('vcard', '%' . $AS . $WS . $word . $WS . '%')
                            . ' OR ' . $dbh->ilike('vcard', '%' . $AS . $WS . $word) . ')';
                    } elseif ($mode & 2) {
                        // prefix
                        $words[] = '(' . $dbh->ilike('vcard', $word . '%')
                            . ' OR ' . $dbh->ilike('vcard', $AS . $WS . $word . '%') . ')';
                    } else {
                        // partial
                        $words[] = $dbh->ilike('vcard', '%' . $word . '%');
                    }
                }
                $where[] = '(' . join(' AND ', $words) . ')';
                if (is_array($value)) {
                    $post_search[$col] = mb_strtolower($val);
                }
            }
        }


        $whereclause = "";
        if (!empty($where)) {
            // use AND operator for advanced searches
            $whereclause = join(is_array($value) ? ' AND ' : ' OR ', $where);
        }
        return [$whereclause, $post_search];
    }

    /**
     *  Determines and returns the number of cards matching the current search criteria.
     */
    private function doCount(): int
    {
        $dbh = $this->db->getDbHandle();

        if ($this->total_cards < 0) {
            $sql_result = $dbh->query(
                'SELECT COUNT(id) as total_cards FROM ' .
                $dbh->table_name('carddav_contacts') .
                ' WHERE abook_id=?' .
                ($this->filter ? " AND (" . $this->filter . ")" : ""),
                $this->id
            );

            $resultrow = $dbh->fetch_assoc($sql_result);
            if ($resultrow !== false) {
                $this->total_cards = $resultrow['total_cards'];
            } else {
                $this->total_cards = -1;
            }
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

        $contacts = $db->get(["id" => $contact_ids, "abook_id" => $this->id], "id,uri,etag,vcard", "contacts");

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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
