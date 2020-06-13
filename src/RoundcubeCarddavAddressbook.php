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
    // currently active search filter
    private $filter;

    private $result;
    // configuration of the addressbook
    private $config;
    // custom labels defined in the addressbook
    private $xlabels;

    const SEPARATOR = ',';

    // total number of contacts in address book
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

        if($this->config['presetname']) {
            $prefs = carddav_common::get_adminsettings();
            if($prefs[$this->config['presetname']]['readonly'])
                $this->readonly = true;
        }

        // refresh the address book if the update interval expired
        // this requires a completely initialized RoundcubeCarddavAddressbook object, so it
        // needs to be at the end of this constructor
        if ($this->config["needs_update"]){
            $this->refreshdb_from_server();
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
        $sql_result = $dbh->query('INSERT INTO ' .
            $dbh->table_name('carddav_xsubtypes') .
            ' (typename,subtype,abook_id) VALUES (?,?,?)',
            $typename, $subtype, $this->id);
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

        foreach($this->coltypes as $k => $v) {
            if(key_exists('subtypes', $v)) {
                $this->xlabels[$k] = [];
            }
        }

        // read extra subtypes
        $xtypes = self::get_dbrecord($this->id,'typename,subtype','xsubtypes',false,'abook_id');

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
        if(strcasecmp($save_data['showas'], 'COMPANY') == 0 && strlen($save_data['organization'])>0) {
            $save_data['name']     = $save_data['organization'];
        }

        // we need a displayname; if we do not have one, try to make one up
        if(strlen($save_data['name']) == 0) {
            $dname = [];
            if(strlen($save_data['firstname'])>0)
                $dname[] = $save_data['firstname'];
            if(strlen($save_data['surname'])>0)
                $dname[] = $save_data['surname'];

            if(count($dname) > 0) {
                $save_data['name'] = implode(' ', $dname);

            } else { // no name? try email and phone
                $ep_keys = array_keys($save_data);
                $ep_keys = preg_grep(";^(email|phone):;", $ep_keys);
                sort($ep_keys, SORT_STRING);
                foreach($ep_keys as $ep_key) {
                    $ep_vals = $save_data[$ep_key];
                    if(!is_array($ep_vals)) $ep_vals = array($ep_vals);

                    foreach($ep_vals as $ep_val) {
                        if(strlen($ep_val)>0) {
                            $save_data['name'] = $ep_val;
                            break 2;
                        }
                    }
                }
            }

            // still no name? set to unknown and hope the user will fix it
            if(strlen($save_data['name']) == 0)
                $save_data['name'] = 'Unset Displayname';
        }
    }

    /**
     * Stores a group vcard in the database.
     *
     * @param string etag of the VCard in the given version on the CardDAV server
     * @param string path to the VCard on the CardDAV server
     * @param string string representation of the VCard
     * @param array  associative array containing at least name and cuid (card UID)
     * @param int    optionally, database id of the group if the store operation is an update
     *
     * @return int  The database id of the created or updated card, false on error.
     */
    public function dbstore_group($etag, $uri, $vcfstr, $save_data, $dbid = null)
    {
        return $this->dbstore_base('groups', $etag, $uri, $vcfstr, $save_data, $dbid);
    }

    private function dbstore_base($table, $etag, $uri, $vcfstr, $save_data, $dbid, $xcol = [], $xval = [])
    {
        $dbh = rcmail::get_instance()->db;

        // get rid of the %u placeholder in the URI, otherwise the refresh operation
        // will not be able to match local cards with those provided by the server
        $username = $this->config['username'];
        if($username === "%u") {
            $username = $_SESSION['username'];
        }
        $uri = str_replace("%u", $username, $uri);

        $xcol[] = 'name';  $xval[] = $save_data['name'];
        $xcol[] = 'etag';  $xval[] = $etag;
        $xcol[] = 'vcard'; $xval[] = $vcfstr;

        if(isset($dbid)) {
            carddav::$logger->debug("UPDATE card $uri");
            $xval[]=$dbid;
            $sql_result = $dbh->query('UPDATE ' .
                $dbh->table_name("carddav_$table") .
                ' SET ' . implode('=?,', $xcol) . '=?' .
                ' WHERE id=?', $xval);
        } else {
            carddav::$logger->debug("INSERT card $uri");

            if (empty($save_data['cuid'])) {
                carddav::$logger->error("Cannot insert - card $uri lacks UID property");
                return false;
            }

            $xcol[] = 'abook_id'; $xval[] = $this->id;
            $xcol[] = 'uri';      $xval[] = $uri;
            $xcol[] = 'cuid';     $xval[] = $save_data['cuid'];

            $sql_result = $dbh->query('INSERT INTO ' .
                $dbh->table_name("carddav_$table") .
                ' (' . implode(',',$xcol) . ') VALUES (?' . str_repeat(',?', count($xcol)-1) .')',
                $xval);

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
     * @return int  The database id of the created or updated card, false on error.
     */
    public function dbstore_contact($etag, $uri, $vcfstr, $save_data, $dbid = null)
    {
        $this->preprocess_rc_savedata($save_data);
        // build email search string
        $email_keys = preg_grep('/^email(:|$)/', array_keys($save_data));
        $email_addrs = [];
        foreach($email_keys as $email_key) {
            $email_addrs = array_merge($email_addrs, (array) $save_data[$email_key]);
        }
        $save_data['email']	= implode(', ', $email_addrs);

        // extra columns for the contacts table
        $xcol_all=array('firstname','surname','organization','showas','email');
        $xcol = [];
        $xval = [];
        foreach($xcol_all as $k) {
            if(key_exists($k,$save_data)) {
                $xcol[] = $k;
                $xval[] = $save_data[$k];
            }
        }

        return $this->dbstore_base('contacts', $etag, $uri, $vcfstr, $save_data, $dbid, $xcol, $xval);
    }

    /**
     * Synchronizes the local card store with the CardDAV server.
     */
    public function refreshdb_from_server()
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

            $duration = time() - $start_refresh;
            carddav::$logger->debug("server refresh took $duration seconds");

            $rcmail->output->show_message(
                $this->frontend->gettext(array('name'=>'cd_msg_synchronized', 'vars' => array(
                    'name' => $this->get_name(),
                    'duration' => $duration,
                )
            )));

            // set last_updated timestamp
            $dbh->query('UPDATE ' .
                $dbh->table_name('carddav_addressbooks') .
                ' SET last_updated=' . $dbh->now() .' WHERE id=?',
                $this->id);
        } catch (\Exception $e) {
            carddav::$logger->error("Errors occurred during the refresh of addressbook " . $this->id . ": $e");
        }
    }

    /**
     * List the current set of contact records
     *
     * @param array $cols   List of cols to show (null means all)
     * @param int   $subset Only return this number of records, use negative values for tail
     *
     * @return array Indexed list of contact records, each a hash array
     */
    public function list_records($cols = null, $subset = 0)
    {
        // refresh from server if refresh interval passed
        if ($this->config['needs_update'] == 1) {
            $this->refreshdb_from_server();
        }

        $this->result = $this->count();

        $records = $this->list_records_readdb($cols,$subset);
        if ($this->list_page <= 1) {
            if ($records < $this->page_size && $subset == 0) {
                $this->result->count = $records;
            } else {
                $this->result->count = $this->_count();
            }
        }

        return ($records > 0) ? $this->result : false;
    }

    private function list_records_readdb($cols, $subset=0, $count_only=false)
    {
        $dbh = rcmail::get_instance()->db;

        // true if we can use DB filtering or no filtering is requested
        $filter = $this->get_search_set();
        $this->determine_filter_params($cols,$subset, $firstrow, $numrows, $read_vcard);

        carddav::$logger->debug("list_records_readdb " . (isset($cols) ? implode(",", $cols) : "ALL") . " $read_vcard");

        $dbattr = $read_vcard ? 'vcard' : 'firstname,surname,email';

        $limit_index = $firstrow;
        $limit_rows  = $numrows;

        $xfrom = '';
        $xwhere = '';
        if($this->group_id) {
            $xfrom = ',' . $dbh->table_name('carddav_group_user');
            $xwhere = ' AND id=contact_id AND group_id=' . $dbh->quote($this->group_id) . ' ';
        }

        if ($this->config['presetname']){
            $prefs = carddav_common::get_adminsettings();
            if (key_exists("require_always", $prefs[$this->config['presetname']])){
                foreach ($prefs[$this->config['presetname']]["require_always"] as $col){
                    $xwhere .= " AND $col <> ".$dbh->quote('')." ";
                }
            }
        }

        // Workaround for Roundcube versions < 0.7.2
        $sort_column = $this->sort_col ? $this->sort_col : 'surname';
        $sort_order  = $this->sort_order ? $this->sort_order : 'ASC';

        $sql_result = $dbh->limitquery("SELECT id,name,$dbattr FROM " .
            $dbh->table_name('carddav_contacts') . $xfrom .
            ' WHERE abook_id=? ' . $xwhere .
            ($this->filter ? " AND (".$this->filter.")" : "") .
            " ORDER BY (CASE WHEN showas='COMPANY' THEN organization ELSE " . $sort_column . " END) "
            . $sort_order,
            $limit_index,
            $limit_rows,
            $this->id
        );

        $addresses = [];
        while($contact = $dbh->fetch_assoc($sql_result)) {
            if($read_vcard) {
                try {
                    $vcf = VObject\Reader::read($contact['vcard'], VObject\Reader::OPTION_FORGIVING);
                    $save_data = $this->create_save_data_from_vcard($vcf);
                } catch (\Exception $e) {
                    $save_data = false;
                }

                if (!$save_data){
                    carddav::$logger->warning("Couldn't parse vcard ".$contact['vcard']);
                    continue;
                }

                // needed by the calendar plugin
                if(is_array($cols) && in_array('vcard', $cols)) {
                    $save_data['save_data']['vcard'] = $contact['vcard'];
                }

                $save_data = $save_data['save_data'];
            } else {
                $save_data = [];
                foreach	($cols as $col) {
                    if(strcmp($col,'email')==0)
                        $save_data[$col] = preg_split('/,\s*/', $contact[$col]);
                    else
                        $save_data[$col] = $contact[$col];
                }
            }
            $addresses[] = array('ID' => $contact['id'], 'name' => $contact['name'], 'save_data' => $save_data);
        }

        if(!$count_only) {
            // create results for roundcube
            foreach($addresses as $a) {
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
    function search($fields, $value, $mode=0, $select=true, $nocount=false, $required = [])
    {
        $dbh = rcmail::get_instance()->db;
        if (!is_array($fields))
            $fields = array($fields);
        if (!is_array($required) && !empty($required))
            $required = array($required);

        $where = $and_where = [];
        $mode = intval($mode);
        $WS = ' ';
        $AS = self::SEPARATOR;

        // build the $where array; each of its entries is an SQL search condition
        foreach ($fields as $idx => $col) {
            // direct ID search
            if ($col == 'ID' || $col == $this->primary_key) {
                $ids     = !is_array($value) ? explode(self::SEPARATOR, $value) : $value;
                $ids     = $dbh->array2list($ids, 'integer');
                $where[] = $this->primary_key.' IN ('.$ids.')';
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
                foreach (explode(" ", self::normalize_string($val)) as $word) {
                    if ($mode & 1) {
                        // strict
                        $words[] = '(' . $dbh->ilike('vcard', $word . $WS . '%')
                            . ' OR ' . $dbh->ilike('vcard', '%' . $AS . $WS . $word . $WS .'%')
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
                if (is_array($value))
                    $post_search[$col] = mb_strtolower($val);
            }
        }

        if ($this->config['presetname']){
            $prefs = carddav_common::get_adminsettings();
            if (key_exists("require_always", $prefs[$this->config['presetname']])){
                $required = array_merge($prefs[$this->config['presetname']]["require_always"], $required);
            }
        }

        foreach (array_intersect($required, $this->table_cols) as $col) {
            $and_where[] = $dbh->quoteIdentifier($col).' <> '.$dbh->quote('');
        }

        if (!empty($where)) {
            // use AND operator for advanced searches
            $where = join(is_array($value) ? ' AND ' : ' OR ', $where);
        }

        if (!empty($and_where))
            $where = ($where ? "($where) AND " : '') . join(' AND ', $and_where);

        // Post-searching in vCard data fields
        // we will search in all records and then build a where clause for their IDs
        if (!empty($post_search)) {
            $ids = array(0);
            // build key name regexp
            $regexp = '/^(' . implode(array_keys($post_search), '|') . ')(?:.*)$/';
            // use initial WHERE clause, to limit records number if possible
            if (!empty($where))
                $this->set_search_set($where);

            // count result pages
            $cnt   = $this->count();
            $pages = ceil($cnt / $this->page_size);
            $scnt  = count($post_search);

            // get (paged) result
            for ($i=0; $i<$pages; $i++) {
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
            $where = $this->primary_key.' IN ('.$ids.')';

            // when we know we have an empty result
            if ($ids == '0') {
                $this->set_search_set($where);
                return ($this->result = new rcube_result_set(0, 0));
            }
        }

        if (!empty($where)) {
            $this->set_search_set($where);
            if ($select)
                $this->list_records(null, 0, $nocount);
            else
                $this->result = $this->count();
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
        if($this->total_cards < 0) {
            $this->_count();
        }
        return new rcube_result_set($this->total_cards, ($this->list_page-1) * $this->page_size);
    }

    // Determines and returns the number of cards matching the current search criteria
    private function _count()
    {
        if($this->total_cards < 0) {
            $dbh = rcmail::get_instance()->db;

            $sql_result = $dbh->query('SELECT COUNT(id) as total_cards FROM ' .
                $dbh->table_name('carddav_contacts') .
                ' WHERE abook_id=?' .
                ($this->filter ? " AND (".$this->filter.")" : ""),
                $this->id
            );

            $resultrow = $dbh->fetch_assoc($sql_result);
            $this->total_cards = $resultrow['total_cards'];
        }
        return $this->total_cards;
    }

    private function determine_filter_params($cols, $subset, &$firstrow, &$numrows, &$read_vcard)
    {
        // determine whether we have to parse the vcard or if only db cols are requested
        $read_vcard = (!isset($cols)) || (count(array_intersect($cols, $this->table_cols)) < count($cols));

        // determine result subset needed
        $firstrow = ($subset>=0) ?
            $this->result->first : ($this->result->first+$this->page_size+$subset);
        $numrows  = $subset ? abs($subset) : $this->page_size;
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

    /**
     * Return the last result set
     *
     * @return rcube_result_set Current result set or NULL if nothing selected yet
     */
    private function get_record_from_carddav($uid)
    {
        $this->createCardDavObj();

        try {
            $result = $this->davAbook->getCard($uid);
        } catch (\Exception $e) {
            carddav::$logger->warning("Failed to fetch VCard '$uid': " . $e->getMessage());
            return false;
        }

        return $result;
    }

    private function parseVCard(string $vcf): VCard
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
            carddav::$logger->debug("get_record $id (return assoc: $assoc)");
            $this->result = $this->count();

            $contact = self::get_dbrecord($id, 'vcard');
            if(!$contact) {
                carddav::$logger->error("contact with database id $id not found");
                return false;
            }

            $vcard = $this->parseVCard($contact['vcard']);

            $retval = $this->create_save_data_from_vcard($vcard);
            if(!$retval) {
                carddav::$logger->error("failed to create save_data from contact (DB id: $id)");
                return false;
            }
            $vcfobj = $retval['vcf'];
            $retval = $retval['save_data'];
            $retval['__vcf'] = $vcfobj;

            $retval['ID'] = $id;
            $this->result->add($retval);
            $sql_arr = $assoc && $this->result ? $this->result->first() : null;
            return $assoc && $sql_arr ? $sql_arr : $this->result;
        } catch (\Exception $e) {
            carddav::$logger->error("Could not get contact $id: " . $e->getMessage());
            return false;
        }
    }

    private function put_record_to_carddav($id, $vcf, $etag='')
    {
        $this->result = $this->count();
        $matchhdr = $etag ?
            "If-Match: $etag" :
            "If-None-Match: *";

        $opts = array(
            'method'=>"PUT",
            'content'=>$vcf,
            'header'=> array(
                "Content-Type: text/vcard; charset=\"utf-8\"",
                $matchhdr,
            ),
        );
        $reply = carddav_common::cdfopen($id, $opts, $this->config);
        if($this->check_http_status($reply)) {
            $etag = $reply["headers"]["etag"];
            if ("$etag" == ""){
                // Server did not reply an etag
                $retval = $this->get_record_from_carddav($id);
                carddav::$logger->debug(var_export($retval, true));
                $etag = $retval["etag"];
            }
            return $etag;
        }

        return false;
    }

    private function delete_record_from_carddav($id)
    {
        $this->result = $this->count();
        $opts = array( 'method'=>"DELETE" );
        $reply = carddav_common::cdfopen($id, $opts, $this->config);
        if($this->check_http_status($reply) && ($reply["status"] == 204 || $reply["status"] == 200)) {
            return true;
        }
        return false;
    }

    private function guid()
    {
        return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    /**
     * Creates a new or updates an existing vcard from save data.
     */
    private function create_vcard_from_save_data($save_data, $vcf=null)
    {
        unset($save_data['vcard']);
        if(!$vcf) { // create fresh minimal vcard
            $vcf = new VObject\Component\VCard(
                array(
                    'REV' => gmdate("Y-m-d\TH:i:s\Z"),
                    'VERSION' => '3.0'
                )
            );
        } else { // update revision
            $vcf->REV = gmdate("Y-m-d\TH:i:s\Z");
        }

        // N is mandatory
        if(key_exists('kind',$save_data) && $save_data['kind'] === 'group') {
            $vcf->N = $save_data['name'];
        } else {
            $vcf->N = array(
                $save_data['surname'],
                $save_data['firstname'],
                $save_data['middlename'],
                $save_data['prefix'],
                $save_data['suffix'],
            );
        }

        $new_org_value = [];
        if (key_exists("organization", $save_data) &&
            strlen($save_data['organization']) > 0 ){
            $new_org_value[] = $save_data['organization'];
        }

        if (key_exists("department", $save_data)){
            if (is_array($save_data['department'])){
                foreach ($save_data['department'] as $key => $value) {
                    $new_org_value[] = $value;
                }
            } else if (strlen($save_data['department']) > 0){
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
                    $save_data[$key] = date('Y-m-d',$val);
                }
            }
        }

        if (key_exists('photo', $save_data) && strlen($save_data['photo']) > 0 && base64_decode($save_data['photo'], true) !== FALSE){
            carddav::$logger->debug("photo is base64 encoded. Decoding...");
            $i=0;
            while(base64_decode($save_data['photo'], true)!==FALSE && $i++ < 10){
                carddav::$logger->debug("Decoding $i...");
                $save_data['photo'] = base64_decode($save_data['photo'], true);
            }
            if ($i >= 10){
                carddav::$logger->warning("PHOTO of ".$save_data['uid']." does not decode after 10 attempts...");
            }
        }

        // process all simple attributes
        foreach ($this->vcf2rc['simple'] as $vkey => $rckey){
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
        foreach ($this->vcf2rc['multi'] as $vkey => $rckey){
            // delete and fully recreate all entries
            // there is no easy way of mapping an address in the existing card
            // to an address in the save data, as subtypes may have changed
            unset($vcf->{$vkey});

            $stmap = array( $rckey => 'other' );
            foreach ($this->coltypes[$rckey]['subtypes'] AS $subtype){
                $stmap[ $rckey.':'.$subtype ] = $subtype;
            }

            foreach ($stmap as $rcqkey => $subtype){
                if(key_exists($rcqkey, $save_data)) {
                    $avalues = is_array($save_data[$rcqkey]) ? $save_data[$rcqkey] : array($save_data[$rcqkey]);
                    foreach($avalues as $evalue) {
                        if (strlen($evalue) > 0){
                            $prop = $vcf->add($vkey, $evalue);
                            $this->set_attr_label($vcf, $prop, $rckey, $subtype); // set label
                        }
                    }
                }
            }
        }

        // process address entries
        unset($vcf->ADR);
        foreach ($this->coltypes['address']['subtypes'] AS $subtype){
            $rcqkey = 'address:'.$subtype;

            if(key_exists($rcqkey, $save_data)) {
                foreach($save_data[$rcqkey] as $avalue) {
                    if ( strlen($avalue['street'])
                        || strlen($avalue['locality'])
                        || strlen($avalue['region'])
                        || strlen($avalue['zipcode'])
                        || strlen($avalue['country'])) {

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
        if(in_array($newlabel, $this->xlabels[$attrname])) {
            if(!$group) {
                do {
                    $group = $this->guid();
                } while (null !== $vcard->{$group . '.X-ABLabel'});

                $pvalue->group = $group;

                // delete standard label if we had one
                $oldlabel = $pvalue['TYPE'];
                if(strlen($oldlabel)>0 &&
                    in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])) {
                    unset($pvalue['TYPE']);
                }
            }

            $vcard->{$group . '.X-ABLabel'} = $newlabel;
            return true;
        }

        // Standard Label
        $had_xlabel = false;
        if($group) { // delete group label property if present
            $had_xlabel = isset($vcard->{$group . '.X-ABLabel'});
            unset($vcard->{$group . '.X-ABLabel'});
        }

        // add or replace?
        $oldlabel = $pvalue['TYPE'];
        if(strlen($oldlabel)>0 &&
            in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])) {
            $had_xlabel = false; // replace
        }

        if($had_xlabel &&is_array($pvalue['TYPE'])) {
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

        if(isset($pvalue['TYPE'])) {
            foreach($pvalue['TYPE'] as $type)
            {
                $type = strtolower($type);
                if(is_array($this->coltypes[$attrname]['subtypes']) && in_array($type, $this->coltypes[$attrname]['subtypes']) )
                {
                    $fallback = $type;
                    if(!(is_array($this->fallbacktypes[$attrname])
                        && in_array($type, $this->fallbacktypes[$attrname])))
                    {
                        return $type;
                    }
                }
            }
        }

        if($fallback) { return $fallback; }

        // check for a custom label using Apple's X-ABLabel extension
        $group = $pvalue->group;
        if($group) {
            $xlabel = $vcard->{$group . '.X-ABLabel'};
            if($xlabel) {
                $xlabel = $xlabel->getParts();
                if($xlabel)
                    $xlabel = $xlabel[0];
            }

            // strange Apple label that I don't know to interpret
            if(strlen($xlabel)<=0) {
                return 'other';
            }

            if(preg_match(';_\$!<(.*)>!\$_;', $xlabel, $matches)) {
                $match = strtolower($matches[1]);
                if(in_array($match, $this->coltypes[$attrname]['subtypes']))
                    return $match;
                return 'other';
            }

            // add to known types if new
            if(!in_array($xlabel, $this->coltypes[$attrname]['subtypes'])) {
                $this->storeextrasubtype($attrname, $xlabel);
                $this->coltypes[$attrname]['subtypes'][] = $xlabel;
            }
            return $xlabel;
        }

        return 'other';
    }

    private function download_photo(&$save_data)
    {
        try {
            $uri = $save_data['photo'];
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
     * @return mixed  false on failure, otherwise associative array with keys:
     *           - save_data:    Roundcube representation of the VCard
     *           - vcf:          VCard object created from the given VCard
     *           - needs_update: boolean that indicates whether the card was modified
     */
    public function create_save_data_from_vcard($vcard)
    {
        $needs_update=false;
        $save_data = array(
            // DEFAULTS
            'kind'   => 'individual',
        );

        foreach ($this->vcf2rc['simple'] as $vkey => $rckey){
            $property = $vcard->{$vkey};
            if ($property !== null){
                $p = $property->getParts();
                $save_data[$rckey] = $p[0];
            }
        }

        // inline photo if external reference
        if(key_exists('photo', $save_data)) {
            $kind = $vcard->PHOTO['VALUE'];
            if($kind && strcasecmp('uri', $kind)==0) {
                if($this->download_photo($save_data)) {
                    $props = [];
                    foreach ($vcard->PHOTO->parameters() as $property => $value) {
                        if(strcasecmp($property, 'VALUE') != 0) {
                            $props[$property] = $value;
                        }
                    }
                    $props['ENCODING'] = 'b';
                    unset($vcard->PHOTO);
                    $vcard->add('PHOTO', $save_data['photo'], $props);
                    $needs_update=true;
                }
            }
            self::xabcropphoto($vcard, $save_data);
        }

        $property = $vcard->N;
        if ($property !== null){
            $N = $property->getParts();
            switch(count($N)){
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
        if ($property){
            $ORG = $property->getParts();
            $save_data['organization'] = $ORG[0];
            for ($i = 1; $i <= count($ORG); $i++){
                $save_data['department'][] = $ORG[$i];
            }
        }

        foreach ($this->vcf2rc['multi'] as $key => $value){
            $property = $vcard->{$key};
            if ($property !== null) {
                foreach ($property as $property_instance){
                    $p = $property_instance->getParts();
                    $label = $this->get_attr_label($vcard, $property_instance, $value);
                    $save_data[$value.':'.$label][] = $p[0];
                }
            }
        }

        $property = ($vcard->ADR) ?: [];
        foreach ($property as $property_instance){
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
            $save_data['address:'.$label][] = $adr;
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
    public function insert($save_data, $check=false)
    {
        try {
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
        if(!$save_data['surname'] && !$save_data['firstname']
            && $save_data['organization'] && !key_exists('showas',$save_data)) {
            $save_data['showas'] = 'COMPANY';
        }
        if(!key_exists('showas',$save_data)) {
            $save_data['showas'] = 'INDIVIDUAL';
        }
        // organization not set but showas==company => show as regular
        if(!$save_data['organization'] && $save_data['showas']==='COMPANY') {
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
            $save_data['showas'] = $contact['showas'];
            $this->preprocess_rc_savedata($save_data);
            $this->createCardDavObj();

            // get current DB data
            $contact = self::get_dbrecord($id,'id,cuid,uri,etag,vcard,showas');
            if($contact === false) {
                throw new \Exception("Contact $id to update not found in database");
            }


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
        $deleted = 0;

        try {
            $this->createCardDavObj();
            foreach ($ids as $dbid) {
                $contact = self::get_dbrecord($dbid,'uri');
                if($contact === false) {
                    carddav::$logger->error("Record $dbid requested for deletion not found in database");
                } else {
                    // delete contact from all groups it is contained in
                    $groups = $this->get_record_groups($dbid);
                    foreach($groups as $group_id => $grpname) {
                        $this->remove_from_group($group_id, $dbid);
                    }

                    $this->davAbook->deleteCard($contact['uri']);
                    ++$deleted;
                }
            }
            $this->refreshdb_from_server();
        } catch (\Exeption $e) {
            $this->set_error(rcube_addressbook::ERROR_SAVING, $e->getMessage());
            carddav::$logger->error("Failed to update contact $id: " . $e->getMessage());
        }

        return $deleted;
    }

    private function update_contact_categories($id,$vcf) {
        $groups = $this->get_record_groups($id);

        if($vcf->{'CATEGORY'}) {
            $cat_name = "CATEGORY";
        } else {
            $cat_name = "CATEGORIES";
        }
        unset($vcf->{$cat_name});
        $categories = [];
        foreach($groups as $group_id => $grpname) {
            $categories[] = $grpname;
        }
        $vcf->{$cat_name} = $categories;
    }

    private function update_contacts($ids) {
        foreach ($ids as $id) {
            $contact = self::get_dbrecord($id,'id,cuid,uri,etag,vcard,showas');
            if(!$contact) return false;

            try {
                $vcf = VObject\Reader::read($contact['vcard'], VObject\Reader::OPTION_FORGIVING);
            } catch (\Exception $e) {
                carddav::$logger->warning("Update: Couldn't parse local vcard: ".$contact['vcard']);
                return false;
            }

            $this->update_contact_categories($id,$vcf);

            $vcfstr = $vcf->serialize();

            $save_data_arr = $this->create_save_data_from_vcard($vcf);
            $save_data = $save_data_arr['save_data'];

            // complete save_data
            $save_data['showas'] = $contact['showas'];
            $this->preprocess_rc_savedata($save_data);

            if(!($etag=$this->put_record_to_carddav($contact['uri'], $vcfstr, $contact['etag']))) {
                carddav::$logger->warning("Updating card on server failed");
                return false;
            }
            $id = $this->dbstore_contact($etag,$contact['uri'],$vcfstr,$save_data,$id);

        }
        return true;
    }

    /**
     * Add the given contact records the a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be added
     * @return int    Number of contacts added
     */
    public function add_to_group($group_id, $ids)
    {
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }

        // get current DB data
        $group = self::get_dbrecord($group_id,'uri,etag,vcard,name,cuid','groups');
        if(!$group)	return false;

        // get current DB data
        $group = self::get_dbrecord($group_id,'uri,etag,vcard,name,cuid','groups');
        if(!$group)	return false;

        // create vcard from current DB data to be updated with the new data
        try {
            $vcf = VObject\Reader::read($group['vcard'], VObject\Reader::OPTION_FORGIVING);
        } catch (\Exception $e) {
            carddav::$logger->warning("Update: Couldn't parse local group vcard: ".$group['vcard']);
            return false;
        }

        foreach ($ids as $cid) {
            $contact = self::get_dbrecord($cid,'cuid');
            if(!$contact) return false;

            $vcf->add('X-ADDRESSBOOKSERVER-MEMBER', "urn:uuid:" . $contact['cuid']);
        }

        $vcfstr = $vcf->serialize();
        if(!($etag = $this->put_record_to_carddav($group['uri'], $vcfstr, $group['etag'])))
            return false;

        if(!$this->dbstore_group($etag,$group['uri'],$vcfstr,$group,$group_id))
            return false;

        $dbh = rcmail::get_instance()->db;
        foreach ($ids as $cid) {
            $dbh->query('INSERT INTO ' .
                $dbh->table_name('carddav_group_user') .
                ' (group_id,contact_id) VALUES (?,?)',
                $group_id, $cid);
        }

        return $added;
    }

    /**
     * Remove the given contact records from a certain group
     *
     * @param string  Group identifier
     * @param array   List of contact identifiers to be removed
     * @return int    Number of deleted group members
     */
    public function remove_from_group($group_id, $ids)
    {
        if (!is_array($ids))
            $ids = explode(',', $ids);
        // get current DB data
        $group = self::get_dbrecord($group_id,'name,cuid,uri,etag,vcard','groups');
        if(!$group)	return false;

        // create vcard from current DB data to be updated with the new data
        try {
            $vcf = VObject\Reader::read($group['vcard'], VObject\Reader::OPTION_FORGIVING);
        } catch (\Exception $e) {
            carddav::$logger->warning("Update: Couldn't parse local group vcard: ".$group['vcard']);
            return false;
        }

        $deleted = 0;
        foreach ($ids as $cid) {
            $contact = self::get_dbrecord($cid,'cuid');
            if(!$contact) return false;

            $search_for = 'urn:uuid:' . $contact['cuid'];
            foreach ($vcf->{'X-ADDRESSBOOKSERVER-MEMBER'} as $member) {
                if ($member == $search_for) {
                    $vcf->remove($member);
                    break;
                }
            }
            $deleted++;
        }

        $vcfstr = $vcf->serialize();
        if(!($etag = $this->put_record_to_carddav($group['uri'], $vcfstr, $group['etag'])))
            return false;
        if(!$this->dbstore_group($etag,$group['uri'],$vcfstr,$group,$group_id))
            return false;

        $deleted = self::delete_dbrecord($ids,'group_user','contact_id', array('group_id' => $group_id));
        return $deleted;
    }

    /**
     * Get group assignments of a specific contact record
     *
     * @param mixed Record identifier
     *
     * @return array List of assigned groups as ID=>Name pairs
     * @since 0.5-beta
     */
    public function get_record_groups($id)
    {
        $dbh = rcmail::get_instance()->db;
        $sql_result = $dbh->query('SELECT id,name FROM '.
            $dbh->table_name('carddav_groups') . ',' .
            $dbh->table_name('carddav_group_user') .
            ' WHERE id=group_id AND contact_id=?', $id);

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
        $this->group_id = $gid;
        $this->total_cards = -1;
        if ($gid) {
            $dbh = rcmail::get_instance()->db;
            $this->filter = "EXISTS(SELECT * FROM ".$dbh->table_name("carddav_group_user")."
                WHERE group_id = '{$gid}' AND contact_id = ".$dbh->table_name("carddav_contacts").".id)";
        } else {
            $this->filter = '';
        }
    }

    /**
     * Get group properties such as name and email address(es)
     *
     * @param string Group identifier
     * @return array Group properties as hash array
     */
    function get_group($group_id)
    {
        $dbh = rcmail::get_instance()->db;

        $sql_result = $dbh->query('SELECT * FROM '.
            $dbh->table_name('carddav_groups').
            ' WHERE id = ?', $group_id);

        if ($sql_result && ($sql_arr = $dbh->fetch_assoc($sql_result))) {
            return $sql_arr;
        }

        return null;
    }

    /**
     * List all active contact groups of this source
     *
     * @param string  Optional search string to match group name
     * @param int     Search mode. Sum of self::SEARCH_* (>= 1.2.3)
     *                0 - partial (*abc*),
     *                1 - strict (=),
     *                2 - prefix (abc*)
     *
     * @return array  Indexed list of contact groups, each a hash array
     */
    public function list_groups($search = null, $mode = 0)
    {
        $dbh = rcmail::get_instance()->db;

        $searchextra = "";
        if ($search !== null){
            if ($mode & 1) {
                $searchextra = $dbh->ilike('name', $search);
            } elseif ($mode & 2) {
                $searchextra = $dbh->ilike('name',"$search%");
            } else {
                $searchextra = $dbh->ilike('name',"%$search%");
            }
            $searchextra = ' AND ' . $searchextra;
        }

        $sql_result = $dbh->query('SELECT id,name from ' .
            $dbh->table_name('carddav_groups') .
            ' WHERE abook_id=?' .
            $searchextra .
            ' ORDER BY name ASC',
            $this->id);

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
     * @param string The group name
     * @return mixed False on error, array with record props in success
     */
    public function create_group($name)
    {
        $cuid = $this->find_free_uid();
        $uri = "$cuid.vcf";

        $save_data = array(
            'name' => $name,
            'kind' => 'group',
            'cuid' => $cuid,
        );

        $vcf = $this->create_vcard_from_save_data($save_data);
        if (!$vcf) return false;
        $vcfstr = $vcf->serialize();
        if (!($etag = $this->put_record_to_carddav($uri, $vcfstr)))
            return false;

        $url = carddav_common::concaturl($this->config['url'], $uri);
        $url = preg_replace(';https?://[^/]+;', '', $url);
        if(!($dbid = $this->dbstore_group($etag,$url,$vcfstr,$save_data)))
            return false;

        return array('id'=>$dbid, 'name'=>$name);
    }

    /**
     * Delete the given group and all linked group members
     *
     * @param string Group identifier
     * @return boolean True on success, false if no data was changed
     */
    public function delete_group($group_id)
    {
        $ids = null;
        // get current DB data
        $group = self::get_dbrecord($group_id,'uri','groups');
        if(!$group)	return false;

        if($this->delete_record_from_carddav($group['uri'])) {
            self::delete_dbrecord($group_id, 'groups');
            self::delete_dbrecord($group_id, 'group_user', 'group_id');
            return true;
        }

        return false;
    }

    /**
     * Rename a specific contact group
     *
     * @param string Group identifier
     * @param string New name to set for this group
     * @param string New group identifier (if changed, otherwise don't set)
     * @return boolean New name on success, false if no data was changed
     */
    public function rename_group($group_id, $newname, &$newid)
    {
        // get current DB data
        $group = self::get_dbrecord($group_id,'uri,etag,vcard,name,cuid','groups');
        if(!$group)	return false;
        $group['name'] = $newname;
        // create vcard from current DB data to be updated with the new data

        // create vcard from current DB data to be updated with the new data
        try {
            $vcf = VObject\Reader::read($group['vcard'], VObject\Reader::OPTION_FORGIVING);
        } catch (\Exception $e) {
            carddav::$logger->warning("Update: Couldn't parse local group vcard: ".$group['vcard']);
            return false;
        }

        $vcf->FN = $newname;
        $vcf->N = $newname;
        $vcfstr = $vcf->serialize();

        if(!($etag = $this->put_record_to_carddav($group['uri'], $vcfstr, $group['etag'])))
            return false;

        if(!$this->dbstore_group($etag,$group['uri'],$vcfstr,$group,$group_id))
            return false;

        return $newname;
    }

    public static function get_dbrecord($id, $cols='*', $table='contacts', $retsingle=true, $idfield='id', $other_conditions = [])
    {
        $dbh = rcmail::get_instance()->db;

        $idfield = $dbh->quoteIdentifier($idfield);
        $id = $dbh->quote($id);
        $sql = "SELECT $cols FROM " . $dbh->table_name("carddav_$table") . ' WHERE ' . $idfield . '=' . $id;

        // Append additional conditions
        foreach ($other_conditions as $field => $value) {
            $sql .= ' AND ' . $dbh->quoteIdentifier($field) . ' = ' . $dbh->quote($value);
        }

        $sql_result = $dbh->query($sql);

        // single row requested?
        if($retsingle) {
            return $dbh->fetch_assoc($sql_result);
        } else {
            // multiple rows requested
            $ret = [];
            while($row = $dbh->fetch_assoc($sql_result)) {
                $ret[] = $row;
            }
            return $ret;
        }
    }

    public static function delete_dbrecord($ids, $table='contacts', $idfield='id', $other_conditions = [])
    {
        $dbh = rcmail::get_instance()->db;

        if(is_array($ids)) {
            if(count($ids) <= 0) {
                return 0;
            }

            foreach($ids as &$id) {
                $id = $dbh->quote(is_array($id)?$id[$idfield]:$id);
            }
            $dspec = ' IN ('. implode(',',$ids) .')';
        } else {
            $dspec = ' = ' . $dbh->quote($ids);
        }

        $idfield = $dbh->quoteIdentifier($idfield);
        $sql = "DELETE FROM " . $dbh->table_name("carddav_$table") . " WHERE $idfield $dspec";

        // Append additional conditions
        foreach ($other_conditions as $field => $value) {
            $sql .= ' AND ' . $dbh->quoteIdentifier($field) . ' = ' . $dbh->quote($value);
        }

        $sql_result = $dbh->query($sql);
        return $dbh->affected_rows($sql_result);
    }

    private function createCardDavObj()
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

    public static function carddavconfig($abookid)
    {
        $dbh = rcmail::get_instance()->db;

        // cludge, agreed, but the MDB abstraction seems to have no way of
        // doing time calculations...
        $timequery = '('. $dbh->now() . ' > ';
        if ($dbh->db_provider === 'sqlite') {
            $timequery .= ' datetime(last_updated,refresh_time))';
        } elseif ($dbh->db_provider === 'mysql') {
            $timequery .= ' date_add(last_updated, INTERVAL refresh_time HOUR_SECOND))';
        } else {
            $timequery .= ' last_updated+refresh_time)';
        }

        $abookrow = self::get_dbrecord($abookid,
            'id as abookid,name,username,password,url,presetname,sync_token,authentication_scheme,'
            . $timequery . ' as needs_update', 'addressbooks');

        if(! $abookrow) {
            carddav::$logger->warning("FATAL! Request for non-existent configuration $abookid");
            return false;
        }

        if ($dbh->db_provider === 'postgres') {
            // postgres will return 't'/'f' here for true/false, normalize it to 1/0
            $nu = $abookrow['needs_update'];
            $nu = ($nu==1 || $nu=='t')?1:0;
            $abookrow['needs_update'] = $nu;
        }

        return $abookrow;
    }

    /**
     * Migrates settings to a separate addressbook table.
     */
    public static function migrateconfig($sub = 'CardDAV')
    {
        $rcmail = rcmail::get_instance();
        $prefs_all = $rcmail->config->get('carddav', 0);
        $dbh = $rcmail->db;

        // adopt password storing scheme if stored password differs from configured scheme
        $sql_result = $dbh->query('SELECT id,password FROM ' .
            $dbh->table_name('carddav_addressbooks') .
            ' WHERE user_id=?', $_SESSION['user_id']);

        while ($abookrow = $dbh->fetch_assoc($sql_result)) {
            $pw_scheme = carddav_common::password_scheme($abookrow['password']);
            if(strcasecmp($pw_scheme, carddav_common::$pwstore_scheme) !== 0) {
                $abookrow['password'] = carddav_common::decrypt_password($abookrow['password']);
                $abookrow['password'] = carddav_common::encrypt_password($abookrow['password']);
                $dbh->query('UPDATE ' .
                    $dbh->table_name('carddav_addressbooks') .
                    ' SET password=? WHERE id=?',
                    $abookrow['password'],
                    $abookrow['id']);
            }
        }

        // any old (Pre-DB) settings to migrate?
        if(!$prefs_all) {
            return;
        }

        // migrate to the multiple addressbook schema first if needed
        if ($prefs_all['db_version'] == 1 || !key_exists('db_version', $prefs_all)){
            carddav::$logger->debug("migrating DB1 to DB2");
            unset($prefs_all['db_version']);
            $p = [];
            $p['CardDAV'] = $prefs_all;
            $p['db_version'] = 2;
            $prefs_all = $p;
        }

        // migrate settings to database
        foreach ($prefs_all as $desc => $prefs){
            // skip non address book attributes
            if (!is_array($prefs)){
                continue;
            }

            $crypt_password = carddav_common::encrypt_password($prefs['password']);

            carddav::$logger->debug("move addressbook $desc");
            $dbh->query('INSERT INTO ' .
                $dbh->table_name('carddav_addressbooks') .
                '(name,username,password,url,active,user_id) ' .
                'VALUES (?, ?, ?, ?, ?, ?)',
                $desc, $prefs['username'], $crypt_password, $prefs['url'],
                $prefs['use_carddav'], $_SESSION['user_id']);
        }

        // delete old settings
        $usettings = $rcmail->user->get_prefs();
        $usettings['carddav'] = [];
        carddav::$logger->debug("delete old prefs: " . $rcmail->user->save_prefs($usettings));
    }

    /**
     * Mark all records in database as deleted
     *
     * @param bool $with_groups Remove also groups
     */
    public function delete_all($with_groups = false)
    {
        $dbh = rcmail::get_instance()->db;
        $abook_id = $this->id;
        $res1 = $dbh->query('SELECT id FROM '.
            $dbh->table_name('carddav_contacts').
            ' WHERE abook_id=?',$abook_id);
        $contact_ids = [];
        while($row = $dbh->fetch_assoc($res1)) {
            $contact_ids[] = $row['id'];
        }
        $this->delete($contact_ids);

        if ($with_groups != false) {
            $res2 = $dbh->query('SELECT id FROM '.
                $dbh->table_name('carddav_groups').
                ' WHERE abook_id=?',$abook_id);
            while($row = $dbh->fetch_assoc($res2)) {
                $this->delete_group($row['id']);
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
