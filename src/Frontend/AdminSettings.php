<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
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

namespace MStilkerich\RCMCardDAV\Frontend;

use Exception;
use Psr\Log\LoggerInterface;
use MStilkerich\RCMCardDAV\{Config, RoundcubeLogger};
use MStilkerich\CardDavClient\AddressbookCollection;

/**
 * Represents the administrative settings of the plugin.
 *
 * @psalm-import-type Int1 from AddressbookManager
 * @psalm-import-type AbookCfg from AddressbookManager
 * @psalm-import-type AccountCfg from AddressbookManager
 * @psalm-import-type AccountSettings from AddressbookManager
 * @psalm-import-type AbookSettings from AddressbookManager
 *
 * @psalm-type PasswordStoreScheme = 'plain' | 'base64' | 'des_key' | 'encrypted'
 * @psalm-type ConfigurablePresetAttr = 'accountname'|'discovery_url'|'username'|'password'|'rediscover_time'|
 *                                      'active'|'refresh_time'|'use_categories'|'readonly'|'require_always_email'|
 *                                      'name'
 * @psalm-type SpecialAbookType = 'collected_recipients'|'collected_senders'
 * @psalm-type SpecialAbookMatch = array{preset: string, matchname?: string, matchurl?: string}
 *
 * @psalm-type PresetExtraAbook = array{
 *     url: string,
 *     name: string,
 *     active: Int1,
 *     readonly: Int1,
 *     refresh_time: numeric-string,
 *     use_categories: Int1,
 *     fixed: list<ConfigurablePresetAttr>,
 *     require_always_email: Int1,
 * }
 *
 * @psalm-type Preset = array{
 *     accountname: string,
 *     username: string,
 *     password: string,
 *     discovery_url: ?string,
 *     rediscover_time: numeric-string,
 *     hide: Int1,
 *     name: string,
 *     active: Int1,
 *     readonly: Int1,
 *     refresh_time: numeric-string,
 *     use_categories: Int1,
 *     fixed: list<ConfigurablePresetAttr>,
 *     require_always_email: Int1,
 *     extra_addressbooks?: array<string, PresetExtraAbook>,
 * }
 *
 * @psalm-type SettingSpecification=array{'url'|'timestr'|'string'|'bool'|'string[]'|'skip', bool}
 */
class AdminSettings
{
    /** @var list<PasswordStoreScheme> List of supported password store schemes */
    public const PWSTORE_SCHEMES = [ 'plain', 'base64', 'des_key', 'encrypted' ];

    /**
     * @var list<string> ALWAYS_FIXED
     *     List of attributes that are always fixed. This makes sure the attribute is updated from the preset on login.
     */
    private const ALWAYS_FIXED = ['readonly', 'require_always_email'];

    /** @var Preset Default values for the preset attributes */
    private const PRESET_DEFAULTS = [
        'accountname'        => '',
        'username'           => '',
        'password'           => '',
        'discovery_url'      => null,
        'rediscover_time'    => '86400',

        'hide'               => '0',
        'name'               => '%N',
        'active'             => '1',
        'readonly'           => '0',
        'refresh_time'       => '3600',
        'use_categories'     => '1',
        'fixed'              => [],
        'require_always_email' => '0',
    ];

    /**
     * @var array<string, SettingSpecification> PRESET_SETTINGS_COMMON
     *   This describes the valid attributes in a preset configuration that are available in the main account but can
     *   also be overridden for extra addressbooks in their preset configuration.
     */
    private const PRESET_SETTINGS_COMMON = [
        //                    type,       mandatory
        'name'           => [ 'string',   false ],
        'active'         => [ 'bool',     false ],
        'readonly'       => [ 'bool',     false ],
        'refresh_time'   => [ 'timestr',  false ],
        'use_categories' => [ 'bool',     false ],
        'fixed'          => [ 'string[]', false ],
        'require_always_email' => [ 'bool', false ],
    ];

    /**
     * @var array<string, SettingSpecification> PRESET_SETTINGS_EXTRA_ABOOK
     *   This describes the valid attributes in a preset configuration of an extra addressbook (non-discovered), their
     *   data type, whether they are mandatory to be specified by the admin, and the default value for optional
     *   attributes.
     */
    private const PRESET_SETTINGS_EXTRA_ABOOK = [
        //                    type,       mandatory
        'url'            => [ 'url',      true  ],
    ] + self::PRESET_SETTINGS_COMMON;

