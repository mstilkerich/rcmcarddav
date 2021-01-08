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
use Psr\Log\LoggerInterface;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, RoundcubeLogger};
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database, AbstractDatabase};

/**
 * @psalm-type CarddavOptions = array{
 *                                    logger?: LoggerInterface, logger_http?: LoggerInterface,
 *                                    db?: AbstractDatabase,
 *                                    cache?: rcube_cache
 *                                   }
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
        'uri' => 'https://github.com/blind-coder/rcmcarddav/'
    ];

    /** @var list<string> ABOOK_PROPS A list of addressbook property keys. These are both found in the settings form as
     *                                well as in the database as columns.
     */
    private const ABOOK_PROPS = [
        "name", "active", "use_categories", "username", "password", "url", "refresh_time", "sync_token"
    ];

    /** @var list<string> ABOOK_PROPS_BOOL A list of addressbook property keys of all boolean properties. */
    private const ABOOK_PROPS_BOOL = [ "active", "use_categories" ];

    /** @var string $pwstore_scheme encryption scheme */
    private $pwstore_scheme = 'encrypted';

    /** @var ?array $admin_settings admin settings from config.inc.php */
    private static $admin_settings;

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var LoggerInterface $httpLogger */
    private $httpLogger;

    /** @var AbstractDatabase $db */
    private $db;

    /** @var ?rcube_cache $cache */
    private $cache;

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
     * @param CarddavOptions $options
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

        $this->logger = $options["logger"] ?? new RoundcubeLogger("carddav", \Psr\Log\LogLevel::ERROR);
        $this->httpLogger = $options["logger_http"] ?? new RoundcubeLogger("carddav_http", \Psr\Log\LogLevel::ERROR);

        $rcube = \rcube::get_instance();
        $this->db = $options["db"] ?? new Database($this->logger, $rcube->db);

        if (isset($options["cache"])) {
            $this->cache = $options["cache"];
            // the roundcube cache object cannot be retrieved at this point
        }
    }

    public function init(): void
    {
        $logger = $this->logger;

        try {
            $prefs = $this->getAdminSettings();

            if ($logger instanceof RoundcubeLogger) {
                if (isset($prefs['_GLOBAL']['loglevel']) && is_string($prefs['_GLOBAL']['loglevel'])) {
                    $logger->setLogLevel($prefs['_GLOBAL']['loglevel']);
                }
            }
            if ($this->httpLogger instanceof RoundcubeLogger) {
                if (isset($prefs['_GLOBAL']['loglevel_http']) && is_string($prefs['_GLOBAL']['loglevel_http'])) {
                    $this->httpLogger->setLogLevel($prefs['_GLOBAL']['loglevel_http']);
                }
            }

            // initialize carddavclient library
            Config::init($logger, $this->httpLogger);

            $this->add_texts('localization/', false);

            $this->add_hook('addressbooks_list', [$this, 'listAddressbooks']);
            $this->add_hook('addressbook_get', [$this, 'getAddressbook']);

            // if preferences are configured as hidden by the admin, don't register the hooks handling preferences
            if (!($prefs['_GLOBAL']['hide_preferences'] ?? false)) {
                $this->add_hook('preferences_list', [$this, 'buildPreferencesPage']);
                $this->add_hook('preferences_save', [$this, 'savePreferences']);
                $this->add_hook('preferences_sections_list', [$this, 'addPreferencesSection']);
            }

            $this->add_hook('login_after', [$this, 'checkMigrations']);
            $this->add_hook('login_after', [$this, 'initPresets']);

            if (!key_exists('user_id', $_SESSION)) {
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
        $logger = $this->logger;

        try {
            $logger->debug(__METHOD__);

            $scriptDir = dirname(__FILE__) . "/dbmigrations/";
            $config = rcube::get_instance()->config;
            $this->db->checkMigrations($config->get('db_prefix', ""), $scriptDir);
        } catch (\Exception $e) {
            $logger->error("Error execution DB schema migrations: " . $e->getMessage());
        }
    }

    public function initPresets(): void
    {
        $logger = $this->logger;

        try {
            $logger->debug(__METHOD__);

            $prefs = $this->getAdminSettings();

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
                $logger->info("Deleting preset addressbooks for " . $_SESSION['user_id']);
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
     */
    public function listAddressbooks(array $p): array
    {
        $logger = $this->logger;

        try {
            $logger->debug(__METHOD__);

            $prefs = $this->getAdminSettings();

            foreach ($this->getAddressbooks() as $abookId => $abookrow) {
                $ro = false;
                if (isset($abookrow['presetname']) && $prefs[$abookrow['presetname']]['readonly']) {
                    $ro = true;
                }

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
     * @return array Returns the passed array extended by a key instance pointing to the addressbook object.
     *     If the addressbook is not provided by the plugin, simply do not set the instance and return what was passed.
     */
    public function getAddressbook(array $p): array
    {
        $logger = $this->logger;

        try {
            $logger->debug(__METHOD__ . "({$p['id']})");

            if (preg_match(";^carddav_(\d+)$;", $p['id'], $match)) {
                $abookId = $match[1];
                $abooks = $this->getAddressbooks(false);

                // check that this addressbook ID actually refers to one of the user's addressbooks
                if (isset($abooks[$abookId])) {
                    $config = $abooks[$abookId];
                    $presetname = $config["presetname"] ?? null;
                    $readonly = false;
                    $requiredProps = [];

                    if (isset($presetname)) {
                        $prefs = $this->getAdminSettings();
                        $readonly = !empty($prefs[$presetname]["readonly"]);
                        $requiredProps = $prefs[$presetname]["require_always"] ?? [];
                    }

                    $config['username'] = self::replacePlaceholdersUsername($config["username"]);
                    $config['password'] = self::replacePlaceholdersPassword(
                        $this->decryptPassword($config["password"])
                    );

                    $abook = new Addressbook(
                        $abookId,
                        $this->db,
                        $this->getRoundcubeCache(),
                        $logger,
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
     * @param array Original parameters
     *
     * @return array Modified parameters
     */
    public function buildPreferencesPage(array $args): array
    {
        $logger = $this->logger;

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            $this->include_stylesheet($this->local_skin_path() . '/carddav.css');
            $prefs = $this->getAdminSettings();
            $abooks = $this->getAddressbooks(false);
            uasort(
                $abooks,
                function (array $a, array $b): int {
                    // presets first
                    $ret = strcasecmp($b["presetname"] ?? "", $a["presetname"] ?? "");
                    if ($ret == 0) {
                        // then alphabetically by name
                        $ret = strcasecmp($a["name"] ?? "", $b["name"] ?? "");
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
                $presetname = $abookrow['presetname'];
                if (
                    empty($presetname)
                    || !isset($prefs[$presetname]['hide'])
                    || $prefs[$presetname]['hide'] === false
                ) {
                    $blockhdr = $abookrow['name'];
                    if (!empty($presetname)) {
                        $blockhdr .= str_replace("_PRESETNAME_", $presetname, $fromPresetStringLocalized);
                    }
                    $args["blocks"]["cd_preferences$abookId"] = $this->buildSettingsBlock($blockhdr, $abookrow);
                }
            }

            // if allowed by admin, provide a block for entering data for a new addressbook
            if (!($prefs['_GLOBAL']['fixed'] ?? false)) {
                $args['blocks']['cd_preferences_section_new'] = $this->buildSettingsBlock(
                    rcube::Q($this->gettext('cd_newabboxtitle')),
                    $this->getAddressbookSettingsFromPOST('new')
                );
            }
        } catch (\Exception $e) {
            $logger->error("Error building carddav preferences page: " . $e->getMessage());
        }

        return $args;
    }

    // add a section to the preferences tab
    public function addPreferencesSection(array $args): array
    {
        $logger = $this->logger;

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
        $logger = $this->logger;

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            $prefs = $this->getAdminSettings();

            // update existing in DB
            foreach ($this->getAddressbooks(false) as $abookId => $abookrow) {
                if (isset($_POST["${abookId}_cd_delete"])) {
                    $this->deleteAddressbook($abookId);
                } else {
                    $newset = $this->getAddressbookSettingsFromPOST($abookId);

                    // only set the password if the user entered a new one
                    if (empty($newset['password'])) {
                        unset($newset['password']);
                    }

                    // remove admin only settings
                    foreach (array_keys($newset) as $pref) {
                        if ($this->noOverrideAllowed($pref, $abookrow, $prefs)) {
                            unset($newset[$pref]);
                        }
                    }

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
                !($prefs['_GLOBAL']['fixed'] ?? false) // creation of addressbooks allowed by admin
                && !empty($new['name']) // user entered a name (and hopefully more data) for a new addressbook
            ) {
                try {
                    if (filter_var($new["url"], FILTER_VALIDATE_URL) === false) {
                        throw new \Exception("Invalid URL: {$new['url']}");
                    }
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

                            $logger->info("Adding addressbook {$new['username']} @ {$new['url']}");
                            $this->insertAddressbook($new);
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
        $username = strtr($username, [
            '%u' => $_SESSION['username'],
            '%l' => $rcube->user->get_username('local'),
            '%d' => $rcube->user->get_username('domain'),
            // %V parses username for macosx, replaces periods and @ by _, work around bugs in contacts.app
            '%V' => strtr($_SESSION['username'], "@.", "__")
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
            $password = $rcube->decrypt($_SESSION['password']);
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

    private function updateAddressbook(string $abookId, array $pa): void
    {
        // encrypt the password before storing it
        if (key_exists('password', $pa)) {
            $pa['password'] = $this->encryptPassword($pa['password']);
        }

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

        $this->db->update($abookId, $qf, $qv, "addressbooks");
        $this->abooksDb = null;
    }

    /**
     * Converts a password to storage format according to the password storage scheme setting.
     *
     * @param string $clear The password in clear text.
     * @return string The password in storage format (e.g. encrypted with user password as key)
     */
    private function encryptPassword(string $clear): string
    {
        $scheme = $this->pwstore_scheme;

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
        $logger = $this->logger;

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
                if (key_exists($k, $abookrow) && key_exists($k, $preset)) {
                    // only update the name if it is used
                    if ($k === 'name') {
                        if (!($preset['carddav_name_only'] ?? false)) {
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
                $this->updateAddressbook($abookrow['id'], $pa);
            }
        }
    }

    private function noOverrideAllowed(string $pref, array $abook, array $prefs): bool
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
    private function buildSettingsBlock(string $blockheader, array $abook): array
    {
        $prefs = self::getAdminSettings();
        $abookId = $abook['id'];

        if ($this->noOverrideAllowed('active', $abook, $prefs)) {
            $content_active = $abook['active'] ? $this->gettext('cd_enabled') : $this->gettext('cd_disabled');
        } else {
            // check box for activating
            $checkbox = new html_checkbox(['name' => $abookId . '_cd_active', 'value' => 1]);
            $content_active = $checkbox->show($abook['active'] ? "1" : "0");
        }

        if ($this->noOverrideAllowed('use_categories', $abook, $prefs)) {
            $content_use_categories = $abook['use_categories']
                ? $this->gettext('cd_enabled')
                : $this->gettext('cd_disabled');
        } else {
            // check box for use categories
            $checkbox = new html_checkbox(['name' => $abookId . '_cd_use_categories', 'value' => 1]);
            $content_use_categories = $checkbox->show($abook['use_categories'] ? "1" : "0");
        }

        if ($this->noOverrideAllowed('username', $abook, $prefs)) {
            $content_username = self::replacePlaceholdersUsername($abook['username']);
        } else {
            // input box for username
            $input = new html_inputfield([
                'name' => $abookId . '_cd_username',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $abook['username']
            ]);
            $content_username = $input->show();
        }

        if ($this->noOverrideAllowed('password', $abook, $prefs)) {
            $content_password = "***";
        } else {
            // only display the password if it was entered for a new addressbook
            $show_pw_val = ($abook['id'] === "new" && isset($abook['password'])) ? $abook['password'] : '';
            // input box for password
            $input = new html_inputfield([
                'name' => $abookId . '_cd_password',
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
                'name' => $abookId . '_cd_url',
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
        if (isset($abook["refresh_time"])) {
            $rt = $abook['refresh_time'];
            $refresh_time_str = sprintf("%02d:%02d:%02d", floor($rt / 3600), ($rt / 60) % 60, $rt % 60);
        } else {
            $refresh_time_str = "";
        }
        if ($this->noOverrideAllowed('refresh_time', $abook, $prefs)) {
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

        if ($this->noOverrideAllowed('name', $abook, $prefs)) {
            $content_name = $abook['name'];
        } else {
            $input = new html_inputfield([
                'name' => $abookId . '_cd_name',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $abook['name'],
                'size' => 40
            ]);
            $content_name = $input->show();
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

        if (empty($abook['presetname']) && preg_match('/^\d+$/', $abookId)) {
            $checkbox = new html_checkbox(['name' => $abookId . '_cd_delete', 'value' => 1]);
            $content_delete = $checkbox->show("0");
            $retval['options'][] = ['title' => rcube::Q($this->gettext('cd_delete')), 'content' => $content_delete];
        }

        if (preg_match('/^\d+$/', $abookId)) {
            $checkbox = new html_checkbox(['name' => $abookId . '_cd_resync', 'value' => 1]);
            $content_resync = $checkbox->show("0");
            $retval['options'][] = ['title' => rcube::Q($this->gettext('cd_resync')), 'content' => $content_resync];
        }

        return $retval;
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
    private function getAddressbookSettingsFromPOST(string $abookId): array
    {
        $nonEmptyDefaults = [
            "active" => "1",
            "use_categories" => "1",
        ];

        $name = rcube_utils::get_input_value("${abookId}_cd_name", rcube_utils::INPUT_POST);
        $active = rcube_utils::get_input_value("${abookId}_cd_active", rcube_utils::INPUT_POST);
        $use_categories = rcube_utils::get_input_value("${abookId}_cd_use_categories", rcube_utils::INPUT_POST);

        $result = [
            'id' => $abookId,
            'name' => $name,
            'username' => rcube_utils::get_input_value("${abookId}_cd_username", rcube_utils::INPUT_POST, true),
            'password' => rcube_utils::get_input_value("${abookId}_cd_password", rcube_utils::INPUT_POST, true),
            'active' => $active,
            'use_categories' => $use_categories,
        ];

        $url = rcube_utils::get_input_value("${abookId}_cd_url", rcube_utils::INPUT_POST);
        if (isset($url)) {
            $url = trim($url);
            if (!empty($url)) {
                // FILTER_VALIDATE_URL requires the scheme component, default to https if not specified
                if (strpos($url, "://") === false) {
                    $url = "https://$url";
                }
                $result["url"] = $url;
            }
        }

        try {
            $refresh_timestr = rcube_utils::get_input_value("${abookId}_cd_refresh_time", rcube_utils::INPUT_POST);
            if (isset($refresh_timestr)) {
                $result["refresh_time"] = (string) self::parseTimeParameter($refresh_timestr);
            }
        } catch (\Exception $e) {
            // will use the DB default for new addressbooks, or leave the value unchanged for existing ones
        }

        if ($abookId == 'new') {
            // detect if the POST request contains user-provided info for this addressbook or not
            // (Problem: unchecked checkboxes don't appear with POSTed values, so we cannot discern not set values from
            // actively unchecked values).
            if (isset($name)) {
                foreach (self::ABOOK_PROPS_BOOL as $boolOpt) {
                    if (!isset($result[$boolOpt])) {
                        $result[$boolOpt] = "0";
                    }
                }
            }

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
            foreach (self::ABOOK_PROPS_BOOL as $boolOpt) {
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

    private function deleteAddressbook(string $abookId): void
    {
        $logger = $this->logger;

        $db = $this->db;

        try {
            $db->startTransaction(false);

            // we explicitly delete all data belonging to the addressbook, since
            // cascaded deleted are not supported by all database backends
            // ...custom subtypes
            $db->delete(['abook_id' => $abookId], 'xsubtypes');

            // ...groups and memberships
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

    private function insertAddressbook(array $pa): void
    {
        // check parameters
        if (key_exists('password', $pa)) {
            $pa['password'] = $this->encryptPassword($pa['password']);
        }

        $pa['user_id']      = $_SESSION['user_id'];

        // required fields
        $qf = ['name','username','password','url','user_id'];
        $qv = [];
        foreach ($qf as $f) {
            if (!key_exists($f, $pa)) {
                throw new \Exception("Required parameter $f not provided for new addressbook");
            }
            $qv[] = $pa[$f];
        }

        // optional fields
        $qfo = ['active','presetname','use_categories','refresh_time'];
        foreach ($qfo as $f) {
            if (key_exists($f, $pa)) {
                $qf[] = $f;
                $qv[] = $pa[$f];
            }
        }

        $this->db->insert("addressbooks", $qf, [$qv]);
        $this->abooksDb = null;
    }

    /**
     * This function read and caches the admin settings from config.inc.php.
     *
     * Upon first call, the config file is read and the result is cached and returned. On subsequent calls, the cached
     * result is returned without reading the file again.
     *
     * @return array The admin settings array defined in config.inc.php.
     */
    private function getAdminSettings(): array
    {
        if (isset(self::$admin_settings)) {
            return self::$admin_settings;
        }

        $prefs = [];
        $configfile = dirname(__FILE__) . "/config.inc.php";
        if (file_exists($configfile)) {
            include($configfile);
        }

        // empty preset key is not allowed
        if (isset($prefs[""])) {
            $this->logger->error("A preset key must be a non-empty string - ignoring preset!");
            unset($prefs[""]);
        }

        // initialize password store scheme if set
        if (isset($prefs['_GLOBAL']['pwstore_scheme'])) {
            $scheme = $prefs['_GLOBAL']['pwstore_scheme'];
            if (preg_match("/^(plain|base64|encrypted|des_key)$/", $scheme)) {
                $this->pwstore_scheme = $scheme;
            }
        }

        // convert values to internal format
        foreach ($prefs as $presetname => &$preset) {
            // _GLOBAL contains plugin configuration not related to an addressbook preset - skip
            if ($presetname === '_GLOBAL') {
                continue;
            }

            // boolean options are stored as 0 / 1 in the DB, internally we represent DB values as string
            foreach (self::ABOOK_PROPS_BOOL as $boolOpt) {
                if (isset($preset[$boolOpt])) {
                    $preset[$boolOpt] = $preset[$boolOpt] ? '1' : '0';
                }
            }

            // refresh_time is stored in seconds
            try {
                if (isset($preset["refresh_time"])) {
                    $preset["refresh_time"] = (string) self::parseTimeParameter($preset["refresh_time"]);
                }
            } catch (\Exception $e) {
                self::$logger->error("Error in preset $presetname: " . $e->getMessage());
                unset($preset["refresh_time"]);
            }
        }

        self::$admin_settings = $prefs;
        return $prefs;
    }

    /**
     * Returns a handle to the roundcube cache for the user.
     *
     * Note: this must be called at a time where the user is already logged on, specifically it must not be called
     * during the constructor of this plugin.
     */
    private function getRoundcubeCache(): rcube_cache
    {
        if (!isset($this->cache)) {
            // TODO make TTL and cache type configurable
            $this->cache = rcube::get_instance()->get_cache("carddav", "db", "1w");
        }

        if (!isset($this->cache)) {
            throw new \Exception("Attempt to request cache where not available yet");
        }

        return $this->cache;
    }

    // password helpers
    private function getDesKey(): string
    {
        $rcube = rcube::get_instance();
        $imap_password = $rcube->decrypt($_SESSION['password']);
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
            $db = $this->db;

            $this->abooksDb = [];
            /** @var FullAbookRow $abookrow */
            foreach ($db->get(['user_id' => $_SESSION['user_id']], '*', 'addressbooks') as $abookrow) {
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
            $this->db->update($abook->getId(), ["last_updated"], [(string) $ts_delay], "addressbooks");
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
            $this->logger->error("Failed to sync addressbook: " . $e->getMessage());
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
