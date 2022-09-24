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
use rcube;
use rcube_utils;
use rcmail_output;
use html;
use html_checkbox;
use html_hiddenfield;
use html_radiobutton;
use html_table;
use html_inputfield;
use MStilkerich\RCMCardDAV\Config;

/**
 * @psalm-import-type AbookCfg from AddressbookManager
 * @psalm-import-type AccountCfg from AddressbookManager
 * @psalm-import-type AbookSettings from AddressbookManager
 * @psalm-import-type AccountSettings from AddressbookManager
 *
 * UIFieldType:
 *   - text: a single-line text box where the user can enter text
 *   - plain: a read-only plain text shown in the form, non-interactive. Field is only shown when a value is available.
 *   - datetime: a read-only date/time plain text shown in the form, non-interactive
 *   - timestr: a text box where the user is expected to enter a time interval in the form HH[:MM[:SS]]
 *   - radio: a selection from options offered as a list of radio buttons
 *   - password: a text box where the user is expected to enter a password. Stored data will never be provided as form
 *               data.
 *
 * FieldSpec:
 *   [0]: label of the field
 *   [1]: key of the field
 *   [2]: UI type of the field
 *   [3]: (optional) default value of the field
 *   [4]: (optional) for UI type radio, a list of key-label pairs for the options of the selection
 *
 * @psalm-type UiFieldType = 'text'|'plain'|'datetime'|'timestr'|'radio'|'password'
 * @psalm-type FieldSpec = array{0: string, 1: string, 2: UiFieldType, 3?: string, 4?: list<array{string,string}>}
 * @psalm-type FieldSetSpec = array{label: string, fields: list<FieldSpec>}
 * @psalm-type FormSpec = list<FieldSetSpec>
 */
