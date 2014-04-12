<?php
/*
    RCM CardDAV Plugin
    Copyright (C) 2011-2013 Benjamin Schieder <blindcoder@scavenger.homeip.net>,
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
require_once('carddav_backend.php');
require_once('carddav_discovery.php');
require_once('carddav_common.php');

class carddav extends rcube_plugin
{
	private static $helper;

	// the dummy task is used by the calendar plugin, which requires
	// the addressbook to be initialized
	public $task = 'addressbook|login|mail|settings|dummy';

	public function init()
	{{{
	self::$helper = new carddav_common('BACKEND: ');

	$this->add_hook('addressbooks_list', array($this, 'address_sources'));
	$this->add_hook('addressbook_get', array($this, 'get_address_book'));

	$this->add_hook('preferences_list', array($this, 'cd_preferences'));
	$this->add_hook('preferences_save', array($this, 'cd_save'));
	$this->add_hook('preferences_sections_list',array($this, 'cd_preferences_section'));

	$this->add_hook('login_after',array($this, 'init_presets'));

	if(!array_key_exists('user_id', $_SESSION))
		return;

	// use this address book for autocompletion queries
	// (maybe this should be configurable by the user?)
	$config = rcmail::get_instance()->config;
	$sources = (array) $config->get('autocomplete_addressbooks', array('sql'));

	$dbh = rcmail::get_instance()->db;
	$sql_result = $dbh->query('SELECT id FROM ' .
		get_table_name('carddav_addressbooks') .
		' WHERE user_id=? AND active=1',
		$_SESSION['user_id']);

	while ($abookrow = $dbh->fetch_assoc($sql_result)) {
		$abookname = "carddav_" . $abookrow['id'];
		if (!in_array($abookname, $sources)) {
			$sources[] = $abookname;
		}
	}
	$config->set('autocomplete_addressbooks', $sources);
	}}}

	public function init_presets()
	{{{
	$dbh = rcmail::get_instance()->db;
	$prefs = carddav_common::get_adminsettings();

	// migrate old settings
	carddav_backend::migrateconfig();

	// read existing presets from DB
	$sql_result = $dbh->query('SELECT * FROM ' .
		get_table_name('carddav_addressbooks') .
		' WHERE user_id=? AND presetname is not null',
		$_SESSION['user_id']);

	$existing_presets = array( );
	while ($abookrow = $dbh->fetch_assoc($sql_result)) {
		$pn = $abookrow['presetname'];
		if(!array_key_exists($pn,$existing_presets)) {
			$existing_presets[$pn] = array();
		}
		$existing_presets[$pn][] = $abookrow;
	}

	// add not existing preset addressbooks
	foreach($prefs as $presetname => $preset) {
		if($presetname === '_GLOBAL') continue;

		// addressbooks exist for this preset => update settings
		if(array_key_exists($presetname, $existing_presets)) {
			if(is_array($preset['fixed'])) {
				// update all existing addressbooks for this preset
				foreach($existing_presets[$presetname] as $abookrow) {
					// decrypt password so that the comparison works
					$abookrow['password'] = self::$helper->decrypt_password($abookrow['password']);

					// update: only admin fix keys, only if it's fixed
					// otherwise there may be user changes that should not be destroyed
					$pa = array();

					foreach($preset['fixed'] as $k) {
						if(array_key_exists($k, $abookrow) &&	array_key_exists($k,$preset)) {

							// only update the name if it is used
							if($k === 'name') {
								if(!$preset['carddav_name_only']) {
									$fullname = $abookrow['name'];
									$cnpos = strpos($fullname, ' (');
									if($cnpos === FALSE && strcmp($preset['name'],$fullname)!==0) {
										$pa['name'] = $preset['name'];
									} else if($cnpos !== FALSE && strcmp($preset['name'],substr($fullname,0,$cnpos))!==0) {
										$pa['name'] = $preset['name'] . substr($fullname, $cnpos);
									}
								}

							} else if ($abookrow[$k] != $preset[$k]) {
								$pa[$k] = $preset[$k];
							}
						}
					}

					// only update if something changed
					if(count($pa)===0) continue;

					self::update_abook($abookrow['id'],$pa);
				}
			}

			unset($existing_presets[$presetname]);

		} else { // create new
			$preset['presetname'] = $presetname;
			$preset['password']   = self::$helper->encrypt_password($preset['password']);
			$abname = $preset['name'];

			$discovery = new carddav_discovery();
			$srvs = $discovery->find_addressbooks($preset['url'], $preset['username'], $preset['password'], $preset['preemptive_auth']);

			if(is_array($srvs)) {
			foreach($srvs as $srv){
				if($srv[name]) {
					if($preset[carddav_name_only])
						$preset['name'] = $srv[name];
					else
						$preset['name'] = "$abname (" . $srv[name] . ')';
				} else {
					$preset['name'] = $abname;
				}
				$preset['url'] = $srv['href'];
				self::insert_abook($preset);
			}}
		}
	}

	// delete existing preset addressbooks that where removed by admin
	foreach($existing_presets as $ep) {
		foreach($ep as $abookrow) {
			self::delete_abook($abookrow['id']);
		}
	}
	}}}

	public function address_sources($p)
	{{{
	$dbh = rcmail::get_instance()->db;
	$prefs = carddav_common::get_adminsettings();

	$sql_result = $dbh->query('SELECT id,name,presetname FROM ' .
		get_table_name('carddav_addressbooks') .
		' WHERE user_id=? AND active=1',
		$_SESSION['user_id']);

	while ($abookrow = $dbh->fetch_assoc($sql_result)) {
		$ro = false;
		if($abookrow['presetname'] && $prefs[$abookrow['presetname']]['readonly'])
			$ro = true;

		$p['sources']["carddav_".$abookrow['id']] = array(
			'id' => "carddav_".$abookrow['id'],
			'name' => $abookrow['name'],
			'groups' => true,
			'autocomplete' => true,
			'readonly' => $ro,
		);
	}
	return $p;
	}}}

	public function get_address_book($p)
	{{{
	if (preg_match(";^carddav_(\d+)$;", $p['id'], $match)){
		$p['instance'] = new carddav_backend($match[1]);
	}

	return $p;
	}}}

	private static function process_cd_time($refresht)
	{{{
	if(preg_match('/^(\d+)(:([0-5]?\d))?(:([0-5]?\d))?$/', $refresht, $match)) {
		$refresht = sprintf("%02d:%02d:%02d", $match[1],
			count($match)>3 ? $match[3] : 0,
			count($match)>5 ? $match[5] : 0);
	} else {
		$refresht = '01:00:00';
	}
	return $refresht;
	}}}

	private static function no_override($pref, $abook, $prefs)
	{{{
		$pn = $abook['presetname'];
		if(!$pn) return false;

		// never enable user change for preset URLs
		if($pref === 'url') return true;

		if(!is_array($prefs[$pn])) return false;
		if(!is_array($prefs[$pn]['fixed'])) return false;

		return in_array($pref,$prefs[$pn]['fixed']);
	}}}

	/**
	 * Builds a setting block for one address book for the preference page.
	 */
	private function cd_preferences_buildblock($blockheader,$abook,$prefs)
	{{{
		$abookid = $abook['id'];

		if (self::no_override('active', $abook, $prefs)) {
			$content_active = $prefs[$abook['presetname']] ? "Enabled" : "Disabled";
		} else {
			// check box for activating
			$checkbox = new html_checkbox(array('name' => $abookid.'_cd_active', 'value' => 1));
			$content_active = $checkbox->show($abook['active']?1:0);
		}

		if (self::no_override('username', $abook, $prefs)) {
			$content_username = $abook['username'] === '%u' ? $_SESSION['username'] : $abook['username'];

		} else {
			// input box for username
			$input = new html_inputfield(array('name' => $abookid.'_cd_username', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['username']));
			$content_username = $input->show();
		}

		if (self::no_override('password', $abook, $prefs)) {
			$content_password = "***";
		} else {
			// input box for password
			$input = new html_inputfield(array('name' => $abookid.'_cd_password', 'type' => 'password', 'autocomplete' => 'off', 'value' => ''));
			$content_password = $input->show();
		}

		if (self::no_override('url', $abook, $prefs)) {
			$content_url = str_replace("%u", $abook['username'], $abook['url']);
		} else {
			// input box for URL
			$size = max(strlen($abook['url']),40);
			$input = new html_inputfield(array('name' => $abookid.'_cd_url', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['url'], 'size' => $size));
			$content_url = $input->show();
		}

		// input box for refresh time
		if (self::no_override('refresh_time', $abook, $prefs)) {
			$content_refresh_time =  $abook['refresh_time'];
		} else {
			$input = new html_inputfield(array('name' => $abookid.'_cd_refresh_time', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['refresh_time'], 'size' => 10));
			$content_refresh_time = $input->show();
		}

		if (self::no_override('name', $abook, $prefs)) {
			$content_name = $abook['name'];
		} else {
			$input = new html_inputfield(array('name' => $abookid.'_cd_name', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['name'], 'size' => 40));
			$content_name = $input->show();
		}

		if (self::no_override('preemptive_auth', $abook, $prefs)) {
			$content_preemptive_auth = ($abook['preemptive_auth'] == 1 ? "yes" : "no");
		} else {
			$checkbox = new html_checkbox(array('name' => $abookid.'_cd_preemptive_auth', 'value' => 1));
			$content_preemptive_auth = $checkbox->show($abook['preemptive_auth']);
		}

		$retval = array(
			'options' => array(
				array('title'=> Q($this->gettext('cd_name')), 'content' => $content_name),
				array('title'=> Q($this->gettext('cd_active')), 'content' => $content_active),
				array('title'=> Q($this->gettext('cd_username')), 'content' => $content_username),
				array('title'=> Q($this->gettext('cd_password')), 'content' => $content_password),
				array('title'=> Q($this->gettext('cd_url')), 'content' => $content_url),
				array('title'=> Q($this->gettext('cd_refresh_time')), 'content' => $content_refresh_time),
				array('title'=> Q($this->gettext('cd_preemptive_auth')), 'content' => $content_preemptive_auth),
			),
			'name' => $blockheader
		);

		if (!$abook['presetname'] && preg_match('/^\d+$/',$abookid)) {
			$checkbox = new html_checkbox(array('name' => $abookid.'_cd_delete', 'value' => 1));
			$content_delete = $checkbox->show(0);
			$retval['options'][] = array('title'=> Q($this->gettext('cd_delete')), 'content' => $content_delete);
		}

		return $retval;
	}}}

	// user preferences
	function cd_preferences($args)
	{{{
		if($args['section'] != 'cd_preferences')
			return;

		$this->include_stylesheet($this->local_skin_path().'/carddav.css');
		$this->add_texts('localization/', false);
		$prefs = carddav_common::get_adminsettings();

		if (version_compare(PHP_VERSION, '5.3.0') < 0) {
			$args['blocks']['cd_preferences'] = array(
				'options' => array(
					array('title'=> Q($this->gettext('cd_php_too_old')), 'content' => PHP_VERSION)
				),
				'name' => Q($this->gettext('cd_title'))
			);
			return $args;
		}

		$abooks = carddav_backend::get_dbrecord($_SESSION['user_id'],'*','addressbooks',false,'user_id');
		foreach($abooks as $abook) {
			$presetname = $abook['presetname'];
			if (empty($presetname) ||
				(!isset($prefs[$presetname]['hide']) || (isset($prefs[$presetname]['hide']) && $prefs[$presetname]['hide'] === FALSE))) {
				$abookid = $abook['id'];
				$blockhdr = $abook['name'];
				if($abook['presetname'])
					$blockhdr .= ' (from preset ' . $abook['presetname'] . ')';
				$args['blocks']['cd_preferences'.$abookid] = $this->cd_preferences_buildblock($blockhdr,$abook,$prefs);
			}
		}

		if(!array_key_exists('_GLOBAL', $prefs) || !$prefs['_GLOBAL']['fixed']) {
			$args['blocks']['cd_preferences_section_new'] = $this->cd_preferences_buildblock(
				Q($this->gettext('cd_newabboxtitle')),
				array(
					'id'           => 'new',
					'active'       => 1,
					'username'     => '',
					'url'          => '',
					'name'         => '',
					'refresh_time' => 1,
					'presetname'   => '',
				), $prefs);
		}

		return($args);
	}}}

	// add a section to the preferences tab
	function cd_preferences_section($args)
	{{{
		$prefs = carddav_common::get_adminsettings();
		if (!isset($prefs['_GLOBAL']['hide_preferences']) || (isset($prefs['_GLOBAL']['hide_preferences']) && $prefs['_GLOBAL']['hide_preferences'] === FALSE)) {
			$this->add_texts('localization/', false);
			$args['list']['cd_preferences'] = array(
				'id'      => 'cd_preferences',
				'section' => Q($this->gettext('cd_title'))
			);
		}
		return($args);
	}}}

	// save preferences
	function cd_save($args)
	{{{
		$this->add_texts('localization/', false);
		if($args['section'] != 'cd_preferences')
			return;
		$prefs = carddav_common::get_adminsettings();
		if (isset($prefs['_GLOBAL']['hide_preferences']) && $prefs['_GLOBAL']['hide_preferences'] === TRUE) {
			return;
		}

		// update existing in DB
		$abooks = carddav_backend::get_dbrecord($_SESSION['user_id'],'id,presetname',
			'addressbooks', false, 'user_id');

		foreach($abooks as $abook) {
			$abookid = $abook['id'];
			if( isset($_POST[$abookid."_cd_delete"]) ) {
				self::delete_abook($abookid);

			} else {
				$newset = array (
					'name' => get_input_value($abookid."_cd_name", RCUBE_INPUT_POST),
					'username' => get_input_value($abookid."_cd_username", RCUBE_INPUT_POST, true),
					'url' => get_input_value($abookid."_cd_url", RCUBE_INPUT_POST),
					'active' => isset($_POST[$abookid.'_cd_active']) ? 1 : 0,
					'refresh_time' => get_input_value($abookid."_cd_refresh_time", RCUBE_INPUT_POST),
					'preemptive_auth' => isset($_POST[$abookid."_cd_preemptive_auth"]) ? 1 : 0,
				);

				// only set the password if the user entered a new one
				$password = get_input_value($abookid."_cd_password", RCUBE_INPUT_POST, true);
				if(strlen($password) > 0) {
					$newset['password'] = $password;
				}

				// remove admin only settings
				foreach($newset as $pref => $value) {
					if(self::no_override($pref, $abook, $prefs)) {
						unset($newset[$pref]);
					}
				}

				self::update_abook($abookid, $newset);
			}
		}

		// add a new address book?
		$new = get_input_value('new_cd_name', RCUBE_INPUT_POST);
		if ( (!array_key_exists('_GLOBAL', $prefs) || !$prefs['_GLOBAL']['fixed']) && strlen($new) > 0) {
			$srv    = get_input_value('new_cd_url', RCUBE_INPUT_POST);
			$usr    = get_input_value('new_cd_username', RCUBE_INPUT_POST, true);
			$pass   = get_input_value('new_cd_password', RCUBE_INPUT_POST, true);
			$pass = self::$helper->encrypt_password($pass);
			$abname = get_input_value('new_cd_name', RCUBE_INPUT_POST);
			$preemptive_auth = isset($_POST["new_cd_preemptive_auth"]) ? 1 : 0;

			$discovery = new carddav_discovery();
			$srvs = $discovery->find_addressbooks($srv, $usr, $pass, $preemptive_auth);

			if(is_array($srvs) && count($srvs)>0) {
				foreach($srvs as $srv){
					self::$helper->debug("ADDING ABOOK " . print_r($srv,true));
					$this_abname = $abname;
					if($srv['name']) {
						$this_abname .= ' (' . $srv['name'] . ')';
					}
					self::insert_abook(array(
						'name'     => $this_abname,
						'username' => $usr,
						'password' => $pass,
						'url'      => $srv['href'],
						'refresh_time' => get_input_value('new_cd_refresh_time', RCUBE_INPUT_POST),
						'preemptive_auth' => $preemptive_auth
					));
				}
			} else {
				$args['abort'] = true;
				$args['message'] = $abname . ': ' . $this->gettext('cd_err_noabfound');
			}
		}

		return($args);
	}}}

	private static function delete_abook($abookid)
	{{{
	carddav_backend::delete_dbrecord($abookid,'addressbooks');
	// we explicitly delete all data belonging to the addressbook, since
	// cascaded deleted are not supported by all database backends
	// ...contacts
	carddav_backend::delete_dbrecord($abookid,'contacts','abook_id');
	// ...custom subtypes
	carddav_backend::delete_dbrecord($abookid,'xsubtypes','abook_id');
	// ...groups and memberships
	$delgroups = carddav_backend::get_dbrecord($abookid, 'id as group_id', 'groups', false, 'abook_id');
	carddav_backend::delete_dbrecord($abookid,'groups','abook_id');
	carddav_backend::delete_dbrecord($delgroups,'group_user','group_id');
	}}}

	private static function insert_abook($pa)
	{{{
	$dbh = rcmail::get_instance()->db;

	// check parameters
	if(array_key_exists('refresh_time', $pa)) {
		$pa['refresh_time'] = self::process_cd_time($pa['refresh_time']);
	}
	$pa['user_id']      = $_SESSION['user_id'];

	// required fields
	$qf=array('name','username','password','url','user_id');
	$qv=array();
	foreach($qf as $f) {
		if(!array_key_exists($f,$pa)) return false;
		$qv[] = $pa[$f];
	}

	// optional fields
	$qfo = array('active','presetname','refresh_time','preemptive_auth');
	foreach($qfo as $f) {
		if(array_key_exists($f,$pa)) {
			$qf[] = $f;
			$qv[] = $pa[$f];
		}
	}

	$dbh->query('INSERT INTO ' . get_table_name('carddav_addressbooks') .
		'('. implode(',',$qf)  .') ' .
		'VALUES (?'. str_repeat(',?', count($qf)-1) . ')',
		$qv
	);
	}}}

	public static function update_abook($abookid, $pa)
	{{{
	$dbh = rcmail::get_instance()->db;

	// check parameters
	if(array_key_exists('refresh_time', $pa))
		$pa['refresh_time'] = self::process_cd_time($pa['refresh_time']);

	// encrypt the password before storing it
	if(array_key_exists('password', $pa))
		$pa['password'] = self::$helper->encrypt_password($pa['password']);

	// optional fields
	$qfo=array('name','username','password','url','active','refresh_time','sync_token','preemptive_auth');
	$qf=array();
	$qv=array();

	foreach($qfo as $f) {
		if(array_key_exists($f,$pa)) {
			$qf[] = $f;
			$qv[] = $pa[$f];
		}
	}
	if(count($qf) <= 0) return true;

	$qv[] = $abookid;
	$dbh->query('UPDATE ' .
		get_table_name('carddav_addressbooks') .
		' SET ' . implode('=?,', $qf) . '=?' .
		' WHERE id=?',
		$qv
	);
	}}}
}

?>
