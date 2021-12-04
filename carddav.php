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
use MStilkerich\CardDavAddressbook4Roundcube\Frontend\{AddressbookManager,AdminSettings,RcmInterface,UI};

// phpcs:ignore PSR1.Classes.ClassDeclaration, Squiz.Classes.ValidClassName -- class name(space) expected by roundcube
class carddav extends rcube_plugin implements RcmInterface
{
    /**
     * The version of this plugin.
     *
     * During development, it is set to the last release and added the suffix +dev.
     */
    private const PLUGIN_VERSION = 'v5.0.0+dev';

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
    public function __construct($api)
    {
        parent::__construct($api);

        // we do not currently use the roundcube mechanism to save preferences
        // but store preferences to custom database tables
        $this->allowed_prefs = [];

        // This supports a self-contained tarball installation of the plugin, at the risk of having conflicts with other
        // versions of the library installed in the global roundcube vendor directory (-> use not recommended)
        if (file_exists(__DIR__ . "/vendor/autoload.php")) {
            include_once __DIR__ . "/vendor/autoload.php";
        }

        $infra = Config::inst();
        $infra->rc($this);

        $this->abMgr = new AddressbookManager();
    }

    public function init(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        $rcmail = \rcmail::get_instance();
        $rcTask = $rcmail->task;
        $rcAction = $rcmail->action;
        $infra->logger()->debug(__METHOD__ . ", $rcTask, $rcAction");

        try {
            $rc = $infra->rc();
            $rc->addHook('login_after', [$this, 'afterLogin']);

            if (!isset($_SESSION['user_id'])) {
                return;
            }

            // initialize carddavclient library
            MStilkerich\CardDavClient\Config::init($logger, $infra->httpLogger());

            $rc->addTexts('localization/');

            $rc->addHook('addressbooks_list', [$this, 'listAddressbooks']);
            $rc->addHook('addressbook_get', [$this, 'getAddressbook']);

            // if preferences are configured as hidden by the admin, don't register the hooks handling preferences
            $admPrefs = $infra->admPrefs();
            if (!$admPrefs->hidePreferences && $rcTask == "settings") {
                new UI($this->abMgr);
            }

            // use this address book for autocompletion queries
            // (maybe this should be configurable by the user?)
            $config = rcube::get_instance()->config;
            $sources = (array) $config->get('autocomplete_addressbooks', ['sql']);

            $carddav_sources = array_map(
                function (string $id): string {
                    return "carddav_$id";
                },
                $this->abMgr->getAddressbookIds()
            );

            $config->set('autocomplete_addressbooks', array_merge($sources, $carddav_sources));
        } catch (\Exception $e) {
            $logger->error("Could not init rcmcarddav: " . $e);
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

    public function inputValue(string $id, bool $allowHtml, int $source = rcube_utils::INPUT_POST): ?string
    {
        $value = rcube_utils::get_input_value($id, $source, $allowHtml);

        return is_string($value) ? $value : null;
    }

    public function showMessage(string $msg, string $msgType = 'notice', $override = false, $timeout = 0): void
    {
        $rcube = \rcube::get_instance();
        $rcube->output->show_message($msg, $msgType, null, $override, $timeout);
    }

    public function clientCommand(string $method, ...$arguments): void
    {
        $rcube = \rcube::get_instance();
        $output = $rcube->output;

        if ($output instanceof \rcmail_output) {
            $output->command($method, ...$arguments);
        }
    }

    public function addHook(string $hook, callable $callback): void
    {
        $this->add_hook($hook, $callback);
    }

    public function registerAction(string $action, callable $callback): void
    {
        $this->register_action($action, $callback);
    }

    public function addTexts(string $dir): void
    {
        $this->add_texts($dir, true);
    }

    public function includeCSS(string $cssFile): void
    {
        $skinPath = $this->local_skin_path();
        $this->include_stylesheet("$skinPath/$cssFile");
    }

    public function includeJS(string $jsFile, bool $rcInclude = false): void
    {
        if ($rcInclude) {
            $rcube = \rcube::get_instance();
            /** @psalm-var \rcmail_output_html */
            $output = $rcube->output;
            $output->include_script($jsFile);
        } else {
            $this->include_script($jsFile);
        }
    }

    public function addGuiObject(string $obj, string $id): void
    {
        $rcube = \rcube::get_instance();

        /** @psalm-var \rcmail_output_html */
        $output = $rcube->output;
        $output->add_gui_object($obj, $id);
    }

    public function setPageTitle(string $title): void
    {
        $rcube = \rcube::get_instance();

        /** @psalm-var \rcmail_output_html */
        $output = $rcube->output;
        $output->set_pagetitle($title);
    }

    public function addTemplateObjHandler(string $name, callable $func): void
    {
        $rcube = \rcube::get_instance();

        /** @psalm-var \rcmail_output_html */
        $output = $rcube->output;
        $output->add_handler($name, $func);
    }

    public function sendTemplate(string $templ, $exit = true): void
    {
        $rcube = \rcube::get_instance();

        /** @psalm-var \rcmail_output_html */
        $output = $rcube->output;
        $output->send($templ, $exit);
    }

    public function requestForm(array $attrib, string $content): string
    {
        $rcube = \rcube::get_instance();

        /** @psalm-var \rcmail_output_html */
        $output = $rcube->output;
        return $output->request_form($attrib, $content);
    }

    /***************************************************************************************
     *                                    HOOK FUNCTIONS
     **************************************************************************************/

    public function afterLogin(): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $admPrefs = $infra->admPrefs();
        $db = $infra->db();

        // Migrate database schema to the current version if needed
        try {
            $logger->debug(__METHOD__);

            $scriptDir = __DIR__ . "/dbmigrations/";
            $config = rcube::get_instance()->config;
            $dbprefix = (string) $config->get('db_prefix', "");
            $db->checkMigrations($dbprefix, $scriptDir);
        } catch (\Exception $e) {
            $logger->error("Error execution DB schema migrations: " . $e->getMessage());
        }

        // Initialize presets
        $admPrefs->initPresets($this->abMgr, $infra);
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
            $abMgr = $this->abMgr;

            foreach ($abMgr->getAddressbookIds() as $abookId) {
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookrow['account_id']);

                if (isset($account['presetname'])) {
                    try {
                        // TODO consider making readonly a DB column
                        $preset = $admPrefs->getPreset($account['presetname'], $abookrow['url']);
                        $readonly = $preset['readonly'];
                    } catch (\Exception $e) {
                        // it appears the admin deleted the preset - don't show the addressbook to roundcube
                        continue;
                    }
                } else {
                    $readonly = false;
                }

                $p['sources']["carddav_$abookId"] = [
                    'id' => "carddav_$abookId",
                    'name' => $abookrow['name'],
                    'groups' => true,
                    'autocomplete' => true,
                    'readonly' => $readonly
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
        $rc = $infra->rc();
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
                    try {
                        $duration = $abMgr->resyncAddressbook($abook);

                        $rc->showMessage(
                            $rc->locText(
                                'cd_msg_synchronized',
                                [ 'name' => $abook->get_name(), 'duration' => (string) $duration ]
                            ),
                            'notice',
                            false
                        );
                    } catch (\Exception $e) {
                        $logger->error("Failed to sync addressbook: {$e->getMessage()}");
                        $rc->showMessage(
                            $rc->locText(
                                'cd_msg_syncfailed',
                                [ 'name' => $abook->get_name(), 'errormsg' => $e->getMessage() ]
                            ),
                            'warning',
                            false
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error loading carddav addressbook $abookId: {$e->getMessage()}");
        }

        return $p;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
