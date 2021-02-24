<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavClient\Services\Discovery;
use Psr\Log\LoggerInterface;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config, RoundcubeLogger};
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database, AbstractDatabase};

/**
 * @psalm-type PasswordStoreScheme = 'plain' | 'base64' | 'des_key' | 'encrypted'
 * @psalm-type ConfigurablePresetAttribute = 'name'|'url'|'username'|'password'|'active'|'refresh_time'
 * @psalm-type Preset = array{
 *     name: string,
 *     url: string,
 *     username: string,
 *     password: string,
 *     active: bool,
 *     use_categories: bool,
 *     readonly: bool,
 *     refresh_time: int,
 *     fixed: list<ConfigurablePresetAttribute>,
 *     require_always: list<string>,
 *     hide: bool,
 *     carddav_name_only: bool
 * }
 * @psalm-type AbookSettings = array{
 *     name?: string,
 *     username?: string,
 *     password?: string,
 *     url?: string,
 *     refresh_time?: int,
 *     active?: bool,
 *     use_categories?: bool,
 *     presetname?: string
 * }
 * @psalm-import-type FullAbookRow from AbstractDatabase
 */
// phpcs:ignore PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName -- class name(space) expected by roundcube
class carddav extends rcube_plugin
{
    /**
     * The version of this plugin.
     *
     * During development, it is set to the last release and added the suffix +dev.
     */
    private const PLUGIN_VERSION = 'v4.1.0+dev';

    /**
     * Information about this plugin that is queried by roundcube.
     */
    private const PLUGIN_INFO = [
        'name' => 'carddav',
        'vendor' => 'Michael Stilkerich, Benjamin Schieder',
        'version' => self::PLUGIN_VERSION,
        'license' => 'GPL-2.0',
        'uri' => 'https://github.com/mstilkerich/rcmcarddav/'
    ];

    /** @var list<PasswordStoreScheme> List of supported password store schemes */
    private const PWSTORE_SCHEMES = [ 'plain', 'base64', 'des_key', 'encrypted' ];

    /**
     * @var AbookSettings Template for addressbook settings from the settings page.
     *      The default values in this template also serve do determine the type (bool, int, string).
     */
    private const ABOOK_TEMPLATE = [
        // standard addressbook settings
        'name' => '',
        'url' => '',
        'username' => '',
        'password' => '',
        'active' => true,
        'use_categories' => true,
        'refresh_time' => 3600,
    ];

    /**
     * @var Preset Template for a preset; has the standard addressbook settings plus some extra properties.
     *      The default values in this template also serve do determine the type (bool, int, string, array).
     */
    private const PRESET_TEMPLATE = self::ABOOK_TEMPLATE + [
        // extra settings for presets
        'readonly' => false,
        'carddav_name_only' => false,
        'hide' => false,
        'fixed' => [],
        'require_always' => [],
    ];

    /** @var PasswordStoreScheme encryption scheme */
    private $pwStoreScheme = 'encrypted';

    /** @var bool Global preference "fixed" */
    private $forbidCustomAddressbooks = false;

    /** @var bool Global preference "hide_preferences" */
    private $hidePreferences = false;

    /** @var array<string, Preset> Presets from config.inc.php */
    private $presets = [];

    public $task = 'addressbook|login|mail|settings';

    /** @var ?array<string, FullAbookRow> $abooksDb Cache of the user's addressbook DB entries.
     *                                              Associative array mapping addressbook IDs to DB rows.
     */
    private $abooksDb = null;

    /**
     * Provide information about this plugin.
     *
     * @return array Meta information about a plugin or false if not implemented.
     * As hash array with the following keys:
     *      name: The plugin name
     *    vendor: Name of the plugin developer
     *   version: Plugin version name
     *   license: License name (short form according to http://spdx.org/licenses/)
     *       uri: The URL to the plugin homepage or source repository
     *   src_uri: Direct download URL to the source code of this plugin
     *   require: List of plugins required for this one (as array of plugin names)
     */
    public static function info()
    {
        return self::PLUGIN_INFO;
    }

    /**
     * Default constructor.
     *
     * @param rcube_plugin_api $api Plugin API
     */
    public function __construct($api, array $options = [])
    {
        // This supports a self-contained tarball installation of the plugin, at the risk of having conflicts with other
        // versions of the library installed in the global roundcube vendor directory (-> use not recommended)
        if (file_exists(dirname(__FILE__) . "/vendor/autoload.php")) {
            include_once dirname(__FILE__) . "/vendor/autoload.php";
        }

        parent::__construct($api);

        // we do not currently use the roundcube mechanism to save preferences
        // but store preferences to custom database tables
        $this->allowed_prefs = [];
    }

