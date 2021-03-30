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
use MStilkerich\CardDavAddressbook4Roundcube\Config;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type AbookSettings from AddressbookManager
 */
class SettingsUI
{
    /**
     * The addressbook manager.
     * @var AddressbookManager
     */
    private $abMgr;

    /**
     * Constructs a new SettingsUI object.
     *
     * @param AddressbookManager $abMgr The AddressbookManager to use.
     */
    public function __construct(AddressbookManager $abMgr)
    {
        $this->abMgr = $abMgr;
    }

    /**
     * Adds a section to the preferences tab.
     * @psalm-param array{list: array, cols: array} $args
     */
    public function addPreferencesSection(array $args): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $rc = $infra->rc();

        try {
            $logger->debug(__METHOD__);

            $args['list']['cd_preferences'] = [
                'id'      => 'cd_preferences',
                'section' => $rc->locText('cd_title')
            ];
        } catch (\Exception $e) {
            $logger->error("Error adding carddav preferences section: " . $e->getMessage());
        }

        return $args;
    }

    /**
     * Handler for preferences_list hook.
     * Adds options blocks into CardDAV settings sections in preferences.
     *
     * @psalm-param array{section: string, blocks: array} $args Original parameters
     * @return array Modified parameters
     */
    public function buildPreferencesPage(array $args): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $rc = $infra->rc();
        $admPrefs = $infra->admPrefs();

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            $abooks = $this->abMgr->getAddressbooks(false);
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

            $fromPresetStringLocalized = $rc->locText('cd_frompreset');
            foreach ($abooks as $abookrow) {
                $abookId = $abookrow["id"];
                $presetname = $abookrow['presetname'] ?? ""; // empty string is not a valid presetname
                if (!($admPrefs->presets[$presetname]['hide'] ?? false)) {
                    $blockhdr = $abookrow['name'];
                    if (!empty($presetname)) {
                        $blockhdr .= str_replace("_PRESETNAME_", $presetname, $fromPresetStringLocalized);
                    }
                    $args["blocks"]["cd_preferences$abookId"] =
                        $this->buildSettingsBlock($blockhdr, $abookrow, $abookId);
                }
            }

            // if allowed by admin, provide a block for entering data for a new addressbook
            if (!$admPrefs->forbidCustomAddressbooks) {
                $args['blocks']['cd_preferences_section_new'] = $this->buildSettingsBlock(
                    $rc->locText('cd_newabboxtitle'),
                    $this->getAddressbookSettingsFromPOST('new'),
                    "new"
                );
            }
        } catch (\Exception $e) {
            $logger->error("Error building carddav preferences page: {$e->getMessage()}");
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
        $infra = Config::inst();
        $logger = $infra->logger();
        $admPrefs = $infra->admPrefs();
        $rc = $infra->rc();
        $abMgr = $this->abMgr;

        try {
            $logger->debug(__METHOD__);

            if ($args['section'] != 'cd_preferences') {
                return $args;
            }

            // update existing in DB
            foreach ($abMgr->getAddressbooks(false) as $abookrow) {
                $abookId = $abookrow["id"];
                if (isset($_POST["${abookId}_cd_delete"])) {
                    $abMgr->deleteAddressbook($abookId);
                } else {
                    $newset = $this->getAddressbookSettingsFromPOST($abookId, $abookrow["presetname"]);
                    $abMgr->updateAddressbook($abookId, $newset);

                    if (isset($_POST["${abookId}_cd_resync"])) {
                        $abook = $abMgr->getAddressbook($abookId);
                        $abMgr->resyncAddressbook($abook);
                    }
                }
            }

            // add a new address book?
            $new = $this->getAddressbookSettingsFromPOST('new');
            if (
                !$admPrefs->forbidCustomAddressbooks // creation of addressbooks allowed by admin
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
                        Utils::replacePlaceholdersPassword($new['password'])
                    );
                    $abooks = $abMgr->determineAddressbooksToAdd($account);

                    if (count($abooks) > 0) {
                        $basename = $new['name'];

                        foreach ($abooks as $abook) {
                            $new['url'] = $abook->getUri();
                            $new['name'] = "$basename ({$abook->getName()})";

                            $logger->info("Adding addressbook {$new['username']} @ {$new['url']}");
                            $abMgr->insertAddressbook($new);
                        }

                        // new addressbook added successfully -> clear the data from the form
                        foreach (array_keys(AddressbookManager::ABOOK_TEMPLATE) as $k) {
                            unset($_POST["new_cd_$k"]);
                        }
                    } else {
                        throw new \Exception($new['name'] . ': ' . $rc->locText('cd_err_noabfound'));
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
        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $rc = $infra->rc();

        // if the value is not set, use the default from the addressbook template
        $value = $value ?? AddressbookManager::ABOOK_TEMPLATE[$attr];
        $roValue = $roValue ?? $value;
        // For new addressbooks, no attribute is fixed (note: noOverrideAllowed always returns true for URL)
        $attrFixed = ($abookId != "new") && $admPrefs->noOverrideAllowed($attr, $presetName);

        if (is_bool(AddressbookManager::ABOOK_TEMPLATE[$attr])) {
            // boolean settings as a checkbox
            if ($attrFixed) {
                $content = $rc->locText($roValue ? 'cd_enabled' : 'cd_disabled');
            } else {
                // check box for activating
                $checkbox = new \html_checkbox(['name' => "${abookId}_cd_$attr", 'value' => 1]);
                $content = $checkbox->show($value ? "1" : "0");
            }
        } elseif (is_string(AddressbookManager::ABOOK_TEMPLATE[$attr])) {
            if ($attrFixed) {
                $content = (string) $roValue;
            } else {
                // input box for username
                $input = new \html_inputfield([
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
        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $rc = $infra->rc();

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
            Utils::replacePlaceholdersUsername($abook['username'] ?? "")
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
        if ($admPrefs->noOverrideAllowed('refresh_time', $presetName)) {
            $content_refresh_time =  $refresh_time_str . ", ";
        } else {
            $input = new \html_inputfield([
                'name' => $abookId . '_cd_refresh_time',
                'type' => 'text',
                'autocomplete' => 'off',
                'value' => $refresh_time_str,
                'size' => 10
            ]);
            $content_refresh_time = $input->show();
        }

        if (!empty($abook['last_updated'])) { // if never synced, last_updated is 0 -> don't show
            $content_refresh_time .=  $rc->locText('cd_lastupdate_time') . ": ";
            $content_refresh_time .=  date("Y-m-d H:i:s", intval($abook['last_updated']));
        }

        $retval = [
            'options' => [
                ['title' => $rc->locText('cd_name'), 'content' => $content_name],
                ['title' => $rc->locText('cd_active'), 'content' => $content_active],
                ['title' => $rc->locText('cd_use_categories'), 'content' => $content_use_categories],
                ['title' => $rc->locText('cd_username'), 'content' => $content_username],
                ['title' => $rc->locText('cd_password'), 'content' => $content_password],
                ['title' => $rc->locText('cd_url'), 'content' => $content_url],
                ['title' => $rc->locText('cd_refresh_time'), 'content' => $content_refresh_time],
            ],
            'name' => $blockheader
        ];

        if (empty($presetName) && preg_match('/^\d+$/', $abookId)) {
            $checkbox = new \html_checkbox(['name' => $abookId . '_cd_delete', 'value' => 1]);
            $content_delete = $checkbox->show("0");
            $retval['options'][] = ['title' => $rc->locText('cd_delete'), 'content' => $content_delete];
        }

        if ($abookId != "new") {
            $checkbox = new \html_checkbox(['name' => $abookId . '_cd_resync', 'value' => 1]);
            $content_resync = $checkbox->show("0");
            $retval['options'][] = ['title' => $rc->locText('cd_resync'), 'content' => $content_resync];
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
     * was not checked or the value was not submitted at all - so the absence of a boolean setting is considered as a
     * false value for the setting.
     *
     * @param string $abookId The ID of the addressbook ("new" for new addressbooks, otherwise the numeric DB id)
     * @param ?string $presetName Name of the preset the addressbook belongs to; null for user-defined addressbook.
     * @return AbookSettings An array with addressbook column keys and their setting.
     */
    private function getAddressbookSettingsFromPOST(string $abookId, ?string $presetName = null): array
    {
        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $rc = $infra->rc();

        $result = [];

        // Fill $result with all values that have been POSTed; for unset boolean values, false is assumed
        foreach (array_keys(AddressbookManager::ABOOK_TEMPLATE) as $attr) {
            // fixed settings for preset addressbooks are ignored
            if ($abookId != "new" && $admPrefs->noOverrideAllowed($attr, $presetName)) {
                continue;
            }

            $allow_html = ($attr == 'password');
            $value = $rc->inputValue("${abookId}_cd_$attr", $allow_html);

            if (is_bool(AddressbookManager::ABOOK_TEMPLATE[$attr])) {
                $result[$attr] = (bool) $value;
            } else {
                if (isset($value)) {
                    if ($attr == "refresh_time") {
                        try {
                            $result["refresh_time"] = Utils::parseTimeParameter($value);
                        } catch (\Exception $e) {
                            // will use the DB default for new addressbooks, or leave the value unchanged for existing
                            // ones
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
        }

        // Set default values for boolean options of new addressbook; if name is null, it means the form is loaded for
        // the first time, otherwise it has been posted.
        if ($abookId == "new" && !isset($result["name"])) {
            foreach (AddressbookManager::ABOOK_TEMPLATE as $attr => $value) {
                if (is_bool($value)) {
                    $result[$attr] = $value;
                }
            }
        }

        /** @psalm-var AbookSettings */
        return $result;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
