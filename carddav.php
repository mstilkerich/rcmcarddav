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
require_once(dirname(__FILE__) . '/carddav_backend.php');

class carddav extends rcube_plugin
{
	public $task = 'addressbook|mail|settings';
	private $abook_id = "CardDAV";

	public function init()
	{{{
		$this->add_hook('addressbooks_list', array($this, 'address_sources'));
		$this->add_hook('addressbook_get', array($this, 'get_address_book'));

		$this->add_hook('preferences_list', array($this, 'cd_preferences'));
		$this->add_hook('preferences_save', array($this, 'cd_save'));
		$this->add_hook('preferences_sections_list',array($this, 'cd_preferences_section'));

		// migrate old settings
		migrateconfig();

		// use this address book for autocompletion queries
		// (maybe this should be configurable by the user?)
		$config = rcmail::get_instance()->config;
		$sources = (array) $config->get('autocomplete_addressbooks', array('sql'));

		$dbh = rcmail::get_instance()->db;
		$sql_result = $dbh->query('SELECT id FROM ' . 
			get_table_name('carddav_addressbooks') .
			' WHERE user_id = ? AND active=1',
			$_SESSION['user_id']);

		while ($abookrow = $dbh->fetch_assoc($sql_result)) {
			$abookname = "carddav_" . $abookrow['id'];
			if (!in_array($abookname, $sources)) {
				$sources[] = $abookname;
			}
		}
		$config->set('autocomplete_addressbooks', $sources);
	}}}

