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
use MStilkerich\CardDavClient\{Account, Config};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\CardDavAddressbook4Roundcube\RoundcubeLogger;
use MStilkerich\CardDavAddressbook4Roundcube\RoundcubeCarddavAddressbook;

require_once("carddav_common.php");

class carddav extends rcube_plugin
{
    /** @var RoundcubeLogger $logger */
    public static $logger;

    // the dummy task is used by the calendar plugin, which requires
    // the addressbook to be initialized
    public $task = 'addressbook|login|mail|settings|dummy';

    /**
     * Default constructor.
     *
     * @param rcube_plugin_api $api Plugin API
     */
    public function __construct($api)
    {
        parent::__construct($api);

        // we do not currently use the roundcube mechanism to save preferences
        // but store preferences to custom database tables
        $this->allowed_prefs = [];
    }

    public function checkMigrations(): void
    {
        $dbh = rcmail::get_instance()->db;

        $db_backend = "unknown";

        switch ($dbh->db_provider) {
            case "mysql":
                $db_backend = "mysql";
                break;
            case "sqlite":
                $db_backend = "sqlite3";
                break;
            case "pgsql":
            case "postgres":
                $db_backend = "postgres";
                break;
        }

        if ($db_backend == "unknown") {
            self::$logger->error("Unknown database backend: " . $dbh->db_provider);
            return;
        }

        # first initialize the carddav_migrations table if it doesn't exist.
        $config = rcmail::get_instance()->config;
        $migrations = array_diff(scandir(dirname(__FILE__) . "/dbmigrations/"), array('..', '.'));
        $mignew = array();
        foreach ($migrations as $k => $v) {
            $mignew[] = $v;
        }
        $migrations = $mignew;
        $qmarks = "?";
        for ($i = 1; $i < count($migrations); $i++) {
            $qmarks .= ",?";
        }

        $dbh->set_option('ignore_key_errors', true);
        $sql_result = $dbh->query('SELECT * FROM ' .
            $dbh->table_name('carddav_migrations') .
            ' WHERE filename IN (' . $qmarks . ');', $migrations);

        if ($sql_result) {
            while ($processed = $dbh->fetch_assoc($sql_result)) {
                if (($key = array_search($processed['filename'], $migrations)) !== false) {
                    unset($migrations[$key]);
                }
            }
        }
        $dbh->set_option('ignore_key_errors', null);

        foreach ($migrations as $migration) {
            self::$logger->notice("In migration: $migration");
            $queries_raw = file_get_contents(dirname(__FILE__) . "/dbmigrations/" . $migration . "/" . $db_backend . ".sql");
            $match_count = preg_match_all('/(.+?;)/s', $queries_raw, $matches);
            self::$logger->debug('Found ' . $match_count . ' matches');
            if ($match_count > 0) {
                foreach ($matches[0] as $query) { // array will have two elements, each holding all queries. Only iterate over one of them
                    if (strlen($query) > 0) {
                        $query = str_replace("TABLE_PREFIX", $config->get('db_prefix', ""), $query);
                        $dbh->query($query);
                    }
                }
                $dbh->query("INSERT INTO " . $dbh->table_name("carddav_migrations") . " (filename) VALUES (?)", $migration);
            } else {
                self::$logger->debug("Did not match any instructions from migration " . $migration);
            }
        }
    }

