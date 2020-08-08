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

use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use rcube_addressbook;
use rcube_result_set;
use rcube_utils;
use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use MStilkerich\CardDavClient\Services\{Discovery, Sync};
use MStilkerich\CardDavAddressbook4Roundcube\SyncHandlerRoundcube;
use rcmail;
use rcube_db;
use carddav;

class Addressbook extends rcube_addressbook
{
    /**
     * @var int MAX_PHOTO_SIZE Maximum size of a photo dimension in pixels.
     *   Used when a photo is cropped for the X-ABCROP-RECTANGLE extension.
     */
    private const MAX_PHOTO_SIZE = 256;

    /** @var string SEPARATOR Separator character used by roundcube to encode multiple values in a single string. */
    private const SEPARATOR = ',';

    /** @var carddav $frontend the frontend object */
    private $frontend;

    /** @var ?AddressbookCollection $davAbook the DAV AddressbookCollection Object */
    private $davAbook = null;

    /** @var string $primary_key database primary key, used by RC to search by ID */
    public $primary_key = 'id';

    /** @var array $coltypes */
    public $coltypes;

    /** @var array $fallbacktypes */
    private $fallbacktypes = array( 'email' => array('internet') );

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

    /** @var array $xlabels custom labels defined in the addressbook */
    private $xlabels = [];

    /** @var int total number of contacts in address book. Negative if not computed yet. */
    private $total_cards = -1;

    /** @var array $table_cols
     * attributes that are redundantly stored in the contact table and need
     * not be parsed from the vcard
     */
    private $table_cols = ['id', 'name', 'email', 'firstname', 'surname'];

    /** @var array $vcf2rc maps VCard property names to roundcube keys */
    private $vcf2rc = array(
        'simple' => array(
            'BDAY' => 'birthday',
            'FN' => 'name',
            'NICKNAME' => 'nickname',
            'NOTE' => 'notes',
            'PHOTO' => 'photo',
            'TITLE' => 'jobtitle',
            'UID' => 'cuid',
            'X-ABShowAs' => 'showas',
            'X-ANNIVERSARY' => 'anniversary',
            'X-ASSISTANT' => 'assistant',
            'X-GENDER' => 'gender',
            'X-MANAGER' => 'manager',
            'X-SPOUSE' => 'spouse',
            // the two kind attributes should not occur both in the same vcard
            //'KIND' => 'kind',   // VCard v4
            'X-ADDRESSBOOKSERVER-KIND' => 'kind', // Apple Addressbook extension
        ),
        'multi' => array(
            'EMAIL' => 'email',
            'TEL' => 'phone',
            'URL' => 'website',
        ),
    );

    /** @var array $datefields list of potential date fields for formatting */
    private $datefields = array('birthday', 'anniversary');