    /**
     * @var array<string, SettingSpecification> PRESET_SETTINGS
     *   This describes the valid attributes in a preset configuration, their data type, whether they are mandatory to
     *   be specified by the admin, and the default value for optional attributes.
     */
    private const PRESET_SETTINGS = [
        //                        type,       mandatory
        'accountname'        => [ 'string',   false ],
        'username'           => [ 'string',   false ],
        'password'           => [ 'string',   false ],
        'discovery_url'      => [ 'url',      false ],
        'rediscover_time'    => [ 'timestr',  false ],
        'hide'               => [ 'bool',     false ],
        'extra_addressbooks' => [ 'skip',     false ],
    ] + self::PRESET_SETTINGS_COMMON;

    /**
     * @var PasswordStoreScheme encryption scheme
     * @readonly
     */
    public $pwStoreScheme = 'encrypted';

    /**
     * @var bool Global preference "fixed"
     * @readonly
     */
    public $forbidCustomAddressbooks = false;

    /**
     * @var bool Global preference "hide_preferences"
     * @readonly
     */
    public $hidePreferences = false;

    /**
     * @var array<SpecialAbookType,SpecialAbookMatch> Match settings for special addressbooks
     */
    private $specialAbookMatchers = [];

    /**
     * @var array<string, Preset> Presets from config.inc.php
     */
    private $presets = [];

    /**
     * Initializes AdminSettings from a config.inc.php file, using default values if that file is not available.
     *
     * @param string $configfile Path of the config.inc.php file to load.
     */
    public function __construct(string $configfile, LoggerInterface $logger, LoggerInterface $httpLogger)
    {
        $prefs = [];
        if (file_exists($configfile)) {
            include($configfile);
            /** @psalm-var mixed $prefs Will be set in the configfile. */
        }

        $gprefs = [];
        if (isset($prefs['_GLOBAL'])) {
            if (is_array($prefs['_GLOBAL'])) {
                $gprefs = $prefs['_GLOBAL'];
            }
            unset($prefs['_GLOBAL']);
        }

        // Extract global preferences
        if (isset($gprefs['pwstore_scheme'])) {
            $scheme = (string) $gprefs['pwstore_scheme'];

            if (in_array($scheme, self::PWSTORE_SCHEMES)) {
                /** @var PasswordStoreScheme $scheme */
                $this->pwStoreScheme = $scheme;
            } else {
                $logger->error("Invalid pwStoreScheme $scheme in config.inc.php - using default 'encrypted'");
            }
        }

        $this->forbidCustomAddressbooks = !empty($gprefs['fixed'] ?? false);
        $this->hidePreferences = !empty($gprefs['hide_preferences'] ?? false);

        foreach (['loglevel' => $logger, 'loglevel_http' => $httpLogger] as $setting => $cfgdLogger) {
            if (($cfgdLogger instanceof RoundcubeLogger) && isset($gprefs[$setting])) {
                try {
                    $cfgdLogger->setLogLevel((string) $gprefs[$setting]);
                } catch (Exception $e) {
                    $logger->error("Cannot set configured loglevel: " . $e->getMessage());
                }
            }
        }

        // Store presets
        foreach ($prefs as $presetName => $preset) {
            if (!is_string($presetName) || strlen($presetName) == 0) {
                $logger->error("A preset key must be a non-empty string - ignoring preset!");
                continue;
            }

            if (!is_array($preset)) {
                $logger->error("A preset definition must be an array of settings - ignoring preset $presetName!");
                continue;
            }

            $this->addPreset($presetName, $preset, $logger);
        }

        // Extract filter for special addressbooks
        foreach ([ 'collected_recipients', 'collected_senders' ] as $setting) {
            if (isset($gprefs[$setting]) && is_array($gprefs[$setting])) {
                $matchSettings = $gprefs[$setting];

                if (
                    isset($matchSettings['preset'])
                    && is_string($matchSettings['preset'])
                    && key_exists($matchSettings['preset'], $this->presets)
                ) {
                    $presetName = $matchSettings['preset'];
                    $matchSettings2 = [ 'preset' => $presetName ];
                    foreach (['matchname', 'matchurl'] as $matchType) {
                        if (isset($matchSettings[$matchType]) && is_string($matchSettings[$matchType])) {
                            $matchexpr = $matchSettings[$matchType];
                            $matchSettings2[$matchType] = Utils::replacePlaceholdersUrl($matchexpr, true);
                        }
                    }
                    $this->specialAbookMatchers[$setting] = $matchSettings2;
                } else {
                    $logger->error("Setting for $setting must include a valid preset attribute");
                }
            }
        }
    }

