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
use carddav;
use carddav_common;

class RoundcubeCarddavAddressbook extends rcube_addressbook
{
    // the carddav frontend object
    private $frontend;

    // the DAV AddressbookCollection Object
    private $davAbook = null;

    // database primary key, used by RC to search by ID
    public $primary_key = 'id';
    public $coltypes;
    private $fallbacktypes = array( 'email' => array('internet') );

    // database ID of the addressbook
    private $id;

    /** @var ?string An additional filter to limit contact searches (content: SQL WHERE clause on contacts table) */
    private $filter;

    private $result;
    // configuration of the addressbook
    private $config;
    // custom labels defined in the addressbook
    private $xlabels;

    const SEPARATOR = ',';

    /** @var int total number of contacts in address book. Negative if not computed yet. */
    private $total_cards = -1;

    // attributes that are redundantly stored in the contact table and need
    // not be parsed from the vcard
    private $table_cols = array('id', 'name', 'email', 'firstname', 'surname');

    // maps VCard property names to roundcube keys
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

    // array with list of potential date fields for formatting
    private $datefields = array('birthday', 'anniversary');

    public function __construct($dbid, $frontend)
    {
        $dbh = rcmail::get_instance()->db;

        $this->frontend = $frontend;
        $this->ready    = $dbh && !$dbh->is_error();
        $this->groups   = true;
        $this->readonly = false;
        $this->id       = $dbid;
        $this->config   = self::carddavconfig($dbid);

        $rc = rcmail::get_instance();
        $this->coltypes = array( /* { */
            'name'         => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('name'), 'category' => 'main'),
            'firstname'    => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('firstname'), 'category' => 'main'),
            'surname'      => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('surname'), 'category' => 'main'),
            'email'        => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => $rc->gettext('email'), 'subtypes' => array('home','work','other','internet'), 'category' => 'main'),
            'middlename'   => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('middlename'), 'category' => 'main'),
            'prefix'       => array('type' => 'text', 'size' => 8,  'maxlength' => 20, 'limit' => 1, 'label' => $rc->gettext('nameprefix'), 'category' => 'main'),
            'suffix'       => array('type' => 'text', 'size' => 8,  'maxlength' => 20, 'limit' => 1, 'label' => $rc->gettext('namesuffix'), 'category' => 'main'),
            'nickname'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('nickname'), 'category' => 'main'),
            'jobtitle'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('jobtitle'), 'category' => 'main'),
            'organization' => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('organization'), 'category' => 'main'),
            'department'   => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => $rc->gettext('department'), 'category' => 'main'),
            'gender'       => array('type' => 'select', 'limit' => 1, 'label' => $rc->gettext('gender'), 'options' => array('male' => $rc->gettext('male'), 'female' => $rc->gettext('female')), 'category' => 'personal'),
            'phone'        => array('type' => 'text', 'size' => 40, 'maxlength' => 20, 'label' => $rc->gettext('phone'), 'subtypes' => array('home','home2','work','work2','mobile','cell','main','homefax','workfax','car','pager','video','assistant','other'), 'category' => 'main'),
            'address'      => array('type' => 'composite', 'label' => $rc->gettext('address'), 'subtypes' => array('home','work','other'), 'childs' => array(
                'street'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => $rc->gettext('street'), 'category' => 'main'),
                'locality'   => array('type' => 'text', 'size' => 28, 'maxlength' => 50, 'label' => $rc->gettext('locality'), 'category' => 'main'),
                'zipcode'    => array('type' => 'text', 'size' => 8,  'maxlength' => 15, 'label' => $rc->gettext('zipcode'), 'category' => 'main'),
                'region'     => array('type' => 'text', 'size' => 12, 'maxlength' => 50, 'label' => $rc->gettext('region'), 'category' => 'main'),
                'country'    => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => $rc->gettext('country'), 'category' => 'main'),), 'category' => 'main'),
            'birthday'     => array('type' => 'date', 'size' => 12, 'maxlength' => 16, 'label' => $rc->gettext('birthday'), 'limit' => 1, 'render_func' => 'rcmail_format_date_col', 'category' => 'personal'),
            'anniversary'  => array('type' => 'date', 'size' => 12, 'maxlength' => 16, 'label' => $rc->gettext('anniversary'), 'limit' => 1, 'render_func' => 'rcmail_format_date_col', 'category' => 'personal'),
            'website'      => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => $rc->gettext('website'), 'subtypes' => array('homepage','work','blog','profile','other'), 'category' => 'main'),
            'notes'        => array('type' => 'textarea', 'size' => 40, 'rows' => 15, 'maxlength' => 500, 'label' => $rc->gettext('notes'), 'limit' => 1),
            'photo'        => array('type' => 'image', 'limit' => 1, 'category' => 'main'),
            'assistant'    => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('assistant'), 'category' => 'personal'),
            'manager'      => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('manager'), 'category' => 'personal'),
            'spouse'       => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => $rc->gettext('spouse'), 'category' => 'personal'),
            // TODO: define fields for vcards like GEO, KEY
        ); /* } */
        $this->addextrasubtypes();

        if ($this->config['presetname']) {
            $prefs = carddav_common::get_adminsettings();
            if ($prefs[$this->config['presetname']]['readonly']) {
                $this->readonly = true;
            }
        }

        // refresh the address book if the update interval expired
        // this requires a completely initialized RoundcubeCarddavAddressbook object, so it
        // needs to be at the end of this constructor
        if ($this->config["needs_update"]) {
            $this->refreshdb_from_server(true);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Stores a custom label in the database (X-ABLabel extension).
     *
     * @param string Name of the type/category (phone,address,email)
     * @param string Name of the custom label to store for the type
     */
    private function storeextrasubtype($typename, $subtype)
    {
        $dbh = rcmail::get_instance()->db;
        $sql_result = $dbh->query(
            'INSERT INTO ' .
            $dbh->table_name('carddav_xsubtypes') .
            ' (typename,subtype,abook_id) VALUES (?,?,?)',
            $typename,
            $subtype,
            $this->id
        );
    }

    /**
     * Adds known custom labels to the roundcube subtype list (X-ABLabel extension).
     *
     * Reads the previously seen custom labels from the database and adds them to the
     * roundcube subtype list in #coltypes and additionally stores them in the #xlabels
     * list.
     */
    private function addextrasubtypes()
    {
        $this->xlabels = [];

        foreach ($this->coltypes as $k => $v) {
            if (key_exists('subtypes', $v)) {
                $this->xlabels[$k] = [];
            }
        }

        // read extra subtypes
        $xtypes = self::get_dbrecord($this->id, 'typename,subtype', 'xsubtypes', false, 'abook_id');

        foreach ($xtypes as $row) {
            $this->coltypes[$row['typename']]['subtypes'][] = $row['subtype'];
            $this->xlabels[$row['typename']][] = $row['subtype'];
        }
    }

    /**
     * Returns addressbook name (e.g. for addressbooks listing).
     *
     * @return string name of this addressbook
     */
    public function get_name()
    {
        return $this->config['name'];
    }

    /**
     * Save a search string for future listings
     *
     * @param mixed $filter Search params to use in listing method, obtained by get_search_set()
     */
    public function set_search_set($filter)
    {
        $this->filter = $filter;
        $this->total_cards = -1;
    }

    /**
     * Getter for saved search properties
     *
     * @return mixed Search properties used by this class
     */
    public function get_search_set()
    {
        return $this->filter;
    }

    /**
     * Reset saved results and search parameters
     */
    public function reset()
    {
        $this->result = null;
        $this->filter = null;
        $this->total_cards = -1;
    }

    /**
     * Determines the name to be displayed for a contact. The routine
     * distinguishes contact cards for individuals from organizations.
     */
    private static function set_displayname(&$save_data)
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

    /**
     * Stores a group in the database.
     *
     * If the group is based on a KIND=group vcard, the record must be stored with ETag, URI and VCard. Otherwise, if
     * the group is derived from a CATEGORIES property of a contact VCard, the ETag, URI and VCard must be set to NULL
     * to indicate this.
     *
     * @param array  associative array containing at least name and cuid (card UID)
     * @param int    optionally, database id of the group if the store operation is an update
     * @param string etag of the VCard in the given version on the CardDAV server
     * @param string path to the VCard on the CardDAV server
     * @param string string representation of the VCard
     *
     * @return int|bool The database id of the created or updated card, false on error.
     */
    public function dbstore_group($save_data, $dbid = null, $etag = null, $uri = null, $vcfstr = null)
    {
        return $this->dbstore_base('groups', $etag, $uri, $vcfstr, $save_data, $dbid);
    }

    private function dbstore_base($table, $etag, $uri, $vcfstr, $save_data, $dbid, $xcol = [], $xval = [])
    {
        $dbh = rcmail::get_instance()->db;

        // get rid of the %u placeholder in the URI, otherwise the refresh operation
        // will not be able to match local cards with those provided by the server
        $username = $this->config['username'];
        if ($username === "%u") {
            $username = $_SESSION['username'];
        }

        $carddesc = $uri ?? "(entry not backed by card)";
        $xcol[] = 'name';
        $xval[] = $save_data['name'];

        if (isset($etag)) {
            $xcol[] = 'etag';
            $xval[] = $etag;
        }

        if (isset($vcfstr)) {
            $xcol[] = 'vcard';
            $xval[] = $vcfstr;
        }

        if (isset($dbid)) {
            carddav::$logger->debug("UPDATE card $dbid/$carddesc in $table");
            $xval[] = $dbid;
            $sql_result = $dbh->query('UPDATE ' .
                $dbh->table_name("carddav_$table") .
                ' SET ' . implode('=?,', $xcol) . '=?' .
                ' WHERE id=?', $xval);
        } else {
            carddav::$logger->debug("INSERT card $carddesc to $table");

            $xcol[] = 'abook_id';
            $xval[] = $this->id;

            if (isset($uri)) {
                $uri = str_replace("%u", $username, $uri);
                $xcol[] = 'uri';
                $xval[] = $uri;
            }
            if (isset($save_data['cuid'])) {
                $xcol[] = 'cuid';
                $xval[] = $save_data['cuid'];
            }

            $sql_result = $dbh->query(
                'INSERT INTO ' .
                $dbh->table_name("carddav_$table") .
                ' (' . implode(',', $xcol) . ') VALUES (?' . str_repeat(',?', count($xcol) - 1) . ')',
                $xval
            );

            $dbid = $dbh->insert_id("carddav_$table");
        }

        if ($dbh->is_error()) {
            carddav::$logger->error($dbh->is_error());
            $this->set_error(self::ERROR_SAVING, $dbh->is_error());
            return false;
        }

        return $dbid;
    }

    /**
     * Stores a contact to the local database.
     *
     * @param string etag of the VCard in the given version on the CardDAV server
     * @param string path to the VCard on the CardDAV server
     * @param string string representation of the VCard
     * @param array  associative array containing the roundcube save data for the contact
     * @param int    optionally, database id of the contact if the store operation is an update
     *
     * @return int|bool  The database id of the created or updated card, false on error.
     */
    public function dbstore_contact($etag, $uri, $vcfstr, $save_data, $dbid = null)
    {
        $this->preprocess_rc_savedata($save_data);
        // build email search string
        $email_keys = preg_grep('/^email(:|$)/', array_keys($save_data));
        $email_addrs = [];
        foreach ($email_keys as $email_key) {
            $email_addrs = array_merge($email_addrs, (array) $save_data[$email_key]);
        }
        $save_data['email'] = implode(', ', $email_addrs);

        // extra columns for the contacts table
        $xcol_all = array('firstname','surname','organization','showas','email');
        $xcol = [];
        $xval = [];
        foreach ($xcol_all as $k) {
            if (key_exists($k, $save_data)) {
                $xcol[] = $k;
                $xval[] = $save_data[$k];
            }
        }

        return $this->dbstore_base('contacts', $etag, $uri, $vcfstr, $save_data, $dbid, $xcol, $xval);
    }

    /**
     * Synchronizes the local card store with the CardDAV server.
     */
    public function refreshdb_from_server($showUIMsg = false)
    {
        try {
            $rcmail = rcmail::get_instance();
            $dbh = $rcmail->db;

            $start_refresh = time();

            $this->createCardDavObj();

            $synchandler = new SyncHandlerRoundcube($this);
            $syncmgr = new Sync();
            $sync_token = $syncmgr->synchronize($this->davAbook, $synchandler, [ ], $this->config['sync_token'] ?? "");
            carddav::update_abook($this->config['abookid'], array('sync_token' => $sync_token));
            $this->config['sync_token'] = $sync_token;
            $this->config['needs_update'] = 0;

            $duration = time() - $start_refresh;
            carddav::$logger->debug("server refresh took $duration seconds");

            // set last_updated timestamp
            $dbh->query(
                'UPDATE ' .
                $dbh->table_name('carddav_addressbooks') .
                ' SET last_updated=' . $dbh->now() . ' WHERE id=?',
                $this->id
            );

            if ($showUIMsg) {
                $rcmail->output->show_message(
                    $this->frontend->gettext(array('name' => 'cd_msg_synchronized', 'vars' => array(
                        'name' => $this->get_name(),
                        'duration' => $duration,
                    )
                    ))
                );
            }
        } catch (\Exception $e) {
            carddav::$logger->error("Errors occurred during the refresh of addressbook " . $this->id . ": $e");
        }
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
    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $this->result = $this->count();

        $records = $this->list_records_readdb($cols, $subset);
        if ($this->list_page <= 1) {
            if ($records < $this->page_size && $subset == 0) {
                $this->result->count = $records;
            } else {
                $this->result->count = $this->_count();
            }
        }

        return $this->result;
    }

    private function list_records_readdb($cols, $subset = 0, $count_only = false): int
    {
        $dbh = rcmail::get_instance()->db;

        // true if we can use DB filtering or no filtering is requested
        $filter = $this->get_search_set();

        // determine whether we have to parse the vcard or if only db cols are requested
        $read_vcard = (!isset($cols)) || (count(array_intersect($cols, $this->table_cols)) < count($cols));

        // determine result subset needed
        $firstrow = ($subset >= 0) ?
            $this->result->first : ($this->result->first + $this->page_size + $subset);
        $numrows  = $subset ? abs($subset) : $this->page_size;

        carddav::$logger->debug("list_records_readdb " . (isset($cols) ? implode(",", $cols) : "ALL") . " $read_vcard");

        $dbattr = $read_vcard ? 'vcard' : 'firstname,surname,email';

        $limit_index = $firstrow;
        $limit_rows  = $numrows;

        $xfrom = '';
        $xwhere = '';
        if ($this->group_id) {
            $xfrom = ',' . $dbh->table_name('carddav_group_user');
            $xwhere = ' AND id=contact_id AND group_id=' . $dbh->quote($this->group_id) . ' ';
        }

        if ($this->config['presetname']) {
            $prefs = carddav_common::get_adminsettings();
            if (key_exists("require_always", $prefs[$this->config['presetname']])) {
                foreach ($prefs[$this->config['presetname']]["require_always"] as $col) {
                    $xwhere .= " AND $col <> " . $dbh->quote('') . " ";
                }
            }
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
                    $save_data = $this->create_save_data_from_vcard($vcf);
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
        if (!$count_only) {
            foreach ($addresses as $a) {
                $a['save_data']['ID'] = $a['ID'];
                $this->result->add($a['save_data']);
            }
        }

        return count($addresses);
    }

    /**
     * Search contacts
     *
     * @param mixed   $fields   The field name of array of field names to search in
     * @param mixed   $value    Search value (or array of values when $fields is array)
     * @param int     $mode     Matching mode:
     *                          0 - partial (*abc*),
     *                          1 - strict (=),
     *                          2 - prefix (abc*)
     * @param boolean $select   True if results are requested, False if count only
     * @param boolean $nocount  True to skip the count query (select only)
     * @param array   $required List of fields that cannot be empty
     *
     * @return object rcube_result_set Contact records and 'count' value
     */
    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        $dbh = rcmail::get_instance()->db;
        if (!is_array($fields)) {
            $fields = array($fields);
        }
        if (!is_array($required) && !empty($required)) {
            $required = array($required);
        }

        $where = $and_where = [];
        $mode = intval($mode);
        $WS = ' ';
        $AS = self::SEPARATOR;
        $post_search = [];

        // build the $where array; each of its entries is an SQL search condition
        foreach ($fields as $idx => $col) {
            // direct ID search
            if ($col == 'ID' || $col == $this->primary_key) {
                $ids     = !is_array($value) ? explode(self::SEPARATOR, $value) : $value;
                $ids     = $dbh->array2list($ids, 'integer');
                $where[] = $this->primary_key . ' IN (' . $ids . ')';
                continue;
            }

            $val = is_array($value) ? $value[$idx] : $value;
            // table column
            if (in_array($col, $this->table_cols)) {
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
            }
            // vCard field
            else {
                $words = [];
                foreach (explode(" ", self::normalize_string($val)) as $word) {
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

        if ($this->config['presetname']) {
            $prefs = carddav_common::get_adminsettings();
            if (key_exists("require_always", $prefs[$this->config['presetname']])) {
                $required = array_merge($prefs[$this->config['presetname']]["require_always"], $required);
            }
        }

        foreach (array_intersect($required, $this->table_cols) as $col) {
            $and_where[] = $dbh->quoteIdentifier($col) . ' <> ' . $dbh->quote('');
        }

        if (!empty($where)) {
            // use AND operator for advanced searches
            $where = join(is_array($value) ? ' AND ' : ' OR ', $where);
        }

        if (!empty($and_where)) {
            $where = ($where ? "($where) AND " : '') . join(' AND ', $and_where);
        }

        // Post-searching in vCard data fields
        // we will search in all records and then build a where clause for their IDs
        if (!empty($post_search)) {
            $ids = array(0);
            // build key name regexp
            $regexp = '/^(' . implode('|', array_keys($post_search)) . ')(?:.*)$/';
            // use initial WHERE clause, to limit records number if possible
            if (!empty($where)) {
                $this->set_search_set($where);
            }

            // count result pages
            $cnt   = $this->count();
            $pages = ceil($cnt / $this->page_size);
            $scnt  = count($post_search);

            // get (paged) result
            for ($i = 0; $i < $pages; $i++) {
                $this->list_records(null, $i, true);
                while ($row = $this->result->next()) {
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
            $where = $this->primary_key . ' IN (' . $ids . ')';

            // when we know we have an empty result
            if ($ids == '0') {
                $this->set_search_set($where);
                return ($this->result = new rcube_result_set(0, 0));
            }
        }

        if (!empty($where)) {
            $this->set_search_set($where);
            if ($select) {
                $this->list_records(null, 0, $nocount);
            } else {
                $this->result = $this->count();
            }
        }

        return $this->result;
    }

    /**
     * Count number of available contacts in database
     *
     * @return rcube_result_set Result set with values for 'count' and 'first'
     */
    public function count()
    {
        carddav::$logger->debug("count()");
        if ($this->total_cards < 0) {
            $this->_count();
        }
        return new rcube_result_set($this->total_cards, ($this->list_page - 1) * $this->page_size);
    }

    // Determines and returns the number of cards matching the current search criteria
    private function _count()
    {
        if ($this->total_cards < 0) {
            $dbh = rcmail::get_instance()->db;

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

    /**
     * Return the last result set
     *
     * @return rcube_result_set Current result set or NULL if nothing selected yet
     */
    public function get_result()
    {
        return $this->result;
    }

    private function parseVCard(string $vcf): VObject\Document
    {
        // create vcard from current DB data to be updated with the new data
        return VObject\Reader::read($vcf, VObject\Reader::OPTION_FORGIVING);
    }

    /**
     * Get a specific contact record
     *
     * @param mixed   $id    Record identifier(s)
     * @param boolean $assoc True to return record as associative array, otherwise a result set is returned
     *
     * @return rcube_result_set|array Result object with all record fields
     */
    public function get_record($id, $assoc = false)
    {
        try {
            carddav::$logger->debug("get_record($id, $assoc)");

            $contact = self::get_dbrecord($id, 'vcard');
            $vcard = $this->parseVCard($contact['vcard']);
            [ 'save_data' => $save_data ] = $this->create_save_data_from_vcard($vcard);
            $save_data['ID'] = $id;

            $this->result = new rcube_result_set(1);
            $this->result->add($save_data);

            return $assoc ? $save_data : $this->result;
        } catch (\Exception $e) {
            carddav::$logger->error("Could not get contact $id: " . $e->getMessage());
            return $assoc ? [] : new rcube_result_set();
        }
    }

    private function guid()
    {
        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    /**
     * Creates a new or updates an existing vcard from save data.
     */
    private function create_vcard_from_save_data($save_data, $vcf = null)
    {
        unset($save_data['vcard']);
        if (isset($vcf)) {
            // update revision
            $vcf->REV = gmdate("Y-m-d\TH:i:s\Z");
        } else {
            // create fresh minimal vcard
            $vcf = new VObject\Component\VCard([
                'REV' => gmdate("Y-m-d\TH:i:s\Z"),
                'VERSION' => '3.0'
            ]);
        }

        // N is mandatory
        if (key_exists('kind', $save_data) && $save_data['kind'] === 'group') {
            $vcf->N = [$save_data['name'],"","","",""];
        } else {
            $vcf->N = [
                $save_data['surname'],
                $save_data['firstname'],
                $save_data['middlename'],
                $save_data['prefix'],
                $save_data['suffix'],
            ];
        }

        $new_org_value = [];
        if (
            key_exists("organization", $save_data) &&
            strlen($save_data['organization']) > 0
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
            $vcf->ORG = $new_org_value;
        } else {
            unset($vcf->ORG);
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

        if (key_exists('photo', $save_data) && strlen($save_data['photo']) > 0 && base64_decode($save_data['photo'], true) !== false) {
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
                    $vcf->{$vkey} = $data;
                } else { // delete the field
                    unset($vcf->{$vkey});
                }
            }
        }

        // Special handling for PHOTO
        if ($property = $vcf->PHOTO) {
            $property['ENCODING'] = 'B';
            $property['VALUE'] = 'BINARY';
        }

        // process all multi-value attributes
        foreach ($this->vcf2rc['multi'] as $vkey => $rckey) {
            // delete and fully recreate all entries
            // there is no easy way of mapping an address in the existing card
            // to an address in the save data, as subtypes may have changed
            unset($vcf->{$vkey});

            $stmap = array( $rckey => 'other' );
            foreach ($this->coltypes[$rckey]['subtypes'] as $subtype) {
                $stmap[ $rckey . ':' . $subtype ] = $subtype;
            }

            foreach ($stmap as $rcqkey => $subtype) {
                if (key_exists($rcqkey, $save_data)) {
                    $avalues = is_array($save_data[$rcqkey]) ? $save_data[$rcqkey] : array($save_data[$rcqkey]);
                    foreach ($avalues as $evalue) {
                        if (strlen($evalue) > 0) {
                            $prop = $vcf->add($vkey, $evalue);
                            $this->set_attr_label($vcf, $prop, $rckey, $subtype); // set label
                        }
                    }
                }
            }
        }

        // process address entries
        unset($vcf->ADR);
        foreach ($this->coltypes['address']['subtypes'] as $subtype) {
            $rcqkey = 'address:' . $subtype;

            if (key_exists($rcqkey, $save_data)) {
                foreach ($save_data[$rcqkey] as $avalue) {
                    if (
                        strlen($avalue['street'])
                        || strlen($avalue['locality'])
                        || strlen($avalue['region'])
                        || strlen($avalue['zipcode'])
                        || strlen($avalue['country'])
                    ) {
                        $prop = $vcf->add('ADR', array(
                            '',
                            '',
                            $avalue['street'],
                            $avalue['locality'],
                            $avalue['region'],
                            $avalue['zipcode'],
                            $avalue['country'],
                        ));
                        $this->set_attr_label($vcf, $prop, 'address', $subtype); // set label
                    }
                }
            }
        }

        return $vcf;
    }

    private function set_attr_label($vcard, $pvalue, $attrname, $newlabel)
    {
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
                    strlen($oldlabel) > 0 &&
                    in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])
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
            strlen($oldlabel) > 0 &&
            in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])
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

    private function get_attr_label($vcard, $pvalue, $attrname)
    {
        // prefer a known standard label if available
        $xlabel = '';
        $fallback = null;

        if (isset($pvalue['TYPE'])) {
            foreach ($pvalue['TYPE'] as $type) {
                $type = strtolower($type);
                if (is_array($this->coltypes[$attrname]['subtypes']) && in_array($type, $this->coltypes[$attrname]['subtypes'])) {
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

    private function download_photo(&$save_data)
    {
        $uri = $save_data['photo'];
        try {
            $this->createCardDavObj();
            carddav::$logger->warning("download_photo: Attempt to download photo from $uri");
            $response = $this->davAbook->downloadResource($uri);
            $save_data['photo'] = $response['body'];
        } catch (\Exception $e) {
            carddav::$logger->warning("download_photo: Attempt to download photo from $uri failed: $e");
            return false;
        }

        return true;
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
     * @param  VCard Sabre VCard object
     * @return array associative array with keys:
     *           - save_data:    Roundcube representation of the VCard
     *           - vcf:          VCard object created from the given VCard
     *           - needs_update: boolean that indicates whether the card was modified
     */
    public function create_save_data_from_vcard($vcard)
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
        if (key_exists('photo', $save_data)) {
            $kind = $vcard->PHOTO['VALUE'];
            if ($kind && strcasecmp('uri', $kind) == 0) {
                if ($this->download_photo($save_data)) {
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
        if ($property !== null) {
            $N = $property->getParts();
            switch (count($N)) {
                case 5:
                    $save_data['suffix']     = $N[4];
                case 4:
                    $save_data['prefix']     = $N[3];
                case 3:
                    $save_data['middlename'] = $N[2];
                case 2:
                    $save_data['firstname']  = $N[1];
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
                    $label = $this->get_attr_label($vcard, $property_instance, $value);
                    $save_data[$value . ':' . $label][] = $p[0];
                }
            }
        }

        $property = ($vcard->ADR) ?: [];
        foreach ($property as $property_instance) {
            $p = $property_instance->getParts();
            $label = $this->get_attr_label($vcard, $property_instance, 'address');
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
        self::set_displayname($save_data);

        return array(
            'save_data'    => $save_data,
            'vcf'          => $vcard,
            'needs_update' => $needs_update,
        );
    }


    const MAX_PHOTO_SIZE = 256;

    private static function xabcropphoto($vcard, &$save_data)
    {
        if (!function_exists('gd_info') || $vcard == null) {
            return $vcard;
        }
        $photo = $vcard->PHOTO;
        if ($photo == null) {
            return $vcard;
        }
        $abcrop = $vcard->PHOTO['X-ABCROP-RECTANGLE'];
        if ($abcrop == null) {
            return $vcard;
        }

        $parts = explode('&', $abcrop);
        $x = intval($parts[1]);
        $y = intval($parts[2]);
        $w = intval($parts[3]);
        $h = intval($parts[4]);
        $dw = min($w, self::MAX_PHOTO_SIZE);
        $dh = min($h, self::MAX_PHOTO_SIZE);

        $src = imagecreatefromstring($photo);
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
     * Create a new contact record
     *
     * @param array $save_data Associative array with save data
     *  Keys:   Field name with optional section in the form FIELD:SECTION
     *  Values: Field value. Can be either a string or an array of strings for multiple values
     * @param boolean $check True to check for duplicates first
     *
     * @return mixed The created record ID on success, False on error
     */
    public function insert($save_data, $check = false)
    {
        try {
            carddav::$logger->debug("insert(" . $save_data["name"] . ", $check)");
            $this->preprocess_rc_savedata($save_data);
            $this->createCardDavObj();

            $vcard = $this->create_vcard_from_save_data($save_data);
            $this->davAbook->createCard($vcard);
            $this->refreshdb_from_server();
            $contact = self::get_dbrecord($vcard->UID, 'id', 'contacts', true, 'cuid');

            if (isset($contact["id"])) {
                return $contact["id"];
            }
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
        }
        return false;
    }

    /**
     * Does some common preprocessing with save data created by roundcube.
     */
    private function preprocess_rc_savedata(&$save_data)
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
        self::set_displayname($save_data);
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
    public function update($id, $save_data)
    {
        try {
            // complete save_data
            $this->preprocess_rc_savedata($save_data);
            $this->createCardDavObj();

            // get current DB data
            $contact = self::get_dbrecord($id, 'id,cuid,uri,etag,vcard,showas');
            $save_data['showas'] = $contact['showas'];

            // create vcard from current DB data to be updated with the new data
            $vcard = $this->parseVCard($contact['vcard']);
            $vcard = $this->create_vcard_from_save_data($save_data, $vcard);
            $this->davAbook->updateCard($contact['uri'], $vcard, $contact['etag']);
            $this->refreshdb_from_server();

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
     */
    public function delete($ids, $force = true)
    {
        $abook_id = $this->id;
        $deleted = 0;
        carddav::$logger->debug("delete([" . implode(",", $ids) . "])");

        try {
            $this->createCardDavObj();

            $contacts = self::get_dbrecord($ids, 'cuid,uri', 'contacts', false);
            $contact_cuids = array_map(
                function ($v) {
                    return $v["cuid"];
                },
                $contacts
            );

            // remove contacts from VCard based groups - get groups that the contacts are members of
            $groupids = array_map(
                function ($v) {
                    return $v["group_id"];
                },
                self::get_dbrecord($ids, 'group_id', 'group_user', false, 'contact_id')
            );

            $groups = self::get_dbrecord($groupids, "id,etag,uri,vcard", "groups", false);

            foreach ($groups as $group) {
                if (isset($group["vcard"])) {
                    $this->removeContactsFromVCardBasedGroup($contact_cuids, $group);
                }
            }

            // delete the contact cards from the server
            foreach ($contacts as $contact) {
                $this->davAbook->deleteCard($contact['uri']);
                ++$deleted;
            }

            // and sync back the changes to the cache
            $this->refreshdb_from_server();
        } catch (\Exception $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            carddav::$logger->error("Failed to delete contacts [" . implode(",", $ids) . "]:" . $e->getMessage());
        }

        return $deleted;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string       $group_id Group identifier
     * @param array|string $ids      List of contact identifiers to be added
     *
     * @return int Number of contacts added
     */
    public function add_to_group($group_id, $ids)
    {
        $added = 0;

        try {
            $this->createCardDavObj();

            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }

            // get current DB data
            $group = self::get_dbrecord($group_id, 'name,uri,etag,vcard', 'groups');

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                // create vcard from current DB data to be updated with the new data
                $vcard = $this->parseVCard($group['vcard']);

                foreach ($ids as $cid) {
                    try {
                        $contact = self::get_dbrecord($cid, 'cuid');
                        $vcard->add('X-ADDRESSBOOKSERVER-MEMBER', "urn:uuid:" . $contact['cuid']);
                        ++$added;
                    } catch (\Exception $e) {
                        carddav::$logger->warning("add_to_group: Contact with ID $cid not found in database");
                    }
                }

                $this->davAbook->updateCard($group['uri'], $vcard, $group['etag']);

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids,
                    function (array &$groups, $contact_id) use ($groupname, &$added) {
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

            $this->refreshdb_from_server();
        } catch (\Exception $e) {
            carddav::$logger->error("add_to_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());
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
    public function remove_from_group($group_id, $ids)
    {
        $abook_id = $this->id;
        $deleted = 0;

        try {
            $this->createCardDavObj();

            if (!is_array($ids)) {
                $ids = explode(self::SEPARATOR, $ids);
            }
            carddav::$logger->debug("remove_from_group($group_id, [" . implode(",", $ids) . "])");

            // get current DB data
            $group = self::get_dbrecord($group_id, 'id,name,uri,etag,vcard', 'groups');

            // if vcard is set, this group is based on a KIND=group VCard
            if (isset($group['vcard'])) {
                $contacts = self::get_dbrecord($ids, "cuid", "contacts", false);
                $deleted = $this->removeContactsFromVCardBasedGroup(
                    array_map(function ($v) {
                        return $v["cuid"];
                    }, $contacts),
                    $group
                );

            // if vcard is not set, this group comes from the CATEGORIES property of the contacts it comprises
            } else {
                $groupname = $group["name"];

                $this->adjustContactCategories(
                    $ids,
                    function (array &$groups, $contact_id) use ($groupname, &$deleted) {
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

            $this->refreshdb_from_server();
        } catch (\Exception $e) {
            carddav::$logger->error("remove_from_group: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());
        }

        return $deleted;
    }

    /**
     * Removes a list of contacts from a KIND=group VCard-based group and updates the group on the server.
     *
     * An update of the card on the server will only be performed if members have actually been removed from the VCard,
     * i. e. the function returns a value greater than 0.
     *
     * @param string[] $contact_cuids The VCard UIDs of the contacts to remove from the group.
     * @param array    $group         Array with keys id, etag, uri and vcard containing the corresponding fields of the
     *                                group, where vcard is the serialized string form of the VCard.
     *
     * @return int The number of members actually removed from the group.
     */
    private function removeContactsFromVCardBasedGroup($contact_cuids, $group): int
    {
        $deleted = 0;

        // create vcard from current DB data to be updated with the new data
        $vcard = $this->parseVCard($group["vcard"]);

        foreach ($contact_cuids as $cuid) {
            $search_for = "urn:uuid:$cuid";
            $found = false;
            foreach ($vcard->{'X-ADDRESSBOOKSERVER-MEMBER'} as $member) {
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
            $this->davAbook->updateCard($group['uri'], $vcard, $group['etag']);
        }

        return $deleted;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     *
     * @return array $id List of assigned groups as ID=>Name pairs
     * @since 0.5-beta
     */
    public function get_record_groups($id)
    {
        carddav::$logger->debug("get_record_groups($id)");
        $dbh = rcmail::get_instance()->db;
        $sql_result = $dbh->query('SELECT id,name FROM ' .
            $dbh->table_name('carddav_group_user') . ',' .
            $dbh->table_name('carddav_groups') .
            ' WHERE contact_id=? AND id=group_id', $id);

        $res = [];
        while ($row = $dbh->fetch_assoc($sql_result)) {
            $res[$row['id']] = $row['name'];
        }

        return $res;
    }

    /**
     * Setter for the current group
     */
    public function set_group($gid)
    {
        carddav::$logger->debug("set_group($gid)");
        $this->group_id = $gid;

        if (isset($gid)) {
            $dbh = rcmail::get_instance()->db;

            $this->set_search_set("EXISTS(SELECT * FROM " . $dbh->table_name("carddav_group_user") . "
                WHERE group_id = '{$gid}' AND contact_id = " . $dbh->table_name("carddav_contacts") . ".id)");
        } else {
            $this->reset();
        }
    }

    /**
     * Get group properties such as name and email address(es)
     *
     * @param string $group_id Group identifier
     *
     * @return array Group properties as hash array
     */
    public function get_group($group_id)
    {
        try {
            carddav::$logger->debug("get_group($group_id)");

            // As of 1.4.6, roundcube is interested in name and email properties of a group,
            // i. e. if the group as a distribution list had an email address of its own. Otherwise, it will fall back
            // to getting the individual members' addresses
            $result = self::get_dbrecord($group_id, 'id,name', 'groups');
        } catch (\Exception $e) {
            carddav::$logger->error("get_group($group_id): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SEARCH, $e->getMessage());
            return [];
        }

        return $result;
    }

    /**
     * List all active contact groups of this source
     *
     * @param string $search Optional search string to match group name
     * @param int    $mode   Search mode. Sum of self::SEARCH_*
     *
     * @return array  Indexed list of contact groups, each a hash array
     */
    public function list_groups($search = null, $mode = 0)
    {
        carddav::$logger->debug("list_groups($search, $mode)");
        $dbh = rcmail::get_instance()->db;

        $searchextra = "";
        if ($search !== null) {
            if ($mode & rcube_addressbook::SEARCH_STRICT) {
                $searchextra = $dbh->ilike('name', $search);
            } elseif ($mode & rcube_addressbook::SEARCH_PREFIX) {
                $searchextra = $dbh->ilike('name', "$search%");
            } else {
                $searchextra = $dbh->ilike('name', "%$search%");
            }
            $searchextra = ' AND ' . $searchextra;
        }

        $sql_result = $dbh->query(
            'SELECT id,name from ' .
            $dbh->table_name('carddav_groups') .
            ' WHERE abook_id=?' . $searchextra .
            ' ORDER BY name ASC',
            $this->id
        );

        $groups = [];
        while ($row = $dbh->fetch_assoc($sql_result)) {
            $row['ID'] = $row['id'];
            $groups[] = $row;
        }

        return $groups;
    }

    /**
     * Create a contact group with the given name
     *
     * @param string $name The group name
     *
     * @return mixed False on error, array with record props in success
     */
    public function create_group($name)
    {
        try {
            carddav::$logger->debug("create_group($name)");
            $save_data = [ 'name' => $name, 'kind' => 'group' ];

            if ($this->config['use_categories']) {
                $groupid = $this->dbstore_group($save_data);

                if ($groupid !== false) {
                    return [ 'id' => $groupid, 'name' => $name ];
                }

                throw new \Exception("New group could not be stored to database");
            } else {
                $this->createCardDavObj();
                $vcard = $this->create_vcard_from_save_data($save_data);
                $this->davAbook->createCard($vcard);

                $this->refreshdb_from_server();

                $group = self::get_dbrecord($vcard->UID, 'id,name', 'groups', true, 'cuid');

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
    public function delete_group($group_id)
    {
        try {
            carddav::$logger->debug("delete_group($group_id)");
            $this->createCardDavObj();

            $group = self::get_dbrecord($group_id, 'name,uri', 'groups');

            if (isset($group["uri"])) { // KIND=group VCard-based group
                $this->davAbook->deleteCard($group["uri"]);
                $this->refreshdb_from_server();
                return true;
            } else { // CATEGORIES-type group
                $groupname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);
                $this->adjustContactCategories(
                    $contact_ids,
                    function (array &$groups, $contact_id) use ($groupname) {
                        return self::stringsAddRemove($groups, [], [$groupname]);
                    }
                );
                return true;
            }
        } catch (\Exception $e) {
            carddav::$logger->error("delete_group($group_id): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
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
    public function rename_group($group_id, $newname, &$newid)
    {
        try {
            carddav::$logger->debug("rename_group($group_id, $newname)");
            $this->createCardDavObj();

            $group = self::get_dbrecord($group_id, 'uri,name,etag,vcard', 'groups');

            if (isset($group["uri"])) { // KIND=group VCard-based group
                $vcard = $this->parseVCard($group["vcard"]);
                $vcard->FN = $newname;
                $vcard->N  = [$newname,"","","",""];

                $this->davAbook->updateCard($group["uri"], $vcard, $group["etag"]);
                $this->refreshdb_from_server();
                return $newname;
            } else { // CATEGORIES-type group
                $oldname = $group["name"];
                $contact_ids = $this->getContactIdsForGroup($group_id);
                $this->adjustContactCategories(
                    $contact_ids,
                    function (array &$groups, $contact_id) use ($oldname, $newname) {
                        return self::stringsAddRemove($groups, [ $newname ], [ $oldname ]);
                    }
                );

                $this->refreshdb_from_server(); // will insert the contact assignments as a new group
                self::delete_dbrecord($group_id, 'groups');
                return $newname;
            }
        } catch (\Exception $e) {
            carddav::$logger->error("rename_group($group_id, $newname): " . $e->getMessage());
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
        }

        return false;
    }

    /**
     * Mark all records in database as deleted
     *
     * @param bool $with_groups Remove also groups
     */
    public function delete_all($with_groups = false)
    {
        try {
            carddav::$logger->debug("delete_all($with_groups)");
            $this->createCardDavObj();
            $dbh = rcmail::get_instance()->db;
            $abook_id = $this->id;

            // first remove / clear KIND=group vcard-based groups
            $vcard_groups = self::get_dbrecord($abook_id, "id,uri,vcard,etag", "groups", false, "abook_id");
            foreach ($vcard_groups as $vcard_group) {
                if (isset($vcard_group["vcard"])) { // skip CATEGORIES-type groups
                    if ($with_groups) {
                        $this->davAbook->deleteCard($vcard_group["uri"]);
                    } else {
                        // create vcard from current DB data to be updated with the new data
                        $vcard = $this->parseVCard($vcard_group['vcard']);
                        $vcard->remove('X-ADDRESSBOOKSERVER-MEMBER');
                        $this->davAbook->updateCard($vcard_group['uri'], $vcard, $vcard_group['etag']);
                    }
                }
            }

            // now delete all contact cards
            $contacts = self::get_dbrecord($abook_id, "uri", "contacts", false, "abook_id");
            foreach ($contacts as $contact) {
                $this->davAbook->deleteCard($contact["uri"]);
            }

            // and sync the changes back
            $this->refreshdb_from_server();

            // CATEGORIES-type groups are still inside the DB - remove if requested
            self::delete_dbrecord($abook_id, "groups", "abook_id");
        } catch (\Exception $e) {
            carddav::$logger->error("delete_all: " . $e->getMessage());
            $this->set_error(self::ERROR_SAVING, $e->getMessage());
        }
    }

    private static function getConditionQuery($dbh, string $field, $value): string
    {
        $sql = $dbh->quoteIdentifier($field);

        if (is_array($value)) {
            if (count($value) > 0) {
                $quoted_values = array_map([ $dbh, 'quote' ], $value);
                $sql .= " IN (" . implode(",", $quoted_values) . ")";
            } else {
                throw new \Exception("getConditionQuery $field - empty values array provided");
            }
        } else {
            $sql .= ' = ' . $dbh->quote($value);
        }

        return $sql;
    }

    private static function getOtherConditionsQuery($dbh, array $other_conditions): string
    {
        $sql = "";

        foreach ($other_conditions as $field => $value) {
            $sql .= ' AND ';
            $sql .= self::getConditionQuery($dbh, $field, $value);
        }

        return $sql;
    }

    public static function get_dbrecord(
        $id,
        $cols = '*',
        $table = 'contacts',
        $retsingle = true,
        $idfield = 'id',
        $other_conditions = []
    ): array {
        $dbh = rcmail::get_instance()->db;

        $idfield = $dbh->quoteIdentifier($idfield);
        $sql = "SELECT $cols FROM " . $dbh->table_name("carddav_$table") . ' WHERE ';

        // Main selection condition
        $sql .= self::getConditionQuery($dbh, $idfield, $id);

        // Append additional conditions
        $sql .= self::getOtherConditionsQuery($dbh, $other_conditions);

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            carddav::$logger->error("get_dbrecord ($sql) ERROR: " . $dbh->is_error());
            throw new \Exception($dbh->is_error());
        }

        // single result row expected?
        if ($retsingle) {
            $ret = $dbh->fetch_assoc($sql_result);
            if ($ret === false) {
                throw new \Exception("Single-row query ($sql) without result");
            }
            return $ret;
        } else {
            // multiple rows requested
            $ret = [];
            while ($row = $dbh->fetch_assoc($sql_result)) {
                $ret[] = $row;
            }
            return $ret;
        }
    }

    public static function delete_dbrecord($ids, $table = 'contacts', $idfield = 'id', $other_conditions = [])
    {
        $dbh = rcmail::get_instance()->db;

        $idfield = $dbh->quoteIdentifier($idfield);
        $sql = "DELETE FROM " . $dbh->table_name("carddav_$table") . " WHERE ";

        // Main selection condition
        $sql .= self::getConditionQuery($dbh, $idfield, $ids);

        // Append additional conditions
        $sql .= self::getOtherConditionsQuery($dbh, $other_conditions);

        carddav::$logger->debug("delete_dbrecord: $sql");

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            carddav::$logger->error("delete_dbrecord ($sql) ERROR: " . $dbh->is_error());
            throw new \Exception($dbh->is_error());
        }

        return $dbh->affected_rows($sql_result);
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

    /**
     * Creates the AddressbookCollection object of the CardDavClient library.
     *
     * This should only be called when interaction with the server is needed, as creation
     * of the object already involves communication with the server to query the properties
     * of the addressbook collection.
     */
    private function createCardDavObj(): void
    {
        if (!isset($this->davAbook)) {
            $url = $this->config["url"];
            $account = new Account(
                $url,
                $this->config["username"],
                carddav_common::decrypt_password($this->config["password"]),
                $url
            );
            $this->davAbook = new AddressbookCollection($url, $account);
        }
    }

    private static function carddavconfig($abookid)
    {
        try {
            $dbh = rcmail::get_instance()->db;

            // cludge, agreed, but the MDB abstraction seems to have no way of
            // doing time calculations...
            $timequery = '(' . $dbh->now() . ' > ';
            if ($dbh->db_provider === 'sqlite') {
                $timequery .= ' datetime(last_updated,refresh_time))';
            } elseif ($dbh->db_provider === 'mysql') {
                $timequery .= ' date_add(last_updated, INTERVAL refresh_time HOUR_SECOND))';
            } else {
                $timequery .= ' last_updated+refresh_time)';
            }

            $abookrow = self::get_dbrecord(
                $abookid,
                'id as abookid,name,username,password,url,presetname,sync_token,authentication_scheme,'
                . $timequery . ' as needs_update',
                'addressbooks'
            );

            if ($dbh->db_provider === 'postgres') {
                // postgres will return 't'/'f' here for true/false, normalize it to 1/0
                $nu = $abookrow['needs_update'];
                $nu = ($nu == 1 || $nu == 't') ? 1 : 0;
                $abookrow['needs_update'] = $nu;
            }
        } catch (\Exception $e) {
            carddav::$logger->error("Error in processing configuration $abookid: " . $e->getMessage());
            return false;
        }

        return $abookrow;
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
        $records = self::get_dbrecord($groupid, 'contact_id', 'group_user', false, 'group_id');
        return array_map(function ($v) {
            return $v["contact_id"];
        }, $records);
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
        foreach ($contact_ids as $contact_id) {
            $contact = self::get_dbrecord($contact_id, 'id,uri,etag,vcard');
            $vcard = $this->parseVCard($contact['vcard']);
            $groups = isset($vcard->CATEGORIES) ? $vcard->CATEGORIES->getParts() : [];

            if ($callback($groups, $contact_id) !== false) {
                if (count($groups) > 0) {
                    $vcard->CATEGORIES = $groups;
                } else {
                    unset($vcard->CATEGORIES);
                }
                $this->davAbook->updateCard($contact['uri'], $vcard, $contact['etag']);
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
