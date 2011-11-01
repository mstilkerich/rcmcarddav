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
$rcmail = rcmail::get_instance();
$user = $rcmail->user;;
require_once(dirname(__FILE__) . '/carddav_backend.php');
define("CARDDAV_DB_VERSION", 2);

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

    // use this address book for autocompletion queries
    // (maybe this should be configurable by the user?)
    $config = rcmail::get_instance()->config;
    $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));
    $prefs = carddavconfig("_cd_RAW");
    foreach ($prefs as $key => $value){
      if (!is_array($prefs[$key])){
	      continue;
      }
      if (!in_array("carddav_".base64_encode($key), $sources)) {
        $sources[] = "carddav_".base64_encode($key);
      }
    }
    $config->set('autocomplete_addressbooks', $sources);
  }}}

  public function address_sources($p)
  {{{
    $prefs = carddavconfig("_cd_RAW");
    foreach ($prefs as $key => $value){
      if (!is_array($prefs[$key])){
	      continue;
      }
      if ($prefs[$key]['use_carddav'] == 1)
        $p['sources']["carddav_".base64_encode($key)] = array(
          'id' => "carddav_".base64_encode($key),
          'name' => $key,
          'readonly' => $abook->readonly,
          'groups' => $abook->groups,
        );
    }
    return $p;
  }}}

  public function get_address_book($p)
  {{{
    if (preg_match(";^carddav_(.*)$;", $p['id'], $match)){
      $p['instance'] = new carddav_backend(base64_decode($match[1]));
    }

    return $p;
  }}}

  // user preferences
  function cd_preferences($args)
  {{{
	if($args['section'] != 'cd_preferences')
		return;

	$this->add_texts('localization/', false);
	$rcmail = rcmail::get_instance();
	
	if (version_compare(PHP_VERSION, '5.3.0') < 0) {
		$args['blocks']['cd_preferences'] = array(
			'options' => array(
				array('title'=> Q($this->gettext('cd_php_too_old')), 'content' => PHP_VERSION)
			),
			'name' => Q($this->gettext('cd_title'))
		);
		return $args;
	}

	$prefs_all = carddavconfig("_cd_RAW"); // defined in carddav_backend.php
	
	foreach ($prefs_all as $key => $prefs){
		if (!is_array($prefs)){
			continue;
		}
		$desc = $key;
		$use_carddav = $prefs['use_carddav'];
		$username = $prefs['username'];
		$password = $prefs['password'];
		$url = $prefs['url'];

		$dont_override = $rcmail->config->get('dont_override', array());

		if (in_array('carddav_use_carddav', $dont_override)) {
			$content_use_carddav = $use_carddav ? "Enabled" : "Disabled";
		} else {
			// check box for activating
			$checkbox = new html_checkbox(array('name' => base64_encode($key).'_cd_use_carddav', 'value' => 1));
			$content_use_carddav = $checkbox->show($use_carddav?1:0);
		}

		if (in_array('carddav_username', $dont_override)){
			$content_username = $username;
		} else {
			// input box for username
			$input = new html_inputfield(array('name' => base64_encode($key).'_cd_username', 'type' => 'text', 'autocomplete' => 'off', 'value' => $username));
			$content_username = $input->show();
		}

		if (in_array('carddav_password', $dont_override)){
			$content_password = "***";
		} else {
			// input box for password
			$input = new html_inputfield(array('name' => base64_encode($key).'_cd_password', 'type' => 'password', 'autocomplete' => 'off', 'value' => $password));
			$content_password = $input->show();
		}

		if (in_array('carddav_url', $dont_override)){
			$content_url = str_replace("%u", "$username", $url);
		} else {
			// input box for URL
			$size = isset($prefs['url']) ? strlen($url) : 40;
			$input = new html_inputfield(array('name' => base64_encode($key).'_cd_url', 'type' => 'text', 'autocomplete' => 'off', 'value' => $prefs['url'], 'size' => $size < 40 ? 40 : $size));
			$content_url = $input->show();
		}

		if (in_array('carddav_description', $dont_override)){
			$content_description = $desc;
		} else {
			$input = new html_inputfield(array('name' => base64_encode($key).'_cd_description', 'type' => 'text', 'autocomplete' => 'off', 'value' => $desc, 'size' => 40));
			$content_description = $input->show();
		}

		if (!in_array('carddav_delete', $dont_override)){
			$checkbox = new html_checkbox(array('name' => base64_encode($key).'_cd_delete', 'value' => 1));
			$content_delete = $checkbox->show(0);
		}
		$args['blocks']['cd_preferences'.base64_encode($key)] = array(
			'options' => array(
				array('title'=> Q($this->gettext('cd_description')), 'content' => $content_description),
				array('title'=> Q($this->gettext('cd_use_carddav')), 'content' => $content_use_carddav), 
				array('title'=> Q($this->gettext('cd_username')), 'content' => $content_username), 
				array('title'=> Q($this->gettext('cd_password')), 'content' => $content_password),
				array('title'=> Q($this->gettext('cd_url')), 'content' => $content_url),
				array('title'=> Q($this->gettext('cd_delete')), 'content' => $content_delete),
			),
			'name' => $key
		);
	}
	$input = new html_inputfield(array('name' => "new_cd_description", 'type' => 'text', 'autocomplete' => 'off', 'size' => 40));
	$content_description = $input->show();
	$checkbox = new html_checkbox(array('name' => 'new_cd_use_carddav', 'value' => 1));
	$content_use_carddav = $checkbox->show(1);
	$input = new html_inputfield(array('name' => 'new_cd_username', 'type' => 'text', 'autocomplete' => 'off'));
	$content_username = $input->show();
	$input = new html_inputfield(array('name' => 'new_cd_password', 'type' => 'password', 'autocomplete' => 'off'));
	$content_password = $input->show();
	$input = new html_inputfield(array('name' => 'new_cd_url', 'type' => 'text', 'autocomplete' => 'off', 'size' => 40));
	$content_url = $input->show();
	$args['blocks']['cd_preferences_section_new'] = array(
		'options' => array(
			array('title'=> Q($this->gettext('cd_description')), 'content' => $content_description),
			array('title'=> Q($this->gettext('cd_use_carddav')), 'content' => $content_use_carddav), 
			array('title'=> Q($this->gettext('cd_username')), 'content' => $content_username), 
			array('title'=> Q($this->gettext('cd_password')), 'content' => $content_password),
			array('title'=> Q($this->gettext('cd_url')), 'content' => $content_url),
		),
		'name' => Q($this->gettext('cd_description_new'))
	);
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

	$rcmail = rcmail::get_instance();

	$prefs_all_old = carddavconfig("_cd_RAW");
	$prefs_all_new = array('db_version' => CARDDAV_DB_VERSION);

	foreach ($prefs_all_old as $key => $prefs){
		if (!is_array($prefs)){
			continue;
		}
		if (isset($_POST[base64_encode($key)."_cd_delete"])){
			continue;
		}
		$prefs_all_new[get_input_value(base64_encode($key)."_cd_description", RCUBE_INPUT_POST)] = array(
			'use_carddav' => isset($_POST[base64_encode($key).'_cd_use_carddav']) ? 1 : 0,
			'username' => get_input_value(base64_encode($key).'_cd_username', RCUBE_INPUT_POST),
			'password' => get_input_value(base64_encode($key).'_cd_password', RCUBE_INPUT_POST),
			'url' => get_input_value(base64_encode($key).'_cd_url', RCUBE_INPUT_POST),
		);
	}
	
	$new = get_input_value('new_cd_description', RCUBE_INPUT_POST);
	if (strlen($new) > 0){
		$prefs_all_new[$new] = array(
			'use_carddav' => isset($_POST['new_cd_use_carddav']) ? 1 : 0,
			'username' => get_input_value('new_cd_username', RCUBE_INPUT_POST),
			'password' => get_input_value('new_cd_password', RCUBE_INPUT_POST),
			'url' => get_input_value('new_cd_url', RCUBE_INPUT_POST),
		);
	}
	$args['prefs']['carddav'] = $prefs_all_new;
	return($args);
  }}}
}