    /**
     * Returns the preset with the given name.
     *
     * Optionally, the URL of a manually added addressbook may be given. In this case, the returned preset will contain
     * the values of that specific addressbook instead of those for auto-discovered addressbooks.
     *
     * @return Preset
     */
    public function getPreset(string $presetName, ?string $xabookUrl = null): array
    {
        if (!isset($this->presets[$presetName])) {
            throw new Exception("Query for undefined preset $presetName");
        }

        $preset = $this->presets[$presetName];

        if (isset($xabookUrl) && isset($preset['extra_addressbooks'][$xabookUrl])) {
            /**
             * @psalm-var Preset $preset psalm assumes that extra keys (e.g. hide) may be present in the xabook preset
             *            with unknown type, but this is not the case
             */
            $preset = $preset['extra_addressbooks'][$xabookUrl] + $preset;
        }

        unset($preset['extra_addressbooks']);
        return $preset;
    }

    /**
     * Creates / updates / deletes preset addressbooks.
     */
    public function initPresets(AddressbookManager $abMgr, Config $infra): void
    {
        $logger = $infra->logger();

        try {
            $userId = (string) $_SESSION['user_id'];

            // Get all existing accounts of this user that have been created from presets
            $accountIds = $abMgr->getAccountIds(true);
            $existingPresets = [];
            foreach ($accountIds as $accountId) {
                $account = $abMgr->getAccountConfig($accountId);
                /** @psalm-var string $presetName Not null because filtered by getAccountIds() */
                $presetName = $account['presetname'];
                $existingPresets[$presetName] = $accountId;
            }

            // Walk over the current presets configured by the admin and add, update or delete addressbooks
            foreach ($this->presets as $presetName => $preset) {
                try {
                    $logger->info("Adding/Updating preset $presetName for user $userId");

                    // Map URL => ID of the existing extra addressbooks in the DB for this preset
                    $existingExtraAbooksByUrl = [];

                    if (isset($existingPresets[$presetName])) {
                        $accountId = $existingPresets[$presetName];

                        // Update the extra addressbooks with the current set of the admin
                        $existingExtraAbooksByUrl = array_column(
                            $abMgr->getAddressbookConfigsForAccount($accountId, AddressbookManager::ABF_EXTRA),
                            'id',
                            'url'
                        );

                        // Delete extra addressbooks that do not exist anymore in preset
                        $delXAbooks = array_diff_key($existingExtraAbooksByUrl, $preset['extra_addressbooks'] ?? []);
                        if (!empty($delXAbooks)) {
                            $logger->info("Deleting deprecated extra addressbooks in $presetName for user $userId");
                            $abMgr->deleteAddressbooks(array_values($delXAbooks));
                            $existingExtraAbooksByUrl = array_diff_key($existingExtraAbooksByUrl, $delXAbooks);
                        }

                        // Update the fixed account/addressbook settings with the current admin values
                        $this->updatePresetSettings($presetName, $accountId, $abMgr, $infra);
                    } else {
                        // Add new account first (addressbooks follow below)
                        $accountCfg = $preset;
                        $accountCfg['presetname'] = $presetName;
                        $accountId = $abMgr->insertAccount($accountCfg);
                    }

                    $accountCfg = $abMgr->getAccountConfig($accountId);

                    // Create the extra addressbooks for this preset
                    foreach (array_keys($preset['extra_addressbooks'] ?? []) as $xabookUrl) {
                        if (!isset($existingExtraAbooksByUrl[$xabookUrl])) {
                            // create new
                            $this->insertExtraAddressbook($abMgr, $infra, $accountCfg, $xabookUrl, $presetName);
                        }
                    }
                } catch (Exception $e) {
                    $logger->error("Error adding/updating preset $presetName for user $userId {$e->getMessage()}");
                }

                unset($existingPresets[$presetName]);
            }

            // delete existing preset addressbooks that were removed by admin
            foreach ($existingPresets as $presetName => $accountId) {
                $logger->info("Deleting preset $presetName for user $userId");
                $abMgr->deleteAccount($accountId); // also deletes the addressbooks
            }
        } catch (Exception $e) {
            $logger->error("Error initializing preconfigured addressbooks: {$e->getMessage()}");
        }
    }

