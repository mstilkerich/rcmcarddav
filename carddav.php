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
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Database, RoundcubeLogger};

// phpcs:ignore PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName -- class name(space) expected by roundcube
class carddav extends rcube_plugin
{

    /** @var string[] ABOOK_PROPS A list of addressbook property keys. These are both found in the settings form as well
     *                            as in the database as columns.
     */
    private const ABOOK_PROPS = [
        "name", "active", "use_categories", "username", "password", "url", "refresh_time", "sync_token"
    ];

    /** @var string $pwstore_scheme encryption scheme */
    private static $pwstore_scheme = 'encrypted';

    /** @var array $admin_settings admin settings from config.inc.php */
    private static $admin_settings;

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

    public function init(): void
    {
        $prefs = self::getAdminSettings();

        self::$logger = new RoundcubeLogger(
            "carddav",
            $prefs['_GLOBAL']['loglevel'] ?? \Psr\Log\LogLevel::ERROR
        );
        $http_logger = new RoundcubeLogger(
            "carddav_http",
            $prefs['_GLOBAL']['loglevel_http'] ?? \Psr\Log\LogLevel::ERROR
        );

        // initialize carddavclient library
        Config::init(self::$logger, $http_logger);

        Database::init(self::$logger);

        $this->add_texts('localization/', false);

        $this->add_hook('addressbooks_list', array($this, 'listAddressbooks'));
        $this->add_hook('addressbook_get', array($this, 'getAddressbook'));

        $this->add_hook('preferences_list', array($this, 'buildPreferencesPage'));
        $this->add_hook('preferences_save', array($this, 'savePreferences'));
        $this->add_hook('preferences_sections_list', array($this, 'addPreferencesSection'));

        $this->add_hook('login_after', array($this, 'checkMigrations'));
        $this->add_hook('login_after', array($this, 'initPresets'));

        if (!key_exists('user_id', $_SESSION)) {
            return;
        }

        // use this address book for autocompletion queries
        // (maybe this should be configurable by the user?)
        $config = rcmail::get_instance()->config;
        $sources = (array) $config->get('autocomplete_addressbooks', array('sql'));

        $sources = array_map(
            function (array $v): string {
                return "carddav_{$v['id']}";
            },
            Database::get($_SESSION['user_id'], 'id', 'addressbooks', false, 'user_id', [ 'active' => '1' ])
        );

        $config->set('autocomplete_addressbooks', $sources);
        $skin_path = $this->local_skin_path();
        $this->include_stylesheet($skin_path . '/carddav.css');
    }

    public function checkMigrations(): void
    {
        $scriptDir = dirname(__FILE__) . "/dbmigrations/";
        $config = rcmail::get_instance()->config;
        Database::checkMigrations($config->get('db_prefix', ""), $scriptDir);
    }

    /**
     * Updates the fixed fields of addressbooks derived from presets against the current admin settings.
     */
    private function updatePresetAddressbooks(array $preset, array $existing_abooks): void
    {
        foreach ($existing_abooks as $abookrow) {
            // decrypt password so that the comparison works
            $abookrow['password'] = self::decryptPassword($abookrow['password']);

            // update: only admin fix keys, only if it's fixed
            // otherwise there may be user changes that should not be destroyed
            $pa = [];

            foreach ($preset['fixed'] as $k) {
                if (key_exists($k, $abookrow) && key_exists($k, $preset)) {
                    // only update the name if it is used
                    if ($k === 'name') {
                        if (!$preset['carddav_name_only']) {
                            $fullname = $abookrow['name'];
                            $cnpos = strpos($fullname, ' (');
                            if ($cnpos === false && $preset['name'] != $fullname) {
                                $pa['name'] = $preset['name'];
                            } elseif ($cnpos !== false && $preset['name'] != substr($fullname, 0, $cnpos)) {
                                $pa['name'] = $preset['name'] . substr($fullname, $cnpos);
                            }
                        }
                    } elseif ($k === 'url') {
                        // the URL cannot be automatically updated, as it was discovered and normally will
                        // not exactly match the discovery URI. Resetting it to the discovery URI would
                        // break the addressbook record
                    } elseif ($abookrow[$k] != $preset[$k]) {
                        $pa[$k] = $preset[$k];
                    }
                }
            }

            // only update if something changed
            if (count($pa) === 0) {
                continue;
            }

            self::updateAddressbook($abookrow['id'], $pa);
        }
    }