    public function init(): void
    {
        $prefs = carddav_common::get_adminsettings();

        self::$logger = new RoundcubeLogger(
            "carddav",
            $prefs['_GLOBAL']['loglevel'] ?? \Psr\Log\LogLevel::ERROR
        );
        $http_logger = new RoundcubeLogger(
            "carddav_http",
            $prefs['_GLOBAL']['loglevel_http'] ?? \Psr\Log\LogLevel::ERROR
        );

        Config::init(self::$logger, $http_logger);

        $this->add_texts('localization/', false);

        $this->add_hook('addressbooks_list', array($this, 'address_sources'));
        $this->add_hook('addressbook_get', array($this, 'get_address_book'));

        $this->add_hook('preferences_list', array($this, 'cd_preferences'));
        $this->add_hook('preferences_save', array($this, 'cd_save'));
        $this->add_hook('preferences_sections_list', array($this, 'cd_preferences_section'));

        $this->add_hook('login_after', array($this, 'checkMigrations'));
        $this->add_hook('login_after', array($this, 'init_presets'));

        if (!array_key_exists('user_id', $_SESSION)) {
            return;
        }

        // use this address book for autocompletion queries
        // (maybe this should be configurable by the user?)
        $config = rcmail::get_instance()->config;
        $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));

        $dbh = rcmail::get_instance()->db;
        $sql_result = $dbh->query(
            'SELECT id FROM ' .
            $dbh->table_name('carddav_addressbooks') .
            ' WHERE user_id=? AND active=1',
            $_SESSION['user_id']
        );

        while ($abookrow = $dbh->fetch_assoc($sql_result)) {
            $abookname = "carddav_" . $abookrow['id'];
            if (!in_array($abookname, $sources)) {
                $sources[] = $abookname;
            }
        }
        $config->set('autocomplete_addressbooks', $sources);
        $skin_path = $this->local_skin_path();
        $this->include_stylesheet($skin_path . '/carddav.css');
    }

    public function init_presets(): void
    {
        $dbh = rcmail::get_instance()->db;
        $prefs = carddav_common::get_adminsettings();

        // read existing presets from DB
        $sql_result = $dbh->query(
            'SELECT * FROM ' .
            $dbh->table_name('carddav_addressbooks') .
            ' WHERE user_id=? AND presetname is not null',
            $_SESSION['user_id']
        );

        $existing_presets = [];
        while ($abookrow = $dbh->fetch_assoc($sql_result)) {
            $pn = $abookrow['presetname'];
            if (!array_key_exists($pn, $existing_presets)) {
                $existing_presets[$pn] = array();
            }
            $existing_presets[$pn][] = $abookrow;
        }

        // add not existing preset addressbooks
        foreach ($prefs as $presetname => $preset) {
            if ($presetname === '_GLOBAL') {
                continue;
            }

            // addressbooks exist for this preset => update settings
            if (array_key_exists($presetname, $existing_presets)) {
                if (is_array($preset['fixed'])) {
                    // update all existing addressbooks for this preset
                    foreach ($existing_presets[$presetname] as $abookrow) {
                        // decrypt password so that the comparison works
                        $abookrow['password'] = carddav_common::decrypt_password($abookrow['password']);

                        // update: only admin fix keys, only if it's fixed
                        // otherwise there may be user changes that should not be destroyed
                        $pa = [];

                        foreach ($preset['fixed'] as $k) {
                            if (array_key_exists($k, $abookrow) &&   array_key_exists($k, $preset)) {
                                // only update the name if it is used
                                if ($k === 'name') {
                                    if (!$preset['carddav_name_only']) {
                                        $fullname = $abookrow['name'];
                                        $cnpos = strpos($fullname, ' (');
                                        if ($cnpos === false && strcmp($preset['name'], $fullname) !== 0) {
                                            $pa['name'] = $preset['name'];
                                        } elseif ($cnpos !== false && strcmp($preset['name'], substr($fullname, 0, $cnpos)) !== 0) {
                                            $pa['name'] = $preset['name'] . substr($fullname, $cnpos);
                                        }
                                    }
                                } elseif ($abookrow[$k] != $preset[$k]) {
                                    $pa[$k] = $preset[$k];
                                }
                            }
                        }

                        // only update if something changed
                        if (count($pa) === 0) {
                            continue;
                        }

                        self::update_abook($abookrow['id'], $pa);
                    }
                }

                unset($existing_presets[$presetname]);
            } else { // create new
                $preset['presetname'] = $presetname;
                $preset['password']   = carddav_common::encrypt_password($preset['password']);
                $abname = $preset['name'];

                $account = new Account($preset['url'], $preset['username'], $preset['password']);
                $discover = new Discovery();
                $abooks = $discover->discoverAddressbooks($account);

                foreach ($abooks as $abook) {
                    if ($preset['carddav_name_only']) {
                        $preset['name'] = $abook->getName();
                    } else {
                        $preset['name'] = "$abname (" . $abook->getName() . ')';
                    }
                    $preset['url'] = $abook->getUri();
                    self::insert_abook($preset);
                }
            }
        }

        // delete existing preset addressbooks that where removed by admin
        foreach ($existing_presets as $ep) {
            foreach ($ep as $abookrow) {
                self::delete_abook($abookrow['id']);
            }
        }
    }

    public function address_sources(array $p): array
    {
        $dbh = rcmail::get_instance()->db;
        $prefs = carddav_common::get_adminsettings();

        $sql_result = $dbh->query(
            'SELECT id,name,presetname FROM ' .
            $dbh->table_name('carddav_addressbooks') .
            ' WHERE user_id=? AND active=1',
            $_SESSION['user_id']
        );

        while ($abookrow = $dbh->fetch_assoc($sql_result)) {
            $ro = false;
            if ($abookrow['presetname'] && $prefs[$abookrow['presetname']]['readonly']) {
                $ro = true;
            }

            $p['sources']["carddav_" . $abookrow['id']] = array(
                'id' => "carddav_" . $abookrow['id'],
                'name' => $abookrow['name'],
                'groups' => true,
                'autocomplete' => true,
                'readonly' => $ro,
            );
        }
        return $p;
    }

    public function get_address_book(array $p): array
    {
        $dbh = rcmail::get_instance()->db;
        if (preg_match(";^carddav_(\d+)$;", $p['id'], $match)) {
            $p['instance'] = new RoundcubeCarddavAddressbook($dbh, $match[1], $this);
        }

        return $p;
    }

    private static function process_cd_time(string $refresht): string
    {
        if (preg_match('/^(\d+)(:([0-5]?\d))?(:([0-5]?\d))?$/', $refresht, $match)) {
            $refresht = sprintf(
                "%02d:%02d:%02d",
                $match[1],
                count($match) > 3 ? $match[3] : 0,
                count($match) > 5 ? $match[5] : 0
            );
        } else {
            $refresht = '01:00:00';
        }
        return $refresht;
    }

    private static function no_override(string $pref, array $abook, array $prefs): bool
    {
        $pn = $abook['presetname'];
        if (!$pn) {
            return false;
        }

        // generally, url is fixed, unless it is mentioned in 'unfixed'
        if ($pref === 'url') {
            if (!is_array($prefs[$pn])) {
                return true;
            }
            if (!is_array($prefs[$pn]['unfixed'])) {
                return true;
            }

            return in_array($pref, $prefs[$pn]['unfixed']);
        }

        if (!is_array($prefs[$pn])) {
            return false;
        }
        if (!is_array($prefs[$pn]['fixed'])) {
            return false;
        }

        return in_array($pref, $prefs[$pn]['fixed']);
    }

    /**
     * Builds a setting block for one address book for the preference page.
     */
    private function cd_preferences_buildblock(string $blockheader, array $abook, array $prefs): array
    {
        $abookid = $abook['id'];
        $rcmail = rcmail::get_instance();

        if (self::no_override('active', $abook, $prefs)) {
            $content_active = $prefs[$abook['presetname']] ? $this->gettext('cd_enabled') : $this->gettext('cd_disabled');
        } else {
            // check box for activating
            $checkbox = new html_checkbox(array('name' => $abookid . '_cd_active', 'value' => 1));
            $content_active = $checkbox->show($abook['active'] ? "1" : "0");
        }

        if (self::no_override('use_categories', $abook, $prefs) || $abook['id'] !== "new") {
            $content_use_categories = $abook['use_categories'] ? $this->gettext('cd_enabled') : $this->gettext('cd_disabled');
        } else {
            // check box for use categories
            $checkbox = new html_checkbox(array('name' => $abookid . '_cd_use_categories', 'value' => 1));
            $content_use_categories = $checkbox->show($abook['use_categories'] ? "1" : "0");
        }

        if (self::no_override('username', $abook, $prefs)) {
            // %V parses username for macosx, replaces periods and @ by _, work around bugs in contacts.app
            if ($abook['username'] === '%V') {
                $content_username = str_replace('@', '_', str_replace('.', '_', $_SESSION['username']));
            } elseif ($abook['username'] === '%u') {
                $content_username = $_SESSION['username'];
            } elseif ($abook['username'] === '%l') {
                $content_username = $rcmail->user->get_username('local');
            } else {
                $content_username = $abook['username'];
            }
        } else {
            // input box for username
            $input = new html_inputfield(array('name' => $abookid . '_cd_username', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['username']));
            $content_username = $input->show();
        }

        if (self::no_override('password', $abook, $prefs)) {
            $content_password = "***";
        } else {
            // input box for password
            $input = new html_inputfield(array('name' => $abookid . '_cd_password', 'type' => 'password', 'autocomplete' => 'off', 'value' => ''));
            $content_password = $input->show();
        }

        if (self::no_override('url', $abook, $prefs)) {
            $content_url = str_replace("%u", $abook['username'], $abook['url']);
        } else {
            // input box for URL
            $size = max(strlen($abook['url']), 40);
            $input = new html_inputfield(array('name' => $abookid . '_cd_url', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['url'], 'size' => $size));
            $content_url = $input->show();
        }

        // input box for refresh time
        if (self::no_override('refresh_time', $abook, $prefs)) {
            $content_refresh_time =  $abook['refresh_time'];
        } else {
            $input = new html_inputfield(array('name' => $abookid . '_cd_refresh_time', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['refresh_time'], 'size' => 10));
            $content_refresh_time = $input->show();
        }
        if (isset($abook['last_updated'])) {
            $content_refresh_time .=  ' (' . rcube::Q($this->gettext('cd_lastupdate_time')) . ': ' . $abook['last_updated'] . ')';
        }

        if (self::no_override('name', $abook, $prefs)) {
            $content_name = $abook['name'];
        } else {
            $input = new html_inputfield(array('name' => $abookid . '_cd_name', 'type' => 'text', 'autocomplete' => 'off', 'value' => $abook['name'], 'size' => 40));
            $content_name = $input->show();
        }

        $retval = array(
            'options' => array(
                array('title' => rcmail::Q($this->gettext('cd_name')), 'content' => $content_name),
                array('title' => rcmail::Q($this->gettext('cd_active')), 'content' => $content_active),
                array('title' => rcmail::Q($this->gettext('cd_use_categories')), 'content' => $content_use_categories),
                array('title' => rcmail::Q($this->gettext('cd_username')), 'content' => $content_username),
                array('title' => rcmail::Q($this->gettext('cd_password')), 'content' => $content_password),
                array('title' => rcmail::Q($this->gettext('cd_url')), 'content' => $content_url),
                array('title' => rcmail::Q($this->gettext('cd_refresh_time')), 'content' => $content_refresh_time),
            ),
            'name' => $blockheader
        );

        if (!$abook['presetname'] && preg_match('/^\d+$/', $abookid)) {
            $checkbox = new html_checkbox(array('name' => $abookid . '_cd_delete', 'value' => 1));
            $content_delete = $checkbox->show("0");
            $retval['options'][] = array('title' => rcmail::Q($this->gettext('cd_delete')), 'content' => $content_delete);
        }

        if (preg_match('/^\d+$/', $abookid)) {
            $checkbox = new html_checkbox(array('name' => $abookid . '_cd_resync', 'value' => 1));
            $content_resync = $checkbox->show("0");
            $retval['options'][] = array('title' => rcmail::Q($this->gettext('cd_resync')), 'content' => $content_resync);
        }

        return $retval;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into CardDAV settings sections in Preferences.
     *
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    public function cd_preferences(array $args): array
    {
        if ($args['section'] != 'cd_preferences') {
            return $args;
        }

        $this->include_stylesheet($this->local_skin_path() . '/carddav.css');
        $prefs = carddav_common::get_adminsettings();

        if (!$prefs['_GLOBAL']['suppress_version_warning']) {
            if (version_compare(PHP_VERSION, '5.6.18', '<')) {
                $args['blocks']['cd_preferences'] = array(
                    'options' => array(
                        array('title' => rcmail::Q($this->gettext('cd_php_too_old')), 'content' => PHP_VERSION)
                    ),
                    'name' => rcmail::Q($this->gettext('cd_title'))
                );
                return $args;
            }
        }

        $abooks = RoundcubeCarddavAddressbook::dbGet($_SESSION['user_id'], '*', 'addressbooks', false, 'user_id');
        foreach ($abooks as $abook) {
            $presetname = $abook['presetname'];
            if (
                empty($presetname) ||
                (!isset($prefs[$presetname]['hide']) || (isset($prefs[$presetname]['hide']) && $prefs[$presetname]['hide'] === false))
            ) {
                $abookid = $abook['id'];
                $blockhdr = $abook['name'];
                if ($abook['presetname']) {
                    $blockhdr .= str_replace("_PRESETNAME_", $abook['presetname'], rcmail::Q($this->gettext('cd_frompreset')));
                }
                $args['blocks']['cd_preferences' . $abookid] = $this->cd_preferences_buildblock($blockhdr, $abook, $prefs);
            }
        }

        if (!array_key_exists('_GLOBAL', $prefs) || !$prefs['_GLOBAL']['fixed']) {
            $args['blocks']['cd_preferences_section_new'] = $this->cd_preferences_buildblock(
                rcmail::Q($this->gettext('cd_newabboxtitle')),
                array(
                    'id'           => 'new',
                    'active'       => 1,
                    'use_categories' => 1,
                    'username'     => '',
                    'url'          => '',
                    'name'         => '',
                    'refresh_time' => 1,
                    'presetname'   => '',
                ),
                $prefs
            );
        }

        return $args;
    }

    // add a section to the preferences tab
    public function cd_preferences_section(array $args): array
    {
        $prefs = carddav_common::get_adminsettings();
        if (!isset($prefs['_GLOBAL']['hide_preferences']) || (isset($prefs['_GLOBAL']['hide_preferences']) && $prefs['_GLOBAL']['hide_preferences'] === false)) {
            $args['list']['cd_preferences'] = array(
                'id'      => 'cd_preferences',
                'section' => rcmail::Q($this->gettext('cd_title'))
            );
        }
        return $args;
    }

    /**
     * Hook function called when the user saves the preferences.
     *
     * This function is called for any preferences section, not just that of the carddav plugin, so we need to check
     * first whether we are in the proper section.
     */
    public function cd_save(array $args): array
    {
        $dbh = rcmail::get_instance()->db;

        if ($args['section'] != 'cd_preferences') {
            return $args;
        }

        $prefs = carddav_common::get_adminsettings();
        if (isset($prefs['_GLOBAL']['hide_preferences']) && $prefs['_GLOBAL']['hide_preferences'] === true) {
            return $args;
        }

        // update existing in DB
        $abooks = RoundcubeCarddavAddressbook::dbGet(
            $_SESSION['user_id'],
            'id,presetname',
            'addressbooks',
            false,
            'user_id'
        );

        foreach ($abooks as $abook) {
            $abookid = $abook['id'];
            if (isset($_POST["${abookid}_cd_delete"])) {
                self::delete_abook($abookid);
            } else {
                $newset = array (
                    'name' => rcube_utils::get_input_value("${abookid}_cd_name", rcube_utils::INPUT_POST),
                    'username' => rcube_utils::get_input_value("${abookid}_cd_username", rcube_utils::INPUT_POST, true),
                    'url' => rcube_utils::get_input_value("${abookid}_cd_url", rcube_utils::INPUT_POST),
                    'active' => isset($_POST[$abookid . '_cd_active']) ? 1 : 0,
                    'use_categories' => isset($_POST[$abookid . '_cd_use_categories']) ? 1 : 0,
                    'refresh_time' => rcube_utils::get_input_value("${abookid}_cd_refresh_time", rcube_utils::INPUT_POST),
                );

                // only set the password if the user entered a new one
                $password = rcube_utils::get_input_value("${abookid}_cd_password", rcube_utils::INPUT_POST, true);
                if (strlen($password) > 0) {
                    $newset['password'] = $password;
                }

                // remove admin only settings
                foreach ($newset as $pref => $value) {
                    if (self::no_override($pref, $abook, $prefs)) {
                        unset($newset[$pref]);
                    }
                }

                self::update_abook($abookid, $newset);

                if (isset($_POST["${abookid}_cd_resync"])) {
                    $backend = new RoundcubeCarddavAddressbook($dbh, $abookid, $this);
                    $backend->resync();
                }
            }
        }

        // add a new address book?
        $new = rcube_utils::get_input_value('new_cd_name', rcube_utils::INPUT_POST);
        if ((!array_key_exists('_GLOBAL', $prefs) || !$prefs['_GLOBAL']['fixed']) && strlen($new) > 0) {
            $srv    = rcube_utils::get_input_value('new_cd_url', rcube_utils::INPUT_POST);
            $usr    = rcube_utils::get_input_value('new_cd_username', rcube_utils::INPUT_POST, true);
            $pass   = rcube_utils::get_input_value('new_cd_password', rcube_utils::INPUT_POST, true);
            $pass = carddav_common::encrypt_password($pass);
            $abname = rcube_utils::get_input_value('new_cd_name', rcube_utils::INPUT_POST);
            $use_categories = intval(rcube_utils::get_input_value('new_cd_use_categories', rcube_utils::INPUT_POST, true), 0);

            $account = new Account($srv, $usr, carddav_common::decrypt_password($pass));
            $discover = new Discovery();
            $abooks = $discover->discoverAddressbooks($account);

            if (count($abooks) > 0) {
                foreach ($abooks as $abook) {
                    self::$logger->debug("ADDING ABOOK " . print_r($srv, true));
                    self::insert_abook([
                        'name'     => "$abname ({$abook->getName()})",
                        'username' => $usr,
                        'password' => $pass,
                        'use_categories' => $use_categories,
                        'url'      => $abook->getUri(),
                        'refresh_time' => rcube_utils::get_input_value('new_cd_refresh_time', rcube_utils::INPUT_POST)
                    ]);
                }
            } else {
                $args['abort'] = true;
                $args['message'] = $abname . ': ' . $this->gettext('cd_err_noabfound');
            }
        }

        return $args;
    }

    private static function delete_abook(string $abookid): void
    {
        RoundcubeCarddavAddressbook::dbDelete($abookid, 'addressbooks');
        // we explicitly delete all data belonging to the addressbook, since
        // cascaded deleted are not supported by all database backends
        // ...contacts
        RoundcubeCarddavAddressbook::dbDelete($abookid, 'contacts', 'abook_id');
        // ...custom subtypes
        RoundcubeCarddavAddressbook::dbDelete($abookid, 'xsubtypes', 'abook_id');
        // ...groups and memberships
        $delgroups = array_map(
            function (array $v): string {
                return $v["id"];
            },
            RoundcubeCarddavAddressbook::dbGet($abookid, 'id', 'groups', false, 'abook_id')
        );
        if (count($delgroups) > 0) {
            RoundcubeCarddavAddressbook::dbDelete($delgroups, 'group_user', 'group_id');
        }
        RoundcubeCarddavAddressbook::dbDelete($abookid, 'groups', 'abook_id');
    }

    private static function insert_abook(array $pa): void
    {
        $dbh = rcmail::get_instance()->db;

        // check parameters
        if (array_key_exists('refresh_time', $pa)) {
            $pa['refresh_time'] = self::process_cd_time($pa['refresh_time']);
        }
        /* Ensure field lengths */
        if (array_key_exists('name', $pa)) {
            if (strlen($pa['name']) > 64) {
                $pa['name'] = substr($pa['name'], 0, 64);
            }
        }
        if (array_key_exists('username', $pa)) {
            if (strlen($pa['username']) > 255) {
                $pa['username'] = substr($pa['username'], 0, 255);
            }
        }
        if (array_key_exists('presetname', $pa)) {
            if (strlen($pa['presetname']) > 255) {
                $pa['presetname'] = substr($pa['presetname'], 0, 255);
            }
        }
        $pa['user_id']      = $_SESSION['user_id'];

        // required fields
        $qf = array('name','username','password','url','user_id');
        $qv = array();
        foreach ($qf as $f) {
            if (!array_key_exists($f, $pa)) {
                throw new \Exception("Required parameter $f not provided for new addressbook");
            }
            $qv[] = $pa[$f];
        }

        // optional fields
        $qfo = array('active','presetname','use_categories','refresh_time');
        foreach ($qfo as $f) {
            if (array_key_exists($f, $pa)) {
                $qf[] = $f;
                $qv[] = $pa[$f];
            }
        }

        $dbh->query(
            'INSERT INTO ' . $dbh->table_name('carddav_addressbooks') .
            '(' . implode(',', $qf)  . ') ' .
            'VALUES (?' . str_repeat(',?', count($qf) - 1) . ')',
            $qv
        );
    }

    public static function update_abook(string $abookid, array $pa): void
    {
        $dbh = rcmail::get_instance()->db;

        // check parameters
        if (array_key_exists('refresh_time', $pa)) {
            $pa['refresh_time'] = self::process_cd_time($pa['refresh_time']);
        }

        // encrypt the password before storing it
        if (array_key_exists('password', $pa)) {
            $pa['password'] = carddav_common::encrypt_password($pa['password']);
        }

        /* Ensure field lengths */
        if (array_key_exists('name', $pa)) {
            if (strlen($pa['name']) > 64) {
                $pa['name'] = substr($pa['name'], 0, 64);
            }
        }
        if (array_key_exists('username', $pa)) {
            if (strlen($pa['username']) > 255) {
                $pa['username'] = substr($pa['username'], 0, 255);
            }
        }
        if (array_key_exists('presetname', $pa)) {
            if (strlen($pa['presetname']) > 255) {
                $pa['presetname'] = substr($pa['presetname'], 0, 255);
            }
        }

        // optional fields
        $qfo = array('name','username','password','url','active','refresh_time','sync_token');
        $qf = array();
        $qv = array();

        foreach ($qfo as $f) {
            if (array_key_exists($f, $pa)) {
                $qf[] = $f;
                $qv[] = $pa[$f];
            }
        }
        if (count($qf) <= 0) {
            return;
        }

        $qv[] = $abookid;
        $dbh->query(
            'UPDATE ' .
            $dbh->table_name('carddav_addressbooks') .
            ' SET ' . implode('=?,', $qf) . '=?' .
            ' WHERE id=?',
            $qv
        );
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