    /**
     * Updates the fixed fields of account and addressbooks derived from a preset with the current admin settings.
     *
     * This is done for all addressbooks of the account, including the template addressbook.
     *
     * Only fixed fields are updated, as non-fixed fields may have been changed by the user.
     *
     * @param AddressbookManager $abMgr The addressbook manager.
     */
    private function updatePresetSettings(
        string $presetName,
        string $accountId,
        AddressbookManager $abMgr,
        Config $infra
    ): void {
        $accountCfg = $abMgr->getAccountConfig($accountId);
        $this->updatePresetAccount($accountCfg, $presetName, $abMgr);

        $abookCfgs = $abMgr->getAddressbookConfigsForAccount($accountId, AddressbookManager::ABF_ALL);
        foreach ($abookCfgs as $abookCfg) {
            $this->updatePresetAddressbook($accountCfg, $abookCfg, $presetName, $abMgr, $infra);
        }
    }

    /**
     * Updates the fixed fields of a preset account with the current admin settings.
     *
     * Only fixed fields are updated, as non-fixed fields may have been changed by the user.
     *
     * @param AccountCfg $accountCfg
     */
    private function updatePresetAccount(array $accountCfg, string $presetName, AddressbookManager $abMgr): void
    {
        $preset = $this->getPreset($presetName);

        // update only those attributes marked as fixed by the admin
        // otherwise there may be user changes that should not be destroyed
        $pa = [];
        foreach ($preset['fixed'] as $k) {
            if (isset($preset[$k]) && isset($accountCfg[$k]) && $accountCfg[$k] != $preset[$k]) {
                $pa[$k] = $preset[$k];
            }
        }

        // only update if something changed
        if (!empty($pa)) {
            /** @psalm-var AccountSettings $pa */
            $abMgr->updateAccount($accountCfg['id'], $pa);
        }
    }

    /**
     * Updates the fixed fields of one preset addressbook with the current admin settings.
     *
     * Only fixed fields are updated, as non-fixed fields may have been changed by the user.
     *
     * @param AccountCfg $accountCfg
     * @param AbookCfg $abookCfg
     */
    private function updatePresetAddressbook(
        array $accountCfg,
        array $abookCfg,
        string $presetName,
        AddressbookManager $abMgr,
        Config $infra
    ): void {
        // extra addressbooks (discovered == 0) can have individual preset settings
        $preset = $this->getPreset($presetName, $abookCfg['url']);

        // update only those attributes marked as fixed by the admin
        // otherwise there may be user changes that should not be destroyed
        $pa = [];
        foreach ($preset['fixed'] as $k) {
            if (isset($preset[$k]) && isset($abookCfg[$k])) {
                try {
                    // no expansion of name template string for the template addressbook
                    if (empty($abookCfg['template']) && $k === 'name') {
                        $account = Config::makeAccount(
                            '',
                            Utils::replacePlaceholdersUsername($accountCfg['username'] ?? ''),
                            Utils::replacePlaceholdersPassword($accountCfg['password'] ?? ''),
                            null
                        );
                        $abook = $infra->makeWebDavResource($abookCfg['url'], $account);
                        if ($abook instanceof AddressbookCollection) {
                            $preset['name'] = $abMgr->replacePlaceholdersAbookName(
                                $preset['name'],
                                $accountCfg,
                                $abook
                            );
                        } else {
                            throw new Exception("no addressbook collection at given URL");
                        }
                    }

                    if ($abookCfg[$k] != $preset[$k]) {
                        $pa[$k] = $preset[$k];
                    }
                } catch (Exception $e) {
                    // skip updating the name but update the remaining attributes, plus log an error
                    $logger = $infra->logger();
                    $logger->error("Cannot update name of addressbook {$abookCfg['id']}: {$e->getMessage()}");
                }
            }
        }

        // only update if something changed
        if (!empty($pa)) {
            /** @psalm-var AbookSettings $pa */
            $abMgr->updateAddressbook($abookCfg['id'], $pa);
        }
    }