    public function initPresets(): void
    {
        $prefs = self::getAdminSettings();

        // Get all existing addressbooks of this user that have been created from presets
        $existing_abooks = Database::get(
            $_SESSION['user_id'],
            '*',
            'addressbooks',
            false,
            'user_id',
            ['!presetname' => null]
        );

        // Group the addressbooks by their preset
        $existing_presets = [];
        foreach ($existing_abooks as $abookrow) {
            $pn = $abookrow['presetname'];
            if (!key_exists($pn, $existing_presets)) {
                $existing_presets[$pn] = [];
            }
            $existing_presets[$pn][] = $abookrow;
        }

        // Walk over the current presets configured by the admin and add, update or delete addressbooks
        foreach ($prefs as $presetname => $preset) {
            // _GLOBAL contains plugin configuration not related to an addressbook preset - skip
            if ($presetname === '_GLOBAL') {
                continue;
            }

            // addressbooks exist for this preset => update settings
            if (key_exists($presetname, $existing_presets)) {
                if (is_array($preset['fixed'])) {
                    $this->updatePresetAddressbooks($preset, $existing_presets[$presetname]);
                }
                unset($existing_presets[$presetname]);
            } else { // create new
                $preset['presetname'] = $presetname;
                $abname = $preset['name'];

                try {
                    $username = self::replacePlaceholdersUsername($preset['username']);
                    $url = self::replacePlaceholdersUrl($preset['url']);
                    $password = self::replacePlaceholdersPassword($preset['password']);

                    self::$logger->debug("Adding preset for $username at URL $url");
                    $account = new Account($url, $username, $password);
                    $discover = new Discovery();
                    $abooks = $discover->discoverAddressbooks($account);

                    foreach ($abooks as $abook) {
                        if ($preset['carddav_name_only']) {
                            $preset['name'] = $abook->getName();
                        } else {
                            $preset['name'] = "$abname (" . $abook->getName() . ')';
                        }

                        $preset['url'] = $abook->getUri();
                        self::insertAddressbook($preset);
                    }
                } catch (\Exception $e) {
                    self::$logger->error("Error adding addressbook from preset $presetname: {$e->getMessage()}");
                }
            }
        }

        // delete existing preset addressbooks that were removed by admin
        foreach ($existing_presets as $ep) {
            self::$logger->debug("Deleting preset addressbooks for " . $_SESSION['user_id']);
            foreach ($ep as $abookrow) {
                self::deleteAddressbook($abookrow['id']);
            }
        }
    }

    public static function replacePlaceholdersUsername(string $username): string
    {
        $rcmail = rcmail::get_instance();
        $username = strtr($username, [
            '%u' => $_SESSION['username'],
            '%l' => $rcmail->user->get_username('local'),
            '%d' => $rcmail->user->get_username('domain'),
            // %V parses username for macosx, replaces periods and @ by _, work around bugs in contacts.app
            '%V' => strtr($_SESSION['username'], "@.", "__")
        ]);

        return $username;
    }

    // only needed inside this class because the URL is not stored with placeholders
    private static function replacePlaceholdersUrl(string $url): string
    {
        // currently same as for username
        return self::replacePlaceholdersUsername($url);
    }

    public static function replacePlaceholdersPassword(string $password): string
    {
        if ($password == '%p') {
            $rcmail = rcmail::get_instance();
            $password = $rcmail->decrypt($_SESSION['password']);
        }

        return $password;
    }