class UI
{
    /** @var FormSpec UI_FORM_NEW_ACCOUNT */
    private const UI_FORM_NEW_ACCOUNT = [
        [
            'label' => 'AccProps_newaccount_lbl',
            'fields' => [
                [ 'AccProps_accountname_lbl', 'accountname', 'text' ],
                [ 'AccProps_discoveryurl_lbl', 'discovery_url', 'text' ],
                [ 'AccProps_username_lbl', 'username', 'text' ],
                [ 'AccProps_password_lbl', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'AccAbProps_miscsettings_seclbl',
            'fields' => [
                [ 'AccProps_rediscover_time_lbl', 'rediscover_time', 'timestr', '86400' ],
            ]
        ],
        [
            'label' => 'AccAbProps_abookinitsettings_seclbl',
            'fields' => [
                [ 'AbProps_abname_lbl', 'name', 'text', '%N' ],
                [ 'AbProps_refresh_time_lbl', 'refresh_time', 'timestr', '3600' ],
                [
                    'AbProps_newgroupstype_lbl',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'AbProps_grouptype_vcard_lbl' ],
                        [ '1', 'AbProps_grouptype_categories_lbl' ],
                    ]
                ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ACCOUNT */
    private const UI_FORM_ACCOUNT = [
        [
            'label' => 'AccAbProps_basicinfo_seclbl',
            'fields' => [
                [ 'AccProps_frompreset_lbl', 'presetname', 'plain' ],
                [ 'AccProps_accountname_lbl', 'accountname', 'text' ],
                [ 'AccProps_discoveryurl_lbl', 'discovery_url', 'text' ],
                [ 'AccProps_username_lbl', 'username', 'text' ],
                [ 'AccProps_password_lbl', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'AccProps_discoveryinfo_seclbl',
            'fields' => [
                [ 'AccProps_rediscover_time_lbl', 'rediscover_time', 'timestr' ],
                [ 'AccProps_lastdiscovered_time_lbl', 'last_discovered', 'datetime' ],
            ]
        ],
        [
            'label' => 'AccAbProps_abookinitsettings_seclbl',
            'fields' => [
                [ 'AbProps_abname_lbl', 'name', 'text', '%N' ],
                [ 'AbProps_refresh_time_lbl', 'refresh_time', 'timestr', '3600' ],
                [
                    'AbProps_newgroupstype_lbl',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'AbProps_grouptype_vcard_lbl' ],
                        [ '1', 'AbProps_grouptype_categories_lbl' ],
                    ]
                ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ABOOK */
    private const UI_FORM_ABOOK = [
        [
            'label' => 'AccAbProps_basicinfo_seclbl',
            'fields' => [
                [ 'AbProps_abname_lbl', 'name', 'text' ],
                [ 'AbProps_url_lbl', 'url', 'plain' ],
                [ 'AbProps_srvname_lbl', 'srvname', 'plain' ],
                [ 'AbProps_srvdesc_lbl', 'srvdesc', 'plain' ],
            ]
        ],
        [
            'label' => 'AbProps_syncinfo_seclbl',
            'fields' => [
                [ 'AbProps_refresh_time_lbl', 'refresh_time', 'timestr' ],
                [ 'AbProps_lastupdate_time_lbl', 'last_updated', 'datetime' ],
            ]
        ],
        [
            'label' => 'AccAbProps_miscsettings_seclbl',
            'fields' => [
                [
                    'AbProps_newgroupstype_lbl',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'AbProps_grouptype_vcard_lbl' ],
                        [ '1', 'AbProps_grouptype_categories_lbl' ],
                    ]
                ],
            ]
        ],
    ];

    /**
     * The addressbook manager.
     * @var AddressbookManager
     */
    private $abMgr;

    /**
     * Constructs a new UI object.
     *
     * @param AddressbookManager $abMgr The AddressbookManager to use.
     */
    public function __construct(AddressbookManager $abMgr)
    {
        $this->abMgr = $abMgr;

        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $rc = $infra->rc();

        $rc->addHook('settings_actions', [$this, 'addSettingsAction']);

        $rc->registerAction('plugin.carddav', [$this, 'renderAddressbookList']);
        $rc->registerAction('plugin.carddav.AbToggleActive', [$this, 'actionAbToggleActive']);
        $rc->registerAction('plugin.carddav.AbDetails', [$this, 'actionAbDetails']);
        $rc->registerAction('plugin.carddav.AbSave', [$this, 'actionAbSave']);
        $rc->registerAction('plugin.carddav.AccDetails', [$this, 'actionAccDetails']);
        $rc->registerAction('plugin.carddav.AccSave', [$this, 'actionAccSave']);
        $rc->registerAction('plugin.carddav.AccRm', [$this, 'actionAccRm']);
        $rc->registerAction('plugin.carddav.AccRedisc', [$this, 'actionAccRedisc']);
        $rc->registerAction('plugin.carddav.AbSync', [$this, 'actionAbSync']);

        $rc->setEnv("carddav_forbidCustomAddressbooks", $admPrefs->forbidCustomAddressbooks);
        if (!$admPrefs->forbidCustomAddressbooks) {
            $rc->registerAction('plugin.carddav.AccAdd', [$this, 'actionAccAdd']);
        }

        $rc->includeCSS('carddav.css');
        $rc->includeJS("carddav.js");
    }

    /**
     * Adds a carddav section in settings.
     * @psalm-param array{actions: array} $args
     */
    public function addSettingsAction(array $args): array
    {
        // register as settings action
        $args['actions'][] = [
            'action' => 'plugin.carddav',
            'class'  => 'cd_preferences', // CSS style
            'label'  => 'CardDAV_rclbl', // text display
            'title'  => 'CardDAV_rctit', // tooltip text
            'domain' => 'carddav',
        ];

        return $args;
    }

    /**
     * Main UI action that creates the addressbooks and account list in the CardDAV pane.
     */
    public function renderAddressbookList(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $rc->setPagetitle($rc->locText('CardDAV_rclbl'));

        $rc->includeJS('treelist.js', true);
        $rc->addTemplateObjHandler('addressbookslist', [$this, 'tmplAddressbooksList']);
        $rc->sendTemplate('carddav.addressbooks');
    }

    /**
     * Template object for list of addressbooks.
     *
     * @psalm-param array{id: string} $attrib
     * @param array $attrib Object attributes
     *
     * @return string HTML content
     */
    public function tmplAddressbooksList(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $admPrefs = $infra->admPrefs();

        $abMgr = $this->abMgr;

        // Collect all accounts manageable via the UI. This must exclude preset accounts with hide=true.
        $accountIds = $abMgr->getAccountIds();
        $accounts = [];
        foreach ($accountIds as $accountId) {
            $account = $abMgr->getAccountConfig($accountId);
            if (isset($account['presetname'])) {
                $preset = $admPrefs->getPreset($account['presetname']);
                if ($preset['hide']) {
                    continue;
                }
            }
            $accounts[$accountId] = $account;
        }

        // Sort accounts by their name
        usort(
            $accounts,
            /**
             * @param AccountCfg $a
             * @param AccountCfg $b
             */
            function (array $a, array $b): int {
                return strcasecmp($a['accountname'], $b['accountname']);
            }
        );

        // Create the list
        $accountListItems = [];
        foreach ($accounts as $account) {
            $attribs = [
                'id'    => 'rcmli_acc' . $account["id"],
                'class' => 'account' . (isset($account["presetname"]) ? ' preset' : '')
            ];
            $accountListItems[] = html::tag('li', $attribs, $this->makeAccountListItem($account));
        }

        $rc->addGuiObject('addressbookslist', $attrib['id']);
        return html::tag('ul', $attrib, implode('', $accountListItems));
    }

    /**
     * Creates the HTML code within the ListItem of an account in the addressbook list.
     *
     * @param AccountCfg $account
     */
    private function makeAccountListItem(array $account): string
    {
        $abMgr = $this->abMgr;
        $account['addressbooks'] = $abMgr->getAddressbookConfigsForAccount($account["id"]);

        // Sort addressbooks by their name
        usort(
            $account['addressbooks'],
            /**
             * @param AbookCfg $a
             * @param AbookCfg $b
             */
            function (array $a, array $b): int {
                return strcasecmp($a['name'], $b['name']);
            }
        );

        $content = html::a(['href' => '#'], rcube::Q($account["accountname"]));

        $addressbookListItems = [];
        foreach (($account["addressbooks"] ?? []) as $abook) {
            $attribs = [
                'id'    => 'rcmli_abook' . $abook["id"],
                'class' => 'addressbook'
            ];

            $abookHtml = $this->makeAbookListItem($abook, $account['presetname']);
            $addressbookListItems[] = html::tag('li', $attribs, $abookHtml);
        }

        if (!empty($addressbookListItems)) {
            $content .= html::div('treetoggle expanded', '&nbsp;');
            $content .= html::tag('ul', ['style' => null], implode("\n", $addressbookListItems));
        }

        return $content;
    }

    /**
     * Creates the HTML code within the ListItem of an addressbook in the addressbook list.
     *
     * @param AbookCfg $abook
     */
    private function makeAbookListItem(array $abook, ?string $presetName): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $checkboxActive = new html_checkbox([
            'name'    => '_active[]',
            'title'   => $rc->locText('AbToggleActive_cb_tit'),
            'onclick' => rcmail_output::JS_OBJECT_NAME .
            ".command('plugin.carddav-AbToggleActive', this.value, this.checked)",
        ]);

        $fixedAttributes = $this->getFixedSettings($presetName, $abook['url']);

        $abookHtml = html::a(['href' => '#'], rcube::Q($abook["name"]));
        $abookHtml .= $checkboxActive->show(
            $abook["active"] ? $abook['id'] : '',
            ['value' => $abook['id'], 'disabled' => in_array('active', $fixedAttributes)]
        );
        return $abookHtml;
    }

    /**
     * This action is invoked when the user toggles the addressbook active checkbox in the addressbook list.
     *
     * It changes the activation state of the specified addressbook to the specified target state, if the specified
     * addressbook belongs to the user, and the addressbook is not part of an admin preset where the active setting is
     * fixed.
     */
    public function actionAbToggleActive(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $abookId = $rc->inputValue("abookid", false);
        // the state parameter is set to 0 (deactivated) or 1 (active) by the client
        $active  = $rc->inputValue("active", false);

        if (isset($abookId) && isset($active)) {
            $active = ($active != "0") ? '1' : '0'; // if this is some invalid value, just consider it as activated
            $suffix = $active ? "" : "_de";
            try {
                $abMgr = $this->abMgr;
                $abookCfg = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookCfg["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookCfg['url']);

                if (in_array('active', $fixedAttributes)) {
                    throw new Exception("active is a fixed setting for addressbook $abookId");
                } else {
                    $abMgr->updateAddressbook($abookId, ['active' => $active ]);
                    $rc->showMessage($rc->locText("AbToggleActive_msg_ok$suffix"), 'confirmation');
                }
            } catch (Exception $e) {
                $logger->error("Failure to toggle addressbook activation: " . $e->getMessage());
                $rc->showMessage($rc->locText("AbToggleActive_msg_fail$suffix"), 'error');
                $rc->clientCommand('carddav_AbResetActive', $abookId, !$active);
            }
        } else {
            $logger->warning(__METHOD__ . " invoked without required HTTP POST inputs");
        }
    }

    /**
     * This action is invoked to show the details of an existing account, or to create a new account.
     */
    public function actionAccDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        // GET - Account selected in list; this is set to "new" if the user pressed the add account button
        $accountId = $rc->inputValue("accountid", false, rcube_utils::INPUT_GET);

        if (isset($accountId)) {
            $rc->setPagetitle($rc->locText('accountproperties'));
            $rc->addTemplateObjHandler('accountdetails', [$this, 'tmplAccountDetails']);
            $rc->sendTemplate('carddav.accountDetails');
        } else {
            $logger->warning(__METHOD__ . ": no account ID found in parameters");
        }
    }

    /**
     * This action is invoked to delete an account of the user.
     */
    public function actionAccRm(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $accountId = $rc->inputValue("accountid", false);
        if (isset($accountId)) {
            try {
                $abMgr = $this->abMgr;
                $abMgr->deleteAccount($accountId);
                $rc->showMessage($rc->locText("AccRm_msg_ok"), 'confirmation');
                $rc->clientCommand('carddav_RemoveListElem', $accountId);
            } catch (Exception $e) {
                $logger->error("Error saving account preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("AccRm_msg_fail", ['errormsg' => $e->getMessage()]), 'error');
            }
        } else {
            $logger->warning(__METHOD__ . ": no account ID found in parameters");
        }
    }

    /**
     * This action is invoked to rediscover the available addressbooks for a specified account.
     */
    public function actionAccRedisc(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $accountId = $rc->inputValue("accountid", false);
        if (isset($accountId)) {
            try {
                $abMgr = $this->abMgr;

                $abookIdsPrev = array_column(
                    $abMgr->getAddressbookConfigsForAccount($accountId, AddressbookManager::ABF_DISCOVERED),
                    'id',
                    'url'
                );

                $accountCfg = $abMgr->getAccountConfig($accountId);
                $abMgr->discoverAddressbooks($accountCfg, []);
                $abookIds = array_column(
                    $abMgr->getAddressbookConfigsForAccount($accountId, AddressbookManager::ABF_DISCOVERED),
                    'id',
                    'url'
                );

                $abooksNew = array_diff_key($abookIds, $abookIdsPrev);
                $abooksRm  = array_diff_key($abookIdsPrev, $abookIds);

                $rc->showMessage(
                    $rc->locText(
                        "AccRedisc_msg_ok",
                        ['new' => (string) count($abooksNew), 'rm' => (string) count($abooksRm)]
                    ),
                    'confirmation'
                );

                if (!empty($abooksRm)) {
                    $rc->clientCommand('carddav_RemoveListElem', $accountId, array_values($abooksRm));
                }

                if (!empty($abooksNew)) {
                    $records = [];
                    foreach ($abooksNew as $abookId) {
                        $abook = $abMgr->getAddressbookConfig($abookId);
                        $newLi = $this->makeAbookListItem($abook, $accountCfg["presetname"]);
                        $records[] = [ $abookId, $newLi, $accountId ];
                    }
                    $rc->clientCommand('carddav_InsertListElem', $records);
                }
            } catch (Exception $e) {
                $logger->error("Error in account rediscovery: " . $e->getMessage());
                $rc->showMessage($rc->locText("AccRedisc_msg_fail", ['errormsg' => $e->getMessage()]), 'error');
            }
        } else {
            $logger->warning(__METHOD__ . ": no account ID found in parameters");
        }
    }

    /**
     * This action is invoked to resync or clear the cached data of an addressbook
     */
    public function actionAbSync(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $abookId = $rc->inputValue("abookid", false);
        $syncType = $rc->inputValue("synctype", false);
        if (isset($abookId) && isset($syncType) && in_array($syncType, ['AbSync', 'AbClrCache'])) {
            $msgParams = [ 'name' => 'Unknown' ];
            try {
                $abMgr = $this->abMgr;
                $abook = $abMgr->getAddressbook($abookId);
                $msgParams['name'] = $abook->get_name();
                if ($syncType == 'AbSync') {
                    $msgParams['duration'] = (string) $abMgr->resyncAddressbook($abook);
                } else {
                    $abMgr->deleteAddressbooks([$abookId], false, true /* do not delete the addressbook itself */);
                }

                // update the form data so the last_updated time is current
                $abookCfg = $this->getEnhancedAbookConfig($abookId);
                $formData = $this->makeSettingsFormData(self::UI_FORM_ABOOK, $abookCfg);
                $rc->showMessage($rc->locText("${syncType}_msg_ok", $msgParams), 'notice', false);
                $rc->clientCommand('carddav_UpdateForm', $formData);
            } catch (Exception $e) {
                $msgParams['errormsg'] = $e->getMessage();
                $logger->error("Failed to sync ($syncType) addressbook: " . $msgParams['errormsg']);
                $rc->showMessage($rc->locText("${syncType}_msg_fail", $msgParams), 'warning', false);
            }
        } else {
            $logger->warning(__METHOD__ . " missing or unexpected values for HTTP POST parameters");
        }
    }

    /**
     * This action is invoked to show the details of an existing addressbook.
     */
    public function actionAbDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        // GET - Addressbook selected in list
        $abookId = $rc->inputValue("abookid", false, rcube_utils::INPUT_GET);

        if (isset($abookId)) {
            $rc->setPagetitle($rc->locText('abookproperties'));
            $rc->addTemplateObjHandler('addressbookdetails', [$this, 'tmplAddressbookDetails']);
            $rc->sendTemplate('carddav.addressbookDetails');
        } else {
            $logger->warning(__METHOD__ . ": no addressbook ID found in parameters");
        }
    }

    public function actionAccAdd(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        try {
            $abMgr = $this->abMgr;

            /** @psalm-var AccountSettings&AbookSettings $accAbookFormVals */
            $accAbookFormVals = $this->getSettingsFromPOST(self::UI_FORM_ACCOUNT, []);
            $accountId = $abMgr->discoverAddressbooks($accAbookFormVals, $accAbookFormVals);
            $this->setTemplateAddressbook($accountId, $accAbookFormVals);

            $account = $abMgr->getAccountConfig($accountId);
            $newLi = $this->makeAccountListItem($account);
            $rc->clientCommand('carddav_InsertListElem', [[$accountId, $newLi]], ['acc', $accountId]);
            $rc->showMessage($rc->locText("AccAdd_msg_ok"), 'confirmation');
        } catch (Exception $e) {
            $logger->error("Error creating CardDAV account: " . $e->getMessage());
            $rc->showMessage($rc->locText("AccAbSave_msg_fail", ['errormsg' => $e->getMessage()]), 'error');
        }
    }

    public function actionAccSave(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $accountId = $rc->inputValue("accountid", false);
        if (isset($accountId)) {
            try {
                $abMgr = $this->abMgr;
                $account = $abMgr->getAccountConfig($accountId);
                $fixedAttributes = $this->getFixedSettings($account['presetname']);
                /** @psalm-var AccountSettings&AbookSettings $accAbookFormVals */
                $accAbookFormVals = $this->getSettingsFromPOST(self::UI_FORM_ACCOUNT, $fixedAttributes);
                $abMgr->updateAccount($accountId, $accAbookFormVals);
                // update template addressbook
                $this->setTemplateAddressbook($accountId, $accAbookFormVals);

                // update account data and echo formatted field data to client
                $account = $abMgr->getAccountConfig($accountId);
                $abook = $abMgr->getTemplateAddressbookForAccount($accountId);
                $formData = $this->makeSettingsFormData(self::UI_FORM_ACCOUNT, array_merge($account, $abook ?? []));
                $formData["_acc$accountId"] = [ 'parent', $account["accountname"] ];

                $rc->clientCommand('carddav_UpdateForm', $formData);
                $rc->showMessage($rc->locText("AccAbSave_msg_ok"), 'confirmation');
            } catch (Exception $e) {
                $logger->error("Error saving account preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("AccAbSave_msg_fail", ['errormsg' => $e->getMessage()]), 'error');
            }
        } else {
            $logger->warning(__METHOD__ . ": no account ID found in parameters");
        }
    }

    /**
     * This action is invoked when an addressbook's properties are saved.
     */
    public function actionAbSave(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $abookId = $rc->inputValue("abookid", false);
        if (isset($abookId)) {
            try {
                $abMgr = $this->abMgr;
                $abookCfg = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookCfg["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookCfg['url']);
                /** @psalm-var AbookSettings $newset */
                $newset = $this->getSettingsFromPOST(self::UI_FORM_ABOOK, $fixedAttributes);
                $abMgr->updateAddressbook($abookId, $newset);

                // update addressbook data and echo formatted field data to client
                $abookCfg = $this->getEnhancedAbookConfig($abookId);
                $formData = $this->makeSettingsFormData(self::UI_FORM_ABOOK, $abookCfg);
                $formData["_abook$abookId"] = [ 'parent', $abookCfg["name"] ];

                $rc->showMessage($rc->locText("AccAbSave_msg_ok"), 'confirmation');
                $rc->clientCommand('carddav_UpdateForm', $formData);
            } catch (Exception $e) {
                $logger->error("Error saving addressbook preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("AccAbSave_msg_fail", ['errormsg' => $e->getMessage()]), 'error');
            }
        } else {
            $logger->warning(__METHOD__ . ": no addressbook ID found in parameters");
        }
    }

    /**
     * @param FormSpec $formSpec Specification of the form
     * @param array<string, ?string> $vals Values for the form fields
     * @param list<string> $fixedAttributes A list of non-changeable settings by choice of the admin
     */
    private function makeSettingsForm(array $formSpec, array $vals, array $fixedAttributes, array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $out = '';
        foreach ($formSpec as $fieldSet) {
            $table = new html_table(['cols' => 2]);

            foreach ($fieldSet['fields'] as $fieldSpec) {
                [ $fieldLabel, $fieldKey, $uiType ] = $fieldSpec;

                $fieldValue = $vals[$fieldKey] ?? $fieldSpec[3] ?? '';

                // plain field is only shown when there is a value to be shown
                if ($uiType == 'plain' && $fieldValue == '') {
                    continue;
                }

                $fixed = in_array($fieldKey, $fixedAttributes);
                $table->add(['class' => 'title'], html::label(['for' => $fieldKey], $rc->locText($fieldLabel)));
                $table->add([], $this->uiField($fieldSpec, $fieldValue, $fixed));
            }

            $out .= html::tag(
                'fieldset',
                [],
                html::tag('legend', [], $rc->locText($fieldSet['label'])) . $table->show($attrib)
            );
        }

        return $out;
    }

    /**
     * Gets the addressbook config enhanced with extra fields shown in the details page but not stored in the DB.
     *
     * @param string $abookId The addressbook ID
     * @return AbookCfg The enhanced addressbook configuration
     */
    private function getEnhancedAbookConfig(string $abookId): array
    {
        $abMgr = $this->abMgr;
        $abook = $this->abMgr->getAddressbook($abookId);
        $davAbook = $abook->getCardDavObj();
        $abookCfg = $abMgr->getAddressbookConfig($abookId);
        $abookCfg['srvname'] = $davAbook->getDisplayName() ?? '';
        $abookCfg['srvdesc'] = $davAbook->getDescription() ?? '';
        return $abookCfg;
    }

    /**
     * @param FormSpec $formSpec Specification of the form
     * @param array<string, ?string> $vals Values for the form fields
     */
    private function makeSettingsFormData(array $formSpec, array $vals): array
    {
        $formData = [];
        foreach ($formSpec as $fieldSet) {
            foreach ($fieldSet['fields'] as $fieldSpec) {
                [ , $fieldKey, $uiType ] = $fieldSpec;

                $fieldValue = $vals[$fieldKey] ?? $fieldSpec[3] ?? '';
                $formData[$fieldKey] = [ $uiType, $this->formatFieldValue($fieldSpec, $fieldValue) ];
            }
        }

        return $formData;
    }

    /**
     * @return list<string> The list of fixed attributes
     */
    private function getFixedSettings(?string $presetName, ?string $abookUrl = null): array
    {
        if (!isset($presetName)) {
            return [];
        }

        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $preset = $admPrefs->getPreset($presetName, $abookUrl);
        return $preset['fixed'];
    }

    /**
     * @param FieldSpec $fieldSpec
     */
    private function formatFieldValue(array $fieldSpec, string $fieldValue): string
    {
        [, $fieldKey, $uiType ] = $fieldSpec;

        $infra = Config::inst();
        $rc = $infra->rc();

        switch ($uiType) {
            case 'datetime':
                $t = intval($fieldValue);
                if ($t > 0) {
                    $fieldValue = date("Y-m-d H:i:s", intval($fieldValue));
                } else {
                    $fieldValue = $rc->locText('DateTime_never_lbl');
                }
                // fall through to plain text

            case 'plain':
                return rcube::Q($fieldValue);

            case 'timestr':
                $t = intval($fieldValue);
                $fieldValue = sprintf("%02d:%02d:%02d", intdiv($t, 3600), intdiv($t, 60) % 60, $t % 60);
                // fall through to text field

            case 'text':
            case 'radio':
                return $fieldValue;

            case 'password':
                return '';
        }

        throw new Exception("Unknown UI element type $uiType for $fieldKey");
    }

    /**
     * @param FieldSpec $fieldSpec
     */
    private function uiField(array $fieldSpec, string $fieldValue, bool $fixed): string
    {
        [, $fieldKey, $uiType ] = $fieldSpec;

        $infra = Config::inst();
        $rc = $infra->rc();

        $fieldValueFormatted = $this->formatFieldValue($fieldSpec, $fieldValue);
        switch ($uiType) {
            case 'datetime':
            case 'plain':
                return html::span(['id' => "rcmcrd_plain_$fieldKey"], $fieldValueFormatted);

            case 'timestr':
                $uiType = 'text'; // choose the proper HTML UI type
            case 'text':
            case 'password':
                $input = new html_inputfield([
                    'name' => $fieldKey,
                    'type' => $uiType,
                    'value' => $fieldValueFormatted,
                    'size' => 60,
                    'disabled' => $fixed,
                ]);
                return $input->show();

            case 'radio':
                $ul = '';
                $radioBtn = new html_radiobutton(['name' => $fieldKey, 'disabled' => $fixed]);

                foreach (($fieldSpec[4] ?? []) as $selectionSpec) {
                    [ $selValue, $selLabel ] = $selectionSpec;
                    $ul .= html::tag(
                        'li',
                        [],
                        $radioBtn->show($fieldValueFormatted, ['value' => $selValue]) . $rc->locText($selLabel)
                    );
                }
                return html::tag('ul', ['class' => 'proplist'], $ul);
        }

        throw new Exception("Unknown UI element type $uiType for $fieldKey");
    }

    // INFO: name, url, group type, refresh time, time of last refresh, discovered vs. manually added,
    //       cache state (# contacts, groups, etc.), list of custom subtypes (add / delete)
    // ACTIONS: Refresh, Delete (for manually-added addressbooks), Clear local cache
    public function tmplAddressbookDetails(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();
        $out = '';

        try {
            $abookId = $rc->inputValue("abookid", false, rcube_utils::INPUT_GET);
            if (isset($abookId)) {
                $abMgr = $this->abMgr;
                $abookCfg = $this->getEnhancedAbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookCfg["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookCfg['url']);

                // HIDDEN FIELDS
                $abookIdField = new html_hiddenfield(['name' => "abookid", 'value' => $abookId]);
                $out .= $abookIdField->show();

                $out .= $this->makeSettingsForm(self::UI_FORM_ABOOK, $abookCfg, $fixedAttributes, $attrib);
                $out = $rc->requestForm($attrib, $out);
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage());
        }

        return $out;
    }

    // INFO: name, url, group type, rediscover time, time of last rediscovery
    // ACTIONS: Rediscover, Delete, Add manual addressbook
    public function tmplAccountDetails(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();
        $out = '';

        try {
            $accountId = $rc->inputValue("accountid", false, rcube_utils::INPUT_GET);
            if (isset($accountId)) {
                // HIDDEN FIELDS
                $accountIdField = new html_hiddenfield(['name' => "accountid", 'value' => $accountId]);
                $out .= $accountIdField->show();

                if ($accountId == "new") {
                    $out .= $this->makeSettingsForm(self::UI_FORM_NEW_ACCOUNT, [], [], $attrib);
                } else {
                    $abMgr = $this->abMgr;
                    $account = $abMgr->getAccountConfig($accountId);
                    $abook = $abMgr->getTemplateAddressbookForAccount($accountId);
                    $fixedAttributes = $this->getFixedSettings($account['presetname']);
                    $out .= $this->makeSettingsForm(
                        self::UI_FORM_ACCOUNT,
                        array_merge($account, $abook ?? []),
                        $fixedAttributes,
                        $attrib
                    );
                }

                $out = $rc->requestForm($attrib, $out);
            }
        } catch (Exception $e) {
            $logger->error($e->getMessage());
        }

        return $out;
    }

    /**
     * This function gets the account/addressbook settings from a POST request.
     *
     * The result array will only have keys set for POSTed values.
     *
     * For fixed settings of preset accounts/addressbooks, no setting values will be contained.
     *
     * @param FormSpec $formSpec Specification of the settings form
     * @param list<string> $fixedAttributes A list of non-changeable settings by choice of the admin
     * @return AccountSettings|AbookSettings An array with addressbook column keys and their setting.
     */
    private function getSettingsFromPOST(array $formSpec, array $fixedAttributes): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $rc = $infra->rc();

        // Fill $result with all values that have been POSTed
        $result = [];
        foreach (array_column($formSpec, 'fields') as $fields) {
            foreach ($fields as $fieldSpec) {
                [ , $fieldKey, $uiType ] = $fieldSpec;

                // Check that the attribute may be overridden
                if (in_array($fieldKey, $fixedAttributes)) {
                    continue;
                }

                $fieldValue = $rc->inputValue($fieldKey, ($uiType == 'password'));
                if (!isset($fieldValue)) {
                    continue;
                }

                // some types require data conversion / validation
                switch ($uiType) {
                    case 'plain':
                    case 'datetime':
                        // These are readonly form elements that cannot be set
                        continue 2;

                    case 'timestr':
                        try {
                            $fieldValue = Utils::parseTimeParameter($fieldValue);
                        } catch (Exception $e) {
                            // ignore format error, keep old value
                            $logger->warning("Format error in timestring parameter $fieldKey: $fieldValue (ignored)");
                            continue 2;
                        }
                        break;

                    case 'radio':
                        $allowedValues = array_column($fieldSpec[4] ?? [], 0);
                        if (!in_array($fieldValue, $allowedValues)) {
                            // ignore not allowed value
                            $logger->warning("Not allowed value $fieldValue POSTed for $fieldKey (ignored)");
                            continue 2;
                        }
                        break;

                    case 'password':
                        // the password is not echoed back in a settings form. If the user did not enter a new password
                        // and just changed some other settings, make sure we do not overwrite the stored password with
                        // an empty string.
                        if (strlen($fieldValue) == 0) {
                            continue 2;
                        }
                        break;
                }

                $result[$fieldKey] = $fieldValue;
            }
        }

        /** @psalm-var AccountSettings|AbookSettings */
        return $result;
    }

    /**
     * Creates or updates the template addressbook for an account.
     *
     * The template addressbook holds the initial addressbook settings that new addressbooks that are added to the
     * account are assigned to.
     *
     * Creation is normally done when the user creates an account, but in case no template addressbook exists yet it can
     * also happen when the user saves account settings. Update is done when a user saves the account settings and the
     * template addressbook already exists.
     *
     * @param string $accountId Id of the account to set the template addressbook for
     * @param AbookSettings $abookCfg Addressbook settings to store in the template. Missing settings get defaults.
     */
    private function setTemplateAddressbook(string $accountId, array $abookCfg): void
    {
        $abMgr = $this->abMgr;
        $abook = $abMgr->getTemplateAddressbookForAccount($accountId);
        if ($abook === null) {
            $abookCfg['account_id'] = $accountId;
            $abookCfg['discovered'] = '0';
            $abookCfg['template'] = '1';
            $abookCfg['url'] = ''; // URL is mandatory but n/a for template addressbook
            $abookCfg['sync_token'] = ''; // mandatory but n/a
            $abMgr->insertAddressbook($abookCfg);
        } else {
            $abMgr->updateAddressbook($abook['id'], $abookCfg);
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