    /**
     * Adds a new non-discovered addressbook to an account.
     *
     * Performs a check with the server ensuring that the addressbook actually exists and can be accessed.
     *
     * @param AccountCfg $accountCfg Array with the settings of the account
     */
    private function insertExtraAddressbook(
        AddressbookManager $abMgr,
        Config $infra,
        array $accountCfg,
        string $xabookUrl,
        string $presetName
    ): void {
        try {
            $account = Config::makeAccount(
                '',
                Utils::replacePlaceholdersUsername($accountCfg['username'] ?? ''),
                Utils::replacePlaceholdersPassword($accountCfg['password'] ?? ''),
                null
            );
            $abook = $infra->makeWebDavResource($xabookUrl, $account);
            if ($abook instanceof AddressbookCollection) {
                // Get values for the optional settings that the admin may have configured as part of the preset
                $abookTmpl = $this->getPreset($presetName, $xabookUrl);
                $abookTmpl['account_id'] = $accountCfg['id'];
                $abookTmpl['discovered'] = '0';
                $abookTmpl['sync_token'] = '';
                $abookTmpl['url'] = $xabookUrl;
                $abookTmpl['name'] = $abMgr->replacePlaceholdersAbookName($abookTmpl['name'], $accountCfg, $abook);
                $abMgr->insertAddressbook($abookTmpl);
            } else {
                throw new Exception("no addressbook collection at given URL");
            }
        } catch (Exception $e) {
            $logger = $infra->logger();
            $logger->error("Failed to add extra addressbook $xabookUrl for preset $presetName: " . $e->getMessage());
        }
    }

    /**
     * Adds the given preset from config.inc.php to $this->presets.
     */
    private function addPreset(string $presetName, array $preset, LoggerInterface $logger): void
    {
        try {
            /** @psalm-var Preset $result Checked by parsePresetArray() */
            $result = $this->parsePresetArray(
                self::PRESET_SETTINGS,
                $preset,
                ['accountname' => $presetName] + self::PRESET_DEFAULTS
            );

            // Parse extra addressbooks
            $result['extra_addressbooks'] = [];
            if (isset($preset['extra_addressbooks'])) {
                if (!is_array($preset['extra_addressbooks'])) {
                    throw new Exception("setting extra_addressbooks must be an array");
                }

                foreach (array_keys($preset['extra_addressbooks']) as $k) {
                    if (is_array($preset['extra_addressbooks'][$k])) {
                        /** @psalm-var PresetExtraAbook $xabook Checked by parsePresetArray() */
                        $xabook = $this->parsePresetArray(
                            self::PRESET_SETTINGS_EXTRA_ABOOK,
                            $preset['extra_addressbooks'][$k],
                            $result
                        );

                        $result['extra_addressbooks'][$xabook['url']] = $xabook;
                    } else {
                        throw new Exception("setting extra_addressbooks[$k] must be an array");
                    }
                }
            }

            $this->presets[$presetName] = $result;
        } catch (Exception $e) {
            $logger->error("Error in preset $presetName: " . $e->getMessage());
        }
    }

    /**
     * Parses / checks a user-input array according to a settings specification.
     *
     * @param array<string, SettingSpecification> $spec The specification of the expected fields.
     * @param array $preset The user-input array
     * @param Preset $defaults An array with defaults for all settings (for mandatory settings, they will not be used)
     * @return array If no error, the resulting array, containing only attributes from $spec.
     */
    private function parsePresetArray(array $spec, array $preset, array $defaults): array
    {
        $result = [];
        foreach ($spec as $attr => $specs) {
            [ $type, $mandatory ] = $specs;

            if ($type === 'skip') {
                // this item has a special handler
                continue;
            }

            if (isset($preset[$attr])) {
                switch ($type) {
                    case 'string':
                    case 'timestr':
                    case 'url':
                        if (is_string($preset[$attr])) {
                            if ($type == 'timestr') {
                                $result[$attr] = (string) Utils::parseTimeParameter($preset[$attr]);
                            } elseif ($type == 'url') {
                                $result[$attr] = Utils::replacePlaceholdersUrl($preset[$attr]);
                            } else {
                                $result[$attr] = $preset[$attr];
                            }
                        } else {
                            throw new Exception("setting $attr must be a string");
                        }
                        break;

                    case 'bool':
                        $result[$attr] = empty($preset[$attr]) ? '0' : '1';
                        break;

                    case 'string[]':
                        if (is_array($preset[$attr])) {
                            $result[$attr] = [];
                            foreach (array_keys($preset[$attr]) as $k) {
                                if (is_string($preset[$attr][$k])) {
                                    $result[$attr][] = $preset[$attr][$k];
                                } else {
                                    throw new Exception("setting $attr\[$k\] must be string");
                                }
                            }
                        } else {
                            throw new Exception("setting $attr must be array");
                        }
                }
            } elseif ($mandatory) {
                throw new Exception("required setting $attr is not set");
            } else {
                $result[$attr] = $defaults[$attr];
            }
        }

        /** @psalm-var Preset|PresetExtraAbook $result */

        // Add attributes that are never user-configurable to fixed to they are updated from admin preset on login
        foreach (self::ALWAYS_FIXED as $attr) {
            if (!in_array($attr, $result['fixed'])) {
                $result['fixed'][] = $attr;
            }
        }

        return $result;
    }