    /**
     * Adds the user's CardDAV addressbooks to Roundcube's addressbook list.
     */
    public function listAddressbooks(array $p): array
    {
        $prefs = self::getAdminSettings();

        $abooks = Database::get(
            $_SESSION['user_id'],
            "id,name,presetname",
            "addressbooks",
            false,
            "user_id",
            [ "active" => "1" ]
        );

        foreach ($abooks as $abook) {
            $ro = false;
            if (isset($abook['presetname']) && $prefs[$abook['presetname']]['readonly']) {
                $ro = true;
            }

            $p['sources']["carddav_" . $abook['id']] = [
                'id' => "carddav_" . $abook['id'],
                'name' => $abook['name'],
                'groups' => true,
                'autocomplete' => true,
                'readonly' => $ro,
            ];
        }

        return $p;
    }

    public function getAddressbook(array $p): array
    {
        $dbh = rcmail::get_instance()->db;
        if (preg_match(";^carddav_(\d+)$;", $p['id'], $match)) {
            $p['instance'] = new Addressbook($dbh, $match[1], $this);
        }

        return $p;
    }

    private static function parseTimeParameter(string $refresht): string
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

    private static function noOverrideAllowed(string $pref, array $abook, array $prefs): bool
    {
        $pn = $abook['presetname'];
        if (!isset($pn)) {
            return false;
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
    private function buildSettingsBlock(string $blockheader, array $abook, array $prefs): array
    {
        $abookid = $abook['id'];

        if (self::noOverrideAllowed('active', $abook, $prefs)) {
            $content_active = $prefs[$abook['presetname']]
                ? $this->gettext('cd_enabled')
                : $this->gettext('cd_disabled');
        } else {
            // check box for activating
            $checkbox = new html_checkbox(['name' => $abookid . '_cd_active', 'value' => 1]);
            $content_active = $checkbox->show($abook['active'] ? "1" : "0");
        }

        if (self::noOverrideAllowed('use_categories', $abook, $prefs)) {
            $content_use_categories = $abook['use_categories']
                ? $this->gettext('cd_enabled')
                : $this->gettext('cd_disabled');
        } else {
            // check box for use categories
            $checkbox = new html_checkbox(['name' => $abookid . '_cd_use_categories', 'value' => 1]);
            $content_use_categories = $checkbox->show($abook['use_categories'] ? "1" : "0");
        }

        if (self::noOverrideAllowed('username', $abook, $prefs)) {
            $content_username = self::replacePlaceholdersUsername($abook['username']);
        } else {
            // input box for username
            $input = new html_inputfield([
                'name' => $abookid . '_cd_username',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $abook['username']
            ]);
            $content_username = $input->show();
        }

        if (self::noOverrideAllowed('password', $abook, $prefs)) {
            $content_password = "***";
        } else {
            // only display the password if it was entered for a new addressbook
            $show_pw_val = ($abook['id'] === "new" && isset($abook['password'])) ? $abook['password'] : '';
            // input box for password
            $input = new html_inputfield([
                'name' => $abookid . '_cd_password',
                'type' => 'password',
                'autocomplete' => 'off',
                'value' => $show_pw_val
            ]);
            $content_password = $input->show();
        }

        // generally, url is fixed, as it results from discovery and has no direct correlation with the admin setting
        // if the URL of the addressbook changes, all URIs of our database objects would have to change, too -> in such
        // cases, deleting and re-adding the addressbook would be simpler
        if ($abook['id'] === "new") {
            // input box for URL
            $size = max(strlen($abook['url']), 40);
            $input = new html_inputfield([
                'name' => $abookid . '_cd_url',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $abook['url'],
                'size' => $size
            ]);
            $content_url = $input->show();
        } else {
            $content_url = $abook['url'];
        }

        // input box for refresh time
        if (self::noOverrideAllowed('refresh_time', $abook, $prefs)) {
            $content_refresh_time =  $abook['refresh_time'];
        } else {
            $input = new html_inputfield([
                'name' => $abookid . '_cd_refresh_time',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $abook['refresh_time'],
                'size' => 10
            ]);
            $content_refresh_time = $input->show();
        }

        if (isset($abook['last_updated'])) {
            $content_refresh_time .=  rcube::Q($this->gettext('cd_lastupdate_time')) . ': ' . $abook['last_updated'];
        }

        if (self::noOverrideAllowed('name', $abook, $prefs)) {
            $content_name = $abook['name'];
        } else {
            $input = new html_inputfield([
                'name' => $abookid . '_cd_name',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $abook['name'],
                'size' => 40
            ]);
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
            $retval['options'][] = ['title' => rcmail::Q($this->gettext('cd_delete')), 'content' => $content_delete];
        }

        if (preg_match('/^\d+$/', $abookid)) {
            $checkbox = new html_checkbox(array('name' => $abookid . '_cd_resync', 'value' => 1));
            $content_resync = $checkbox->show("0");
            $retval['options'][] = ['title' => rcmail::Q($this->gettext('cd_resync')), 'content' => $content_resync];
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
    public function buildPreferencesPage(array $args): array
    {
        if ($args['section'] != 'cd_preferences') {
            return $args;
        }

        $this->include_stylesheet($this->local_skin_path() . '/carddav.css');
        $prefs = self::getAdminSettings();

        $abooks = Database::get($_SESSION['user_id'], '*', 'addressbooks', false, 'user_id');
        foreach ($abooks as $abook) {
            $presetname = $abook['presetname'];
            if (
                empty($presetname)
                || (!isset($prefs[$presetname]['hide']) || ($prefs[$presetname]['hide'] === false))
            ) {
                $abookid = $abook['id'];
                $blockhdr = $abook['name'];
                if ($abook['presetname']) {
                    $blockhdr .= str_replace(
                        "_PRESETNAME_",
                        $abook['presetname'],
                        rcmail::Q($this->gettext('cd_frompreset'))
                    );
                }
                $args['blocks']['cd_preferences' . $abookid] = $this->buildSettingsBlock($blockhdr, $abook, $prefs);
            }
        }

        if (!key_exists('_GLOBAL', $prefs) || !$prefs['_GLOBAL']['fixed']) {
            $args['blocks']['cd_preferences_section_new'] = $this->buildSettingsBlock(
                rcmail::Q($this->gettext('cd_newabboxtitle')),
                self::getAddressbookSettingsFromPOST('new'),
                $prefs
            );
        }

        return $args;
    }

    /**
     * This function gets the addressbook settings from a POST request.
     *
     * The behavior varies depending on whether the settings for an existing or a new addressbook are queried.
     * For an existing addressbook, the result array will only have keys set for POSTed values. In particular, this
     * means that for fixed settings of preset addressbooks, no setting values will be contained.
     * For a new addressbook, all settings are set in the resulting array. If not provided by the user, default values
     * are used.
     *
     * @param string $abookId The ID of the addressbook ("new" for new addressbooks, otherwise the numeric DB id)
     * @return string[] An array with addressbook column keys and their setting.
     */
    private static function getAddressbookSettingsFromPOST(string $abookId): array
    {
        /** @var string[] $boolOptions These options have checkbox elements and are NULL in post if deselected */
        $boolOptions = [ 'active', 'use_categories' ];
        $nonEmptyDefaults = [
            "refresh_time" => "01:00:00",
            "active" => "1",
            "use_categories" => "1",
        ];

        // for name we must not whether it is null or not to detect whether the settings form was POSTed or not
        $name = rcube_utils::get_input_value("${abookId}_cd_name", rcube_utils::INPUT_POST);
        $active = rcube_utils::get_input_value("${abookId}_cd_active", rcube_utils::INPUT_POST);
        $use_categories = rcube_utils::get_input_value("${abookId}_cd_use_categories", rcube_utils::INPUT_POST);

        $result = [
            'id' => $abookId,
            'name' => $name,
            'username' => rcube_utils::get_input_value("${abookId}_cd_username", rcube_utils::INPUT_POST, true),
            'password' => rcube_utils::get_input_value("${abookId}_cd_password", rcube_utils::INPUT_POST, true),
            'url' => rcube_utils::get_input_value("${abookId}_cd_url", rcube_utils::INPUT_POST),
            'refresh_time' => rcube_utils::get_input_value("${abookId}_cd_refresh_time", rcube_utils::INPUT_POST),
            'active' => $active,
            'use_categories' => $use_categories,
        ];

        if ($abookId == 'new') {
            // detect if the POST request contains user-provided info for this addressbook or not
            // (Problem: unchecked checkboxes don't appear with POSTed values, so we cannot discern not set values from
            // actively unchecked values).
            if (isset($name)) {
                foreach ($boolOptions as $boolOpt) {
                    if (!isset($result[$boolOpt])) {
                        $result[$boolOpt] = "0";
                    }
                }
            }
            $result["presetname"] = "";

            // for new addressbooks, carry over the posted values or set defaults otherwise
            foreach ($result as $k => $v) {
                if (!isset($v)) {
                    $result[$k] = $nonEmptyDefaults[$k] ?? '';
                }
            }
        } else {
            // for existing addressbooks, we only set the keys for that values were POSTed
            // (for fixed settings, no values are posted)
            foreach ($result as $k => $v) {
                if (!isset($v)) {
                    unset($result[$k]);
                }
            }
            foreach ($boolOptions as $boolOpt) {
                if (!isset($result[$boolOpt])) {
                    $result[$boolOpt] = "0";
                }
            }
        }

        // this is for the static analyzer only, which will not detect from the above that
        // array values will never be NULL
        $r = [];
        foreach ($result as $k => $v) {
            if (isset($v)) {
                $r[$k] = $v;
            }
        }

        return $r;
    }

    // add a section to the preferences tab
    public function addPreferencesSection(array $args): array
    {
        $prefs = self::getAdminSettings();
        if (
            !isset($prefs['_GLOBAL']['hide_preferences'])
            || (isset($prefs['_GLOBAL']['hide_preferences'])
                && $prefs['_GLOBAL']['hide_preferences'] === false)
        ) {
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
    public function savePreferences(array $args): array
    {
        if ($args['section'] != 'cd_preferences') {
            return $args;
        }

        $prefs = self::getAdminSettings();
        if (isset($prefs['_GLOBAL']['hide_preferences']) && $prefs['_GLOBAL']['hide_preferences'] === true) {
            return $args;
        }

        // update existing in DB
        $abooks = Database::get(
            $_SESSION['user_id'],
            'id,presetname',
            'addressbooks',
            false,
            'user_id'
        );

        foreach ($abooks as $abook) {
            $abookid = $abook['id'];
            if (isset($_POST["${abookid}_cd_delete"])) {
                self::deleteAddressbook($abookid);
            } else {
                $newset = self::getAddressbookSettingsFromPOST($abookid);

                // only set the password if the user entered a new one
                if (empty($newset['password'])) {
                    unset($newset['password']);
                }

                // remove admin only settings
                foreach ($newset as $pref => $value) {
                    if (self::noOverrideAllowed($pref, $abook, $prefs)) {
                        unset($newset[$pref]);
                    }
                }

                self::updateAddressbook($abookid, $newset);

                if (isset($_POST["${abookid}_cd_resync"])) {
                    $dbh = rcmail::get_instance()->db;
                    $backend = new Addressbook($dbh, $abookid, $this);
                    $backend->resync();
                }
            }
        }

        // add a new address book?
        $new = self::getAddressbookSettingsFromPOST('new');
        if ((!key_exists('_GLOBAL', $prefs) || !$prefs['_GLOBAL']['fixed']) && !empty($new['name'])) {
            try {
                $account = new Account(
                    $new['url'],
                    $new['username'],
                    self::replacePlaceholdersPassword($new['password'])
                );
                $discover = new Discovery();
                $abooks = $discover->discoverAddressbooks($account);

                if (count($abooks) > 0) {
                    $basename = $new['name'];

                    foreach ($abooks as $abook) {
                        $new['url'] = $abook->getUri();
                        $new['name'] = "$basename ({$abook->getName()})";

                        self::$logger->debug("ADDING ABOOK {$new['username']} @ {$new['url']}");
                        self::insertAddressbook($new);
                    }

                    // new addressbook added successfully -> clear the data from the form
                    foreach (self::ABOOK_PROPS as $k) {
                        unset($_POST["new_cd_$k"]);
                    }
                } else {
                    throw new \Exception($new['name'] . ': ' . $this->gettext('cd_err_noabfound'));
                }
            } catch (\Exception $e) {
                $args['abort'] = true;
                $args['message'] = $e->getMessage();
            }
        }

        return $args;
    }

    private static function deleteAddressbook(string $abookid): void
    {
        Database::delete($abookid, 'addressbooks');
        // we explicitly delete all data belonging to the addressbook, since
        // cascaded deleted are not supported by all database backends
        // ...contacts
        Database::delete($abookid, 'contacts', 'abook_id');
        // ...custom subtypes
        Database::delete($abookid, 'xsubtypes', 'abook_id');
        // ...groups and memberships
        $delgroups = array_map(
            function (array $v): string {
                return $v["id"];
            },
            Database::get($abookid, 'id', 'groups', false, 'abook_id')
        );
        if (count($delgroups) > 0) {
            Database::delete($delgroups, 'group_user', 'group_id');
        }
        Database::delete($abookid, 'groups', 'abook_id');
    }

    private static function insertAddressbook(array $pa): void
    {
        // check parameters
        if (key_exists('refresh_time', $pa)) {
            $pa['refresh_time'] = self::parseTimeParameter($pa['refresh_time']);
        }

        if (key_exists('password', $pa)) {
            $pa['password'] = self::encryptPassword($pa['password']);
        }

        /* Ensure field lengths */
        self::checkAddressbookFieldLengths($pa);

        $pa['user_id']      = $_SESSION['user_id'];

        // required fields
        $qf = array('name','username','password','url','user_id');
        $qv = array();
        foreach ($qf as $f) {
            if (!key_exists($f, $pa)) {
                throw new \Exception("Required parameter $f not provided for new addressbook");
            }
            $qv[] = $pa[$f];
        }

        // optional fields
        $qfo = array('active','presetname','use_categories','refresh_time');
        foreach ($qfo as $f) {
            if (key_exists($f, $pa)) {
                $qf[] = $f;
                $qv[] = $pa[$f];
            }
        }

        Database::insert("addressbooks", $qf, $qv);
    }

    /**
     * Checks that the values for addressbook fields fit into their database types.
     *
     * @param string[] $attrs An associative array of database columns and their values.
     */
    private static function checkAddressbookFieldLengths($attrs): void
    {
        $limits = [
            'name' => 64,
            'username' => 255,
            'password' => 255,
        ];

        foreach ($limits as $key => $limit) {
            if (key_exists($key, $attrs)) {
                if (strlen($attrs[$key]) > $limit) {
                    throw new \Exception("The addressbook $key must not exceed $limit characters in length");
                }
            }
        }
    }

    public static function updateAddressbook(string $abookid, array $pa): void
    {
        // check parameters
        if (key_exists('refresh_time', $pa)) {
            $pa['refresh_time'] = self::parseTimeParameter($pa['refresh_time']);
        }

        // encrypt the password before storing it
        if (key_exists('password', $pa)) {
            $pa['password'] = self::encryptPassword($pa['password']);
        }

        /* Ensure field lengths */
        self::checkAddressbookFieldLengths($pa);

        // optional fields
        $qf = [];
        $qv = [];

        foreach (self::ABOOK_PROPS as $f) {
            if (key_exists($f, $pa)) {
                $qf[] = $f;
                $qv[] = $pa[$f];
            }
        }
        if (count($qf) <= 0) {
            return;
        }

        Database::update($abookid, $qf, $qv, "addressbooks");
    }

    // admin settings from config.inc.php
    public static function getAdminSettings(): array
    {
        if (isset(self::$admin_settings)) {
            return self::$admin_settings;
        }

        $prefs = array();
        $configfile = dirname(__FILE__) . "/config.inc.php";
        if (file_exists($configfile)) {
            include($configfile);
        }
        self::$admin_settings = $prefs;

        if (isset($prefs['_GLOBAL']['pwstore_scheme'])) {
            $scheme = $prefs['_GLOBAL']['pwstore_scheme'];
            if (preg_match("/^(plain|base64|encrypted|des_key)$/", $scheme)) {
                self::$pwstore_scheme = $scheme;
            }
        }
        return $prefs;
    }

    // password helpers
    private static function getDesKey(): string
    {
        $rcmail = rcmail::get_instance();
        $imap_password = $rcmail->decrypt($_SESSION['password']);
        while (strlen($imap_password) < 24) {
            $imap_password .= $imap_password;
        }
        return substr($imap_password, 0, 24);
    }

    public static function encryptPassword(string $clear): string
    {
        if (strcasecmp(self::$pwstore_scheme, 'plain') === 0) {
            return $clear;
        }

        if (strcasecmp(self::$pwstore_scheme, 'encrypted') === 0) {
            // return {IGNORE} scheme if session password is empty (krb_authentication plugin)
            if (empty($_SESSION['password'])) {
                return '{IGNORE}';
            }

            // encrypted with IMAP password
            $rcmail = rcmail::get_instance();

            $imap_password = self::getDesKey();
            $deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

            $crypted = $rcmail->encrypt($clear, 'carddav_des_key');

            // there seems to be no way to unset a preference
            $deskey_backup = $rcmail->config->set('carddav_des_key', '');

            return '{ENCRYPTED}' . $crypted;
        }

        if (strcasecmp(self::$pwstore_scheme, 'des_key') === 0) {
            // encrypted with global des_key
            $rcmail = rcmail::get_instance();
            $crypted = $rcmail->encrypt($clear);

            return '{DES_KEY}' . $crypted;
        }

        // default: base64-coded password
        return '{BASE64}' . base64_encode($clear);
    }

    public static function decryptPassword(string $crypt): string
    {
        if (strpos($crypt, '{ENCRYPTED}') === 0) {
            // return {IGNORE} scheme if session password is empty (krb_authentication plugin)
            if (empty($_SESSION['password'])) {
                return '{IGNORE}';
            }

            $crypt = substr($crypt, strlen('{ENCRYPTED}'));
            $rcmail = rcmail::get_instance();

            $imap_password = self::getDesKey();
            $deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

            $clear = $rcmail->decrypt($crypt, 'carddav_des_key');

            // there seems to be no way to unset a preference
            $deskey_backup = $rcmail->config->set('carddav_des_key', '');

            return $clear;
        }

        if (strpos($crypt, '{DES_KEY}') === 0) {
            $crypt = substr($crypt, strlen('{DES_KEY}'));
            $rcmail = rcmail::get_instance();

            return $rcmail->decrypt($crypt);
        }

        if (strpos($crypt, '{BASE64}') === 0) {
            $crypt = substr($crypt, strlen('{BASE64}'));
            return base64_decode($crypt);
        }

        // unknown scheme, assume cleartext
        return $crypt;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