    public function init(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $this->readAdminSettings();

            // initialize carddavclient library
            MStilkerich\CardDavClient\Config::init($logger, $infra->httpLogger());

            $this->add_texts('localization/', false);

            $this->add_hook('addressbooks_list', [$this, 'listAddressbooks']);
            $this->add_hook('addressbook_get', [$this, 'getAddressbook']);

            // if preferences are configured as hidden by the admin, don't register the hooks handling preferences
            if (!$this->hidePreferences) {
                $this->add_hook('preferences_list', [$this, 'buildPreferencesPage']);
                $this->add_hook('preferences_save', [$this, 'savePreferences']);
                $this->add_hook('preferences_sections_list', [$this, 'addPreferencesSection']);
            }

            $this->add_hook('login_after', [$this, 'checkMigrations']);
            $this->add_hook('login_after', [$this, 'initPresets']);

            if (!isset($_SESSION['user_id'])) {
                return;
            }

            // use this address book for autocompletion queries
            // (maybe this should be configurable by the user?)
            $config = rcube::get_instance()->config;
            $sources = (array) $config->get('autocomplete_addressbooks', ['sql']);

            $carddav_sources = array_map(
                function (string $id): string {
                    return "carddav_$id";
                },
                array_keys($this->getAddressbooks())
            );

            $config->set('autocomplete_addressbooks', array_merge($sources, $carddav_sources));
            $skin_path = $this->local_skin_path();
            $this->include_stylesheet($skin_path . '/carddav.css');
        } catch (\Exception $e) {
            $logger->error("Could not init rcmcarddav: " . $e->getMessage());
        }
    }

    /***************************************************************************************
     *                                    HOOK FUNCTIONS
     **************************************************************************************/

    public function checkMigrations(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $logger->debug(__METHOD__);

            $scriptDir = dirname(__FILE__) . "/dbmigrations/";
            $config = rcube::get_instance()->config;
            $dbprefix = (string) $config->get('db_prefix', "");
            $db->checkMigrations($dbprefix, $scriptDir);
        } catch (\Exception $e) {
            $logger->error("Error execution DB schema migrations: " . $e->getMessage());
        }
    }

    public function initPresets(): void
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            // Get all existing addressbooks of this user that have been created from presets
            $existing_abooks = $this->getAddressbooks(false, true);

            // Group the addressbooks by their preset
            $existing_presets = [];
            foreach ($existing_abooks as $abookrow) {
                /** @var string $pn Not null because filtered by getAddressbooks() */
                $pn = $abookrow['presetname'];
                if (!key_exists($pn, $existing_presets)) {
                    $existing_presets[$pn] = [];
                }
                $existing_presets[$pn][] = $abookrow;
            }

            // Walk over the current presets configured by the admin and add, update or delete addressbooks
            foreach ($this->presets as $presetname => $preset) {
                // addressbooks exist for this preset => update settings
                if (key_exists($presetname, $existing_presets)) {
                    if (!empty($preset['fixed'])) {
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

                        $logger->info("Adding preset for $username at URL $url");
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
                            $this->insertAddressbook($preset);
                        }
                    } catch (\Exception $e) {
                        $logger->error("Error adding addressbook from preset $presetname: {$e->getMessage()}");
                    }
                }
            }

            // delete existing preset addressbooks that were removed by admin
            foreach ($existing_presets as $ep) {
                $logger->info("Deleting preset addressbooks for " . (string) $_SESSION['user_id']);
                foreach ($ep as $abookrow) {
                    $this->deleteAddressbook($abookrow['id']);
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error initializing preconfigured addressbooks: " . $e->getMessage());
        }
    }

    /**
     * Adds the user's CardDAV addressbooks to Roundcube's addressbook list.
     *
     * @psalm-type RcAddressbookInfo = array{id: string, name: string, groups: bool, autocomplete: bool, readonly: bool}
     * @psalm-param array{sources: array<string, RcAddressbookInfo>} $p
     * @return array{sources: array<string, RcAddressbookInfo>}
     */
    public function listAddressbooks(array $p): array
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            foreach ($this->getAddressbooks() as $abookId => $abookrow) {
                $presetname = $abookrow['presetname'] ?? ""; // empty string is not a valid preset name
                $ro = $this->presets[$presetname]['readonly'] ?? false;

                $p['sources']["carddav_$abookId"] = [
                    'id' => "carddav_$abookId",
                    'name' => $abookrow['name'],
                    'groups' => true,
                    'autocomplete' => true,
                    'readonly' => $ro,
                ];
            }
        } catch (\Exception $e) {
            $logger->error("Error reading carddav addressbooks: " . $e->getMessage());
        }

        return $p;
    }

    /**
     * Hook called by roundcube to retrieve the instance of an addressbook.
     *
     * @param array $p The passed array contains the keys:
     *     id: ID of the addressbook as passed to roundcube in the listAddressbooks hook.
     *     writeable: Whether the addressbook needs to be writeable (checked by roundcube after returning an instance).
     * @psalm-param array{id: string} $p
     * @return array Returns the passed array extended by a key instance pointing to the addressbook object.
     *     If the addressbook is not provided by the plugin, simply do not set the instance and return what was passed.
     */
    public function getAddressbook(array $p): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->debug(__METHOD__ . "({$p['id']})");

            if (preg_match(";^carddav_(\d+)$;", $p['id'], $match)) {
                $abookId = $match[1];
                $abooks = $this->getAddressbooks(false);

                // check that this addressbook ID actually refers to one of the user's addressbooks
                if (isset($abooks[$abookId])) {
                    $config = $abooks[$abookId];
                    $presetname = $config["presetname"] ?? ""; // empty string is not a valid preset name

                    $readonly = !empty($this->presets[$presetname]["readonly"] ?? '0');
                    $requiredProps = $this->presets[$presetname]["require_always"] ?? [];

                    $config['username'] = self::replacePlaceholdersUsername($config["username"]);
                    $config['password'] = self::replacePlaceholdersPassword(
                        $this->decryptPassword($config["password"])
                    );

                    $abook = new Addressbook(
                        $abookId,
                        $config,
                        $readonly,
                        $requiredProps
                    );
                    $p['instance'] = $abook;

                    // refresh the address book if the update interval expired this requires a completely initialized
                    // Addressbook object, so it needs to be at the end of this constructor
                    $ts_syncdue = $abook->checkResyncDue();
                    if ($ts_syncdue <= 0) {
                        $this->resyncAddressbook($abook);
                    }
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error loading carddav addressbook {$p['id']}: " . $e->getMessage());
        }

        return $p;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into CardDAV settings sections in Preferences.
     *
     * @psalm-param array{section: string, blocks: array} $args Original parameters
     * @return array Modified parameters
     */
    public function buildPreferencesPage(array $args): array
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            $this->include_stylesheet($this->local_skin_path() . '/carddav.css');
            $abooks = $this->getAddressbooks(false);
            uasort(
                $abooks,
                function (array $a, array $b): int {
                    /** @var FullAbookRow $a */
                    $a = $a;
                    /** @var FullAbookRow $b */
                    $b = $b;
                    // presets first
                    $ret = strcasecmp($b["presetname"] ?? "", $a["presetname"] ?? "");
                    if ($ret == 0) {
                        // then alphabetically by name
                        $ret = strcasecmp($a["name"], $b["name"]);
                    }
                    if ($ret == 0) {
                        // finally by id (normally the names will differ)
                        $ret = $a["id"] <=> $b["id"];
                    }
                    return $ret;
                }
            );


            $fromPresetStringLocalized = rcube::Q($this->gettext('cd_frompreset'));
            foreach ($abooks as $abookId => $abookrow) {
                $presetname = $abookrow['presetname'] ?? ""; // empty string is not a valid presetname
                if (!($this->presets[$presetname]['hide'] ?? false)) {
                    $blockhdr = $abookrow['name'];
                    if (!empty($presetname)) {
                        $blockhdr .= str_replace("_PRESETNAME_", $presetname, $fromPresetStringLocalized);
                    }
                    $args["blocks"]["cd_preferences$abookId"] =
                        $this->buildSettingsBlock($blockhdr, $abookrow, $abookId);
                }
            }

            // if allowed by admin, provide a block for entering data for a new addressbook
            if (!$this->forbidCustomAddressbooks) {
                $args['blocks']['cd_preferences_section_new'] = $this->buildSettingsBlock(
                    rcube::Q($this->gettext('cd_newabboxtitle')),
                    $this->getAddressbookSettingsFromPOST('new'),
                    "new"
                );
            }
        } catch (\Exception $e) {
            $logger->error("Error building carddav preferences page: " . $e->getMessage());
        }

        return $args;
    }

    /**
     * add a section to the preferences tab
     * @psalm-param array{list: array, cols: array} $args
     */
    public function addPreferencesSection(array $args): array
    {
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            $args['list']['cd_preferences'] = [
                'id'      => 'cd_preferences',
                'section' => rcube::Q($this->gettext('cd_title'))
            ];
        } catch (\Exception $e) {
            $logger->error("Error adding carddav preferences section: " . $e->getMessage());
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
        $logger = Config::inst()->logger();

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            // update existing in DB
            foreach ($this->getAddressbooks(false) as $abookId => $abookrow) {
                if (isset($_POST["${abookId}_cd_delete"])) {
                    $this->deleteAddressbook($abookId);
                } else {
                    $newset = $this->getAddressbookSettingsFromPOST($abookId, $abookrow["presetname"]);
                    $this->updateAddressbook($abookId, $newset);

                    if (isset($_POST["${abookId}_cd_resync"])) {
                        [ 'instance' => $backend ] = $this->getAddressbook(['id' => "carddav_$abookId"]);
                        if ($backend instanceof Addressbook) {
                            $this->resyncAddressbook($backend);
                        }
                    }
                }
            }

            // add a new address book?
            $new = $this->getAddressbookSettingsFromPOST('new');
            if (
                !$this->forbidCustomAddressbooks // creation of addressbooks allowed by admin
                && !empty($new['name']) // user entered a name (and hopefully more data) for a new addressbook
            ) {
                try {
                    $new["url"] = $new["url"] ?? "";
                    $new["username"] = $new['username'] ?? "";
                    $new["password"] = $new['password'] ?? "";

                    if (filter_var($new["url"], FILTER_VALIDATE_URL) === false) {
                        throw new \Exception("Invalid URL: " . $new["url"]);
                    }
                    $account = new Account(
                        $new["url"],
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

                            $logger->info("Adding addressbook {$new['username']} @ {$new['url']}");
                            $this->insertAddressbook($new);
                        }

                        // new addressbook added successfully -> clear the data from the form
                        foreach (array_keys(self::ABOOK_TEMPLATE) as $k) {
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
        } catch (\Exception $e) {
            $logger->error("Error saving carddav preferences: " . $e->getMessage());
        }

        return $args;
    }


    /***************************************************************************************
     *                              PRIVATE FUNCTIONS
     **************************************************************************************/

    private static function replacePlaceholdersUsername(string $username): string
    {
        $rcube = rcube::get_instance();
        $rcusername = (string) $_SESSION['username'];

        $username = strtr($username, [
            '%u' => $rcusername,
            '%l' => $rcube->user->get_username('local'),
            '%d' => $rcube->user->get_username('domain'),
            // %V parses username for macosx, replaces periods and @ by _, work around bugs in contacts.app
            '%V' => strtr($rcusername, "@.", "__")
        ]);

        return $username;
    }

    private static function replacePlaceholdersUrl(string $url): string
    {
        // currently same as for username
        return self::replacePlaceholdersUsername($url);
    }

    private static function replacePlaceholdersPassword(string $password): string
    {
        if ($password == '%p') {
            $rcube = rcube::get_instance();
            $password = $rcube->decrypt((string) $_SESSION['password']);
        }

        return $password;
    }

    /**
     * Parses a time string to seconds.
     *
     * The time string must have the format HH[:MM[:SS]]. If the format does not match, an exception is thrown.
     *
     * @param string $refresht The time string to parse
     * @return int The time in seconds
     */
    private static function parseTimeParameter(string $refresht): int
    {
        if (preg_match('/^(\d+)(:([0-5]?\d))?(:([0-5]?\d))?$/', $refresht, $match)) {
            $ret = 0;

            $ret += intval($match[1] ?? 0) * 3600;
            $ret += intval($match[3] ?? 0) * 60;
            $ret += intval($match[5] ?? 0);
        } else {
            throw new \Exception("Time string $refresht could not be parsed");
        }

        return $ret;
    }

    /**
     * @param AbookSettings $pa Array with the settings to update
     */
    private function updateAddressbook(string $abookId, array $pa): void
    {
        // encrypt the password before storing it
        if (isset($pa['password'])) {
            $pa['password'] = $this->encryptPassword($pa['password']);
        }

        // optional fields
        $qf = [];
        $qv = [];

        foreach (array_keys(self::ABOOK_TEMPLATE) as $f) {
            if (isset($pa[$f])) {
                $v = $pa[$f];

                $qf[] = $f;
                if (is_bool($v)) {
                    $qv[] = $v ? '1' : '0';
                } else {
                    $qv[] = (string) $v;
                }
            }
        }

        if (!empty($qf)) {
            $db = Config::inst()->db();
            $db->update($abookId, $qf, $qv, "addressbooks");
            $this->abooksDb = null;
        }
    }

    /**
     * Converts a password to storage format according to the password storage scheme setting.
     *
     * @param string $clear The password in clear text.
     * @return string The password in storage format (e.g. encrypted with user password as key)
     */
    private function encryptPassword(string $clear): string
    {
        $scheme = $this->pwStoreScheme;

        if (strcasecmp($scheme, 'plain') === 0) {
            return $clear;
        }

        if (strcasecmp($scheme, 'encrypted') === 0) {
            if (empty($_SESSION['password'])) { // no key for encryption available, downgrade to DES_KEY
                $scheme = 'des_key';
            } else {
                // encrypted with IMAP password
                $rcube = rcube::get_instance();

                $imap_password = $this->getDesKey();
                $rcube->config->set('carddav_des_key', $imap_password);

                $crypted = $rcube->encrypt($clear, 'carddav_des_key');

                // there seems to be no way to unset a preference
                $rcube->config->set('carddav_des_key', '');

                return '{ENCRYPTED}' . $crypted;
            }
        }

        if (strcasecmp($scheme, 'des_key') === 0) {
            // encrypted with global des_key
            $rcube = rcube::get_instance();
            $crypted = $rcube->encrypt($clear);
            return '{DES_KEY}' . $crypted;
        }

        // default: base64-coded password
        return '{BASE64}' . base64_encode($clear);
    }

    private function decryptPassword(string $crypt): string
    {
        $logger = Config::inst()->logger();

        if (strpos($crypt, '{ENCRYPTED}') === 0) {
            // return empty password if decruption key not available
            if (empty($_SESSION['password'])) {
                $logger->warning("Cannot decrypt password as now session password is available");
                return "";
            }

            $crypt = substr($crypt, strlen('{ENCRYPTED}'));
            $rcube = rcube::get_instance();

            $imap_password = $this->getDesKey();
            $rcube->config->set('carddav_des_key', $imap_password);

            $clear = $rcube->decrypt($crypt, 'carddav_des_key');

            // there seems to be no way to unset a preference
            $rcube->config->set('carddav_des_key', '');

            return $clear;
        }

        if (strpos($crypt, '{DES_KEY}') === 0) {
            $crypt = substr($crypt, strlen('{DES_KEY}'));
            $rcube = rcube::get_instance();

            return $rcube->decrypt($crypt);
        }

        if (strpos($crypt, '{BASE64}') === 0) {
            $crypt = substr($crypt, strlen('{BASE64}'));
            return base64_decode($crypt);
        }

        // unknown scheme, assume cleartext
        return $crypt;
    }

    /**
     * Updates the fixed fields of addressbooks derived from presets against the current admin settings.
     * @param Preset $preset
     * @param list<FullAbookRow> $existing_abooks for the given preset
     */
    private function updatePresetAddressbooks(array $preset, array $existing_abooks): void
    {
        if (!is_array($preset["fixed"] ?? "")) {
            return;
        }

        foreach ($existing_abooks as $abookrow) {
            // decrypt password so that the comparison works
            $abookrow['password'] = $this->decryptPassword($abookrow['password']);

            // update only those attributes marked as fixed by the admin
            // otherwise there may be user changes that should not be destroyed
            $pa = [];

            foreach ($preset['fixed'] as $k) {
                if (isset($abookrow[$k]) && isset($preset[$k])) {
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
            if (!empty($pa)) {
                /** @psalm-var AbookSettings $pa */
                $this->updateAddressbook($abookrow['id'], $pa);
            }
        }
    }

    /**
     * @param ?string $presetName If the setting is checked for an addressbook from a preset, the key of the preset.
     *                            Null if the setting is checked for a user-defined addressbook.
     * @return bool True if the setting is fixed for the given preset. Always false for user-defined addressbooks.
     */
    private function noOverrideAllowed(string $pref, ?string $presetName): bool
    {
        // generally, url is fixed, as it results from discovery and has no direct correlation with the admin setting
        // if the URL of the addressbook changes, all URIs of our database objects would have to change, too -> in such
        // cases, deleting and re-adding the addressbook would be simpler
        if ($pref == "url") {
            return true;
        }

        $pn = $presetName ?? ""; // empty string is not a valid presetname
        return in_array($pref, $this->presets[$pn]['fixed'] ?? []);
    }

    /**
     * @param null|string|bool $value Value to show if the field can be edited.
     * @param null|string|bool $roValue Value to show if the field is shown in non-editable form.
     */
    private function buildSettingField(
        string $abookId,
        string $attr,
        $value,
        ?string $presetName,
        $roValue = null
    ): string {
        // if the value is not set, use the default from the addressbook template
        $value = $value ?? self::ABOOK_TEMPLATE[$attr];
        $roValue = $roValue ?? $value;
        // For new addressbooks, no attribute is fixed (note: noOverrideAllowed always returns true for URL)
        $attrFixed = $abookId != "new" && $this->noOverrideAllowed($attr, $presetName);

        if (is_bool(self::ABOOK_TEMPLATE[$attr])) {
            // boolean settings as a checkbox
            if ($attrFixed) {
                $content = $roValue ? $this->gettext('cd_enabled') : $this->gettext('cd_disabled');
            } else {
                // check box for activating
                $checkbox = new html_checkbox(['name' => "${abookId}_cd_$attr", 'value' => 1]);
                $content = $checkbox->show($value ? "1" : "0");
            }
        } elseif (is_string(self::ABOOK_TEMPLATE[$attr])) {
            if ($attrFixed) {
                $content = (string) $roValue;
            } else {
                // input box for username
                $input = new html_inputfield([
                    'name' => "${abookId}_cd_$attr",
                    'type' => ($attr == 'password') ? 'password' : 'text',
                    'autocomplete' => 'off',
                    'value' => $value
                ]);
                $content = $input->show();
            }
        } else {
            throw new \Exception("unsupported type");
        }

        return $content;
    }

    /**
     * Builds a setting block for one address book for the preference page.
     * @param FullAbookRow|AbookSettings $abook
     */
    private function buildSettingsBlock(string $blockheader, array $abook, string $abookId): array
    {
        $presetName = $abook["presetname"] ?? null;
        $content_active = $this->buildSettingField($abookId, "active", $abook['active'] ?? null, $presetName);
        $content_use_categories =
            $this->buildSettingField($abookId, "use_categories", $abook['use_categories'] ?? null, $presetName);
        $content_name = $this->buildSettingField($abookId, "name", $abook['name'] ?? null, $presetName);
        $content_username = $this->buildSettingField(
            $abookId,
            "username",
            $abook['username'] ?? null,
            $presetName,
            self::replacePlaceholdersUsername($abook['username'] ?? "")
        );
        $content_password = $this->buildSettingField(
            $abookId,
            "password",
            // only display the password if it was entered for a new addressbook
            ($abookId == "new") ? ($abook['password'] ?? "") : "",
            $presetName,
            "***"
        );
        $content_url = $this->buildSettingField(
            $abookId,
            "url",
            $abook['url'] ?? null,
            $presetName
        );

        // input box for refresh time
        if (isset($abook["refresh_time"])) {
            $rt = $abook['refresh_time'];
            $refresh_time_str = sprintf("%02d:%02d:%02d", floor($rt / 3600), ($rt / 60) % 60, $rt % 60);
        } else {
            $refresh_time_str = "";
        }
        if ($this->noOverrideAllowed('refresh_time', $presetName)) {
            $content_refresh_time =  $refresh_time_str . ", ";
        } else {
            $input = new html_inputfield([
                'name' => $abookId . '_cd_refresh_time',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $refresh_time_str,
                'size' => 10
            ]);
            $content_refresh_time = $input->show();
        }

        if (!empty($abook['last_updated'])) { // if never synced, last_updated is 0 -> don't show
            $content_refresh_time .=  rcube::Q($this->gettext('cd_lastupdate_time')) . ": ";
            $content_refresh_time .=  date("Y-m-d H:i:s", intval($abook['last_updated']));
        }

        $retval = [
            'options' => [
                ['title' => rcube::Q($this->gettext('cd_name')), 'content' => $content_name],
                ['title' => rcube::Q($this->gettext('cd_active')), 'content' => $content_active],
                ['title' => rcube::Q($this->gettext('cd_use_categories')), 'content' => $content_use_categories],
                ['title' => rcube::Q($this->gettext('cd_username')), 'content' => $content_username],
                ['title' => rcube::Q($this->gettext('cd_password')), 'content' => $content_password],
                ['title' => rcube::Q($this->gettext('cd_url')), 'content' => $content_url],
                ['title' => rcube::Q($this->gettext('cd_refresh_time')), 'content' => $content_refresh_time],
            ],
            'name' => $blockheader
        ];

        if (empty($presetName) && preg_match('/^\d+$/', $abookId)) {
            $checkbox = new html_checkbox(['name' => $abookId . '_cd_delete', 'value' => 1]);
            $content_delete = $checkbox->show("0");
            $retval['options'][] = ['title' => rcube::Q($this->gettext('cd_delete')), 'content' => $content_delete];
        }

        if ($abookId != "new") {
            $checkbox = new html_checkbox(['name' => $abookId . '_cd_resync', 'value' => 1]);
            $content_resync = $checkbox->show("0");
            $retval['options'][] = ['title' => rcube::Q($this->gettext('cd_resync')), 'content' => $content_resync];
        }

        return $retval;
    }

    /**
     * This function gets the addressbook settings from a POST request.
     *
     * The result array will only have keys set for POSTed values.
     *
     * For fixed settings of preset addressbooks, no setting values will be contained.
     *
     * Boolean settings will always be present in the result, since there is no way to differentiate whether a checkbox
     * was not ticket or the value was not submitted at all - so the absence of a boolean setting is considered as a
     * false value for the setting.
     *
     * @param string $abookId The ID of the addressbook ("new" for new addressbooks, otherwise the numeric DB id)
     * @param ?string $presetName Name of the preset the addressbook belongs to; null for user-defined addressbook.
     * @return AbookSettings An array with addressbook column keys and their setting.
     */
    private function getAddressbookSettingsFromPOST(string $abookId, ?string $presetName = null): array
    {
        $result = [ ];

        // Fill $result with all values that have been POSTed; for unset boolean values, false is assumed
        foreach (array_keys(self::ABOOK_TEMPLATE) as $attr) {
            // fixed settings for preset addressbooks are ignored
            if ($abookId != "new" && $this->noOverrideAllowed($attr, $presetName)) {
                continue;
            }

            $allow_html = ($attr == 'password');
            $value = rcube_utils::get_input_value("${abookId}_cd_$attr", rcube_utils::INPUT_POST, $allow_html);

            if (is_bool(self::ABOOK_TEMPLATE[$attr])) {
                $result[$attr] = (bool) $value;
            } elseif (isset($value)) {
                if ($attr == "refresh_time") {
                    try {
                        $result["refresh_time"] = self::parseTimeParameter($value);
                    } catch (\Exception $e) {
                        // will use the DB default for new addressbooks, or leave the value unchanged for existing ones
                    }
                } elseif ($attr == "url") {
                    $value = trim($value);
                    if (!empty($value)) {
                        // FILTER_VALIDATE_URL requires the scheme component, default to https if not specified
                        if (strpos($value, "://") === false) {
                            $value = "https://$value";
                        }
                    }
                    $result["url"] = $value;
                } elseif ($attr == "password") {
                    // Password is only updated if not empty
                    if (!empty($value)) {
                        $result["password"] = $value;
                    }
                } else {
                    $result[$attr] = $value;
                }
            }
        }

        // Set default values for boolean options of new addressbook; if name is null, it means the form is loaded for
        // the first time, otherwise it has been posted.
        if ($abookId == "new" && !isset($result["name"])) {
            foreach (self::ABOOK_TEMPLATE as $attr => $value) {
                if (is_bool($value)) {
                    $result[$attr] = $value;
                }
            }
        }

        /** @psalm-var AbookSettings */
        return $result;
    }

    private function deleteAddressbook(string $abookId): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $db = $infra->db();

        try {
            $db->startTransaction(false);

            // we explicitly delete all data belonging to the addressbook, since
            // cascaded deleted are not supported by all database backends
            // ...custom subtypes
            $db->delete(['abook_id' => $abookId], 'xsubtypes');

            // ...groups and memberships
            /** @psalm-var list<string> $delgroups */
            $delgroups = array_column($db->get(['abook_id' => $abookId], 'id', 'groups'), "id");
            if (!empty($delgroups)) {
                $db->delete(['group_id' => $delgroups], 'group_user');
            }

            $db->delete(['abook_id' => $abookId], 'groups');

            // ...contacts
            $db->delete(['abook_id' => $abookId], 'contacts');

            $db->delete($abookId, 'addressbooks');

            $db->endTransaction();
        } catch (\Exception $e) {
            $logger->error("Could not delete addressbook: " . $e->getMessage());
            $db->rollbackTransaction();
        }
        $this->abooksDb = null;
    }

    /**
     * @param AbookSettings $pa Array with the settings for the new addressbook
     */
    private function insertAddressbook(array $pa): void
    {
        $db = Config::inst()->db();

        // check parameters
        if (isset($pa['password'])) {
            $pa['password'] = $this->encryptPassword($pa['password']);
        }

        $pa['user_id'] = (string) $_SESSION['user_id'];

        // required fields
        $qf = ['name','username','password','url','user_id'];
        $qv = [];
        foreach ($qf as $f) {
            if (!isset($pa[$f])) {
                throw new \Exception("Required parameter $f not provided for new addressbook");
            }
            $v = $pa[$f];
            if (is_bool($v)) {
                $qv[] = $v ? '1' : '0';
            } else {
                $qv[] = (string) $pa[$f];
            }
        }

        // optional fields
        $qfo = ['active','presetname','use_categories','refresh_time'];
        foreach ($qfo as $f) {
            if (isset($pa[$f])) {
                $qf[] = $f;

                $v = $pa[$f];
                if (is_bool($v)) {
                    $qv[] = $v ? '1' : '0';
                } else {
                    $qv[] = (string) $pa[$f];
                }
            }
        }

        $db->insert("addressbooks", $qf, [$qv]);
        $this->abooksDb = null;
    }

    /**
     * This function read and caches the admin settings from config.inc.php.
     *
     * Upon first call, the config file is read and the result is cached and returned. On subsequent calls, the cached
     * result is returned without reading the file again.
     */
    private function readAdminSettings(): void
    {
        $logger = Config::inst()->logger();
        $httpLogger = Config::inst()->httpLogger();
        $prefs = [];
        $configfile = dirname(__FILE__) . "/config.inc.php";
        if (file_exists($configfile)) {
            include($configfile);
        }

        // Extract global preferences
        if (isset($prefs['_GLOBAL']['pwstore_scheme']) && is_string($prefs['_GLOBAL']['pwstore_scheme'])) {
            $scheme = $prefs['_GLOBAL']['pwstore_scheme'];

            if (in_array($scheme, self::PWSTORE_SCHEMES)) {
                /** @var PasswordStoreScheme $scheme */
                $this->pwStoreScheme = $scheme;
            }
        }

        $this->forbidCustomAddressbooks = ($prefs['_GLOBAL']['fixed'] ?? false) ? true : false;
        $this->hidePreferences = ($prefs['_GLOBAL']['hide_preferences'] ?? false) ? true : false;

        foreach (['loglevel' => $logger, 'loglevel_http' => $httpLogger] as $setting => $logger) {
            if (isset($prefs['_GLOBAL'][$setting]) && is_string($prefs['_GLOBAL'][$setting])) {
                if ($logger instanceof RoundcubeLogger) {
                    $logger->setLogLevel($prefs['_GLOBAL'][$setting]);
                }
            }
        }

        // Store presets
        foreach ($prefs as $presetname => $preset) {
            // _GLOBAL contains plugin configuration not related to an addressbook preset - skip
            if ($presetname === '_GLOBAL') {
                continue;
            }

            if (!is_string($presetname) || empty($presetname)) {
                $logger->error("A preset key must be a non-empty string - ignoring preset!");
                continue;
            }

            if (!is_array($preset)) {
                $logger->error("A preset definition must be an array of settings - ignoring preset $presetname!");
                continue;
            }

            $this->addPreset($presetname, $preset);
        }
    }

    /**
     * Adds the given preset from config.inc.php to $this->presets.
     */
    private function addPreset(string $presetname, array $preset): void
    {
        $logger = Config::inst()->logger();

        // Resulting preset initialized with defaults
        $result = self::PRESET_TEMPLATE;

        try {
            foreach (array_keys($result) as $attr) {
                if ($attr == 'refresh_time') {
                    // refresh_time is stored in seconds
                    if (isset($preset["refresh_time"])) {
                        if (is_string($preset["refresh_time"])) {
                            $result["refresh_time"] = self::parseTimeParameter($preset["refresh_time"]);
                        } else {
                            $logger->error("Preset $presetname: setting $attr must be time string like 01:00:00");
                        }
                    }
                } elseif (is_bool($result[$attr])) {
                    if (isset($preset[$attr])) {
                        if (is_bool($preset[$attr])) {
                            $result[$attr] = $preset[$attr];
                        } else {
                            $logger->error("Preset $presetname: setting $attr must be boolean");
                        }
                    }
                } elseif (is_array($result[$attr])) {
                    if (isset($preset[$attr]) && is_array($preset[$attr])) {
                        foreach (array_keys($preset[$attr]) as $k) {
                            if (is_string($preset[$attr][$k])) {
                                $result[$attr][] = $preset[$attr][$k];
                            }
                        }
                    }
                } else {
                    if (isset($preset[$attr]) && is_string($preset[$attr])) {
                        $result[$attr] = $preset[$attr];
                    }
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error in preset $presetname: " . $e->getMessage());
        }
    }

    // password helpers
    private function getDesKey(): string
    {
        $rcube = rcube::get_instance();
        $imap_password = $rcube->decrypt((string) $_SESSION['password']);
        while (strlen($imap_password) < 24) {
            $imap_password .= $imap_password;
        }
        return substr($imap_password, 0, 24);
    }

    /**
     * Returns all the users addressbooks, optionally filtered.
     *
     * @param $activeOnly If true, only the active addressbooks of the user are returned.
     * @param $presetsOnly If true, only the addressbooks created from an admin preset are returned.
     * @return array<string, FullAbookRow>
     */
    private function getAddressbooks(bool $activeOnly = true, bool $presetsOnly = false): array
    {
        if (!isset($this->abooksDb)) {
            $db = Config::inst()->db();

            $this->abooksDb = [];
            /** @var FullAbookRow $abookrow */
            foreach ($db->get(['user_id' => (string) $_SESSION['user_id']], '*', 'addressbooks') as $abookrow) {
                $this->abooksDb[$abookrow["id"]] = $abookrow;
            }
        }

        $result = $this->abooksDb;

        if ($activeOnly) {
            $result = array_filter($result, function (array $v): bool {
                return $v["active"] == "1";
            });
        }

        if ($presetsOnly) {
            $result = array_filter($result, function (array $v): bool {
                return !empty($v["presetname"]);
            });
        }

        return $result;
    }

    /**
     * Resyncs the given addressbook and displays a popup message about duration.
     *
     * @param Addressbook $abook The addressbook object
     */
    private function resyncAddressbook(Addressbook $abook): void
    {
        try {
            // To avoid unneccessary work followed by roll back with other time-triggered refreshes, we temporarily
            // set the last_updated time such that the next due time will be five minutes from now
            $ts_delay = time() + 300 - $abook->getRefreshTime();
            $db = Config::inst()->db();
            $db->update($abook->getId(), ["last_updated"], [(string) $ts_delay], "addressbooks");
            $duration = $abook->resync();

            $rcube = \rcube::get_instance();
            $rcube->output->show_message(
                $this->gettext([
                    'name' => 'cd_msg_synchronized',
                    'vars' => [
                        'name' => $abook->get_name(),
                        'duration' => $duration,
                    ]
                ])
            );
        } catch (\Exception $e) {
            $logger = Config::inst()->logger();
            $logger->error("Failed to sync addressbook: " . $e->getMessage());
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