    /**
     * Gets the special addressbooks that are configured to CardDAV sources by the admin.
     *
     * These special addressbooks as of roundcube 1.5 are collected recipients and collected senders. The admin can
     * configure a match expression for the name or the URL of the addressbook, that is looked for in a specific preset.
     *
     * @return array<SpecialAbookType, string> ID for each special addressbook for that a CardDAV source is selected
     */
    public function getSpecialAddressbooks(AddressbookManager $abMgr, Config $infra): array
    {
        $logger = $infra->logger();
        $ret = [];

        if (empty($this->specialAbookMatchers)) {
            return $ret;
        }

        // Create a mapping Presetname => AccountConfig
        $presetIdsByPresetname = [];
        foreach ($abMgr->getAccountIds(true) as $accountId) {
            $accountCfg = $abMgr->getAccountConfig($accountId);
            /** @psalm-var string $presetName Not null because filtered by getAccountIds() */
            $presetName = $accountCfg['presetname'];
            $presetIdsByPresetname[$presetName] = $accountId;
        }

        // Search for the addressbook to use for each of the special addressbooks if configured
        foreach ($this->specialAbookMatchers as $type => $matchSettings) {
            $presetName = $matchSettings['preset'];
            $matches = [];

            // When an admin creates a new preset in the configuration and a user is still logged on, the user will not
            // have an account for that preset yet until the next login. If this new preset is referred to for a special
            // addressbook, we cannot set it just yet.
            if (!isset($presetIdsByPresetname[$presetName])) {
                $logger->debug("Cannot set special addressbook $type, no account for preset $presetName in DB");
                continue;
            }

            $accountId = $presetIdsByPresetname[$presetName];

            // Read-only, inactive and template addressbooks are not considered for candidates
            $abCandidates = $abMgr->getAddressbookConfigsForAccount($accountId, AddressbookManager::ABF_ACTIVE_RW);
            foreach ($abCandidates as $abookCfg) {
                // check all addressbooks for that preset
                // All specified matchers must match
                // If no matcher is set, any addressbook of the preset is considered a match
                foreach (['matchname', 'matchurl'] as $matchType) {
                    $matchexpr = $matchSettings[$matchType] ?? 0;
                    if (is_string($matchexpr)) {
                        if (!preg_match($matchexpr, $abookCfg[substr($matchType, 5)])) {
                            continue 2;
                        }
                    }
                }

                $matches[] = $abookCfg['id'];
            }

            // we need exactly one match, in any other case we leave the roundcube setting as is
            $numMatches = count($matches);
            if ($numMatches != 1) {
                $logger->error("Cannot set special addressbook $type, there are $numMatches candidates (need: 1)");
            } else {
                $ret[$type] = $matches[0];
            }
        }

        return $ret;
    }

    /**
     * Provides the addressbook template for new addressbooks of an account, incorporating fixed settings of presets.
     *
     * This function can be used for both user-defined and preset accounts.
     *
     * For a user-defined account, it will simply provide the addressbook template of that account, if available, or
     * otherwise an empty array.
     *
     * For a preset account, it will provide the preset settings unless a template addressbook is also available for the
     * account. In the latter case, it will adapt the template addressbook to overwrite all fixed fields with the
     * settings from the preset.
     *
     * @param AddressbookManager $abMgr
     * @param string $accountId ID of the account for that the addressbook template shall be provided
     * @return AbookSettings The initial addressbook settings
     */
    public function getAddressbookTemplate(AddressbookManager $abMgr, string $accountId): array
    {
        $accountCfg = $abMgr->getAccountConfig($accountId);
        $abookTmpl = $abMgr->getTemplateAddressbookForAccount($accountId);

        $presetName = $accountCfg['presetname'];
        if ($presetName !== null) {
            $preset = $this->getPreset($presetName);
            if ($abookTmpl === null) {
                $abookTmpl = $preset;
            } else {
                // take the fixed attributes from the preset
                foreach (array_keys($abookTmpl) as $attr) {
                    if (in_array($attr, $preset['fixed'])) {
                        if (isset($preset[$attr])) {
                            $abookTmpl[$attr] = $preset[$attr];
                        }
                    }
                }
            }
        }

        /** @psalm-var ?AbookSettings $abookTmpl */
        return $abookTmpl ?? [];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
