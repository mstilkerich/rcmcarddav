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

declare(strict_types=1);

use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use Psr\Log\LoggerInterface;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config, RoundcubeLogger};
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database, AbstractDatabase};
use MStilkerich\CardDavAddressbook4Roundcube\Frontend\{AddressbookManager,AdminSettings,RcmInterface,SettingsUI};

// phpcs:ignore PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName -- class name(space) expected by roundcube
class carddav extends rcube_plugin implements RcmInterface
{
    /**
     * The version of this plugin.
     *
     * During development, it is set to the last release and added the suffix +dev.
     */
    private const PLUGIN_VERSION = 'v4.2.0+dev';

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

    /**
     * Regular expression defining task(s) to bind with
     * @var string
     */
    public $task = 'addressbook|login|mail|settings';

    /**
     * The addressbook manager.
     * @var AddressbookManager
     */
    private $abMgr;

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
        parent::__construct($api);

        // we do not currently use the roundcube mechanism to save preferences
        // but store preferences to custom database tables
        $this->allowed_prefs = [];

        // This supports a self-contained tarball installation of the plugin, at the risk of having conflicts with other
        // versions of the library installed in the global roundcube vendor directory (-> use not recommended)
        if (file_exists(dirname(__FILE__) . "/vendor/autoload.php")) {
            include_once dirname(__FILE__) . "/vendor/autoload.php";
        }

        $infra = Config::inst();
        $infra->rc($this);

        $admPrefs = new AdminSettings(dirname(__FILE__) . "/config.inc.php");
        $infra->admPrefs($admPrefs);

        $this->abMgr = new AddressbookManager();
    }

    public function init(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $admPrefs = $infra->admPrefs();

        try {
            // initialize carddavclient library
            MStilkerich\CardDavClient\Config::init($logger, $infra->httpLogger());

            $this->add_texts('localization/', false);

            $this->add_hook('addressbooks_list', [$this, 'listAddressbooks']);
            $this->add_hook('addressbook_get', [$this, 'getAddressbook']);

            // if preferences are configured as hidden by the admin, don't register the hooks handling preferences
            if (!$admPrefs->hidePreferences) {
                $ui = new SettingsUI($this->abMgr);
                $this->add_hook('preferences_list', [$ui, 'buildPreferencesPage']);
                $this->add_hook('preferences_save', [$ui, 'savePreferences']);
                $this->add_hook('preferences_sections_list', [$ui, 'addPreferencesSection']);
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
                array_keys($this->abMgr->getAddressbooks())
            );

            $config->set('autocomplete_addressbooks', array_merge($sources, $carddav_sources));
            $skin_path = $this->local_skin_path();
            $this->include_stylesheet($skin_path . '/carddav.css');
        } catch (\Exception $e) {
            $logger->error("Could not init rcmcarddav: " . $e->getMessage());
        }
    }

    /***************************************************************************************
     *                                  ROUNDCUBE ADAPTER
     **************************************************************************************/

    public function locText(string $msgId, array $vars = []): string
    {
        $locMsg = $this->gettext(["name" => $msgId, "vars" => $vars]);
        return rcube::Q($locMsg);
    }

    public function inputValue(string $id, bool $allowHtml): ?string
    {
        return rcube_utils::get_input_value($id, rcube_utils::INPUT_POST, $allowHtml);
    }

    public function showMessage(string $msg, string $msgType = 'notice', $override = false, $timeout = 0): void
    {
        $rcube = \rcube::get_instance();
        $rcube->output->show_message($msg, $msgType, null, $override, $timeout);
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
        $admPrefs = Config::inst()->admPrefs();
        $admPrefs->initPresets($this->abMgr);
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
        $infra = Config::inst();
        $logger = $infra->logger();
        $admPrefs = $infra->admPrefs();

        try {
            $logger->debug(__METHOD__);

            foreach ($this->abMgr->getAddressbooks() as $abookrow) {
                $abookId = $abookrow["id"];
                $presetname = $abookrow['presetname'] ?? ""; // empty string is not a valid preset name
                $ro = $admPrefs->presets[$presetname]['readonly'] ?? false;

                $p['sources']["carddav_$abookId"] = [
                    'id' => "carddav_$abookId",
                    'name' => $abookrow['name'],
                    'groups' => true,
                    'autocomplete' => true,
                    'readonly' => $ro,
                ];
            }
        } catch (\Exception $e) {
            $logger->error("Error reading carddav addressbooks: {$e->getMessage()}");
        }

        return $p;
    }

    /**
     * Hook called by roundcube to retrieve the instance of an addressbook.
     *
     * @param array $p The passed array contains the keys:
     *     id: ID of the addressbook as passed to roundcube in the listAddressbooks hook.
     *     writeable: Whether the addressbook needs to be writeable (checked by roundcube after returning an instance).
     * @psalm-param array{id: ?string} $p
     * @return array Returns the passed array extended by a key instance pointing to the addressbook object.
     *     If the addressbook is not provided by the plugin, simply do not set the instance and return what was passed.
     */
    public function getAddressbook(array $p): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $abMgr = $this->abMgr;

        $abookId = $p['id'] ?? 'null';

        try {
            $logger->debug(__METHOD__ . "($abookId)");

            if (preg_match(";^carddav_(\d+)$;", $abookId, $match)) {
                $abookId = $match[1];
                $abook = $abMgr->getAddressbook($abookId);
                $p['instance'] = $abook;

                // refresh the address book if the update interval expired this requires a completely initialized
                // Addressbook object, so it needs to be at the end of this constructor
                $ts_syncdue = $abook->checkResyncDue();
                if ($ts_syncdue <= 0) {
                    $abMgr->resyncAddressbook($abook);
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error loading carddav addressbook $abookId: {$e->getMessage()}");
        }

        return $p;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
