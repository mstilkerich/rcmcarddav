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

require("inc/http.php");
require("inc/sasl.php");
require("inc/vcard.php");

function carddavconfig($abookid){{{
	$dbh = rcmail::get_instance()->db;

	// cludge, agreed, but the MDB abstraction seems to have no way of
	// doing time calculations...
	$timequery = ($dbh->db_provider === 'sqlite')
		? "(datetime('now') > datetime(last_updated,refresh_time))" 
		: '('.$dbh->now().'>last_updated+refresh_time)';

	$sql_result = $dbh->query('SELECT id as abookid,name,username,password,url,presetname,'.
		"$timequery as needs_update FROM " .
		get_table_name('carddav_addressbooks') .
		' WHERE id=?', $abookid);

	$abookrow = $dbh->fetch_assoc($sql_result); // can only be a single row
	if(! $abookrow) {
		write_log("carddav.warn", "FATAL! Request for non-existent configuration $abookid");
		return false;
	}

	// postgres will return 't'/'f' here for true/false, normalize it to 1/0
	$nu = $abookrow['needs_update'];
	$nu = ($nu==1 || $nu=='t')?1:0;
	$abookrow['needs_update'] = $nu;

	$abookrow['url'] = str_replace("%u", $abookrow['username'], $abookrow['url']);
	return $abookrow;
}}}

/**
 * Migrates settings to a separate addressbook table.
 */
function migrateconfig($sub = 'CardDAV'){{{
	$rcmail = rcmail::get_instance();
	$prefs_all = $rcmail->config->get('carddav', 0);
	$dbh = $rcmail->db;

	// any old settings to migrate?
	if(!$prefs_all) {
		return;
	}

	// migrate to the multiple addressbook schema first if needed
	if ($prefs_all['db_version'] == 1 || !array_key_exists('db_version', $prefs_all)){
		write_log("carddav", "migrateconfig: DB1 to DB2");
		unset($prefs_all['db_version']);
		// FIXME $p does not seem to be initialized
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

		write_log("carddav", "migrateconfig: move addressbook $desc");
		$dbh->query('INSERT INTO ' .
			get_table_name('carddav_addressbooks') .
			'(name,username,password,url,active,user_id) ' .
			'VALUES (?, ?, ?, ?, ?, ?)',
				$desc, $prefs['username'], $prefs['password'], $prefs['url'],
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

	// is $cat a simple filename?
	// then attach it to the URL
	if (substr($cat, 0, 1) != "/"){
		$urlpart .= "/$cat";
	
	// $cat is a full path, the append it to the
	// hostpart only
	} else {
		$urlpart = "$cat";
	}

	// remove // in the path
	$urlpart = preg_replace(';//+;','/',$urlpart);
	return $hostpart.$urlpart;
}}}

function startElement_addvcards($parser, $n, $attrs) {{{
	global $ctag;

	$n = str_replace("SYNC-", "", $n);
	if (strlen($n)>0){ $ctag .= "||$n";}
}}}
function endElement_addvcards($parser, $n) {{{
	global $ctag;
	global $vcards;
	global $cur_vcard;

	$n = str_replace("SYNC-", "", $n);
	$ctag = preg_replace(";\|\|$n$;", "", $ctag);
	if ($n == "DAV::RESPONSE"){
		$vcards[] = $cur_vcard;
		$cur_vcard = array();
	}
}}}
function characterData_addvcards($parser, $data) {{{
	global $ctag; global $cur_vcard;
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::HREF"){
		$cur_vcard['href'] = $data;
	}
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||URN:IETF:PARAMS:XML:NS:CARDDAV:ADDRESS-DATA"){
		$cur_vcard['vcf'] .= $data;
	}
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||DAV::GETETAG"){
		$cur_vcard['etag'] .= $data;
	}
	if ($ctag == "||DAV::MULTISTATUS||DAV::RESPONSE||DAV::PROPSTAT||DAV::PROP||DAV::GETCONTENTTYPE"){
		$cur_vcard['content-type'] = $data;
	}
}}}

class carddav_backend extends rcube_addressbook
{
	// database primary key, used by RC to search by ID
	public $primary_key = 'id';
	public $coltypes;

	private $filter;
	private $result;
	private $config;
	private $xlabels;

	const DEBUG      = true; // set to true for basic debugging
	const DEBUG_HTTP = false; // set to true for debugging raw http stream

	// contains a the URIs, db ids and etags of the locally stored cards whenever
	// a refresh from the server is attempted. This is used to avoid a separate
	// "exists?" DB query for each card retrieved from the server and also allows
	// to detect whether cards were deleted on the server
	private $existing_card_cache = array();
	private $existing_grpcard_cache = array();

	// total number of contacts in address book
	private $total_cards = -1;
	// filter string for DB queries
	private $search_filter = '';
	// attributes that are redundantly stored in the contact table and need
	// not be parsed from the vcard
	private $table_cols = array('id', 'name', 'email', 'firstname', 'surname');

	private $vcf2rc = array(
		'simple' => array(
			'BDAY' => 'birthday',
			'FN' => 'name',
			'NICKNAME' => 'nickname',
			'NOTE' => 'notes',
			'ORG' => 'organization',
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
			'KIND' => 'kind',   // VCard v4
			'X-ADDRESSBOOKSERVER-KIND' => 'kind', // Apple Addressbook extension
		),
		'multi' => array(
			'EMAIL' => 'email',
			'TEL' => 'phone',
			'URL' => 'website',
		),
	);