	public function address_sources($p)
	{{{
		$dbh = rcmail::get_instance()->db;
		$sql_result = $dbh->query('SELECT id,name FROM ' . 
			get_table_name('carddav_addressbooks') .
			' WHERE user_id = ? AND active=1',
			$_SESSION['user_id']);

		while ($abookrow = $dbh->fetch_assoc($sql_result)) {
			$p['sources']["carddav_".$abookrow['id']] = array(
				'id' => "carddav_".$abookrow['id'],
				'name' => $abookrow['name'],
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

	/**
	 * Builds a setting block for one address book for the preference page.
	 */
	private function cd_preferences_buildblock($blockheader,$abook,$dont_override)
	{{{
		$abookid = $abook['id'];

		if (in_array('carddav_use_carddav', $dont_override)) {
			$content_use_carddav = $use_carddav ? "Enabled" : "Disabled";
		} else {
			// check box for activating
			$checkbox = new html_checkbox(array('name' => $abookid.'_cd_use_carddav', 'value' => 1));
			$content_use_carddav = $checkbox->show($abook['active']?1:0);
		}

		if (in_array('carddav_username', $dont_override)){
			$content_username = $username;
		} else {
			// input box for username
			$input = new html_inputfield(array('name' => $abookid.'_cd_username', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['username']));
			$content_username = $input->show();
		}

		if (in_array('carddav_password', $dont_override)){
			$content_password = "***";
		} else {
			// input box for password
			$input = new html_inputfield(array('name' => $abookid.'_cd_password', 'type' => 'password', 'autocomplete' => 'off', 'value' => ''));
			$content_password = $input->show();
		}

		if (in_array('carddav_url', $dont_override)){
			$content_url = str_replace("%u", "$username", $url);
		} else {
			// input box for URL
			$size = max(strlen($url),40);
			$input = new html_inputfield(array('name' => $abookid.'_cd_url', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['url'], 'size' => $size));
			$content_url = $input->show();
		}

		// input box for refresh time
		$input = new html_inputfield(array('name' => $abookid.'_cd_refresh_time', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['refresh_time'], 'size' => 10));
		$content_refresh_time = $input->show();

		if (in_array('carddav_description', $dont_override)){
			$content_description = $desc;
		} else {
			$input = new html_inputfield(array('name' => $abookid.'_cd_description', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['name'], 'size' => 40));
			$content_description = $input->show();
		}

		// dropdown for display order
		$input = new html_select(array('name' => $abookid.'_cd_displayorder'));
		$input->add('First Name, Last Name', 'firstlast');
		$input->add('Last Name, First Name', 'lastfirst');
		$content_displayorder = $input->show($abook['displayorder']);

		// dropdown for sort order
		$input = new html_select(array('name' => $abookid.'_cd_sortorder'));
		$input->add('First Name', 'firstname');
		$input->add('Last Name', 'surname');
		$content_sortorder = $input->show($abook['sortorder']);

		$retval = array(
			'options' => array(
				array('title'=> Q($this->gettext('cd_description')), 'content' => $content_description),
				array('title'=> Q($this->gettext('cd_use_carddav')), 'content' => $content_use_carddav), 
				array('title'=> Q($this->gettext('cd_username')), 'content' => $content_username), 
				array('title'=> Q($this->gettext('cd_password')), 'content' => $content_password),
				array('title'=> Q($this->gettext('cd_url')), 'content' => $content_url),
				array('title'=> Q($this->gettext('cd_refresh_time')), 'content' => $content_refresh_time),
				array('title'=> Q($this->gettext('cd_displayorder')), 'content' => $content_displayorder),
				array('title'=> Q($this->gettext('cd_sortorder')), 'content' => $content_sortorder),
			),
			'name' => $blockheader
		);

		if (!in_array('carddav_delete', $dont_override) && preg_match('/^\d+$/',$abookid)) {
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

		$this->add_texts('localization/', false);
		$rcmail = rcmail::get_instance();
		$dbh    = $rcmail->db;

		if (version_compare(PHP_VERSION, '5.3.0') < 0) {
			$args['blocks']['cd_preferences'] = array(
				'options' => array(
					array('title'=> Q($this->gettext('cd_php_too_old')), 'content' => PHP_VERSION)
				),
				'name' => Q($this->gettext('cd_title'))
			);
			return $args;
		}

		$dont_override = $rcmail->config->get('dont_override', array());

		$sql_result = $dbh->query('SELECT * FROM ' . 
			get_table_name('carddav_addressbooks') .
			' WHERE user_id = ?',
			$_SESSION['user_id']);

		while ($abook = $dbh->fetch_assoc($sql_result)) {
			$abookid     = $abook['id'];
			$args['blocks']['cd_preferences'.$abookid] = $this->cd_preferences_buildblock($abook['name'],$abook,$dont_override);
		}

		$args['blocks']['cd_preferences_section_new'] = $this->cd_preferences_buildblock('Configure new addressbook', array( 'id'=>'new', 'active'=>1, 'refresh_time'=>1), array());

		return($args);
	}}}

	// add a section to the preferences tab
	function cd_preferences_section($args)
	{{{
		$this->add_texts('localization/', false);
		$args['list']['cd_preferences'] = array(
			'id'      => 'cd_preferences',
			'section' => Q($this->gettext('cd_title'))
		);
		return($args);
	}}}

	// save preferences
	function cd_save($args)
	{{{
		if($args['section'] != 'cd_preferences')
			return;

		$dbh = rcmail::get_instance()->db;
		// update existing in DB
		$sql_result = $dbh->query('SELECT id,sortorder,displayorder FROM ' . 
			get_table_name('carddav_addressbooks') .
			' WHERE user_id = ?',
			$_SESSION['user_id']);

		while ($abook = $dbh->fetch_assoc($sql_result)) {
			$abookid = $abook['id'];
			if( isset($_POST[$abookid."_cd_delete"]) ) {
				$dbh->query('DELETE FROM ' .
					get_table_name('carddav_addressbooks') .
					' WHERE id = ?', $abookid);
			} else {
				$olddisplayorder = $abook['displayorder'];
				$oldsortorder = $abook['sortorder'];

				$refresht = get_input_value($abookid."_cd_refresh_time", RCUBE_INPUT_POST);
				if(preg_match('/^(\d+)(:([0-5]?\d))?(:([0-5]?\d))?$/', $refresht, $match)) {
					$refresht = sprintf("%02d:%02d:%02d", $match[1],
						$match[3] ? $match[3] : 0,
						$match[5] ? $match[5] : 0);
				} else {
					write_log("carddav.warn", "Could not parse given refresh time '$refresht'");
					$refresht = '01:00:00';
				}

				$displayorder = get_input_value($abookid."_cd_displayorder", RCUBE_INPUT_POST);
				$sortorder = get_input_value($abookid."_cd_sortorder", RCUBE_INPUT_POST);
				$newset = array (
					get_input_value($abookid."_cd_description", RCUBE_INPUT_POST),
					get_input_value($abookid."_cd_username", RCUBE_INPUT_POST),
					get_input_value($abookid."_cd_url", RCUBE_INPUT_POST),
					isset($_POST[$abookid.'_cd_use_carddav']) ? 1 : 0,
					$displayorder, $sortorder, $refresht,
				);

				// only set the password if the user entered a new one
				$extraquery = '';
				$password = get_input_value($abookid."_cd_password", RCUBE_INPUT_POST);
				if(strlen($password) > 0) {
					$extraquery = ',password=?';
					$newset[] = $password;
				}
				$newset[] = $abookid;

				$dbh->query('UPDATE ' .
					get_table_name('carddav_addressbooks') .
					' SET name=?,username=?,url=?,active=?,displayorder=?,sortorder=?,refresh_time=?' .
					$extraquery .
					' WHERE id = ?',
					$newset);

				// update display names if changed
				if($olddisplayorder !== $displayorder) {
					$dostr = ($displayorder==='firstlast')?'firstname," ",surname':'surname,", ",firstname';
					write_log("carddav", "UPDATE DISP ORDER $olddisplayorder TO $displayorder DOSTR $dostr ID $abookid");
					$dbh->query('UPDATE ' .
						get_table_name('carddav_contacts') .
						" SET name=CONCAT($dostr) " .
						" WHERE abook_id=? AND showas=''",
						$abookid);
				}

				// update sort names if setting changed
				if($oldsortorder !== $sortorder) {
					$dostr = ($sortorder==='firstname')?'firstname,surname':'surname,firstname';
					$dbh->query('UPDATE ' .
						get_table_name('carddav_contacts') .
						' SET sortname=CONCAT(' . $dostr . ') ' .
						" WHERE abook_id=? AND showas=''",
						$abookid);
				}
			}
		}

		// add a new address book?	
		$new = get_input_value('new_cd_description', RCUBE_INPUT_POST);
		if (strlen($new) > 0) {
			$srv  = get_input_value('new_cd_url', RCUBE_INPUT_POST);
			$usr  = get_input_value('new_cd_username', RCUBE_INPUT_POST);
			$pass = get_input_value('new_cd_password', RCUBE_INPUT_POST);

			$srv = carddav_backend::find_addressbook(array('url'=>$srv,'password'=>$pass,'username'=>$usr));
			if($srv) {
			$dbh->query('INSERT INTO ' . get_table_name('carddav_addressbooks') .
				'(name,username,password,url,active,displayorder,sortorder,refresh_time,user_id) ' .
				'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
					get_input_value('new_cd_description', RCUBE_INPUT_POST), 
					$usr,$pass,$srv,
					isset($_POST['new_cd_use_carddav']) ? 1 : 0,
					get_input_value('new_cd_displayorder', RCUBE_INPUT_POST),
					get_input_value('new_cd_sortorder', RCUBE_INPUT_POST),
					get_input_value('new_cd_refresh_time', RCUBE_INPUT_POST),
					$_SESSION['user_id']);
			}
		}

		return($args);
	}}}
}
