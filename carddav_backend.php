<?php
/*
    RCM CardDAV Plugin
    Copyright (C) 2011 Benjamin Schieder <blindcoder@scavenger.homeip.net>

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

// requires Roundcubemail 0.7.2 or later

require("inc/http.php");
require("inc/sasl.php");
require("inc/vcard.php");

function carddavconfig($abookid){{{
	$dbh = rcmail::get_instance()->db;

	// cludge, agreed, but the MDB abstraction seems to have no way of
	// doing time calculations...
	$timequery = '('. $dbh->now() . ' > ';
	if ($dbh->db_provider === 'sqlite') {
		$timequery .= ' datetime(last_updated,refresh_time))';
	} else {
		$timequery .= ' last_updated+refresh_time)';
	}

	$abookrow = carddav_backend::get_dbrecord($abookid,
		'id as abookid,name,username,password,url,presetname,sync_token,'
		. $timequery . ' as needs_update', 'addressbooks');

	if(! $abookrow) {
		carddav_backend::warn("FATAL! Request for non-existent configuration $abookid");
		return false;
	}

	// postgres will return 't'/'f' here for true/false, normalize it to 1/0
	$nu = $abookrow['needs_update'];
	$nu = ($nu==1 || $nu=='t')?1:0;
	$abookrow['needs_update'] = $nu;

	return $abookrow;
}}}

/**
 * Migrates settings to a separate addressbook table.
 */