	// log helpers
	private static function warn() {
		write_log("carddav.warn", implode(' ', func_get_args()));
	}
	private static function debug() {
		if(self::DEBUG) {
			write_log("carddav", implode(' ', func_get_args()));
		}
	}
	private static function debug_http() {
		if(self::DEBUG_HTTP) {
			write_log("carddav", implode(' ', func_get_args()));
		}
	}

	public function __construct($dbid)
	{{{
	$dbh = rcmail::get_instance()->db;
	$this->ready  = $dbh && !$dbh->is_error();
	$this->groups = true;
	$this->readonly = false;

	$this->config = carddavconfig($dbid);
	if($this->config['presetname']) {
		$prefs = self::get_adminsettings();
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
	 */
	private function storeextrasubtype($typename, $subtype)
	{{{
	$dbh = rcmail::get_instance()->db;
	$sql_result = $dbh->query('INSERT INTO ' .
		get_table_name('carddav_xsubtypes') .
		' (typename,subtype,abook_id) VALUES (?,?,?)',
			$typename,
			$subtype,
			$this->config['abookid']
	);
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
	$dbh = rcmail::get_instance()->db;
	$this->xlabels = array();

	foreach($this->coltypes as $k => $v) {
		if(array_key_exists('subtypes', $v)) {
			$this->xlabels[$k] = array();
		}
	}

	// read extra subtypes
	$sql_result = $dbh->query('SELECT typename,subtype FROM ' .
		get_table_name('carddav_xsubtypes') .
		' WHERE abook_id=?',
		$this->config['abookid']
	);

	while ( $row = $dbh->fetch_assoc($sql_result) ) {
		$this->coltypes[$row['typename']]['subtypes'][] = $row['subtype'];
		$this->xlabels[$row['typename']][] = $row['subtype'];
	}
	}}}

  /**
   * Returns addressbook name (e.g. for addressbooks listing).
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
	$dbh = rcmail::get_instance()->db;
	// these attributes can be directly searched for in the DB
	$fast_search = array($this->primary_key, 'firstname', 'surname', 'email', 'name', 'organization');
	$dbsearch = true;

	// create uniform filter layout
	if(!is_array($filter['value'])) {
		$searchvalue = $filter['value'];
		$filter['value'] = array();

		foreach ($filter['keys'] as $key => $val) {
			$filter['value'][$key] = $searchvalue;
		}
	}

	$newfilter = array('keys' => array(), 'value' => array());
	$this->search_filter = '';

	foreach ($filter['keys'] as $arrk => $filterfield) {
		$searchvalue = $filter['value'][$arrk];

		// the special filter field key '*' means search any field
		// in this case, we don't need any additional search filters
		// and we cannot use the DB to prefilter	
		if ($filterfield === '*') {
			$this->filter = $filter;
			$this->search_filter = " AND " . $dbh->ilike('vcard',"%$searchvalue%");
			return;
		}

		// common keys can be filtered at the DB layer
		if(in_array($filterfield, $fast_search)) {
			if($filterfield === $this->primary_key) {
				$this->search_filter .= " OR $filterfield =  " . $dbh->quote($searchvalue, 'integer');
			} else {
				$this->search_filter .= " OR " . $dbh->ilike($filterfield,"%$searchvalue%");
			}
		} else {
			$dbsearch = false;
		}

		foreach($this->coltypes as $key => $value){
			if (preg_match(";$filterfield;", $key)){
				if ($value['subtypes']){
					foreach ($value['subtypes'] AS $skey => $svalue){
						$newfilter['keys'][]  = "$key:$svalue";
						$newfilter['value'][] = $searchvalue;
					}
				} else {
					$newfilter['keys'][]  = $key;
					$newfilter['value'][] = $searchvalue;
				}
			}
		}
	}

	if(!$dbsearch) {
		$this->search_filter = '';
	} else {
		$this->search_filter = preg_replace("/^ OR /", "", $this->search_filter, 1);
		$this->search_filter = ' AND (' . $this->search_filter . ') ';
	}
	$this->filter = $newfilter;
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
	$this->search_filter = '';
  }}}

	private function set_displayname(&$save_data)
	{{{
	if(strcasecmp($save_data['showas'], 'COMPANY') == 0) {
		$save_data['name']     = $save_data['organization'];
		$save_data['sortname'] = $save_data['organization'];
	} else if(!$save_data['kind']) {
		if($this->config['displayorder'] === 'lastfirst') {
			$save_data['name'] = $save_data['surname'].", ".$save_data['firstname'];
		} else {
			$save_data['name'] = $save_data['firstname']." ".$save_data['surname'];
		}
		if($this->config['sortorder'] === 'firstname') {
			$save_data['sortname'] = $save_data['firstname'].$save_data['surname'];
		} else {
			$save_data['sortname'] = $save_data['surname'].$save_data['firstname'];
		}
	}
	}}}

	private function dbstore_group($vcard) {{{
	$dbh = rcmail::get_instance()->db;

	$card_exists = false;
	if(array_key_exists($vcard['href'], $this->existing_grpcard_cache)) {
		$card_exists = true;
		$group = $this->existing_grpcard_cache[$vcard['href']];
		$dbid = $group['id'];
		
		// delete from the cache, cards left are known to be deleted from the server
		unset($this->existing_grpcard_cache[$vcard['href']]);

		// abort if card has not changed
		if($group['etag'] === $vcard['etag']) {
			self::debug("dbstore: UNCHANGED group card " . $vcard['href']);
			return $dbid;
		}
	}
	
	$save_data = $this->create_save_data_from_vcard($vcard['vcf']);
	$vcf = $save_data['vcf'];
	$save_data = $save_data['save_data'];
	if($save_data['kind'] !== 'group') {
		self::warn("Attempt to store non-group vcard as a group: " . print_r($save_data['kind'],true) . ' VCF: '	. $vcard['vcf']);
		return false;
	}

	if($card_exists) {
		self::debug("dbstore: UPDATE group card " . $vcard['href']);
		$sql_result = $dbh->query('UPDATE ' .
			get_table_name('carddav_groups') .
			' SET name=?,vcard=?,etag=?' .
			' WHERE id=?',
			$save_data['name'], $vcard['vcf'], $vcard['etag'], $dbid);

		// delete current group members (will be reinserted if needed below)	
		$dbh->query('DELETE FROM ' .
			get_table_name('carddav_group_user') .
			' WHERE group_id=?', $dbid);

	} else {
		self::debug("dbstore: INSERT group card " . $vcard['href']);
		$sql_result = $dbh->query('INSERT INTO ' .
			get_table_name('carddav_groups') .
			' (abook_id,name,vcard,etag,uri,cuid) VALUES (?,?,?,?,?,?)',
				$this->config['abookid'], $save_data['name'],
				$vcard['vcf'], $vcard['etag'], $vcard['href'],$save_data['cuid']);

		// XXX the parameter is the sequence name for postgres; it doesn't work
		// when using the name of the table. For some reason it still provides
		// the correct ID for MySQL...
		$dbid = $dbh->insert_id('carddav_group_ids');
	}

	// add group members
	$cuid2dbid = array();
	$sql_result = $dbh->query('SELECT id,cuid from '.
		get_table_name('carddav_contacts') .
		' WHERE abook_id=?', $this->config['abookid']);
	while($row = $dbh->fetch_assoc($sql_result)) {
		$cuid2dbid[$row['cuid']] = $row['id'];
	}

	$members = $vcf->getProperties('X-ADDRESSBOOKSERVER-MEMBER');
	self::warn("Group " . $save_data['name'] . ' has ' . count($members) . ' members');
	foreach($members as $mbr) {
		$mbr = $mbr->getComponents(':');
		if(!$mbr) continue;
		if(count($mbr)!=3 || $mbr[0] !== 'urn' || $mbr[1] !== 'uuid') {
			self::warn("don't know how to interpret group membership: " . $implode(':', $mbr));
			continue;
		}
		if(!array_key_exists($mbr[2], $cuid2dbid)) {
			self::warn("member uid not found " . $implode(':', $mbr));
			continue;
		}
		$dbh->query('INSERT INTO '.
			get_table_name('carddav_group_user') .
			' (group_id,contact_id) VALUES (?,?)',
			$dbid, $cuid2dbid[$mbr[2]]);
	}

	return $dbid;
	}}}

	/**
	 * Stores a vcard to the local database.
	 *
	 * If the cache of existing cards is initialized with the existing cards in the
	 * DB, the card will only be stored if the provided etag is different from the
	 * stored one. This function removes all cards found in the card cache from the
	 * cache once processed.
	 *
	 * @param array Associative array that contains the values with the keys:
	 *               - vcf:  the VCard content as a string
	 *               - etag: the etag of the VCard on the CardDAV server, identifying the version
	 *               - href: the URI of the vcard on the CardDAV server
	 *
	 * @param array Optional roundcube representation of the VCard. Will be created on the fly
	 *               from the VCard content if not provided.
	 *
	 * @param int   If an existing card is updated, the database id of that card.
	 *               If not provided a new card will be created. If the cache
	 *               of existing cards was initialized, it will be used to acquire
	 *               the database id of existing cards and this parameter needs not
	 *               be provided.
	 *
	 * @return int  The database id of the created or updated card, false on error.
	 */
	private function dbstore_vcard($vcard, $save_data=null, $dbid=0)
	{{{
	$dbh = rcmail::get_instance()->db;
	$card_exists = ($dbid!=0);

	// SYNC mode: cache of existing cards exists
	// use it to check if we need to update the card
	if(array_key_exists($vcard['href'], $this->existing_card_cache)) {
		$card_exists = true;
		$contact = $this->existing_card_cache[$vcard['href']];
		$dbid = $contact['id'];
		
		// delete from the cache, cards left are known to be deleted from the server
		unset($this->existing_card_cache[$vcard['href']]);

		// abort if card has not changed
		if($contact['etag'] === $vcard['etag']) {
			self::debug("dbstore: UNCHANGED card " . $vcard['href']);
			return $dbid;
		}
	}

	// if no sync data was provided, create it on the fly	
	$vcfstr = $vcard['vcf'];
	if(!$save_data) {
		$save_data = $this->create_save_data_from_vcard($vcard['vcf']);
		if (!$save_data){
			self::warn("Couldn't parse vcard ".$vcard['vcf']);
			return false;
		}
		// update the vcf string if the card was modified (photo inlined)
		if($save_data['needs_update'])
			$vcfstr = $save_data['vcf']->toString();
		$save_data = $save_data['save_data'];
	}

	// build email search string
	$email_keys = preg_grep('/^email:/', array_keys($save_data));
	$email_addrs = array();
	foreach($email_keys as $email_key) {
		$email_addrs[] = implode(", ", $save_data[$email_key]);
	}
	$emails = implode(', ', $email_addrs);

	$qparams = array(
		$save_data['name'], $save_data['sortname'], $emails,
		$save_data['firstname'], $save_data['surname'],	$save_data['organization'],
		$vcfstr, $vcard['etag'], $save_data['showas']?$save_data['showas']:''
	);
	$qcolumns= array(
		'name','sortname','email',
		'firstname','surname','organization',
		'vcard','etag','showas'
	);

	// does the card already exist in the local db? yes => update
	if($card_exists) {
		self::debug("dbstore: UPDATE card " . $vcard['href']);
		$qparams[]  = $dbid;
		$sql_result = $dbh->query('UPDATE ' .
			get_table_name('carddav_contacts') .
			' SET ' . implode('=?,',$qcolumns) . '=?' .
			' WHERE id=?',
			$qparams);

	// does not exist => insert new card
	} else {
		$qcolumns[] = 'abook_id'; $qparams[] = $this->config['abookid'];
		$qcolumns[] = 'uri';      $qparams[] = $vcard['href'];
		$qcolumns[] = 'cuid';     $qparams[] = $save_data['cuid'];

		self::debug("dbstore: INSERT card " . $vcard['href']);
		$sql_result = $dbh->query('INSERT INTO ' .
			get_table_name('carddav_contacts') .
			' ('. implode(',', $qcolumns) . ') VALUES (?' . str_repeat(',?', count($qcolumns)-1) .')',
				$qparams);
		// XXX the parameter is the sequence name for postgres; it doesn't work
		// when using the name of the table. For some reason it still provides
		// the correct ID for MySQL...
		$dbid = $dbh->insert_id('carddav_contact_ids');
	}

	if($dbh->is_error()) {
		$this->set_error(self::ERROR_SAVING, $dbh->is_error());
		return false;
	}

	// return database id of the card
	return $dbid;
	}}}

	/**
	 * Parses a textual list of VCards and creates a local copy.
	 *
	 * @param  string String representation of one or more VCards.
	 * @return int    The number of cards successfully parsed and stored.
	 */
  private function addvcards($reply, $try = 0)
  {{{
	$dbh = rcmail::get_instance()->db;
	global $vcards;
	$vcards = array();
	$xml_parser = xml_parser_create_ns();
	xml_set_element_handler($xml_parser, "startElement_addvcards", "endElement_addvcards");
	xml_set_character_data_handler($xml_parser, "characterData_addvcards");
	xml_parse($xml_parser, $reply, true);
	xml_parser_free($xml_parser);
	$tryagain = array();

	$x = 0;
	foreach ($vcards as $vcard){
		if (!preg_match(";BEGIN;", $vcard['vcf'])){
			// Seems like the server didn't give us the vcf data
			$tryagain[] = $vcard['href'];
			continue;
		}

		if (preg_match(";X-ADDRESSBOOKSERVER-KIND:group;", $vcard['vcf'])){
			if(!$this->dbstore_group($vcard))
				return false;
		} else {
			if(!$this->dbstore_vcard($vcard))
			 return false;
			$x++;
		}
	}

	if ($try < 3 && count($tryagain) > 0){
		$reply = $this->query_addressbook_multiget($tryagain);
		$reply = $reply["body"];
		$numcards = $this->addvcards($reply, ++$try);
		if(!$numcards) return false;
		$x += $numcards;
	}
	return $x;
  }}}

	/**
	 * @param array config array containing at least the keys
	 *             - url: base url if $url is a relative url
	 *             - username
	 *             - password
	 */
  public static function cdfopen($caller, $url, $opts, $carddav)
  {{{
	$http=new http_class;
	$http->timeout=10;
	$http->data_timeout=0;
	$http->user_agent="RCM CardDAV plugin/TRUNK";
	$http->follow_redirect=1;
	$http->redirection_limit=5;
	$http->prefer_curl=1;

	if(!strpos($url, '://')) {
		$url = concaturl($carddav['url'], $url);
	}

	self::debug("cdfopen: $caller requesting $url");

	$url = preg_replace(";://;", "://".urlencode($carddav['username']).":".urlencode($carddav['password'])."@", $url);
	$error = $http->GetRequestArguments($url,$arguments);
	$arguments["RequestMethod"] = $opts['http']['method'];
	if (strlen($opts['http']['content']) > 0 && $opts['http']['method'] != "GET"){
		$arguments["Body"] = $opts['http']['content']."\r\n";
	}
	if (is_array($opts['http']['header'])){
		foreach ($opts['http']['header'] as $key => $value){
			$h = explode(": ", $value);
			$arguments["Headers"][$h[0]] = $h[1];
		}
	} else {
		$h = explode(": ", $opts['http']['header']);
		$arguments["Headers"][$h[0]] = $h[1];
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
					self::warn("cdfopen: Could not read reply body: ".$error);
				}
			} else {
				self::warn("cdfopen: Could not read reply header: ".$error);
			}
		} else {
			self::warn("cdfopen: Could not send request: ".$error);
		}
	} else {
		self::warn("cdfopen: Could not open: ".$error);
		self::debug_http("cdfopen failed: ".var_export($http, true));
	}
	return -1;
  }}}

	/**
	 * Synchronizes the local card store with the CardDAV server.
	 */
	private function refreshdb_from_server()
	{{{
	$dbh = rcmail::get_instance()->db;

	// determine existing local contact URIs and ETAGs
	$sql_result = $dbh->query('SELECT uri,id,etag FROM ' .
		get_table_name('carddav_contacts') .
		' WHERE abook_id=?',
		$this->config['abookid']);

	while($contact = $dbh->fetch_assoc($sql_result)) {
		$this->existing_card_cache[$contact['uri']] = array(
			'id'=>$contact['id'],
			'etag'=>$contact['etag']
		);
	}

	// determine existing local group URIs and ETAGs
	$sql_result = $dbh->query('SELECT uri,id,etag FROM ' .
		get_table_name('carddav_groups') .
		' WHERE abook_id=?',
		$this->config['abookid']);

	while($group = $dbh->fetch_assoc($sql_result)) {
		$this->existing_grpcard_cache[$group['uri']] = array(
			'id'=>$group['id'],
			'etag'=>$group['etag']
		);
	}

	$records = $this->list_records_sync_collection($cols, $subset);
	if ($records < 0){ // returned error -1
		$records = $this->list_records_propfind_resourcetype($cols, $subset);
	}

	// delete cards not present on the server anymore
	if ($records >= 0) {
		foreach($this->existing_card_cache as $uri => $value) {
			$sql_result = $dbh->query('DELETE FROM ' .
				get_table_name('carddav_contacts') .
				' WHERE id=?', $value['id']);
			self::debug("deleted local card: $uri");
		}
		foreach($this->existing_grpcard_cache as $uri => $value) {
			$sql_result = $dbh->query('DELETE FROM ' .
				get_table_name('carddav_groups') .
				' WHERE id=?', $value['id']);
			self::debug("deleted group card: $uri");
		}
	}
	$this->existing_card_cache = array();
	$this->existing_grpcard_cache = array();
	
	// set last_updated timestamp
	$dbh->query('UPDATE ' .
		get_table_name('carddav_addressbooks') .
		' SET last_updated=' . $dbh->now() .' WHERE id=?',
			$this->config['abookid']);
	}}}

	/**
	 * List the current set of contact records
	 *
	 * @param  array   List of cols to show, Null means all
	 * @param  int     Only return this number of records, use negative values for tail
	 * @param  boolean True to skip the count query (select only)
	 * @return array   Indexed list of contact records, each a hash array
	 */
  public function list_records($cols=null, $subset=0, $nocount=false)
  {{{
	// refresh from server if refresh interval passed
	if ( $this->config['needs_update'] == 1 )
		$this->refreshdb_from_server();

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


	public static function find_addressbook($config)
	{{{
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<a:propfind xmlns:a="DAV:">
				<a:prop>
					<a:current-user-principal/>
				</a:prop>
			</a:propfind>';
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);

	$reply = self::cdfopen("geturl", "/", $opts, $config);
	if ($reply == -1) // error occured, as opposed to "" which means empty reply
		return false;

	$xml = new SimpleXMLElement($reply['body']);
	$princurl = $xml->response->propstat->prop->{'current-user-principal'}->href;
	self::debug("find_addressbook Principal URL: $princurl");

	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<a:propfind xmlns:a="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">>
				<a:prop>
					<C:addressbook-home-set/>
				</a:prop>
			</a:propfind>';
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);
	
	$reply = self::cdfopen("geturl", $princurl, $opts, $config);
	if ($reply == -1) // error occured, as opposed to "" which means empty reply
		return false;

	$xml = new SimpleXMLElement($reply['body']);
	$abookhome = $xml->response->propstat->prop->{'addressbook-home-set'}->href;
	self::debug("find_addressbook addressbook home: $abookhome");

	if(!preg_match(';^[^/]+://[^/]+;', $abookhome, $match))
		return false;
	$serverpart = $match[0];

	$xmlquery =
		'<?xml version="1.0" encoding="utf-8"?'.'>
		<D:propfind xmlns:D="DAV:"><D:prop>
  		<D:resourcetype />
		</D:prop></D:propfind>';
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);
	
	$reply = self::cdfopen("geturl", $abookhome, $opts, $config);
	if ($reply == -1) // error occured, as opposed to "" which means empty reply
		return false;

	$xml = new SimpleXMLElement($reply['body']);
	foreach($xml->response as $coll) {
		if($coll->propstat->prop->resourcetype->addressbook) {
			$serverpart .= $coll->href;
			self::debug("find_addressbook found: $serverpart");
			return $serverpart;
		}
	}
	return false;
	}}}

  /**
   * Retrieves the Card URIs from the CardDAV server
   *
   * @return int  number of cards in collection, -1 on error
   */
  private function list_records_sync_collection($cols, $subset)
  {{{
	$records = 0;
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<D:sync-collection xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
			<D:sync-token></D:sync-token>
			<D:prop>
				<D:getcontenttype/>
				<D:getetag/>
				<D:allprop/>
				<C:address-data>
					<C:allprop/>
				</C:address-data>
			</D:prop>
			<C:filter/>
			</D:sync-collection>';
	$opts = array(
		'http'=>array(
			'method'=>"REPORT",
			'header'=>array("Depth: infinite", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);

	$reply = self::cdfopen("list_records_sync_collection", "", $opts, $this->config);
	if ($reply == -1) // error occured, as opposed to "" which means empty reply
		return -1;

	$reply = $reply["body"];
	if (strlen($reply)) {
		$records = $this->addvcards($reply);
	}
	return $records;
  }}}

	private function list_records_filter_generic($all_addr, $skip_rows, $max_rows)
	{{{
		$addresses = array();

		foreach($all_addr as $a) {
			if(count($addresses) >= $max_rows)
				break;

			foreach ($filter["keys"] as $key => $filterfield) {
				$filtervalue = $filter["value"][$key];
				$does_match = false;

				// check match
				if (is_array($a['save_data'][$filterfield])){
					// TODO: We should correctly iterate here ... Good enough for now
					foreach($a['save_data'][$value] AS $akey => $avalue){
						if (@preg_match(";".$filtervalue.";i", $avalue)){
							$does_match = true;
							break;
						}
					}
				} else if (preg_match(";".$filtervalue.";i", $a['save_data'][$filterfield])){
					$does_match = true;
				}

				if($does_match) {
					if($skip_rows > 0) {
						$skip_rows--;
					} else {
						$addresses[] = $a;
					}
				}
			}
		}

		return $addresses;
	}}}

	private function list_records_readdb($cols, $subset=0, $count_only=false)
	{{{
	$dbh = rcmail::get_instance()->db;

	// true if we can use DB filtering or no filtering is requested
	$filter = $this->get_search_set();
	$this->determine_filter_params($cols,$subset,$fast_filter, $firstrow, $numrows, $read_vcard);

	$dbattr = $read_vcard ? 'vcard' : 'firstname,surname,email';

	if($fast_filter) {
		$limit_index = $firstrow;
		$limit_rows  = $numrows;
	} else { // take all rows and filter on application level
		$limit_index = 0;
		$limit_rows  = 0;
	}

	$xfrom = '';
	$xwhere = '';
	if($this->group_id) {
		$xfrom = ',' . get_table_name('carddav_group_user');
		$xwhere = ' AND id=contact_id AND group_id=' . $dbh->quote($this->group_id) . ' ';
	}

	$sql_result = $dbh->limitquery("SELECT id,name,$dbattr FROM " .
		get_table_name('carddav_contacts') . $xfrom .
		' WHERE abook_id=? ' . $xwhere .
		$this->search_filter .
		' ORDER BY sortname ASC',
		$limit_index,
		$limit_rows,
		$this->config['abookid']
	);

	$addresses = array();
	while($contact = $dbh->fetch_assoc($sql_result)) {
		if($read_vcard) {
			$save_data = $this->create_save_data_from_vcard($contact['vcard']);
			if (!$save_data){
				self::warn("Couldn't parse vcard ".$contact['vcard']);
				continue;
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

	// generic filter if needed
	if(!$fast_filter)
		$addresses = list_records_filter_generic($addresses, $firstrow, $numrows);

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
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
				<D:prop>
					<D:getetag/>
					<C:address-data>
						<C:allprop/>
					</C:address-data>
				</D:prop> ';
	foreach ($hrefs as $href){
		$xmlquery .= "<D:href>$href</D:href> ";
	}
	$xmlquery .= "</C:addressbook-multiget>";

	$optsREPORT = array(
		'http' => array(
			'method'=>"REPORT",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=>$xmlquery
		)
	);

	$reply = self::cdfopen("query_addressbook_multiget", "", $optsREPORT, $this->config);
	return $reply;
  }}}

	private function list_records_propfind_resourcetype($cols, $subset)
  {{{
	$records = 0;
	$xmlquery =
		'<?xml version="1.0" encoding="utf-8" ?'.'>
			<a:propfind xmlns:a="DAV:">
				<a:prop>
					<a:resourcetype/>
				</a:prop>
			</a:propfind>';
	$opts = array(
		'http'=>array(
			'method'=>"PROPFIND",
			'header'=>array("Depth: 1", "Content-Type: text/xml; charset=\"utf-8\""),
			'content'=> $xmlquery
		)
	);

	$reply = self::cdfopen("list_records_propfind_resourcetype", "", $opts, $this->config);
	if ($reply == -1) // error occured, as opposed to "" which means empty reply
		return -1;

	$reply = $reply["body"];
	if (strlen($reply)) {
		$xml_parser = xml_parser_create_ns();
		global $vcards;
		$vcards = array();
		xml_set_element_handler($xml_parser, "startElement_addvcards", "endElement_addvcards");
		xml_set_character_data_handler($xml_parser, "characterData_addvcards");
		xml_parse($xml_parser, $reply, true);
		xml_parser_free($xml_parser);
		$urls = array();
		foreach($vcards as $vcard){
			$urls[] = $vcard['href'];
		}
		$reply = $this->query_addressbook_multiget($urls);
		$reply = $reply["body"];
		$records += $this->addvcards($reply);
	}
	return $records;
  }}}

  /**
   * Search records
   *
   * @param array   List of fields to search in
   * @param string  Search value
   * @param boolean True if results are requested, False if count only
   * @param boolean True to skip the count query (select only)
   * @param array   List of fields that cannot be empty
   * @return object rcube_result_set List of contact records and 'count' value
   */
  public function search($fields, $value, $strict=false, $select=true, $nocount=false, $required=array())
  {{{ // TODO this interface is not yet fully implemented
	$f = array();
	if (is_array($fields)){
		foreach ($fields as $v){
			$f["keys"][] = $v;
		}
	} else {
		$f["keys"][] = $fields;
	}
	$f["value"] = $value;
	$this->set_search_set($f);
	if (!$this->list_records()){
		return false;
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
	private function _count($cols=null)
	{{{
	if($this->total_cards < 0) {
		$dbh = rcmail::get_instance()->db;

		$this->determine_filter_params($cols, 0, $fast_filter, $firstrow, $numrows, $read_vcard);

		if($fast_filter) {
			$sql_result = $dbh->query('SELECT COUNT(id) as total_cards FROM ' .
				get_table_name('carddav_contacts') .
				' WHERE abook_id=?' .
				$this->search_filter,
				$this->config['abookid']
			);
	
			$resultrow = $dbh->fetch_assoc($sql_result);
			$this->total_cards = $resultrow['total_cards'];

		} else { // else we just use list_records (slow...)
			$this->total_cards = list_records_readdb($cols, 0, true);
		}
	}
	return $this->total_cards;
	}}}

	private function determine_filter_params($cols, $subset, &$fast_filter, &$firstrow, &$numrows, &$read_vcard) {
		$filter = $this->get_search_set();

		// true if we can use DB filtering or no filtering is requested
		$fast_filter = (strlen($this->search_filter)>0) || empty($filter) || empty($filter['keys']);
		
		// determine whether we have to parse the vcard or if only db cols are requested
		$read_vcard = !$cols || count(array_intersect($cols, $this->table_cols)) < count($cols);
		
		// determine result subset needed
		$firstrow = ($subset>=0) ? $this->result->first : ($this->result->first+$this->page_size+$subset);
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
	if (!strlen($reply["body"])) { return false; }
	if ($reply["status"] == 404){
		self::warn("Request for VCF '$uid' which doesn't exits on the server.");
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
	
	$dbh = rcmail::get_instance()->db;
	$sql_result = $dbh->query('SELECT vcard FROM ' .
		get_table_name('carddav_contacts') .
		' WHERE abook_id=? AND id=?',
		$this->config['abookid'], $oid);

	$contact = $dbh->fetch_assoc($sql_result);
	if(!$contact['vcard']) {
		return false;
	}

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
				"Content-Type: text/vcard",
				$matchhdr,
			),
		)
	);
	$reply = self::cdfopen("put_record_to_carddav", $id, $opts, $this->config);
	if ($reply["status"] >= 200 && $reply["status"] < 300) {
		return $reply["headers"]["etag"];
	}

	return false;
  }}}

  private function delete_record_from_carddav($id, $vcf = "")
  {{{
	$this->result = $this->count();
	$opts = array(
		'http'=>array(
			'method'=>"DELETE",
		)
	);
	$id = preg_replace(";_rcmcddot_;", ".", $id);
	$reply = self::cdfopen("delete_record_from_carddav", $id, $opts, $this->config);
	if ($reply["status"] == 204){
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
  private function create_vcard_from_save_data($cuid, $save_data, $vcf=null)
  {{{
	if(!$vcf) { // create fresh minimal vcard
		$vcfstr = array(
			"BEGIN:VCARD",
			"VERSION:3.0",
			"UID:$cuid",
			"REV:".date("c"),
			"END:VCARD"
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
	$vcf->setProperty("N", $save_data['surname'],   0,0);
	$vcf->setProperty("N", $save_data['firstname'], 0,1);
	$vcf->setProperty("N", $save_data['middlename'],0,2);
	$vcf->setProperty("N", $save_data['prefix'],    0,3);
	$vcf->setProperty("N", $save_data['suffix'],    0,4);

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

		foreach ($this->coltypes[$rckey]['subtypes'] AS $subtype){
			$rcqkey = $rckey.':'.$subtype;

			if(array_key_exists($rcqkey, $save_data)) {
			foreach($save_data[$rcqkey] as $evalue) {
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
		$xlabel = strtolower($pvalue->params['TYPE'][0]);
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
	if ($reply["status"] == 200){
		$save_data['photo'] = base64_encode($reply['body']);
		return true;
	}
	self::warn("Downloading $uri failed: " . $reply["status"]);
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
	$save_data = array();

	foreach ($this->vcf2rc['simple'] as $vkey => $rckey){
		$property = $vcf->getProperty($vkey);
		if ($property){
			$p = $property->getComponents();
			$save_data[$rckey] = $p[0];
		}
	}

	// inline photo if external reference
	if($save_data['photo']) {
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
		if(count($N)==5) {
			$save_data['surname']    = $N[0];
			$save_data['firstname']  = $N[1];
			$save_data['middlename'] = $N[2];
			$save_data['prefix']     = $N[3];
			$save_data['suffix']     = $N[4];
		}
	}

	foreach ($this->vcf2rc['multi'] as $key => $value){
		$property = $vcf->getProperties($key);
		if ($property){
			foreach ($property as $pkey => $pvalue){
				$p = $pvalue->getComponents();
				$label = $this->get_attr_label($vcf, $pvalue, $value);
				$save_data[$value.':'.$label][] = $p[0];
	} } }

	$property = $vcf->getProperties("ADR");
	if ($property){
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
	} }

	// set displayname according to settings
	$this->set_displayname($save_data);

	return array(
		'save_data'    => $save_data,
		'vcf'          => $vcf,
		'needs_update' => $needs_update,
	);
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
	$cuid = $this->guid();
	while ($this->get_record_from_carddav("$cuid.vcf")){
		$cuid = $this->guid();
	}
	$save_data['cuid'] = $cuid;

	$vcf = $this->create_vcard_from_save_data($cuid, $save_data);
	if ($vcf == false){
		return false;
	}

	$vcf = $vcf->toString();
	$uri = "$cuid.vcf";
	if ($etag = $this->put_record_to_carddav($uri, $vcf)) {
		$url = concaturl($this->config['url'], $uri);
		$url = preg_replace(';https?://[^/]+;', '', $url);
		$vcard = array (
			'vcf'  => $vcf,
			'etag' => $etag,
			'href' => $url,
		);
		$dbid = $this->dbstore_vcard($vcard, $save_data);
		if($dbid) {
			if($this->total_cards != -1)
				$this->total_cards++; 
			return $dbid;
		}
	}
	return false;
  }}}

	/**
	 * Does some common preprocessing with save data created by roundcube.
	 */
	private function preprocess_rc_savedata(&$save_data)
	{{{
	if (array_key_exists('photo', $save_data)) {
		$save_data['photo'] = base64_encode($save_data['photo']);
	}

	// heuristic to determine X-ABShowAs setting
	// organization set but neither first nor surname => showas company
	if(!$save_data['surname'] && !$save_data['firstname']
		&& $save_data['organization'] && !$save_data['showas']) {
		$save_data['showas'] = 'COMPANY';
	}
	// organization not set but showas==company => show as regular
	if(!$save_data['organization'] && $save_data['showas']==='COMPANY') {
		$save_data['showas'] = '';
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
	$dbh = rcmail::get_instance()->db;

	// get current DB data
	$sql_res = $dbh->query('SELECT id,uri,etag,vcard,showas FROM ' .
		get_table_name('carddav_contacts') .
		' WHERE id=?',
		$id);
	$contact = $dbh->fetch_assoc($sql_result);
	if($contact['id'] != $id)
		return false;
	$url = $contact['uri'];

	// complete save_data
	$save_data['showas'] = $contact['showas'];
	$this->preprocess_rc_savedata($save_data);

	// check if changed on server
	if(!($srv_etag = $this->get_record_from_carddav($url)))
		return false;
	$srv_etag = $srv_etag['etag'];

	// card has changed on server, abort for now
	if($contact['etag'] !== $srv_etag) {
		self::warn("Card Changed $url, abort");
		return false;
	}

	// create vcard from current DB data to be updated with the new data
	$vcf = new VCard;
	if(!$vcf->parse($contact['vcard'])){
		self::warn("Update: Couldn't parse local vcard: ".$contact['vcard']);
		return false;
	}

	$cuid = $vcf->getProperty("UID")->getComponents();
	$cuid = $cuid[0];
	$save_data['cuid'] = $cuid;
	$vcf = $this->create_vcard_from_save_data($cuid, $save_data, $vcf);
	if(!$vcf) {
		self::warn("Update: Couldn't adopt local vcard to new settings");
		return false;
	}

	$vcfstr = $vcf->toString();
	if($etag = $this->put_record_to_carddav($url, $vcfstr, $srv_etag)) {
		$vcard = array (
			'vcf'  => $vcfstr,
			'etag' => $etag,
			'href' => $url,
		);
		$id = $this->dbstore_vcard($vcard, $save_data, $id);
		return ($id>0);
	} else {
		self::warn("Could not store: $vcfstr");
	}

	return false;
  }}}

  /**
   * Mark one or more contact records as deleted
   *
   * @param array  Record identifiers
   * @param bool   Remove records irreversible (see self::undelete)
   */
  public function delete($ids)
  {{{
	$dbh = rcmail::get_instance()->db;
	$deleted = 0;

	foreach ($ids as $dbid){
		$sql_result = $dbh->query('SELECT id,uri FROM '.
			get_table_name('carddav_contacts') .
			' WHERE id=?',
			$dbid);

		$contact = $dbh->fetch_assoc($sql_result);
		if($contact['id'] === $dbid) {
			if($this->delete_record_from_carddav($contact['uri'])) {
				$dbh->query('DELETE FROM '.
					get_table_name('carddav_contacts') .
					' WHERE id=?',
					$dbid);

				$deleted++;
			}
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
  function add_to_group($group_id, $ids)
	{{{
	if (!is_array($ids))
		$ids = explode(',', $ids);
	return false;
  }}}

  /**
   * Remove the given contact records from a certain group
   *
   * @param string  Group identifier
   * @param array   List of contact identifiers to be removed
   * @return int    Number of deleted group members
   */
  function remove_from_group($group_id, $ids)
  {{{
	return false;
  }}}

	/**
	 * Get group assignments of a specific contact record
	 *
	 * @param mixed Record identifier
	 *
	 * @return array List of assigned groups as ID=>Name pairs
	 * @since 0.5-beta
	 */
	function get_record_groups($id)
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

  function get_group()
  {{{
	self::warn("get group");
	return false;
  }}}

  /**
   * Setter for the current group
   */
  function set_group($gid)
  {{{
	self::warn("set group $gid");
	$this->group_id = $gid;
  }}}

  /**
   * List all active contact groups of this source
   *
   * @param string  Optional search string to match group name
   * @return array  Indexed list of contact groups, each a hash array
   */
  function list_groups($search = null)
  {{{
	self::warn("list groups $search");

	$dbh = rcmail::get_instance()->db;

	$searchextra = $search
		? " AND " . $dbh->ilike('name',"%$search%")
		: '';

	$sql_result = $dbh->query('SELECT id,name from ' .
		get_table_name('carddav_groups') .
		' WHERE abook_id=?' .
		$searchextra .
		' ORDER BY name ASC',
		$this->config['abookid']);

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
  function create_group($name)
  {{{
	self::warn("create group $name");
	return false;
  }}}

  /**
   * Delete the given group and all linked group members
   *
   * @param string Group identifier
   * @return boolean True on success, false if no data was changed
   */
  function delete_group($gid)
  {{{
	self::warn("delete group $gid");
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
  function rename_group($gid, $newname)
  {{{
	self::warn("rename group $gid to $newname");
	return false;
  }}}
		
	public static function get_adminsettings()
	{{{
	$rcmail = rcmail::get_instance();
	$prefs;
	if (file_exists("plugins/carddav/config.inc.php"))
		require("plugins/carddav/config.inc.php");
	return $prefs;
	}}}
}