    public function __construct(string $dbid, carddav $frontend, bool $readonly, array $requiredProps)
    {
        $this->frontend = $frontend;
        $this->groups   = true;
        $this->readonly = $readonly;
        $this->requiredProps = $requiredProps;
        $this->id       = $dbid;
        // XXX roundcube has further default types: maidenname, im
        $this->coltypes = [
            'name' => [],
            'firstname' => [],
            'surname' => [],
            'email' => [
                'subtypes' => ['home','work','other','internet'],
            ],
            'middlename' => [],
            'prefix' => [],
            'suffix' => [],
            'nickname' => [],
            'jobtitle' => [],
            'organization' => [],
            'department' => [],
            'gender' => [],
            'phone' => [
                'subtypes' => [
                    'home','home2','work','work2','mobile','main','homefax','workfax','car','pager','video',
                    'assistant','other'
                ],
            ],
            'address' => [
                'subtypes' => ['home','work','other'],
            ],
            'birthday' => [],
            'anniversary' => [],
            'website' => [
                'subtypes' => ['homepage','work','blog','profile','other'],
            ],
            'notes' => [],
            'photo' => [],
            'assistant' => [],
            'manager' => [],
            'spouse' => [],
        ];

        try {
            $this->config   = Database::getAbookCfg($dbid);
            $this->addextrasubtypes();
            $this->ready = true;

            // refresh the address book if the update interval expired
            // this requires a completely initialized Addressbook object, so it
            // needs to be at the end of this constructor
            if ($this->config["needs_update"]) {
                $this->resync(true);
            }
        } catch (\Exception $e) {
            carddav::$logger->error("Failed to construct addressbook: " . $e->getMessage());
            $this->ready = false;
            $this->config = [];
        }
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
            carddav::$logger->error(__METHOD__ . " exception: " . $e->getMessage());
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
        $dbh = Database::getDbHandle();

        if (!is_array($fields)) {
            $fields = array($fields);
        }
        if (!is_array($required)) {
            $required = empty($required) ? [] : [$required];
        }

        carddav::$logger->debug(
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
            $ids = array(0);
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
        carddav::$logger->debug("count()");

        try {
            if ($this->total_cards < 0) {
                $this->doCount();
            }

            return new rcube_result_set($this->total_cards, ($this->list_page - 1) * $this->page_size);
        } catch (\Exception $e) {
            carddav::$logger->error(__METHOD__ . " exception: " . $e->getMessage());
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
            carddav::$logger->debug("get_record($id, $assoc)");

            $contact = Database::get($id, 'vcard', 'contacts', true, 'id', ["abook_id" => $this->id]);
            $vcard = $this->parseVCard($contact['vcard']);
            [ 'save_data' => $save_data ] = $this->convVCard2Rcube($vcard);
            $save_data['ID'] = $id;

            $this->result = new rcube_result_set(1);
            $this->result->add($save_data);

            return $assoc ? $save_data : $this->result;
        } catch (\Exception $e) {
            carddav::$logger->error("Could not get contact $id: " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return $assoc ? [] : new rcube_result_set();
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
            carddav::$logger->debug("insert(" . $save_data["name"] . ", $check)");
            $this->preprocessRcubeData($save_data);
            $davAbook = $this->getCardDavObj();

            $vcard = $this->convRcube2VCard($save_data);
            $davAbook->createCard($vcard);
            $this->resync();
            $contact = Database::get((string) $vcard->UID, 'id', 'contacts', true, 'cuid', ["abook_id" => $this->id]);

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
     * @param array $save_data Associative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     *
     * @return mixed On success if ID has been changed returns ID, otherwise True, False on error
     */
    public function update($id, $save_data)
    {
        try {
            // complete save_data
            $this->preprocessRcubeData($save_data);
            $davAbook = $this->getCardDavObj();

            // get current DB data
            $contact = Database::get($id, "uri,etag,vcard,showas", "contacts", true, "id", ["abook_id" => $this->id]);
            $save_data['showas'] = $contact['showas'];

            // create vcard from current DB data to be updated with the new data
            $vcard = $this->parseVCard($contact['vcard']);
            $vcard = $this->convRcube2VCard($save_data, $vcard);
            $davAbook->updateCard($contact['uri'], $vcard, $contact['etag']);
            $this->resync();

            return true;
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            carddav::$logger->error("Failed to update contact $id: " . $e->getMessage());
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
        $abook_id = $this->id;
        $deleted = 0;
        carddav::$logger->debug("delete([" . implode(",", $ids) . "])");
        $inTransaction = false;

        try {
            $davAbook = $this->getCardDavObj();

            Database::startTransaction(false);
            $inTransaction = true;
            $contacts = Database::get($ids, 'id,cuid,uri', 'contacts', false, "id", ["abook_id" => $this->id]);

            // make sure we only have contacts in $ids that belong to this addressbook
            $ids = array_column($contacts, "id");
            $contact_cuids = array_column($contacts, "cuid");

            // remove contacts from VCard based groups - get groups that the contacts are members of
            $groupids = array_column(Database::get($ids, 'group_id', 'group_user', false, 'contact_id'), "group_id");

            if (!empty($groupids)) {
                $groups = Database::get($groupids, "id,etag,uri,vcard", "groups", false);

                foreach ($groups as $group) {
                    if (isset($group["vcard"])) {
                        $this->removeContactsFromVCardBasedGroup($contact_cuids, $group);
                    }
                }
            }
            Database::endTransaction();
            $inTransaction = false;

            // delete the contact cards from the server
            foreach ($contacts as $contact) {
                $davAbook->deleteCard($contact['uri']);
                ++$deleted;
            }

            // and sync back the changes to the cache
            $this->resync();
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            carddav::$logger->error("Failed to delete contacts [" . implode(",", $ids) . "]:" . $e->getMessage());

            if ($inTransaction) {
                Database::rollbackTransaction();
            }
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
            carddav::$logger->debug("delete_all($with_groups)");
            $davAbook = $this->getCardDavObj();
            $abook_id = $this->id;

            // first remove / clear KIND=group vcard-based groups
            $vcard_groups = Database::get($abook_id, "id,uri,vcard,etag", "groups", false, "abook_id");
            foreach ($vcard_groups as $vcard_group) {
                if (isset($vcard_group["vcard"])) { // skip CATEGORIES-type groups
                    if ($with_groups) {
                        $davAbook->deleteCard($vcard_group["uri"]);
                    } else {
                        // create vcard from current DB data to be updated with the new data
                        $vcard = $this->parseVCard($vcard_group['vcard']);
                        $vcard->remove('X-ADDRESSBOOKSERVER-MEMBER');
                        $davAbook->updateCard($vcard_group['uri'], $vcard, $vcard_group['etag']);
                    }
                }
            }

            // now delete all contact cards
            $contacts = Database::get($abook_id, "uri", "contacts", false, "abook_id");
            foreach ($contacts as $contact) {
                $davAbook->deleteCard($contact["uri"]);
            }

            // and sync the changes back
            $this->resync();

            // CATEGORIES-type groups are still inside the DB - remove if requested
            Database::delete($abook_id, "groups", "abook_id");
        } catch (\Exception $e) {
            carddav::$logger->error("delete_all: " . $e->getMessage());
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
            carddav::$logger->debug("set_group($gid)");

            if ($gid) {
                // check for valid ID with the database - this throws an exception if the group cannot be found
                Database::get($gid, "id", "groups", true, "id", ["abook_id" => $this->id]);

                $dbh = Database::getDbHandle();
                $this->set_search_set(
                    "EXISTS(SELECT * FROM " . $dbh->table_name("carddav_group_user")
                    . " WHERE group_id='$gid' AND contact_id=" . $dbh->table_name("carddav_contacts") . ".id)"
                );
            } else {
                $this->reset();
            }

            $this->group_id = $gid;
        } catch (\Exception $e) {
            carddav::$logger->error("set_group($gid): " . $e->getMessage());
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
            carddav::$logger->debug("list_groups(" . ($search ?? 'null') . ", $mode)");

            $xconditions = [];
            if ($search !== null) {
                if ($mode & rcube_addressbook::SEARCH_STRICT) {
                    $xconditions['%name'] = $search;
                } elseif ($mode & rcube_addressbook::SEARCH_PREFIX) {
                    $xconditions['%name'] = "$search%";
                } else {
                    $xconditions['%name'] = "%$search%";
                }
            }

            $groups = Database::get($this->id, "id,name", "groups", false, "abook_id", $xconditions);

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
            carddav::$logger->error(__METHOD__ . "(" . ($search ?? 'null') . ", $mode) exception: " . $e->getMessage());
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
            carddav::$logger->debug("get_group($group_id)");

            // As of 1.4.6, roundcube is interested in name and email properties of a group,
            // i. e. if the group as a distribution list had an email address of its own. Otherwise, it will fall back
            // to getting the individual members' addresses
            $result = Database::get($group_id, 'id,name', 'groups', true, "id", ["abook_id" => $this->id]);
        } catch (\Exception $e) {
            carddav::$logger->error("get_group($group_id): " . $e->getMessage());
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
     * @return mixed False on error, array with record props in success
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function create_group($name)
    {
        try {
            carddav::$logger->debug("create_group($name)");
            $save_data = [ 'name' => $name, 'kind' => 'group' ];

            if ($this->config['use_categories']) {
                $groupid = Database::storeGroup($this->id, $save_data);
                return [ 'id' => $groupid, 'name' => $name ];
            } else {
                $davAbook = $this->getCardDavObj();
                $vcard = $this->convRcube2VCard($save_data);
                $davAbook->createCard($vcard);

                $this->resync();

                $group = Database::get((string) $vcard->UID, 'id,name', 'groups', true, 'cuid');

                if (isset($group["id"])) {
                    return $group;
                }
            }
        } catch (\Exception $e) {
            carddav::$logger->error("create_group($name): " . $e->getMessage());
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
        $inTransaction = false;

        try {
            carddav::$logger->debug("delete_group($group_id)");
            $davAbook = $this->getCardDavObj();

            Database::startTransaction(false);
            $inTransaction = true;

            $group = Database::get($group_id, 'name,uri', 'groups', true, "id", ["abook_id" => $this->id]);

            if (isset($group["uri"])) { // KIND=group VCard-based group
                $davAbook->deleteCard($group["uri"]);
                $this->resync();
            } else { // CATEGORIES-type group
                $groupname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);

                if (empty($contact_ids)) {
                    // will not be deleted by sync, delete right now
                    Database::delete($group_id, "groups", "id", ["abook_id" => $this->id]);
                } else {
                    $this->adjustContactCategories(
                        $contact_ids,
                        function (array &$groups, string $contact_id) use ($groupname): bool {
                            return self::stringsAddRemove($groups, [], [$groupname]);
                        }
                    );
                    $this->resync();
                }
            }

            Database::endTransaction();
            $inTransaction = false;

            return true;
        } catch (\Exception $e) {
            carddav::$logger->error("delete_group($group_id): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());

            if ($inTransaction) {
                Database::rollbackTransaction();
            }
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
     * @return boolean|string New name on success, false if no data was changed
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function rename_group($group_id, $newname, &$newid)
    {
        $inTransaction = false;

        try {
            carddav::$logger->debug("rename_group($group_id, $newname)");
            $davAbook = $this->getCardDavObj();

            Database::startTransaction(false);
            $inTransaction = true;

            $group = Database::get($group_id, 'uri,name,etag,vcard', 'groups', true, "id", ["abook_id" => $this->id]);

            if (isset($group["uri"])) { // KIND=group VCard-based group
                $vcard = $this->parseVCard($group["vcard"]);
                $vcard->FN = $newname;
                $vcard->N  = [$newname,"","","",""];

                $davAbook->updateCard($group["uri"], $vcard, $group["etag"]);
                $this->resync();
            } else { // CATEGORIES-type group
                $oldname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);

                if (empty($contact_ids)) {
                    // rename empty group in DB
                    Database::update($group_id, ["name"], [$newname], "groups", "id", ["abook_id" => $this->id]);
                } else {
                    $this->adjustContactCategories(
                        $contact_ids,
                        function (array &$groups, string $contact_id) use ($oldname, $newname): bool {
                            return self::stringsAddRemove($groups, [ $newname ], [ $oldname ]);
                        }
                    );

                    $this->resync(); // resync will insert the contact assignments as a new group
                }
            }

            Database::endTransaction();
            $inTransaction = false;

            return $newname;
        } catch (\Exception $e) {
            carddav::$logger->error("rename_group($group_id, $newname): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());

            if ($inTransaction) {
                Database::rollbackTransaction();
            }
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
        $inTransaction = false;

        try {
            $davAbook = $this->getCardDavObj();

            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }

            Database::startTransaction(false);
            $inTransaction = true;

            // get current DB data
            $group = Database::get($group_id, 'name,uri,etag,vcard', 'groups', true, "id", ["abook_id" => $this->id]);

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                // create vcard from current DB data to be updated with the new data
                $vcard = $this->parseVCard($group['vcard']);

                $contacts = Database::get($ids, "id, cuid", "contacts", false, "id", ["abook_id" => $this->id]);
                foreach ($contacts as $contact) {
                    try {
                        $vcard->add('X-ADDRESSBOOKSERVER-MEMBER', "urn:uuid:" . $contact['cuid']);
                        ++$added;
                    } catch (\Exception $e) {
                        carddav::$logger->warning("add_to_group: Contact with ID {$contact['cuid']} not found in DB");
                    }
                }

                $davAbook->updateCard($group['uri'], $vcard, $group['etag']);

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids, // unfiltered ids allowed in adjustContactCategories()
                    function (array &$groups, string $contact_id) use ($groupname, &$added): bool {
                        if (self::stringsAddRemove($groups, [ $groupname ])) {
                            carddav::$logger->debug("Adding contact $contact_id to category $groupname");
                            ++$added;
                            return true;
                        } else {
                            carddav::$logger->debug("Contact $contact_id already belongs to category $groupname");
                        }
                        return false;
                    }
                );
            }

            Database::endTransaction();
            $inTransaction = false;

            $this->resync();
        } catch (\Exception $e) {
            carddav::$logger->error("add_to_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());

            if ($inTransaction) {
                Database::rollbackTransaction();
            }
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
        $abook_id = $this->id;
        $deleted = 0;
        $inTransaction = false;

        try {
            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }
            carddav::$logger->debug("remove_from_group($group_id, [" . implode(",", $ids) . "])");

            Database::startTransaction(false);
            $inTransaction = true;

            // get current DB data
            $group = Database::get($group_id, 'name,uri,etag,vcard', 'groups', true, "id", ["abook_id" => $this->id]);

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                $contacts = Database::get($ids, "id, cuid", "contacts", false, "id", ["abook_id" => $this->id]);
                $deleted = $this->removeContactsFromVCardBasedGroup(array_column($contacts, "cuid"), $group);

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids, // unfiltered ids allowed in adjustContactCategories()
                    function (array &$groups, string $contact_id) use ($groupname, &$deleted): bool {
                        if (self::stringsAddRemove($groups, [], [$groupname])) {
                            carddav::$logger->debug("Removing contact $contact_id from category $groupname");
                            ++$deleted;
                            return true;
                        } else {
                            carddav::$logger->debug("Contact $contact_id not a member category $groupname - skipped");
                        }
                        return false;
                    }
                );
            }

            Database::endTransaction();
            $inTransaction = false;

            $this->resync();
        } catch (\Exception $e) {
            carddav::$logger->error("remove_from_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());

            if ($inTransaction) {
                Database::rollbackTransaction();
            }
        }

        return $deleted;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param string $id Record identifier
     *
     * @return array List of assigned groups as ID=>Name pairs
     * @since 0.5-beta
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- method name defined by rcube_addressbook class
    public function get_record_groups($id): array
    {
        try {
            carddav::$logger->debug("get_record_groups($id)");
            $dbh = Database::getDbHandle();
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
            carddav::$logger->error("get_record_groups($id): " . $e->getMessage());
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
     * Creates the roundcube representation of a contact from a VCard.
     *
     * If the card contains a URI referencing an external photo, this
     * function will download the photo and inline it into the VCard.
     * The returned array contains a boolean that indicates that the
     * VCard was modified and should be stored to avoid repeated
     * redownloads of the photo in the future. The returned VCard
     * object contains the modified representation and can be used
     * for storage.
     *
     * @param  VCard $vcard Sabre VCard object
     *
     * @return array associative array with keys:
     *           - save_data:    Roundcube representation of the VCard
     *           - vcf:          VCard object created from the given VCard
     *           - needs_update: boolean that indicates whether the card was modified
     */
    public function convVCard2Rcube(VCard $vcard): array
    {
        $needs_update = false;
        $save_data = array(
            // DEFAULTS
            'kind'   => 'individual',
        );

        foreach ($this->vcf2rc['simple'] as $vkey => $rckey) {
            $property = $vcard->{$vkey};
            if ($property !== null) {
                $p = $property->getParts();
                $save_data[$rckey] = $p[0];
            }
        }

        // inline photo if external reference
        // note: isset($vcard->PHOTO) is true if $save_data['photo'] exists, the check
        // is for the static analyzer
        if (key_exists('photo', $save_data) && isset($vcard->PHOTO)) {
            $kind = $vcard->PHOTO['VALUE'];
            if (($kind instanceof VObject\Parameter) && strcasecmp('uri', (string) $kind) == 0) {
                if ($this->downloadPhoto($save_data)) {
                    $props = [];
                    foreach ($vcard->PHOTO->parameters() as $property => $value) {
                        if (strcasecmp($property, 'VALUE') != 0) {
                            $props[$property] = $value;
                        }
                    }
                    $props['ENCODING'] = 'b';
                    unset($vcard->PHOTO);
                    $vcard->add('PHOTO', $save_data['photo'], $props);
                    $needs_update = true;
                }
            }
            self::xabcropphoto($vcard, $save_data);
        }

        $property = $vcard->N;
        if (isset($property)) {
            $N = $property->getParts();
            switch (count($N)) {
                case 5:
                    $save_data['suffix']     = $N[4]; // fall through
                case 4:
                    $save_data['prefix']     = $N[3]; // fall through
                case 3:
                    $save_data['middlename'] = $N[2]; // fall through
                case 2:
                    $save_data['firstname']  = $N[1]; // fall through
                case 1:
                    $save_data['surname']    = $N[0];
            }
        }

        $property = $vcard->ORG;
        if ($property) {
            $ORG = $property->getParts();
            $save_data['organization'] = $ORG[0];
            for ($i = 1; $i <= count($ORG); $i++) {
                $save_data['department'][] = $ORG[$i];
            }
        }

        foreach ($this->vcf2rc['multi'] as $key => $value) {
            $property = $vcard->{$key};
            if ($property !== null) {
                foreach ($property as $property_instance) {
                    $p = $property_instance->getParts();
                    $label = $this->getAttrLabel($vcard, $property_instance, $value);
                    $save_data[$value . ':' . $label][] = $p[0];
                }
            }
        }

        $property = ($vcard->ADR) ?: [];
        foreach ($property as $property_instance) {
            $p = $property_instance->getParts();
            $label = $this->getAttrLabel($vcard, $property_instance, 'address');
            $adr = array(
                'pobox'    => $p[0], // post office box
                'extended' => $p[1], // extended address
                'street'   => $p[2], // street address
                'locality' => $p[3], // locality (e.g., city)
                'region'   => $p[4], // region (e.g., state or province)
                'zipcode'  => $p[5], // postal code
                'country'  => $p[6], // country name
            );
            $save_data['address:' . $label][] = $adr;
        }

        // set displayname according to settings
        self::setDisplayname($save_data);

        return array(
            'save_data'    => $save_data,
            'vcf'          => $vcard,
            'needs_update' => $needs_update,
        );
    }


    /**
     * Synchronizes the local card store with the CardDAV server.
     *
     * @param bool $showUIMsg Whether or not to issue a sync completion notification to the Roundcube UI.
     */
    public function resync(bool $showUIMsg = false): void
    {
        $inTransaction = false;

        try {
            $start_refresh = time();
            $davAbook = $this->getCardDavObj();
            $synchandler = new SyncHandlerRoundcube($this);
            $syncmgr = new Sync();

            Database::startTransaction(false);
            $inTransaction = true;

            $sync_token = $syncmgr->synchronize($davAbook, $synchandler, [ ], $this->config['sync_token'] ?? "");
            // set last_updated timestamp and sync token
            Database::update($this->id, ["last_updated", "sync_token"], [Database::now(), $sync_token], "addressbooks");

            Database::endTransaction();
            $inTransaction = false;

            $this->config['sync_token'] = $sync_token;
            $this->config['needs_update'] = 0;

            $duration = time() - $start_refresh;
            carddav::$logger->debug("server refresh took $duration seconds");

            if ($showUIMsg) {
                $rcmail = rcmail::get_instance();
                $rcmail->output->show_message(
                    $this->frontend->gettext([
                        'name' => 'cd_msg_synchronized',
                        'vars' => [
                            'name' => $this->get_name(),
                            'duration' => $duration,
                        ]
                    ])
                );

                if ($synchandler->hadErrors) {
                    $this->set_error(rcube_addressbook::ERROR_SAVING, "Non-fatal errors occurred during sync");
                }
            }
        } catch (\Exception $e) {
            carddav::$logger->error("Errors occurred during the refresh of addressbook " . $this->id . ": $e");
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());

            if ($inTransaction) {
                Database::rollbackTransaction();
            }
        }
    }


    /**
     * Does some common preprocessing with save data created by roundcube.
     */
    public function preprocessRcubeData(array &$save_data): void
    {
        // heuristic to determine X-ABShowAs setting
        // organization set but neither first nor surname => showas company
        if (
            !$save_data['surname'] && !$save_data['firstname']
            && $save_data['organization'] && !key_exists('showas', $save_data)
        ) {
            $save_data['showas'] = 'COMPANY';
        }
        if (!key_exists('showas', $save_data)) {
            $save_data['showas'] = 'INDIVIDUAL';
        }
        // organization not set but showas==company => show as regular
        if (!$save_data['organization'] && $save_data['showas'] === 'COMPANY') {
            $save_data['showas'] = 'INDIVIDUAL';
        }

        // generate display name according to display order setting
        self::setDisplayname($save_data);
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

    /**
     * Stores a custom label in the database (X-ABLabel extension).
     *
     * @param string Name of the type/category (phone,address,email)
     * @param string Name of the custom label to store for the type
     */
    private function storeextrasubtype(string $typename, string $subtype): void
    {
        Database::insert("xsubtypes", ["typename", "subtype", "abook_id"], [$typename, $subtype, $this->id]);
    }

    /**
     * Adds known custom labels to the roundcube subtype list (X-ABLabel extension).
     *
     * Reads the previously seen custom labels from the database and adds them to the
     * roundcube subtype list in #coltypes and additionally stores them in the #xlabels
     * list.
     */
    private function addextrasubtypes(): void
    {
        $this->xlabels = [];

        foreach ($this->coltypes as $k => $v) {
            if (key_exists('subtypes', $v)) {
                $this->xlabels[$k] = [];
            }
        }

        // read extra subtypes
        $xtypes = Database::get($this->id, 'typename,subtype', 'xsubtypes', false, 'abook_id');

        foreach ($xtypes as $row) {
            $this->coltypes[$row['typename']]['subtypes'][] = $row['subtype'];
            $this->xlabels[$row['typename']][] = $row['subtype'];
        }
    }


    /**
     * Determines the name to be displayed for a contact. The routine
     * distinguishes contact cards for individuals from organizations.
     */
    private static function setDisplayname(array &$save_data): void
    {
        if (strcasecmp($save_data['showas'], 'COMPANY') == 0 && strlen($save_data['organization']) > 0) {
            $save_data['name']     = $save_data['organization'];
        }

        // we need a displayname; if we do not have one, try to make one up
        if (strlen($save_data['name']) == 0) {
            $dname = [];
            if (strlen($save_data['firstname']) > 0) {
                $dname[] = $save_data['firstname'];
            }
            if (strlen($save_data['surname']) > 0) {
                $dname[] = $save_data['surname'];
            }

            if (count($dname) > 0) {
                $save_data['name'] = implode(' ', $dname);
            } else { // no name? try email and phone
                $ep_keys = array_keys($save_data);
                $ep_keys = preg_grep(";^(email|phone):;", $ep_keys);
                sort($ep_keys, SORT_STRING);
                foreach ($ep_keys as $ep_key) {
                    $ep_vals = $save_data[$ep_key];
                    if (!is_array($ep_vals)) {
                        $ep_vals = array($ep_vals);
                    }

                    foreach ($ep_vals as $ep_val) {
                        if (strlen($ep_val) > 0) {
                            $save_data['name'] = $ep_val;
                            break 2;
                        }
                    }
                }
            }

            // still no name? set to unknown and hope the user will fix it
            if (strlen($save_data['name']) == 0) {
                $save_data['name'] = 'Unset Displayname';
            }
        }
    }


    private function listRecordsReadDB(?array $cols, int $subset, rcube_result_set $result): int
    {
        $dbh = Database::getDbHandle();

        // true if we can use DB filtering or no filtering is requested
        $filter = $this->get_search_set();

        // determine whether we have to parse the vcard or if only db cols are requested
        $read_vcard = (!isset($cols)) || (count(array_intersect($cols, $this->table_cols)) < count($cols));

        // determine result subset needed
        $firstrow = ($subset >= 0) ?
            $result->first : ($result->first + $this->page_size + $subset);
        $numrows  = $subset ? abs($subset) : $this->page_size;

        carddav::$logger->debug("listRecordsReadDB " . (isset($cols) ? implode(",", $cols) : "ALL") . " $read_vcard");

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

        // Workaround for Roundcube versions < 0.7.2
        $sort_column = $this->sort_col ? $this->sort_col : 'surname';
        $sort_order  = $this->sort_order ? $this->sort_order : 'ASC';

        $sql_result = $dbh->limitquery(
            "SELECT id,name,$dbattr FROM " .
            $dbh->table_name('carddav_contacts') . $xfrom .
            ' WHERE abook_id=? ' . $xwhere .
            ($this->filter ? " AND (" . $this->filter . ")" : "") .
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
                    $save_data = $this->convVCard2Rcube($vcf);
                } catch (\Exception $e) {
                    $save_data = false;
                }

                if (!$save_data) {
                    carddav::$logger->warning("Couldn't parse vcard " . $contact['vcard']);
                    continue;
                }

                // needed by the calendar plugin
                if (is_array($cols) && in_array('vcard', $cols)) {
                    $save_data['save_data']['vcard'] = $contact['vcard'];
                }

                $save_data = $save_data['save_data'];
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
            $addresses[] = array('ID' => $contact['id'], 'name' => $contact['name'], 'save_data' => $save_data);
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
        $dbh = Database::getDbHandle();
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
        $dbh = Database::getDbHandle();

        if ($this->total_cards < 0) {
            $sql_result = $dbh->query(
                'SELECT COUNT(id) as total_cards FROM ' .
                $dbh->table_name('carddav_contacts') .
                ' WHERE abook_id=?' .
                ($this->filter ? " AND (" . $this->filter . ")" : ""),
                $this->id
            );

            $resultrow = $dbh->fetch_assoc($sql_result);
            $this->total_cards = $resultrow['total_cards'];
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

    private function guid(): string
    {
        return sprintf(
            '%04X%04X-%04X-%04X-%04X-%04X%04X%04X',
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(16384, 20479),
            mt_rand(32768, 49151),
            mt_rand(0, 65535),
            mt_rand(0, 65535),
            mt_rand(0, 65535)
        );
    }

    /**
     * Creates a new or updates an existing vcard from save data.
     */
    private function convRcube2VCard(array $save_data, VCard $vcard = null): VCard
    {
        unset($save_data['vcard']);
        if (isset($vcard)) {
            // update revision
            $vcard->REV = gmdate("Y-m-d\TH:i:s\Z");
        } else {
            // create fresh minimal vcard
            $vcard = new VObject\Component\VCard([
                'REV' => gmdate("Y-m-d\TH:i:s\Z"),
                'VERSION' => '3.0'
            ]);
        }

        // N is mandatory
        if (key_exists('kind', $save_data) && $save_data['kind'] === 'group') {
            $vcard->N = [$save_data['name'],"","","",""];
        } else {
            $vcard->N = [
                $save_data['surname'],
                $save_data['firstname'],
                $save_data['middlename'],
                $save_data['prefix'],
                $save_data['suffix'],
            ];
        }

        $new_org_value = [];
        if (
            key_exists("organization", $save_data)
            && strlen($save_data['organization']) > 0
        ) {
            $new_org_value[] = $save_data['organization'];
        }

        if (key_exists("department", $save_data)) {
            if (is_array($save_data['department'])) {
                foreach ($save_data['department'] as $key => $value) {
                    $new_org_value[] = $value;
                }
            } elseif (strlen($save_data['department']) > 0) {
                $new_org_value[] = $save_data['department'];
            }
        }

        if (count($new_org_value) > 0) {
            $vcard->ORG = $new_org_value;
        } else {
            unset($vcard->ORG);
        }

        // normalize date fields to RFC2425 YYYY-MM-DD date values
        foreach ($this->datefields as $key) {
            if (key_exists($key, $save_data)) {
                $data = (is_array($save_data[$key])) ?  $save_data[$key][0] : $save_data[$key];
                if (strlen($data) > 0) {
                    $val = rcube_utils::strtotime($data);
                    $save_data[$key] = date('Y-m-d', $val);
                }
            }
        }

        if (
            key_exists('photo', $save_data)
            && strlen($save_data['photo']) > 0
            && base64_decode($save_data['photo'], true) !== false
        ) {
            carddav::$logger->debug("photo is base64 encoded. Decoding...");
            $i = 0;
            while (base64_decode($save_data['photo'], true) !== false && $i++ < 10) {
                carddav::$logger->debug("Decoding $i...");
                $save_data['photo'] = base64_decode($save_data['photo'], true);
            }
            if ($i >= 10) {
                carddav::$logger->warning("PHOTO of " . $save_data['uid'] . " does not decode after 10 attempts...");
            }
        }

        // process all simple attributes
        foreach ($this->vcf2rc['simple'] as $vkey => $rckey) {
            if (key_exists($rckey, $save_data)) {
                $data = (is_array($save_data[$rckey])) ? $save_data[$rckey][0] : $save_data[$rckey];
                if (strlen($data) > 0) {
                    $vcard->{$vkey} = $data;
                } else { // delete the field
                    unset($vcard->{$vkey});
                }
            }
        }

        // Special handling for PHOTO
        if ($property = $vcard->PHOTO) {
            $property['ENCODING'] = 'B';
            $property['VALUE'] = 'BINARY';
        }

        // process all multi-value attributes
        foreach ($this->vcf2rc['multi'] as $vkey => $rckey) {
            // delete and fully recreate all entries
            // there is no easy way of mapping an address in the existing card
            // to an address in the save data, as subtypes may have changed
            unset($vcard->{$vkey});

            $stmap = array( $rckey => 'other' );
            foreach ($this->coltypes[$rckey]['subtypes'] as $subtype) {
                $stmap[ $rckey . ':' . $subtype ] = $subtype;
            }

            foreach ($stmap as $rcqkey => $subtype) {
                if (key_exists($rcqkey, $save_data)) {
                    $avalues = is_array($save_data[$rcqkey]) ? $save_data[$rcqkey] : array($save_data[$rcqkey]);
                    foreach ($avalues as $evalue) {
                        if (strlen($evalue) > 0) {
                            $prop = $vcard->add($vkey, $evalue);
                            if (!($prop instanceof VObject\Property)) {
                                throw new \Exception("Sabre did not return a propertyafter adding $vkey property");
                            }
                            $this->setAttrLabel($vcard, $prop, $rckey, $subtype); // set label
                        }
                    }
                }
            }
        }

        // process address entries
        unset($vcard->ADR);
        foreach ($this->coltypes['address']['subtypes'] as $subtype) {
            $rcqkey = 'address:' . $subtype;

            if (is_array($save_data[$rcqkey])) {
                foreach ($save_data[$rcqkey] as $avalue) {
                    if (
                        strlen($avalue['street'])
                        || strlen($avalue['locality'])
                        || strlen($avalue['region'])
                        || strlen($avalue['zipcode'])
                        || strlen($avalue['country'])
                    ) {
                        $prop = $vcard->add('ADR', [
                            '',
                            '',
                            $avalue['street'],
                            $avalue['locality'],
                            $avalue['region'],
                            $avalue['zipcode'],
                            $avalue['country'],
                        ]);

                        if (!($prop instanceof VObject\Property)) {
                            throw new \Exception("Sabre did not provide a property object when adding ADR");
                        }
                        $this->setAttrLabel($vcard, $prop, 'address', $subtype); // set label
                    }
                }
            }
        }

        return $vcard;
    }

    private function setAttrLabel(
        VCard $vcard,
        VObject\Property $pvalue,
        string $attrname,
        string $newlabel
    ): bool {
        $group = $pvalue->group;

        // X-ABLabel?
        if (in_array($newlabel, $this->xlabels[$attrname])) {
            if (!$group) {
                do {
                    $group = $this->guid();
                } while (null !== $vcard->{$group . '.X-ABLabel'});

                $pvalue->group = $group;

                // delete standard label if we had one
                $oldlabel = $pvalue['TYPE'];
                if (
                    ($oldlabel instanceof VObject\Parameter)
                    && (strlen((string) $oldlabel) > 0)
                    && in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])
                ) {
                    unset($pvalue['TYPE']);
                }
            }

            $vcard->{$group . '.X-ABLabel'} = $newlabel;
            return true;
        }

        // Standard Label
        $had_xlabel = false;
        if ($group) { // delete group label property if present
            $had_xlabel = isset($vcard->{$group . '.X-ABLabel'});
            unset($vcard->{$group . '.X-ABLabel'});
        }

        // add or replace?
        $oldlabel = $pvalue['TYPE'];
        if (
            ($oldlabel instanceof VObject\Parameter)
            && (strlen((string) $oldlabel) > 0)
            && in_array((string) $oldlabel, $this->coltypes[$attrname]['subtypes'])
        ) {
            $had_xlabel = false; // replace
        }

        if ($had_xlabel && is_array($pvalue['TYPE'])) {
            $new_type = $pvalue['TYPE'];
            array_unshift($new_type, $newlabel);
        } else {
            $new_type = $newlabel;
        }
        $pvalue['TYPE'] = $new_type;

        return false;
    }

    private function getAttrLabel(VCard $vcard, VObject\Property $pvalue, string $attrname): string
    {
        // prefer a known standard label if available
        $xlabel = '';
        $fallback = null;

        if (isset($pvalue['TYPE'])) {
            foreach ($pvalue['TYPE'] as $type) {
                $type = strtolower($type);
                if (
                    is_array($this->coltypes[$attrname]['subtypes'])
                    && in_array($type, $this->coltypes[$attrname]['subtypes'])
                ) {
                    $fallback = $type;
                    if (
                        !(is_array($this->fallbacktypes[$attrname])
                        && in_array($type, $this->fallbacktypes[$attrname]))
                    ) {
                        return $type;
                    }
                }
            }
        }

        if ($fallback) {
            return $fallback;
        }

        // check for a custom label using Apple's X-ABLabel extension
        $group = $pvalue->group;
        if ($group) {
            $xlabel = $vcard->{$group . '.X-ABLabel'};
            if ($xlabel) {
                $xlabel = $xlabel->getParts();
                if ($xlabel) {
                    $xlabel = $xlabel[0];
                }
            }

            // strange Apple label that I don't know to interpret
            if (strlen($xlabel) <= 0) {
                return 'other';
            }

            if (preg_match(';_\$!<(.*)>!\$_;', $xlabel, $matches)) {
                $match = strtolower($matches[1]);
                if (in_array($match, $this->coltypes[$attrname]['subtypes'])) {
                    return $match;
                }
                return 'other';
            }

            // add to known types if new
            if (!in_array($xlabel, $this->coltypes[$attrname]['subtypes'])) {
                $this->storeextrasubtype($attrname, $xlabel);
                $this->coltypes[$attrname]['subtypes'][] = $xlabel;
            }
            return $xlabel;
        }

        return 'other';
    }

    private function downloadPhoto(array &$save_data): bool
    {
        $uri = $save_data['photo'];
        try {
            $davAbook = $this->getCardDavObj();
            carddav::$logger->warning("downloadPhoto: Attempt to download photo from $uri");
            $response = $davAbook->downloadResource($uri);
            $save_data['photo'] = $response['body'];
        } catch (\Exception $e) {
            carddav::$logger->warning("downloadPhoto: Attempt to download photo from $uri failed: $e");
            return false;
        }

        return true;
    }

    private static function xabcropphoto(VCard $vcard, array &$save_data): VCard
    {
        if (!function_exists('gd_info') || $vcard == null) {
            return $vcard;
        }
        $photo = $vcard->PHOTO;
        if ($photo == null) {
            return $vcard;
        }
        $abcrop = $photo['X-ABCROP-RECTANGLE'];
        if (!($abcrop instanceof VObject\Parameter)) {
            return $vcard;
        }

        $parts = explode('&', (string) $abcrop);
        $x = intval($parts[1]);
        $y = intval($parts[2]);
        $w = intval($parts[3]);
        $h = intval($parts[4]);
        $dw = min($w, self::MAX_PHOTO_SIZE);
        $dh = min($h, self::MAX_PHOTO_SIZE);

        $src = imagecreatefromstring((string) $photo);
        $dst = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($dst, $src, 0, 0, $x, imagesy($src) - $y - $h, $dw, $dh, $w, $h);

        ob_start();
        imagepng($dst);
        $data = ob_get_contents();
        ob_end_clean();
        $save_data['photo'] = $data;

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
     *
     * This should only be called when interaction with the server is needed, as creation
     * of the object already involves communication with the server to query the properties
     * of the addressbook collection.
     */
    private function getCardDavObj(): AddressbookCollection
    {
        if (!isset($this->davAbook)) {
            $url = $this->config["url"];

            // only the username and password are stored to DB before replacing placeholders
            $username = carddav::replacePlaceholdersUsername($this->config["username"]);
            $password = carddav::replacePlaceholdersPassword(carddav::decryptPassword($this->config["password"]));

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
        $records = Database::get($groupid, 'contact_id', 'group_user', false, 'group_id');
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

        $contacts = Database::get(
            $contact_ids,
            "id,uri,etag,vcard",
            "contacts",
            false,
            "id",
            ["abook_id" => $this->id]
        );

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