function migrateconfig($sub = 'CardDAV'){{{
	$rcmail = rcmail::get_instance();
	$prefs_all = $rcmail->config->get('carddav', 0);
	$dbh = $rcmail->db;

	// adopt password storing scheme if stored password differs from configured scheme
	$sql_result = $dbh->query('SELECT id,password FROM ' .
		get_table_name('carddav_addressbooks') .
		' WHERE user_id=?', $_SESSION['user_id']);

	while ($abookrow = $dbh->fetch_assoc($sql_result)) {
		$pw_scheme = carddav_backend::password_scheme($abookrow['password']);
		if(strcasecmp($pw_scheme, carddav_backend::$pwstore_scheme) !== 0) {
			$abookrow['password'] = carddav_backend::decrypt_password($abookrow['password']);
			$abookrow['password'] = carddav_backend::encrypt_password($abookrow['password']);
			$dbh->query('UPDATE ' .
				get_table_name('carddav_addressbooks') .
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
	if ($prefs_all['db_version'] == 1 || !array_key_exists('db_version', $prefs_all)){
		write_log("carddav", "migrateconfig: DB1 to DB2");
		unset($prefs_all['db_version']);
		$p = array();
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

		$crypt_password = carddav_backend::encrypt_password($prefs['password']);

		write_log("carddav", "migrateconfig: move addressbook $desc");
		$dbh->query('INSERT INTO ' .
			get_table_name('carddav_addressbooks') .
			'(name,username,password,url,active,user_id) ' .
			'VALUES (?, ?, ?, ?, ?, ?)',
				$desc, $prefs['username'], $crypt_password, $prefs['url'],
				$prefs['use_carddav'], $_SESSION['user_id']);
	}

	// delete old settings
	$usettings = $rcmail->user->get_prefs();
	$usettings['carddav'] = array();
	write_log("carddav", "migrateconfig: delete old prefs: " . $rcmail->user->save_prefs($usettings));
}}}

function concaturl($str, $cat){{{
	preg_match(";(^https?://[^/]+)(.*);", $str, $match);
	$hostpart = $match[1];
	$urlpart  = $match[2];

	// is $cat already a full URL?
	if(strpos($cat, '://') !== FALSE) {
		return $cat;
	}

	// is $cat a simple filename?
	// then attach it to the URL
	if (substr($cat, 0, 1) != "/"){
		$urlpart .= "/$cat";

	// $cat is a full path, the append it to the
	// hostpart only
	} else {
		$urlpart = $cat;
	}

	// remove // in the path
	$urlpart = preg_replace(';//+;','/',$urlpart);
	return $hostpart.$urlpart;
}}}

class carddav_backend extends rcube_addressbook
{
	// database primary key, used by RC to search by ID
	public $primary_key = 'id';
	public $coltypes;

	// database ID of the addressbook
	private $id;
	// currently active search filter
	private $filter;

	private $result;
	// configuration of the addressbook
	private $config;
	// custom labels defined in the addressbook
	private $xlabels;
	// admin settings from config.inc.php
	private static $admin_settings;
	// encryption scheme
	public static $pwstore_scheme = 'base64';

	const SEPARATOR = ',';

	const NSDAV = 'DAV:';
	const NSCARDDAV = 'urn:ietf:params:xml:ns:carddav';

	const DEBUG      = false; // set to true for basic debugging
	const DEBUG_HTTP = false; // set to true for debugging raw http stream

	// contains a the URIs, db ids and etags of the locally stored cards whenever
	// a refresh from the server is attempted. This is used to avoid a separate
	// "exists?" DB query for each card retrieved from the server and also allows
	// to detect whether cards were deleted on the server
	private $existing_card_cache = array();
	// same thing for groups
	private $existing_grpcard_cache = array();
	// used in refresh DB to record group memberships for the delayed
	// creation in the database (after all contacts have been loaded and
	// stored from the server)
	private $users_to_add;

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

	// log helpers
	public static function warn() {
		write_log("carddav.warn", implode(' ', func_get_args()));
	}

	public static function debug() {
		if(self::DEBUG) {
			write_log("carddav", implode(' ', func_get_args()));
		}
	}

	public static function debug_http() {
		if(self::DEBUG_HTTP) {
			write_log("carddav", implode(' ', func_get_args()));
		}
	}

	private static function checkAndParseXML($reply) {
		if(!is_array($reply))
			return false;

		if(!self::check_contenttype($reply['headers']['content-type'], ';(text|application)/xml;'))
			return false;

		$xml = new SimpleXMLElement($reply['body']);
		self::registerNamespaces($xml);
		return $xml;
	}

	private static function registerNamespaces($xml) {
		$xml->registerXPathNamespace('C', self::NSCARDDAV);
		$xml->registerXPathNamespace('D', self::NSDAV);
	}

	public function __construct($dbid)
	{{{
	$dbh = rcmail::get_instance()->db;

	$this->ready    = $dbh && !$dbh->is_error();
	$this->groups   = true;
	$this->readonly = false;
	$this->id       = $dbid;

	$this->config = carddavconfig($dbid);

	$prefs = self::get_adminsettings();
	if($this->config['presetname']) {
		if($prefs[$this->config['presetname']]['readonly'])
			$this->readonly = true;
	}

	$this->coltypes = array( /* {{{ */
		'name'         => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('name'), 'category' => 'main'),
		'firstname'    => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('firstname'), 'category' => 'main'),
		'surname'      => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('surname'), 'category' => 'main'),
		'email'        => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('email'), 'subtypes' => array('home','work','other','internet'), 'category' => 'main'),
		'middlename'   => array('type' => 'text', 'size' => 19, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('middlename'), 'category' => 'main'),
		'prefix'       => array('type' => 'text', 'size' => 8,  'maxlength' => 20, 'limit' => 1, 'label' => rcube_label('nameprefix'), 'category' => 'main'),
		'suffix'       => array('type' => 'text', 'size' => 8,  'maxlength' => 20, 'limit' => 1, 'label' => rcube_label('namesuffix'), 'category' => 'main'),
		'nickname'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('nickname'), 'category' => 'main'),
		'jobtitle'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('jobtitle'), 'category' => 'main'),
		'organization' => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('organization'), 'category' => 'main'),
		'department'   => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('department'), 'category' => 'main'),
		'gender'       => array('type' => 'select', 'limit' => 1, 'label' => rcube_label('gender'), 'options' => array('male' => rcube_label('male'), 'female' => rcube_label('female')), 'category' => 'personal'),
		'phone'        => array('type' => 'text', 'size' => 40, 'maxlength' => 20, 'label' => rcube_label('phone'), 'subtypes' => array('home','home2','work','work2','mobile','cell','main','homefax','workfax','car','pager','video','assistant','other'), 'category' => 'main'),
		'address'      => array('type' => 'composite', 'label' => rcube_label('address'), 'subtypes' => array('home','work','other'), 'childs' => array(
			'street'     => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('street'), 'category' => 'main'),
			'locality'   => array('type' => 'text', 'size' => 28, 'maxlength' => 50, 'label' => rcube_label('locality'), 'category' => 'main'),
			'zipcode'    => array('type' => 'text', 'size' => 8,  'maxlength' => 15, 'label' => rcube_label('zipcode'), 'category' => 'main'),
			'region'     => array('type' => 'text', 'size' => 12, 'maxlength' => 50, 'label' => rcube_label('region'), 'category' => 'main'),
			'country'    => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('country'), 'category' => 'main'),), 'category' => 'main'),
		'birthday'     => array('type' => 'date', 'size' => 12, 'maxlength' => 16, 'label' => rcube_label('birthday'), 'limit' => 1, 'render_func' => 'rcmail_format_date_col', 'category' => 'personal'),
		'anniversary'  => array('type' => 'date', 'size' => 12, 'maxlength' => 16, 'label' => rcube_label('anniversary'), 'limit' => 1, 'render_func' => 'rcmail_format_date_col', 'category' => 'personal'),
		'website'      => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'label' => rcube_label('website'), 'subtypes' => array('homepage','work','blog','profile','other'), 'category' => 'main'),
		'notes'        => array('type' => 'textarea', 'size' => 40, 'rows' => 15, 'maxlength' => 500, 'label' => rcube_label('notes'), 'limit' => 1),
		'photo'        => array('type' => 'image', 'limit' => 1, 'category' => 'main'),
		'assistant'    => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('assistant'), 'category' => 'personal'),
		'manager'      => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('manager'), 'category' => 'personal'),
		'spouse'       => array('type' => 'text', 'size' => 40, 'maxlength' => 50, 'limit' => 1, 'label' => rcube_label('spouse'), 'category' => 'personal'),
		// TODO: define fields for vcards like GEO, KEY
	); /* }}} */
	$this->addextrasubtypes();
	}}}

	/**
	 * Stores a custom label in the database (X-ABLabel extension).
	 *
	 * @param string Name of the type/category (phone,address,email)
	 * @param string Name of the custom label to store for the type
	 */
	private function storeextrasubtype($typename, $subtype)
	{{{
	$dbh = rcmail::get_instance()->db;
	$sql_result = $dbh->query('INSERT INTO ' .
		get_table_name('carddav_xsubtypes') .
		' (typename,subtype,abook_id) VALUES (?,?,?)',
			$typename, $subtype, $this->id);
	}}}

	/**
	 * Adds known custom labels to the roundcube subtype list (X-ABLabel extension).
	 *
	 * Reads the previously seen custom labels from the database and adds them to the
	 * roundcube subtype list in #coltypes and additionally stores them in the #xlabels
	 * list.
	 */
	private function addextrasubtypes()
	{{{
	$this->xlabels = array();

	foreach($this->coltypes as $k => $v) {
		if(array_key_exists('subtypes', $v)) {
			$this->xlabels[$k] = array();
	} }

	// read extra subtypes
	$xtypes = self::get_dbrecord($this->id,'typename,subtype','xsubtypes',false,'abook_id');

	foreach ($xtypes as $row) {
		$this->coltypes[$row['typename']]['subtypes'][] = $row['subtype'];
		$this->xlabels[$row['typename']][] = $row['subtype'];
	}
	}}}

	/**
	 * Returns addressbook name (e.g. for addressbooks listing).
	 *
	 * @return string name of this addressbook
	 */
	public function get_name()
	{{{
	return $this->config['name'];
	}}}

	/**
	 * Save a search string for future listings.
	 *
	 * @param mixed Search params to use in listing method, obtained by get_search_set()
	 */
	public function set_search_set($filter)
	{{{
	$this->filter = $filter;
	}}}

	/**
	 * Getter for saved search properties
	 *
	 * @return mixed Search properties used by this class
	 */
	public function get_search_set()
	{{{
	return $this->filter;
	}}}

	/**
	 * Reset saved results and search parameters
	 */
	public function reset()
	{{{
	$this->result = null;
	$this->filter = null;
	$this->total_cards = -1;
	}}}

	private static function retrieve_addressbook_properties($url, $serverpart, $config)
	{{{
	// check if the given URL points to an addressbook
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", 'Content-Type: application/xml; charset="utf-8"'),
			'content'=> <<<EOF
<?xml version="1.0" encoding="utf-8"?>
<D:propfind xmlns:D="DAV:"><D:prop>
    <D:resourcetype />
    <D:displayname />
</D:prop></D:propfind>
EOF
		)
	);

	$reply = self::cdfopen("retrieve_addressbook_properties", $url, $opts, $config);
	$xml = self::checkAndParseXML($reply);
	if($xml === false) return false;

	$aBooks = array();

	$xpresult = $xml->xpath('//D:response[descendant::D:resourcetype/C:addressbook]');
	foreach($xpresult as $ab) {
		self::registerNamespaces($ab);
		$aBook = array();
		list($aBook['href']) = $ab->xpath('child::D:href');
		list($aBook['name']) = $ab->xpath('descendant::D:displayname');

		if(strlen($aBook['href']) > 0) {
			$aBook['href'] = concaturl($serverpart, $aBook['href']);
			self::debug("retrieve_addressbook_properties found: ".$aBook['name']." at ".$aBook['href']);
			$aBooks[] = $aBook;
		}
	}

	return $aBooks;
	}}}

	/**
	 * Determines the name to be displayed for a contact. The routine
	 * distinguishes contact cards for individuals from organizations.
	 */
	private function set_displayname(&$save_data)
	{{{
	if(strcasecmp($save_data['showas'], 'COMPANY') == 0 && strlen($save_data['organization'])>0) {
		$save_data['name']     = $save_data['organization'];
	}

	// we need a displayname; if we do not have one, try to make one up
	if(strlen($save_data['name']) == 0) {
		$dname = array();
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
	}}}

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
	private function dbstore_group($etag, $uri, $vcfstr, $save_data, $dbid=0)
	{{{
	return $this->dbstore_base('groups',$etag,$uri,$vcfstr,$save_data,$dbid);
	}}}

	private function dbstore_base($table, $etag, $uri, $vcfstr, $save_data, $dbid=0, $xcol=array(), $xval=array())
	{{{
	$dbh = rcmail::get_instance()->db;

	// get rid of the %u placeholder in the URI, otherwise the refresh operation
	// will not be able to match local cards with those provided by the server
	$username = $this->config['username'];
	if($username === "%u")
		$username = $_SESSION['username'];
	$uri = str_replace("%u", $username, $uri);

	$xcol[]='name';  $xval[]=$save_data['name'];
	$xcol[]='etag';  $xval[]=$etag;
	$xcol[]='vcard'; $xval[]=$vcfstr;

	if($dbid) {
		self::debug("dbstore: UPDATE card $uri");
		$xval[]=$dbid;
		$sql_result = $dbh->query('UPDATE ' .
			get_table_name("carddav_$table") .
			' SET ' . implode('=?,', $xcol) . '=?' .
			' WHERE id=?', $xval);

	} else {
		self::debug("dbstore: INSERT card $uri");
		if ("x".$save_data['cuid'] == "x"){
			// There is no contact UID in the VCARD, try to create one
			$cuid = $uri;
			$cuid = preg_replace(';^.*/;', "", $cuid);
			$cuid = preg_replace(';\.vcf$;', "", $cuid);
			$save_data['cuid'] = $cuid;
		}
		$xcol[]='abook_id'; $xval[]=$this->id;
		$xcol[]='uri';      $xval[]=$uri;
		$xcol[]='cuid';     $xval[]=$save_data['cuid'];

		$sql_result = $dbh->query('INSERT INTO ' .
			get_table_name("carddav_$table") .
			' (' . implode(',',$xcol) . ') VALUES (?' . str_repeat(',?', count($xcol)-1) .')',
				$xval);

		// XXX the parameter is the sequence name for postgres; it doesn't work
		// when using the name of the table. For some reason it still provides
		// the correct ID for MySQL...
		$seqname = preg_replace('/s$/', '', $table);
		$dbid = $dbh->insert_id("carddav_$seqname"."_ids");
	}

	if($dbh->is_error()) {
		self::warn($dbh->is_error());
		$this->set_error(self::ERROR_SAVING, $dbh->is_error());
		return false;
	}

	return $dbid;
	}}}

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
	private function dbstore_contact($etag, $uri, $vcfstr, $save_data, $dbid=0)
	{{{
	// build email search string
	$email_keys = preg_grep('/^email:/', array_keys($save_data));
	$email_addrs = array();
	foreach($email_keys as $email_key) {
		$email_addrs[] = implode(", ", $save_data[$email_key]);
	}
	$save_data['email']	= implode(', ', $email_addrs);

	// extra columns for the contacts table
	$xcol_all=array('firstname','surname','organization','showas','email');
	$xcol=array();
	$xval=array();
	foreach($xcol_all as $k) {
		if(array_key_exists($k,$save_data)) {
			$xcol[] = $k;
			$xval[] = $save_data[$k];
	} }

	return $this->dbstore_base('contacts',$etag,$uri,$vcfstr,$save_data,$dbid,$xcol,$xval);
	}}}

	/**
	 * Checks if the given local card cache (for contacts or groups) contains
	 * a card with the given URI. If not, the function returns false.
	 * If yes, the card is marked seen in the cache, and the cached etag is
	 * compared with the given one. The function returns an associative array
	 * with the database id of the existing card (key dbid) and a boolean that
	 * indicates whether the card needs a server refresh as determined by the
	 * etag comparison (key needs_update).
	 */
	private static function checkcache(&$cache, $uri, $etag)
	{{{
	if(!array_key_exists($uri, $cache))
		return false;

	$cache[$uri]['seen'] = true;

	$dbrec = $cache[$uri];
	$dbid  = $dbrec['id'];

	$needsupd = true;

	// abort if card has not changed
	if($etag === $dbrec['etag']) {
		self::debug("checkcache: UNCHANGED card $uri");
		$needsupd = false;
	}
	return array('needs_update'=>$needsupd, 'dbid'=>$dbid);
	}}}

	/**
	 * @param array config array containing at least the keys
	 *             - url: base url if $url is a relative url
	 *             - username
	 *             - password
	 */
	public static function cdfopen($caller, $url, $opts, $carddav)
	{{{
	$rcmail = rcmail::get_instance();

	$http=new http_class;
	$http->timeout=10;
	$http->data_timeout=0;
	$http->user_agent="RCM CardDAV plugin/TRUNK";
	$http->follow_redirect=1;
	$http->redirection_limit=5;
	$http->prefer_curl=1;

	// if $url is relative, prepend the base url
	$url = concaturl($carddav['url'], $url);

	$carddav['password'] = self::decrypt_password($carddav['password']);

	// Substitute Placeholders
	if($carddav['username'] === '%u')
		$carddav['username'] = $_SESSION['username'];
	if($carddav['password'] === '%p')
		$carddav['password'] = $rcmail->decrypt($_SESSION['password']);
	$url = str_replace("%u", $carddav['username'], $url);

	self::debug("cdfopen: $caller requesting $url");

	$url = preg_replace(";://;", "://".urlencode($carddav['username']).":".urlencode($carddav['password'])."@", $url);
	$error = $http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"] = $opts['http']['method'];
	if (array_key_exists('content',$opts['http']) && strlen($opts['http']['content']) > 0 && $opts['http']['method'] != "GET"){
		$arguments["Body"] = $opts['http']['content']."\r\n";
	}
	if(array_key_exists('header',$opts['http'])) {
		if (is_array($opts['http']['header'])){
			foreach ($opts['http']['header'] as $key => $value){
				$h = explode(": ", $value);
				if (strlen($h[0]) > 0 && strlen($h[1]) > 0){
					// Only append headers with key AND value
					$arguments["Headers"][$h[0]] = $h[1];
				}
			}
		} else {
			$h = explode(": ", $opts['http']['header']);
			$arguments["Headers"][$h[0]] = $h[1];
		}
	}
	$error = $http->Open($arguments);
	if ($error == ""){
		$error=$http->SendRequest($arguments);
		self::debug_http("cdfopen SendRequest: ".var_export($http, true));

		if ($error == ""){
			$error=$http->ReadReplyHeaders($headers);
			if ($error == ""){
				$error = $http->ReadWholeReplyBody($body);
				if ($error == ""){
					$reply["status"] = $http->response_status;
					$reply["headers"] = $headers;
					$reply["body"] = $body;
					self::debug_http("cdfopen success: ".var_export($reply, true));
					return $reply;
				} else {
					self::warn("cdfopen: Could not read reply body: $error");
				}
			} else {
				self::warn("cdfopen: Could not read reply header: $error");
			}
		} else {
			self::warn("cdfopen: Could not send request: $error");
		}
	} else {
		self::warn("cdfopen: Could not open: $error");
		self::debug_http("cdfopen failed: ".var_export($http, true));
		return -1;
	}
	return $http->response_status;
	}}}

	/**
	 * Synchronizes the local card store with the CardDAV server.
	 */
	private function refreshdb_from_server()
	{{{
	$dbh = rcmail::get_instance()->db;
	$duration = time();

	// determine existing local contact URIs and ETAGs
	$contacts = self::get_dbrecord($this->id,'id,uri,etag','contacts',false,'abook_id');
	foreach($contacts as $contact) {
		$this->existing_card_cache[$contact['uri']] = $contact;
	}

	// determine existing local group URIs and ETAGs
	$groups = self::get_dbrecord($this->id,'id,uri,etag','groups',false,'abook_id');
	foreach($groups as $group) {
		$this->existing_grpcard_cache[$group['uri']] = $group;
	}

	// used to record which users need to be added to which groups
	$this->users_to_add = array();

	// Check for supported-report-set and only use sync-collection if server advertises it.
	// This suppresses 501 Not implemented errors with ownCloud.
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 0", 'Content-Type: application/xml; charset="utf-8"'),
			'content'=> <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<propfind xmlns="DAV:"> <prop>
    <supported-report-set/>
</prop> </propfind>
EOF
		)
	);
	$reply = self::cdfopen("refreshdb_from_server", $this->config['url'], $opts, $this->config);

	$records = -1;

	$xml = self::checkAndParseXML($reply);
	if($xml !== false) {
		$xpresult = $xml->xpath('//D:supported-report/D:report/D:sync-collection');
		if(count($xpresult) > 0) {
			$records = $this->list_records_sync_collection();
		}
	}

	// sync-collection not supported or returned error
	if ($records < 0){
		$records = $this->list_records_propfind();
	}

	foreach($this->users_to_add as $dbid => $cuids) {
		if(count($cuids)<=0) continue;
		$sql_result = $dbh->query('INSERT INTO '.
			get_table_name('carddav_group_user') .
			' (group_id,contact_id) SELECT ?,id from ' .
			get_table_name('carddav_contacts') .
			' WHERE abook_id=? AND cuid IN (' . implode(',', $cuids) . ')', $dbid, $this->id);
		self::debug("Added " . $dbh->affected_rows($sql_result) . " contacts to group $dbid");
	}

	unset($this->users_to_add);
	$this->existing_card_cache = array();
	$this->existing_grpcard_cache = array();

	// set last_updated timestamp
	$dbh->query('UPDATE ' .
		get_table_name('carddav_addressbooks') .
		' SET last_updated=' . $dbh->now() .' WHERE id=?',
			$this->id);

	$duration = time() - $duration;
	self::debug("server refresh took $duration seconds");
	if($records < 0) {
		self::warn("Errors occurred during the refresh of addressbook " . $this->id);
	}
	}}}

	/**
	 * List the current set of contact records
	 *
	 * @param  array   List of cols to show, Null means all
	 * @param  int     Only return this number of records, use negative values for tail
	 * @param  boolean True to skip the count query (select only)
	 * @return array   Indexed list of contact records, each a hash array
	 */
	public function list_records($cols=array(), $subset=0, $nocount=false)
	{{{
	// refresh from server if refresh interval passed
	if ( $this->config['needs_update'] == 1 )
		$this->refreshdb_from_server();

	// XXX workaround for a roundcube bug to support roundcube's displayname setting
	// Reported as Roundcube Ticket #1488394
	if(count($cols)>0) {
		if(!in_array('firstname', $cols)) {
			$cols[] = 'firstname';
		}
		if(!in_array('surname', $cols)) {
			$cols[] = 'surname';
		}
	}
	// XXX workaround for a roundcube bug to support roundcube's displayname setting

	// if the count is not requested we can save one query
	if($nocount)
		$this->result = new rcube_result_set();
	else
		$this->result = $this->count();

	$records = $this->list_records_readdb($cols,$subset);
	if($nocount) {
		$this->result->count = $records;

	} else if ($this->list_page <= 1) {
		if ($records < $this->page_size && $subset == 0)
			$this->result->count = $records;
		else
			$this->result->count = $this->_count($cols);
	}

	if ($records > 0){
		return $this->result;
	}

	return false;
	}}}

	public static function check_contenttype($ctheader, $expectedct)
	{{{
	if(!is_array($ctheader)) {
		$ctheader = array($ctheader);
	}

	foreach($ctheader as $ct) {
		if(preg_match($expectedct, $ct))
			return true;
	}

	return false;
	}}}

	/**
	 * Determines the location of the addressbook for the current user on the
	 * CardDAV server.
	 */
	public static function find_addressbook($config)
	{{{
	if (!preg_match(';^[^/]+://[^/]+;', $config['url'], $match))
		return false;
	$serverpart = $match[0];

	$retVal = self::retrieve_addressbook_properties($config['url'], $serverpart, $config);
	if(is_array($retVal) && count($retVal)>0)
		return $retVal;

	// No addressbook found, try to auto-determine addressbook locations
	// Retrieve Principal URL
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", 'Content-Type: application/xml; charset="utf-8"'),
			'content'=> <<<EOF
<?xml version="1.0" encoding="utf-8" ?>
<a:propfind xmlns:a="DAV:"> <a:prop>
    <a:current-user-principal/>
</a:prop> </a:propfind>
EOF
		)
	);

	$reply = self::cdfopen("find_addressbook", $config['url'], $opts, $config);
	$xml = self::checkAndParseXML($reply);
	if($xml === false) return false;

	list($princurl) = $xml->xpath('//D:current-user-principal/D:href');
	if(strlen($princurl) == 0) {
		self::debug("find_addressbook no principal URL found");
		return false;
	}
	self::debug("find_addressbook Principal URL: $princurl");
	if (preg_match(';^[^/]+://[^/]+;', $princurl, $match))
		$serverpart = $match[0];

	// Find Addressbook Home Path of Principal
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", 'Content-Type: application/xml; charset="utf-8"'),
			'content'=>  <<<EOF
<?xml version="1.0" encoding="utf-8" ?>
<a:propfind xmlns:a="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"> <a:prop>
    <C:addressbook-home-set/>
</a:prop> </a:propfind>
EOF
		)
	);

	$reply = self::cdfopen("find_addressbook", concaturl($serverpart,$princurl), $opts, $config);
	$xml = self::checkAndParseXML($reply);
	if($xml === false) return false;

	list($abookhome) = $xml->xpath('//C:addressbook-home-set/D:href');
	if(strlen($abookhome) == 0) {
		$abookhome = $princurl;
	} else if (preg_match(';^[^/]+://[^/]+;', $abookhome, $match)) {
		$serverpart = $match[0];
	}
	self::debug("find_addressbook addressbook home: $abookhome");

	$retVal = self::retrieve_addressbook_properties(concaturl($serverpart,$abookhome), $serverpart, $config);
	return $retVal;
	}}}

	/**
	 * Retrieves the Card URIs from the CardDAV server
	 *
	 * @return int  number of cards in collection, -1 on error
	 */
	private function list_records_sync_collection()
	{{{
	$sync_token = $this->config['sync_token'];

	while(true) {
		$opts = array(
			'http'=>array(
				'method'=>"REPORT",
				'header'=>array("Depth: 0", 'Content-Type: application/xml; charset="utf-8"'),
				'content'=> <<<EOF
<?xml version="1.0" encoding="utf-8" ?>
<D:sync-collection xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
    <D:sync-token>$sync_token</D:sync-token>
    <D:sync-level>1</D:sync-level>
    <D:prop>
        <D:getcontenttype/>
        <D:getetag/>
    </D:prop>
</D:sync-collection>
EOF
			)
		);

		$reply = self::cdfopen("list_records_sync_collection", $this->config['url'], $opts, $this->config);

		$xml = self::checkAndParseXML($reply);

		if($xml === false) {
			// a server may invalidate old sync-tokens, in which case we need to do a full resync
			if (strlen($sync_token)>0 && $reply == 412){
				self::warn("Server reported invalid sync-token in sync of addressbook " . $this->config['abookid'] . ". Resorting to full resync.");
				$sync_token = '';
				continue;
			} else {
				return -1;
			}
		}

		list($new_sync_token) = $xml->xpath('//D:sync-token');

		$records = $this->addvcards($xml);

		if(strlen($sync_token) == 0) {
			if($records>=0) {
				$this->delete_unseen();
			}
		} else {
			$this->delete_synccoll($xml);
		}

		if($records >= 0) {
			carddav::update_abook($this->config['abookid'], array('sync_token' => "$new_sync_token"));

			// if we got a truncated result set continue sync
			$xpresult = $xml->xpath('//D:response[contains(child::D:status, " 507 Insufficient Storage")]');
			if(count($xpresult) > 0) {
				$sync_token = "$new_sync_token";
				continue;
			}
		}

		break;
	}
	return $records;
	}}}

	private function list_records_readdb($cols, $subset=0, $count_only=false)
	{{{
	$dbh = rcmail::get_instance()->db;

	// true if we can use DB filtering or no filtering is requested
	$filter = $this->get_search_set();
	$this->determine_filter_params($cols,$subset, $firstrow, $numrows, $read_vcard);

	$dbattr = $read_vcard ? 'vcard' : 'firstname,surname,email';

	$limit_index = $firstrow;
	$limit_rows  = $numrows;

	$xfrom = '';
	$xwhere = '';
	if($this->group_id) {
		$xfrom = ',' . get_table_name('carddav_group_user');
		$xwhere = ' AND id=contact_id AND group_id=' . $dbh->quote($this->group_id) . ' ';
	}

	// Workaround for Roundcube versions < 0.7.2
	$sort_column = $this->sort_col ? $this->sort_col : 'surname';
	$sort_order  = $this->sort_order ? $this->sort_order : 'ASC';

	$sql_result = $dbh->limitquery("SELECT id,name,$dbattr FROM " .
		get_table_name('carddav_contacts') . $xfrom .
		' WHERE abook_id=? ' . $xwhere .
		($this->filter ? " AND (".$this->filter.")" : "") .
		" ORDER BY (CASE WHEN showas='COMPANY' THEN organization ELSE " . $sort_column . " END) "
		. $sort_order,
		$limit_index,
		$limit_rows,
		$this->id
	);

	$addresses = array();
	while($contact = $dbh->fetch_assoc($sql_result)) {
		if($read_vcard) {
			$save_data = $this->create_save_data_from_vcard($contact['vcard']);
			if (!$save_data){
				self::warn("Couldn't parse vcard ".$contact['vcard']);
				continue;
			}

			// needed by the calendar plugin
			if(is_array($cols) && in_array('vcard', $cols)) {
				$save_data['save_data']['vcard'] = $contact['vcard'];
			}

			$save_data = $save_data['save_data'];
		} else {
			$save_data = array();
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
	}}}

	private function query_addressbook_multiget($hrefs)
	{{{
	$dbh = rcmail::get_instance()->db;
	$hrefstr = '';
	foreach ($hrefs as $href) {
		$hrefstr .= "<D:href>$href</D:href>\n";
	}

	$optsREPORT = array(
		'http' => array(
			'method'=>"REPORT",
			'header'=>array("Depth: 0", 'Content-Type: application/xml; charset="utf-8"'),
			'content'=> <<<EOF
<?xml version="1.0" encoding="utf-8" ?>
<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
<D:prop>
    <D:getetag/>
    <C:address-data>
        <C:allprop/>
    </C:address-data>
</D:prop>
$hrefstr
</C:addressbook-multiget>
EOF
		)
	);

	$reply = self::cdfopen("query_addressbook_multiget", $this->config['url'], $optsREPORT, $this->config);
	$xml = self::checkAndParseXML($reply);
	if($xml === false) return -1;

	$xpresult = $xml->xpath('//D:response[descendant::C:address-data]');

	$numcards = 0;
	foreach ($xpresult as $vcard) {
		self::registerNamespaces($vcard);
		list($href) = $vcard->xpath('child::D:href');
		list($etag) = $vcard->xpath('descendant::D:getetag');
		list($vcf)  = $vcard->xpath('descendant::C:address-data');

		// determine database ID of existing cards by checking the cache
		$dbid = 0;
		if(	($ret = self::checkcache($this->existing_card_cache,"$href","$etag"))
			|| ($ret = self::checkcache($this->existing_grpcard_cache,"$href","$etag")) ) {
			$dbid = $ret['dbid'];
		}

		// changed on server, parse VCF
		$save_data = $this->create_save_data_from_vcard("$vcf");
		$vcfobj = $save_data['vcf'];
		if($save_data['needs_update'])
			$vcf = $vcfobj->toString();
		$save_data = $save_data['save_data'];

		if($save_data['kind'] === 'group') {
			self::debug('Processing Group ' . $save_data['name']);
			// delete current group members (will be reinserted if needed below)
			if($dbid) self::delete_dbrecord($dbid,'group_user','group_id');

			// store group card
			if(!($dbid = $this->dbstore_group("$etag","$href","$vcf",$save_data,$dbid)))
				return -1;

			// record group members for deferred store
			$this->users_to_add[$dbid] = array();
			$members = $vcfobj->getProperties('X-ADDRESSBOOKSERVER-MEMBER');
			self::debug("Group $dbid has " . count($members) . " members");
			foreach($members as $mbr) {
				$mbr = $mbr->getComponents(':');
				if(!$mbr) continue;
				if(count($mbr)!=3 || $mbr[0] !== 'urn' || $mbr[1] !== 'uuid') {
					self::warn("don't know how to interpret group membership: " . implode(':', $mbr));
					continue;
				}
				$this->users_to_add[$dbid][] = $dbh->quote($mbr[2]);
			}

		} else { // individual/other
			if(!$this->dbstore_contact("$etag","$href","$vcf",$save_data,$dbid))
				return -1;
		}
		$numcards++;
	}
	return $numcards;
	}}}

	private function list_records_propfind()
	{{{
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", 'Content-Type: application/xml; charset="utf-8"'),
			'content'=> <<<EOF
<?xml version="1.0" encoding="utf-8" ?>
<a:propfind xmlns:a="DAV:"> <a:prop>
    <a:getcontenttype/>
    <a:getetag/>
</a:prop> </a:propfind>
EOF
		)
	);

	$reply = self::cdfopen("list_records_propfind", "", $opts, $this->config);
	$xml = self::checkAndParseXML($reply);
	if($xml === false) return -1;
	$records = $this->addvcards($xml);
	if($records>=0) {
		$this->delete_unseen();
	}

	return $records;
	}}}

	private function addvcards($xml)
	{{{
	$urls = array();
	$xpresult = $xml->xpath('//D:response[contains(child::D:propstat/D:status, " 200 OK") and child::D:propstat/D:prop/D:getetag]');
	foreach ($xpresult as $r) {
		self::registerNamespaces($r);

		list($href) = $r->xpath('child::D:href');
		if(preg_match('/\/$/', $href)) continue;

		list($etag) = $r->xpath('descendant::D:getetag');

		$ret = self::checkcache($this->existing_card_cache,"$href","$etag");
		$retgrp = self::checkcache($this->existing_grpcard_cache,"$href","$etag");

		if(	($ret===false && $retgrp===false)
			|| (is_array($ret) && $ret['needs_update'])
			|| (is_array($retgrp) && $retgrp['needs_update']) )
		{
			$urls[] = "$href";
		}
	}

	if (count($urls) > 0) {
		$records = $this->query_addressbook_multiget($urls);
	}
	}}}

	/** delete cards not present on the server anymore */
	private function delete_unseen()
	{{{
	$delids = array();
	foreach($this->existing_card_cache as $value) {
		if(!array_key_exists('seen', $value) || !$value['seen']) {
			$delids[] = $value['id'];
		}
	}
	$del = self::delete_dbrecord($delids);
	self::debug("deleted $del contacts during server refresh");

	$delids = array();
	foreach($this->existing_grpcard_cache as $value) {
		if(!array_key_exists('seen', $value) || !$value['seen']) {
			$delids[] = $value['id'];
		}
	}
	$del = self::delete_dbrecord($delids,'groups');
	self::debug("deleted $del groups during server refresh");
	}}}

	/** delete cards reported deleted by the server */
	private function delete_synccoll($xml)
	{{{
	$xpresult = $xml->xpath('//D:response[contains(child::D:status, " 404 Not Found")]');
	$del_contacts = array();
	$del_groups = array();

	foreach ($xpresult as $r) {
		self::registerNamespaces($r);

		list($href) = $r->xpath('child::D:href');
		if(preg_match('/\/$/', $href)) continue;

		if(isset($this->existing_card_cache["$href"])) {
			$del_contacts[] = $this->existing_card_cache["$href"]['id'];
		} else if(isset($this->existing_grpcard_cache["$href"])) {
			$del_groups[] = $this->existing_grpcard_cache["$href"]['id'];
		}
	}
	$del = self::delete_dbrecord($del_contacts);
	self::debug("deleted $del contacts during incremental server refresh");
	$del = self::delete_dbrecord($del_groups,'groups');
	self::debug("deleted $del groups during incremental server refresh");
	}}}

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
	function search($fields, $value, $mode=0, $select=true, $nocount=false, $required=array())
	{{{
	$dbh = rcmail::get_instance()->db;
	if (!is_array($fields))
		$fields = array($fields);
	if (!is_array($required) && !empty($required))
		$required = array($required);

	$where = $and_where = array();
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
			switch ($mode) {
			case 1: // strict
				$where[] = '(' . $dbh->quoteIdentifier($col) . ' = ' . $dbh->quote($val)
					. ' OR ' . $dbh->ilike($col, $val . $AS . '%')
					. ' OR ' . $dbh->ilike($col, '%' . $AS . $val . $AS . '%')
					. ' OR ' . $dbh->ilike($col, '%' . $AS . $val) . ')';
				break;
			case 2: // prefix
				$where[] = '(' . $dbh->ilike($col, $val . '%')
					. ' OR ' . $dbh->ilike($col, $AS . $val . '%') . ')';
				break;
			default: // partial
				$where[] = $dbh->ilike($col, '%' . $val . '%');
			}
		}
		// vCard field
		else {
				foreach (explode(" ", self::normalize_string($val)) as $word) {
					switch ($mode) {
					case 1: // strict
						$words[] = '(' . $dbh->ilike('vcard', $word . $WS . '%')
							. ' OR ' . $dbh->ilike('vcard', '%' . $AS . $word . $WS .'%')
							. ' OR ' . $dbh->ilike('vcard', '%' . $AS . $word) . ')';
						break;
					case 2: // prefix
						$words[] = '(' . $dbh->ilike('vcard', $word . '%')
							. ' OR ' . $dbh->ilike('vcard', $AS . $word . '%') . ')';
						break;
					default: // partial
						$words[] = $dbh->ilike('vcard', '%' . $word . '%');
					}
				}
				$where[] = '(' . join(' AND ', $words) . ')';
			if (is_array($value))
				$post_search[$col] = mb_strtolower($val);
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
				$found = array();
				foreach (preg_grep($regexp, array_keys($row)) as $col) {
					$pos     = strpos($col, ':');
					$colname = $pos ? substr($col, 0, $pos) : $col;
					$search  = $post_search[$colname];
					foreach ((array)$row[$col] as $value) {
						// composite field, e.g. address
						foreach ((array)$value as $val) {
							$val = mb_strtolower($val);
							switch ($mode) {
							case 1:
								$got = ($val == $search);
								break;
							case 2:
								$got = ($search == substr($val, 0, strlen($search)));
								break;
							default:
								$got = (strpos($val, $search) !== false);
								break;
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
	}}}

	/**
	 * Count number of available contacts in database
	 *
	 * @return rcube_result_set Result set with values for 'count' and 'first'
	 */
	public function count()
	{{{
	if($this->total_cards < 0) {
		$this->_count();
	}
	return new rcube_result_set($this->total_cards, ($this->list_page-1) * $this->page_size);
	}}}

	// Determines and returns the number of cards matching the current search criteria
	private function _count($cols=array())
	{{{
	if($this->total_cards < 0) {
		$dbh = rcmail::get_instance()->db;

		$sql_result = $dbh->query('SELECT COUNT(id) as total_cards FROM ' .
			get_table_name('carddav_contacts') .
			' WHERE abook_id=?' .
			($this->filter ? " AND (".$this->filter.")" : ""),
			$this->id
		);

		$resultrow = $dbh->fetch_assoc($sql_result);
		$this->total_cards = $resultrow['total_cards'];
	}
	return $this->total_cards;
	}}}

	private function determine_filter_params($cols, $subset, &$firstrow, &$numrows, &$read_vcard) {
		// determine whether we have to parse the vcard or if only db cols are requested
		$read_vcard = !$cols || count(array_intersect($cols, $this->table_cols)) < count($cols);

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
	{{{
	return $this->result;
	}}}

	/**
	 * Return the last result set
	 *
	 * @return rcube_result_set Current result set or NULL if nothing selected yet
	 */
	private function get_record_from_carddav($uid)
	{{{
	$opts = array(
		'http'=>array(
			'method'=>"GET",
		)
	);
	$reply = self::cdfopen("get_record_from_carddav", $uid, $opts, $this->config);
	if (!is_array($reply) || strlen($reply["body"])==0) { return false; }
	if ($reply["status"] == 404){
		self::warn("Request for VCF '$uid' which doesn't exist on the server.");
		return false;
	}

	return array(
		'vcf'  => $reply["body"],
		'etag' => $reply['headers']['etag'],
	);
	}}}

	/**
	 * Get a specific contact record
	 *
	 * @param mixed record identifier(s)
	 * @param boolean True to return record as associative array, otherwise a result set is returned
	 *
	 * @return mixed Result object with all record fields or False if not found
	 */
	public function get_record($oid, $assoc_return=false)
	{{{
	$this->result = $this->count();

	$contact = self::get_dbrecord($oid, 'vcard');
	if(!$contact) return false;

	$retval = $this->create_save_data_from_vcard($contact['vcard']);
	if(!$retval) {
		return false;
	}
	$retval = $retval['save_data'];

	$retval['ID'] = $oid;
	$this->result->add($retval);
	$sql_arr = $assoc_return && $this->result ? $this->result->first() : null;
	return $assoc_return && $sql_arr ? $sql_arr : $this->result;
	}}}

	private function put_record_to_carddav($id, $vcf, $etag='')
	{{{
	$this->result = $this->count();
	$matchhdr = $etag ?
		"If-Match: $etag" :
		"If-None-Match: *";

	$opts = array(
		'http'=>array(
			'method'=>"PUT",
			'content'=>$vcf,
			'header'=> array(
				"Content-Type: text/vcard;charset=utf-8",
				$matchhdr,
			),
		)
	);
	$reply = self::cdfopen("put_record_to_carddav", $id, $opts, $this->config);
	if (is_array($reply) && $reply["status"] >= 200 && $reply["status"] < 300) {
		$etag = $reply["headers"]["etag"];
		if ("$etag" == ""){
			// Server did not reply an etag
			$retval = $this->get_record_from_carddav($id);
			self::debug(var_export($retval, true));
			$etag = $retval["etag"];
		}
		return $etag;
	}

	return false;
	}}}

	private function delete_record_from_carddav($id)
	{{{
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"DELETE",
		)
	);
	$id = preg_replace(";_rcmcddot_;", ".", $id);
	$reply = self::cdfopen("delete_record_from_carddav", $id, $opts, $this->config);
	if (is_array($reply) && $reply["status"] == 204){
		return true;
	}
	return false;
	}}}

	private function guid()
	{{{
	return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}}}

	/**
	 * Creates a new or updates an existing vcard from save data.
	 */
	private function create_vcard_from_save_data($save_data, $vcf=null)
	{{{
	unset($save_data['vcard']);
	if(!$vcf) { // create fresh minimal vcard
		$vcfstr = array(
			'BEGIN:VCARD',
			'VERSION:3.0',
			'UID:'.$save_data['cuid'],
			'REV:'.date('c'),
			'END:VCARD'
		);

		$vcf = new VCard;
		if(!$vcf->parse($vcfstr)) {
			self::warn("Couldn't parse newly created vcard " . implode("\n", $vcfstr));
			return false;
		}
	} else { // update revision
		$vcf->setProperty("REV", date("c"));
	}

	// N is mandatory
	if(array_key_exists('kind',$save_data) && $save_data['kind'] === 'group') {
		$vcf->setProperty("N", $save_data['name'],   0,0);
	} else {
		$vcf->setProperty("N", $save_data['surname'],   0,0);
		$vcf->setProperty("N", $save_data['firstname'], 0,1);
		$vcf->setProperty("N", $save_data['middlename'],0,2);
		$vcf->setProperty("N", $save_data['prefix'],    0,3);
		$vcf->setProperty("N", $save_data['suffix'],    0,4);
	}

	if (array_key_exists("organization", $save_data)){
		$vcf->setProperty("ORG", $save_data['organization'], 0, 0);
	}
	if (array_key_exists("department", $save_data)){
		if (is_array($save_data['department'])){
			$i = 0;
			foreach ($save_data['department'] AS $key => $value){
				$i++;
				$vcf->setProperty("ORG", $value, 0, $i);
			}
		} else if (strlen($save_data['department']) > 0){
			$vcf->setProperty("ORG", $save_data['department'], 0, 1);
		}
	}

	// normalize date fields to RFC2425 YYYY-MM-DD date values
	foreach ($this->datefields as $key) {
		if (array_key_exists($key, $save_data) && strlen($save_data[$key])>0) {
			$val = rcube_strtotime($save_data[$key]);
			$save_data[$key] = date('Y-m-d',$val);
		}
	}

	// process all simple attributes
	foreach ($this->vcf2rc['simple'] as $vkey => $rckey){
		if (array_key_exists($rckey, $save_data)) {
			if (strlen($save_data[$rckey]) > 0) {
				$vcf->setProperty($vkey, $save_data[$rckey]);

			} else { // delete the field
				$vcf->deleteProperty($vkey);
			}
		}
	}

	// process all multi-value attributes
	foreach ($this->vcf2rc['multi'] as $vkey => $rckey){
		// delete and fully recreate all entries
		// there is no easy way of mapping an address in the existing card
		// to an address in the save data, as subtypes may have changed
		$vcf->deleteProperty($vkey);

		$stmap = array( $rckey => 'other' );
		foreach ($this->coltypes[$rckey]['subtypes'] AS $subtype){
			$stmap[ $rckey.':'.$subtype ] = $subtype;
		}

		foreach ($stmap as $rcqkey => $subtype){
			if(array_key_exists($rcqkey, $save_data)) {
			$avalues = is_array($save_data[$rcqkey]) ? $save_data[$rcqkey] : array($save_data[$rcqkey]);
			foreach($avalues as $evalue) {
				if (strlen($evalue) > 0){
					$propidx = $vcf->setProperty($vkey, $evalue, -1);
					$props = $vcf->getProperties($vkey);
					$this->set_attr_label($vcf, $props[$propidx], $rckey, $subtype); // set label
				}
			} }
		}
	}

	// process address entries
	$vcf->deleteProperty('ADR');
	foreach ($this->coltypes['address']['subtypes'] AS $subtype){
		$rcqkey = 'address:'.$subtype;

		if(array_key_exists($rcqkey, $save_data)) {
		foreach($save_data[$rcqkey] as $avalue) {
			if ( strlen($avalue['street'])
				|| strlen($avalue['locality'])
				|| strlen($avalue['region'])
				|| strlen($avalue['zipcode'])
				|| strlen($avalue['country'])) {

				$propidx = $vcf->setProperty('ADR', $avalue['street'], -1, 2);
				$vcf->setProperty('ADR', $avalue['locality'], $propidx, 3);
				$vcf->setProperty('ADR', $avalue['region'],   $propidx, 4);
				$vcf->setProperty('ADR', $avalue['zipcode'],  $propidx, 5);
				$vcf->setProperty('ADR', $avalue['country'],  $propidx, 6);
				$props = $vcf->getProperties('ADR');
				$this->set_attr_label($vcf, $props[$propidx], 'address', $subtype); // set label
			}
		} }
	}

	return $vcf;
	}}}

	private function set_attr_label($vcard, $pvalue, $attrname, $newlabel) {
		$group = $pvalue->getGroup();

		// X-ABLabel?
		if(in_array($newlabel, $this->xlabels[$attrname])) {
			if(!$group) {
				$group = $vcard->genGroupLabel();
				$pvalue->setGroup($group);

				// delete standard label if we had one
				$oldlabel = $pvalue->getParam('TYPE', 0);
				if(strlen($oldlabel)>0 &&
					in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])) {
					$pvalue->deleteParam('TYPE', 0, 1);
				}
			}

			$vcard->setProperty('X-ABLabel', $newlabel, -1, 0, $group);
			return true;
		}

		// Standard Label
		$had_xlabel = false;
		if($group) { // delete group label property if present
			$had_xlabel = $vcard->deletePropertyByGroup('X-ABLabel', $group);
		}

		// add or replace?
		$oldlabel = $pvalue->getParam('TYPE', 0);
		if(strlen($oldlabel)>0 &&
			in_array($oldlabel, $this->coltypes[$attrname]['subtypes'])) {
				$had_xlabel = false; // replace
		}

		if($had_xlabel) {
			$pvalue->setParam('TYPE', $newlabel, -2);
		} else {
			$pvalue->setParam('TYPE', $newlabel, 0);
		}

		return false;
	}

	private function get_attr_label($vcard, $pvalue, $attrname) {
		// prefer a known standard label if available
		$xlabel = '';
		if(array_key_exists('TYPE', $pvalue->params)) {
			$xlabel = strtolower($pvalue->params['TYPE'][0]);
		}
		if(strlen($xlabel)>0 &&
			in_array($xlabel, $this->coltypes[$attrname]['subtypes'])) {
				return $xlabel;
		}

		// check for a custom label using Apple's X-ABLabel extension
		$group = $pvalue->getGroup();
		if($group) {
			$xlabel = $vcard->getProperty('X-ABLabel', $group);
			if($xlabel) {
				$xlabel = $xlabel->getComponents();
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
	{{{
	$opts = array(
		'http'=>array(
			'method'=>"GET",
		)
	);
	$uri = $save_data['photo'];
	$reply = self::cdfopen("download_photo", $uri, $opts, $this->config);
	if (is_array($reply) && $reply["status"] == 200){
		$save_data['photo'] = base64_encode($reply['body']);
		return true;
	}
	self::warn("Downloading $uri failed: " . (is_array($reply) ? $reply["status"] : $reply) );
	return false;
	}}}

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
	 * @param  string Textual representation of a VCard.
	 * @return mixed  false on failure, otherwise associative array with keys:
	 *           - save_data:    Roundcube representation of the VCard
	 *           - vcf:          VCard object created from the given VCard
	 *           - needs_update: boolean that indicates whether the card was modified
	 */
	private function create_save_data_from_vcard($vcfstr)
	{{{
	$vcf = new VCard;
	if (!$vcf->parse($vcfstr)){
		self::warn("Couldn't parse vcard: $vcfstr");
		return false;
	}

	$needs_update=false;
	$save_data = array(
		// DEFAULTS
		'kind'   => 'individual',
		'showas' => 'INDIVIDUAL',
	);

	foreach ($this->vcf2rc['simple'] as $vkey => $rckey){
		$property = $vcf->getProperty($vkey);
		if ($property){
			$p = $property->getComponents();
			$save_data[$rckey] = $p[0];
		}
	}

	// inline photo if external reference
	if(array_key_exists('photo', $save_data)) {
		$kind = $vcf->getProperty('PHOTO')->getParam('VALUE',0);
		if($kind && strcasecmp('uri', $kind)==0) {
			if($this->download_photo($save_data)) {
				$vcf->getProperty('PHOTO')->deleteParam('VALUE');
				$vcf->getProperty('PHOTO')->setParam('ENCODING', 'b', 0);
				$vcf->getProperty('PHOTO')->setComponent($save_data['photo'],0);
				$needs_update=true;
	} } }

	$property = $vcf->getProperty("N");
	if ($property){
		$N = $property->getComponents();
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

	$property = $vcf->getProperty("ORG");
	if ($property){
		$ORG = $property->getComponents();
		$save_data['organization'] = $ORG[0];
		for ($i = 1; $i <= count($ORG); $i++){
			$save_data['department'][] = $ORG[$i];
		}
	}

	foreach ($this->vcf2rc['multi'] as $key => $value){
		$property = $vcf->getProperties($key);
			foreach ($property as $pkey => $pvalue){
				$p = $pvalue->getComponents();
				$label = $this->get_attr_label($vcf, $pvalue, $value);
				$save_data[$value.':'.$label][] = $p[0];
	} }

	$property = $vcf->getProperties("ADR");
	foreach ($property as $pkey => $pvalue){
		$p = $pvalue->getComponents();
		$label = $this->get_attr_label($vcf, $pvalue, 'address');
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
	$this->set_displayname($save_data);

	return array(
		'save_data'    => $save_data,
		'vcf'          => $vcf,
		'needs_update' => $needs_update,
	);
	}}}

	private function find_free_uid()
	{{{
	// find an unused UID
	$cuid = $this->guid();
	while ($this->get_record_from_carddav("$cuid.vcf")){
		$cuid = $this->guid();
	}
	return $cuid;
	}}}

	/**
	 * Create a new contact record
	 *
	 * @param array Assoziative array with save data
	 *  Keys:   Field name with optional section in the form FIELD:SECTION
	 *  Values: Field value. Can be either a string or an array of strings for multiple values
	 * @param boolean True to check for duplicates first
	 * @return mixed The created record ID on success, False on error
	 */
	public function insert($save_data, $check=false)
	{{{
	$this->preprocess_rc_savedata($save_data);

	// find an unused UID
	$save_data['cuid'] = $this->find_free_uid();

	$vcf = $this->create_vcard_from_save_data($save_data);
	if(!$vcf) return false;
	$vcfstr = $vcf->toString();

	$uri = $save_data['cuid'] . '.vcf';
	if(!($etag = $this->put_record_to_carddav($uri, $vcfstr)))
		return false;

	$url = concaturl($this->config['url'], $uri);
	$url = preg_replace(';https?://[^/]+;', '', $url);
	$dbid = $this->dbstore_contact($etag,$url,$vcfstr,$save_data);
	if(!$dbid) return false;

	if($this->total_cards != -1)
		$this->total_cards++;
	return $dbid;
	}}}

	/**
	 * Does some common preprocessing with save data created by roundcube.
	 */
	private function preprocess_rc_savedata(&$save_data)
	{{{
	if (array_key_exists('photo', $save_data)
		// photos uploaded via the addressbook interface are provided in binary form
		// photos from other addressbooks (at least roundcubes builtin one) are base64 encoded
		&& base64_decode($save_data['photo'], true) === FALSE)
	{
		$save_data['photo'] = base64_encode($save_data['photo']);
	}

	// heuristic to determine X-ABShowAs setting
	// organization set but neither first nor surname => showas company
	if(!$save_data['surname'] && !$save_data['firstname']
		&& $save_data['organization'] && !array_key_exists('showas',$save_data)) {
		$save_data['showas'] = 'COMPANY';
	}
	// organization not set but showas==company => show as regular
	if(!$save_data['organization'] && $save_data['showas']==='COMPANY') {
		$save_data['showas'] = 'INDIVIDUAL';
	}

	// generate display name according to display order setting
	$this->set_displayname($save_data);
	}}}

	/**
	 * Update a specific contact record
	 *
	 * @param mixed Record identifier
	 * @param array Assoziative array with save data
	 *  Keys:   Field name with optional section in the form FIELD:SECTION
	 *  Values: Field value. Can be either a string or an array of strings for multiple values
	 * @return boolean True on success, False on error
	 */
	public function update($id, $save_data)
	{{{
	// get current DB data
	$contact = self::get_dbrecord($id,'id,cuid,uri,etag,vcard,showas');
	if(!$contact) return false;

	// complete save_data
	$save_data['showas'] = $contact['showas'];
	$this->preprocess_rc_savedata($save_data);

	// create vcard from current DB data to be updated with the new data
	$vcf = new VCard;
	if(!$vcf->parse($contact['vcard'])){
		self::warn("Update: Couldn't parse local vcard: ".$contact['vcard']);
		return false;
	}

	$vcf = $this->create_vcard_from_save_data($save_data, $vcf);
	if(!$vcf) {
		self::warn("Update: Couldn't adopt local vcard to new settings");
		return false;
	}

	$vcfstr = $vcf->toString();
	if(!($etag=$this->put_record_to_carddav($contact['uri'], $vcfstr, $contact['etag']))) {
		self::warn("Updating card on server failed");
		return false;
	}
	$id = $this->dbstore_contact($etag,$contact['uri'],$vcfstr,$save_data,$id);
	return ($id!=0);
	}}}

	/**
	 * Mark one or more contact records as deleted
	 *
	 * @param array  Record identifiers
	 * @param bool   Remove records irreversible (see self::undelete)
	 */
	public function delete($ids)
	{{{
	$deleted = 0;
	foreach ($ids as $dbid) {
		$contact = self::get_dbrecord($dbid,'uri');
		if(!$contact) continue;

		// delete contact from all groups it is contained in
		$groups = $this->get_record_groups($dbid);
		foreach($groups as $group_id => $grpname)
			$this->remove_from_group($group_id, $dbid);

		if($this->delete_record_from_carddav($contact['uri'])) {
			$deleted += self::delete_dbrecord($dbid);
		}
	}

	if($this->total_cards != -1)
		$this->total_cards -= $deleted;
	return $deleted;
	}}}

	/**
	 * Add the given contact records the a certain group
	 *
	 * @param string  Group identifier
	 * @param array   List of contact identifiers to be added
	 * @return int    Number of contacts added
	 */
	public function add_to_group($group_id, $ids)
	{{{
	if (!is_array($ids))
		$ids = explode(',', $ids);

	// get current DB data
	$group = self::get_dbrecord($group_id,'uri,etag,vcard,name,cuid','groups');
	if(!$group)	return false;

	// create vcard from current DB data to be updated with the new data
	$vcf = new VCard;
	if(!$vcf->parse($group['vcard'])){
		self::warn("Update: Couldn't parse local group vcard: ".$group['vcard']);
		return false;
	}

	foreach ($ids as $cid) {
		$contact = self::get_dbrecord($cid,'cuid');
		if(!$contact) return false;

		$vcf->setProperty('X-ADDRESSBOOKSERVER-MEMBER',
			"urn:uuid:" . $contact['cuid'], -1);
	}

	$vcfstr = $vcf->toString();
	if(!($etag = $this->put_record_to_carddav($group['uri'], $vcfstr, $group['etag'])))
		return false;

	if(!$this->dbstore_group($etag,$group['uri'],$vcfstr,$group,$group_id))
		return false;

	$dbh = rcmail::get_instance()->db;
	foreach ($ids as $cid) {
		$dbh->query('INSERT INTO ' .
			get_table_name('carddav_group_user') .
			' (group_id,contact_id) VALUES (?,?)',
				$group_id, $cid);
	}

	return true;
	}}}

	/**
	 * Remove the given contact records from a certain group
	 *
	 * @param string  Group identifier
	 * @param array   List of contact identifiers to be removed
	 * @return int    Number of deleted group members
	 */
	public function remove_from_group($group_id, $ids)
	{{{
	if (!is_array($ids))
		$ids = explode(',', $ids);

	// get current DB data
	$group = self::get_dbrecord($group_id,'name,cuid,uri,etag,vcard','groups');
	if(!$group)	return false;

	// create vcard from current DB data to be updated with the new data
	$vcf = new VCard;
	if(!$vcf->parse($group['vcard'])){
		self::warn("Update: Couldn't parse local group vcard: ".$group['vcard']);
		return false;
	}

	$deleted = 0;
	foreach ($ids as $cid) {
		$contact = self::get_dbrecord($cid,'cuid');
		if(!$contact) return false;

		$vcf->deletePropertyByValue('X-ADDRESSBOOKSERVER-MEMBER',	"urn:uuid:" . $contact['cuid']);
		$deleted++;
	}

	$vcfstr = $vcf->toString();
	if(!($etag = $this->put_record_to_carddav($group['uri'], $vcfstr, $group['etag'])))
		return false;

	if(!$this->dbstore_group($etag,$group['uri'],$vcfstr,$group,$group_id))
		return false;

	self::delete_dbrecord($ids,'group_user','contact_id');
	return $deleted;
	}}}

	/**
	 * Get group assignments of a specific contact record
	 *
	 * @param mixed Record identifier
	 *
	 * @return array List of assigned groups as ID=>Name pairs
	 * @since 0.5-beta
	 */
	public function get_record_groups($id)
	{{{
	$dbh = rcmail::get_instance()->db;
	$sql_result = $dbh->query('SELECT id,name FROM '.
		get_table_name('carddav_groups') . ',' .
		get_table_name('carddav_group_user') .
		' WHERE id=group_id AND contact_id=?', $id);

	$res = array();
	while ($row = $dbh->fetch_assoc($sql_result)) {
		$res[$row['id']] = $row['name'];
	}

	return $res;
	}}}

	/**
	 * Setter for the current group
	 */
	public function set_group($gid)
	{{{
	$this->group_id = $gid;
	}}}

	/**
	 * List all active contact groups of this source
	 *
	 * @param string  Optional search string to match group name
	 * @return array  Indexed list of contact groups, each a hash array
	 */
	public function list_groups($search = null)
	{{{
	$dbh = rcmail::get_instance()->db;

	$searchextra = $search
		? " AND " . $dbh->ilike('name',"%$search%")
		: '';

	$sql_result = $dbh->query('SELECT id,name from ' .
		get_table_name('carddav_groups') .
		' WHERE abook_id=?' .
		$searchextra .
		' ORDER BY name ASC',
		$this->id);

	$groups = array();

	while ($row = $dbh->fetch_assoc($sql_result)) {
		$row['ID'] = $row['id'];
		$groups[] = $row;
	}

	return $groups;
	}}}

	/**
	 * Create a contact group with the given name
	 *
	 * @param string The group name
	 * @return mixed False on error, array with record props in success
	 */
	public function create_group($name)
	{{{
	$cuid = $this->find_free_uid();
	$uri = "$cuid.vcf";

	$save_data = array(
		'name' => $name,
		'kind' => 'group',
		'cuid' => $cuid,
	);

	$vcf = $this->create_vcard_from_save_data($save_data);
	if (!$vcf) return false;
	$vcfstr = $vcf->toString();

	if (!($etag = $this->put_record_to_carddav($uri, $vcfstr)))
		return false;

	$url = concaturl($this->config['url'], $uri);
	$url = preg_replace(';https?://[^/]+;', '', $url);
	if(!($dbid = $this->dbstore_group($etag,$url,$vcfstr,$save_data)))
		return false;

	return array('id'=>$dbid, 'name'=>$name);
	}}}

	/**
	 * Delete the given group and all linked group members
	 *
	 * @param string Group identifier
	 * @return boolean True on success, false if no data was changed
	 */
	public function delete_group($group_id)
	{{{
	// get current DB data
	$group = self::get_dbrecord($group_id,'uri','groups');
	if(!$group)	return false;

	if($this->delete_record_from_carddav($group['uri'])) {
		self::delete_dbrecord($group_id, 'groups');
		self::delete_dbrecord($group_id, 'group_user', 'group_id');
		return true;
	}

	return false;
	}}}

	/**
	 * Rename a specific contact group
	 *
	 * @param string Group identifier
	 * @param string New name to set for this group
	 * @param string New group identifier (if changed, otherwise don't set)
	 * @return boolean New name on success, false if no data was changed
	 */
	public function rename_group($group_id, $newname)
	{{{
	// get current DB data
	$group = self::get_dbrecord($group_id,'uri,etag,vcard,name,cuid','groups');
	if(!$group)	return false;
	$group['name'] = $newname;

	// create vcard from current DB data to be updated with the new data
	$vcf = new VCard;
	if(!$vcf->parse($group['vcard'])){
		self::warn("Update: Couldn't parse local group vcard: ".$group['vcard']);
		return false;
	}

	$vcf->setProperty('FN', $newname);
	$vcf->setProperty('N', $newname);
	$vcfstr = $vcf->toString();

	if(!($etag = $this->put_record_to_carddav($group['uri'], $vcfstr, $group['etag'])))
		return false;

	if(!$this->dbstore_group($etag,$group['uri'],$vcfstr,$group,$group_id))
		return false;

	return $newname;
	}}}

	private static function carddav_des_key()
	{{{
	$rcmail = rcmail::get_instance();
	$imap_password = $rcmail->decrypt($_SESSION['password']);
	while(strlen($imap_password)<24) {
		$imap_password .= $imap_password;
	}
	return substr($imap_password, 0, 24);
	}}}

	public static function encrypt_password($clear)
	{{{
	if(strcasecmp(self::$pwstore_scheme, 'plain')===0)
		return $clear;

	if(strcasecmp(self::$pwstore_scheme, 'encrypted')===0) {

		// encrypted with IMAP password
		$rcmail = rcmail::get_instance();

		$imap_password = self::carddav_des_key();
		$deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

		$crypted = $rcmail->encrypt($clear, 'carddav_des_key');

		// there seems to be no way to unset a preference
		$deskey_backup = $rcmail->config->set('carddav_des_key', '');

		return '{ENCRYPTED}'.$crypted;
	}

	// default: base64-coded password
	return '{BASE64}'.base64_encode($clear);
	}}}

	public static function password_scheme($crypt)
	{{{
	if(strpos($crypt, '{ENCRYPTED}') === 0)
		return 'encrypted';

	if(strpos($crypt, '{BASE64}') === 0)
		return 'base64';

	// unknown scheme, assume cleartext
	return 'plain';
	}}}

	public static function decrypt_password($crypt)
	{{{
	if(strpos($crypt, '{ENCRYPTED}') === 0) {
		$crypt = substr($crypt, strlen('{ENCRYPTED}'));
		$rcmail = rcmail::get_instance();

		$imap_password = self::carddav_des_key();
		$deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

		$clear = $rcmail->decrypt($crypt, 'carddav_des_key');

		// there seems to be no way to unset a preference
		$deskey_backup = $rcmail->config->set('carddav_des_key', '');

		return $clear;
	}

	if(strpos($crypt, '{BASE64}') === 0) {
		$crypt = substr($crypt, strlen('{BASE64}'));
		return base64_decode($crypt);
	}

	// unknown scheme, assume cleartext
	return $crypt;
	}}}

	public static function get_adminsettings()
	{{{
	if(is_array(self::$admin_settings))
		return self::$admin_settings;

	$rcmail = rcmail::get_instance();
	$prefs = array();
	if (file_exists("plugins/carddav/config.inc.php"))
		require("plugins/carddav/config.inc.php");
	self::$admin_settings = $prefs;

	if(is_array($prefs['_GLOBAL'])) {
		$scheme = $prefs['_GLOBAL']['pwstore_scheme'];
		if(preg_match("/^(plain|base64|encrypted)$/", $scheme))
			self::$pwstore_scheme = $scheme;
	}
	return $prefs;
	}}}

	public static function get_dbrecord($id, $cols='*', $table='contacts', $retsingle=true, $idfield='id')
	{{{
	$dbh = rcmail::get_instance()->db;
	$sql_result = $dbh->query("SELECT $cols FROM " .
		get_table_name("carddav_$table") .
		' WHERE ' . $dbh->quoteIdentifier($idfield) . '=?', $id);

	// single row requested?
	if($retsingle)
		return $dbh->fetch_assoc($sql_result);

	// multiple rows requested
	$ret = array();
	while($row = $dbh->fetch_assoc($sql_result))
		$ret[] = $row;
	return $ret;
	}}}

	public static function delete_dbrecord($ids, $table='contacts', $idfield='id')
	{{{
	$dbh = rcmail::get_instance()->db;

	if(is_array($ids)) {
		if(count($ids) <= 0) return 0;
		foreach($ids as &$id)
			$id = $dbh->quote(is_array($id)?$id[$idfield]:$id);
		$dspec = ' IN ('. implode(',',$ids) .')';
	} else {
		$dspec = ' = ' . $dbh->quote($ids);
	}

	$idfield = $dbh->quoteIdentifier($idfield);
	$sql_result = $dbh->query("DELETE FROM " .
		get_table_name("carddav_$table") .
		" WHERE $idfield $dspec" );
	return $dbh->affected_rows($sql_result);
	}}}
}
