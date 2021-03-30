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

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavAddressbook4Roundcube\{Config, RoundcubeLogger};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * Represents the administrative settings of the plugin.
 *
 * @psalm-type PasswordStoreScheme = 'plain' | 'base64' | 'des_key' | 'encrypted'
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type AbookSettings from AddressbookManager
 * @psalm-import-type Preset from AddressbookManager
 */
class AdminSettings
{
    /** @var list<PasswordStoreScheme> List of supported password store schemes */
    public const PWSTORE_SCHEMES = [ 'plain', 'base64', 'des_key', 'encrypted' ];

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
     * @var array<string, Preset> Presets from config.inc.php
     * @readonly
     * @psalm-allow-private-mutation
     */
    public $presets = [];

    /**
     * Initializes AdminSettings from a config.inc.php file, using default values if that file is not available.
     * @var string $configfile Path of the config.inc.php file to load.
     */
    public function __construct(string $configfile)
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $httpLogger = $infra->httpLogger();

        $prefs = [];
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

            if (!is_string($presetname) || strlen($presetname) == 0) {
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
     * @param ?string $presetName If the setting is checked for an addressbook from a preset, the key of the preset.
     *                            Null if the setting is checked for a user-defined addressbook.
     * @return bool True if the setting is fixed for the given preset. Always false for user-defined addressbooks.
     */
    public function noOverrideAllowed(string $pref, ?string $presetName): bool
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
     * Creates / updates / deletes preset addressbooks.
     */
    public function initPresets(AddressbookManager $abMgr): void
    {
        $infra = Config::inst();
        $logger = $infra->logger();

        try {
            $logger->debug(__METHOD__);

            // Get all existing addressbooks of this user that have been created from presets
            $abooks = $abMgr->getAddressbooks(false, true);

            // Group the addressbooks by their preset
            $existingPresets = [];
            foreach ($abooks as $abookrow) {
                /** @var string $pn Not null because filtered by getAddressbooks() */
                $pn = $abookrow['presetname'];
                if (!key_exists($pn, $existingPresets)) {
                    $existingPresets[$pn] = [];
                }
                $existingPresets[$pn][] = $abookrow;
            }

            // Walk over the current presets configured by the admin and add, update or delete addressbooks
            foreach ($this->presets as $presetname => $preset) {
                // addressbooks exist for this preset => update settings
                if (key_exists($presetname, $existingPresets)) {
                    $this->updatePresetAddressbooks($preset, $existingPresets[$presetname], $abMgr);
                    unset($existingPresets[$presetname]);
                } else { // create new
                    $preset['presetname'] = $presetname;
                    $abname = $preset['name'];

                    try {
                        $username = Utils::replacePlaceholdersUsername($preset['username']);
                        $url = Utils::replacePlaceholdersUrl($preset['url']);
                        $password = Utils::replacePlaceholdersPassword($preset['password']);

                        $logger->info("Adding preset for $username at URL $url");
                        $account = new Account($url, $username, $password);
                        $abooks = $abMgr->determineAddressbooksToAdd($account);

                        foreach ($abooks as $abook) {
                            if ($preset['carddav_name_only']) {
                                $preset['name'] = $abook->getName();
                            } else {
                                $preset['name'] = "$abname (" . $abook->getName() . ')';
                            }

                            $preset['url'] = $abook->getUri();
                            $abMgr->insertAddressbook($preset);
                        }
                    } catch (\Exception $e) {
                        $logger->error("Error adding addressbook from preset $presetname: {$e->getMessage()}");
                    }
                }
            }

            // delete existing preset addressbooks that were removed by admin
            foreach ($existingPresets as $ep) {
                $logger->info("Deleting preset addressbooks for " . (string) $_SESSION['user_id']);
                foreach ($ep as $abookrow) {
                    $abMgr->deleteAddressbook($abookrow['id']);
                }
            }
        } catch (\Exception $e) {
            $logger->error("Error initializing preconfigured addressbooks: {$e->getMessage()}");
        }
    }

    /**
     * Updates the fixed fields of addressbooks derived from presets against the current admin settings.
     * @param Preset $preset
     * @param list<FullAbookRow> $abooks for the given preset
     * @param AddressbookManager $abMgr The addressbook manager.
     */
    private function updatePresetAddressbooks(array $preset, array $abooks, AddressbookManager $abMgr): void
    {
        if (!is_array($preset["fixed"] ?? "")) {
            return;
        }

        foreach ($abooks as $abookrow) {
            // decrypt password so that the comparison works
            $abookrow['password'] = Utils::decryptPassword($abookrow['password']);

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
                $abMgr->updateAddressbook($abookrow['id'], $pa);
            }
        }
    }

    /**
     * Adds the given preset from config.inc.php to $this->presets.
     */
    private function addPreset(string $presetname, array $preset): void
    {
        $logger = Config::inst()->logger();

        // Resulting preset initialized with defaults
        $result = AddressbookManager::PRESET_TEMPLATE;

        try {
            foreach (array_keys($result) as $attr) {
                if ($attr == 'refresh_time') {
                    // refresh_time is stored in seconds
                    if (isset($preset["refresh_time"])) {
                        if (is_string($preset["refresh_time"])) {
                            $result["refresh_time"] = Utils::parseTimeParameter($preset["refresh_time"]);
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

            /** @var Preset */
            $this->presets[$presetname] = $result;
        } catch (\Exception $e) {
            $logger->error("Error in preset $presetname: " . $e->getMessage());
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
