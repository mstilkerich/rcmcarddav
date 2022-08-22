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
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type AbookSettings from AddressbookManager
 * @psalm-import-type AccountSettings from AddressbookManager
 */
class UI
{
    /** @var FormSpec UI_FORM_NEWACCOUNT */
    private const UI_FORM_NEWACCOUNT = [
        [
            'label' => 'newaccount',
            'fields' => [
                [ 'accountname', 'name', 'text' ],
                [ 'discoveryurl', 'url', 'text' ],
                [ 'cd_username', 'username', 'text' ],
                [ 'cd_password', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'miscsettings',
            'fields' => [
                [ 'rediscover_time', 'rediscover_time', 'timestr', '86400' ],
                [ 'cd_refresh_time', 'refresh_time', 'timestr', '3600' ],
                [
                    'newgroupstype',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'grouptype_vcard' ],
                        [ '1', 'grouptype_categories' ],
                    ]
                ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ACCOUNT */
    private const UI_FORM_ACCOUNT = [
        [
            'label' => 'basicinfo',
            'fields' => [
                [ 'frompreset', 'presetname', 'plain' ],
                [ 'accountname', 'name', 'text' ],
                [ 'discoveryurl', 'url', 'text' ],
                [ 'cd_username', 'username', 'text' ],
                [ 'cd_password', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'discoveryinfo',
            'fields' => [
                [ 'rediscover_time', 'rediscover_time', 'timestr' ],
                [ 'lastdiscovered_time', 'last_discovered', 'datetime' ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ABOOK */
    private const UI_FORM_ABOOK = [
        [
            'label' => 'basicinfo',
            'fields' => [
                [ 'cd_name', 'name', 'text' ],
                [ 'cd_url', 'url', 'plain' ],
            ]
        ],
        [
            'label' => 'syncinfo',
            'fields' => [
                [ 'cd_refresh_time', 'refresh_time', 'timestr' ],
                [ 'cd_lastupdate_time', 'last_updated', 'datetime' ],
            ]
        ],
        [
            'label' => 'miscsettings',
            'fields' => [
                [
                    'newgroupstype',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'grouptype_vcard' ],
                        [ '1', 'grouptype_categories' ],
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
             * @param FullAccountRow $a
             * @param FullAccountRow $b
             */
            function (array $a, array $b): int {
                return strcasecmp($a['name'], $b['name']);
            }
        );

        // Create the list
        $accountListItems = [];
        foreach ($accounts as $account) {
            $attribs = [
                'id'    => 'rcmli_acc' . $account["id"],
                'class' => 'account' . (isset($account["presetname"]) ? ' preset' : '')
            ];
            $accountListItems[] = \html::tag('li', $attribs, $this->makeAccountListItem($account));
        }

        $rc->addGuiObject('addressbookslist', $attrib['id']);
        return \html::tag('ul', $attrib, implode('', $accountListItems));
    }

    /**
     * Creates the HTML code within the ListItem of an account in the addressbook list.
     *
     * @param FullAccountRow $account
     */
    private function makeAccountListItem(array $account): string
    {
        $abMgr = $this->abMgr;
        $account['addressbooks'] = $abMgr->getAddressbookConfigsForAccount($account["id"]);

        // Sort addressbooks by their name
        usort(
            $account['addressbooks'],
            /**
             * @param FullAbookRow $a
             * @param FullAbookRow $b
             */
            function (array $a, array $b): int {
                return strcasecmp($a['name'], $b['name']);
            }
        );

        $content = \html::a(['href' => '#'], \rcube::Q($account["name"]));

        $addressbookListItems = [];
        foreach (($account["addressbooks"] ?? []) as $abook) {
            $attribs = [
                'id'    => 'rcmli_abook' . $abook["id"],
                'class' => 'addressbook'
            ];

            $abookHtml = $this->makeAbookListItem($abook, $account['presetname']);
            $addressbookListItems[] = \html::tag('li', $attribs, $abookHtml);
        }

        if (!empty($addressbookListItems)) {
            $content .= \html::div('treetoggle expanded', '&nbsp;');
            $content .= \html::tag('ul', ['style' => null], implode("\n", $addressbookListItems));
        }

        return $content;
    }

    /**
     * Creates the HTML code within the ListItem of an addressbook in the addressbook list.
     *
     * @param FullAbookRow $abook
     */
    private function makeAbookListItem(array $abook, ?string $presetName): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $checkboxActive = new \html_checkbox([
            'name'    => '_active[]',
            'title'   => $rc->locText('changeactive'),
            'onclick' => \rcmail_output::JS_OBJECT_NAME .
            ".command('plugin.carddav-AbToggleActive', this.value, this.checked)",
        ]);

        $fixedAttributes = $this->getFixedSettings($presetName, $abook['url']);

        $abookHtml = \html::a(['href' => '#'], \rcube::Q($abook["name"]));
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
            $active = ($active != "0"); // if this is some invalid value, just consider it as activated
            $suffix = $active ? "" : "_de";
            try {
                $abMgr = $this->abMgr;
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookrow["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);

                if (in_array('active', $fixedAttributes)) {
                    throw new \Exception("active is a fixed setting for addressbook $abookId");
                } else {
                    $abMgr->updateAddressbook($abookId, ['active' => $active ]);
                    $rc->showMessage($rc->locText("AbToggleActive_msg_ok$suffix"), 'confirmation');
                }
            } catch (\Exception $e) {
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
        $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_GET);

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
            } catch (\Exception $e) {
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

                $abookIdsPrev = array_column($abMgr->getAddressbookConfigsForAccount($accountId, true), 'id', 'url');

                $accountCfg = $abMgr->getAccountConfig($accountId);
                $abMgr->discoverAddressbooks($accountCfg, []);
                $abookIds = array_column($abMgr->getAddressbookConfigsForAccount($accountId, true), 'id', 'url');

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
            } catch (\Exception $e) {
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
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $formData = $this->makeSettingsFormData(self::UI_FORM_ABOOK, $abookrow);
                $rc->showMessage($rc->locText("${syncType}_msg_ok", $msgParams), 'notice', false);
                $rc->clientCommand('carddav_UpdateForm', $formData);
            } catch (\Exception $e) {
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
        $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GET);

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

            /** @psalm-var AccountSettings */
            $newaccount = $this->getSettingsFromPOST(self::UI_FORM_ACCOUNT, []);
            /** @psalm-var AbookSettings */
            $abooksettings = $this->getSettingsFromPOST(self::UI_FORM_ABOOK, []);
            $accountId = $abMgr->discoverAddressbooks($newaccount, $abooksettings);
            $account = $abMgr->getAccountConfig($accountId);

            $newLi = $this->makeAccountListItem($account);
            $rc->clientCommand('carddav_InsertListElem', [[$accountId, $newLi]], ['acc', $accountId]);
            $rc->showMessage($rc->locText("AccAdd_msg_ok"), 'confirmation');
        } catch (\Exception $e) {
            $logger->error("Error creating CardDAV account: " . $e->getMessage());
            $rc->showMessage($rc->locText("savefail", ['errormsg' => $e->getMessage()]), 'error');
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
                /** @psalm-var AccountSettings */
                $newset = $this->getSettingsFromPOST(self::UI_FORM_ACCOUNT, $fixedAttributes);
                $abMgr->updateAccount($accountId, $newset);

                // update account data and echo formatted field data to client
                $account = $abMgr->getAccountConfig($accountId);
                $formData = $this->makeSettingsFormData(self::UI_FORM_ACCOUNT, $account);
                $formData["_acc$accountId"] = [ 'parent', $account["name"] ];

                $rc->clientCommand('carddav_UpdateForm', $formData);
                $rc->showMessage($rc->locText("saveok"), 'confirmation');
            } catch (\Exception $e) {
                $logger->error("Error saving account preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("savefail", ['errormsg' => $e->getMessage()]), 'error');
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
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookrow["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);
                /** @psalm-var AbookSettings */
                $newset = $this->getSettingsFromPOST(self::UI_FORM_ABOOK, $fixedAttributes);
                $abMgr->updateAddressbook($abookId, $newset);

                // update addressbook data and echo formatted field data to client
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $formData = $this->makeSettingsFormData(self::UI_FORM_ABOOK, $abookrow);
                $formData["_abook$abookId"] = [ 'parent', $abookrow["name"] ];

                $rc->showMessage($rc->locText("saveok"), 'confirmation');
                $rc->clientCommand('carddav_UpdateForm', $formData);
            } catch (\Exception $e) {
                $logger->error("Error saving addressbook preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("savefail", ['errormsg' => $e->getMessage()]), 'error');
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
            $table = new \html_table(['cols' => 2]);

            foreach ($fieldSet['fields'] as $fieldSpec) {
                [ $fieldLabel, $fieldKey, $uiType ] = $fieldSpec;

                $fieldValue = $vals[$fieldKey] ?? $fieldSpec[3] ?? '';

                // plain field is only shown when there is a value to be shown
                if ($uiType == 'plain' && $fieldValue == '') {
                    continue;
                }

                $readonly = in_array($fieldKey, $fixedAttributes);
                $table->add(['class' => 'title'], \html::label(['for' => $fieldKey], $rc->locText($fieldLabel)));
                $table->add([], $this->uiField($fieldSpec, $fieldValue, $readonly));
            }

            $out .= \html::tag(
                'fieldset',
                [],
                \html::tag('legend', [], $rc->locText($fieldSet['label'])) . $table->show($attrib)
            );
        }

        return $out;
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
                    $fieldValue = $rc->locText('never');
                }
                // fall through to plain text

            case 'plain':
                return \rcube::Q($fieldValue);

            case 'timestr':
                $t = intval($fieldValue);
                $fieldValue = sprintf("%02d:%02d:%02d", floor($t / 3600), ($t / 60) % 60, $t % 60);
                // fall through to text field

            case 'text':
            case 'radio':
                return $fieldValue;

            case 'password':
                return '';
        }

        throw new \Exception("Unknown UI element type $uiType for $fieldKey");
    }

    /**
     * @param FieldSpec $fieldSpec
     */
    private function uiField(array $fieldSpec, string $fieldValue, bool $readonly): string
    {
        [, $fieldKey, $uiType ] = $fieldSpec;

        $infra = Config::inst();
        $rc = $infra->rc();

        $fieldValueFormatted = $this->formatFieldValue($fieldSpec, $fieldValue);
        switch ($uiType) {
            case 'datetime':
            case 'plain':
                return \html::span(['id' => "rcmcrd_plain_$fieldKey"], $fieldValueFormatted);

            case 'timestr':
            case 'text':
            case 'password':
                $input = new \html_inputfield([
                    'name' => $fieldKey,
                    'type' => $uiType,
                    'value' => $fieldValueFormatted,
                    'size' => 60,
                    'disabled' => $readonly,
                ]);
                return $input->show();

            case 'radio':
                $ul = '';
                $radioBtn = new \html_radiobutton(['name' => $fieldKey, 'disabled' => $readonly]);

                foreach (($fieldSpec[4] ?? []) as $selectionSpec) {
                    [ $selValue, $selLabel ] = $selectionSpec;
                    $ul .= \html::tag(
                        'li',
                        [],
                        $radioBtn->show($fieldValueFormatted, ['value' => $selValue]) . $rc->locText($selLabel)
                    );
                }
                return \html::tag('ul', ['class' => 'proplist'], $ul);
        }

        throw new \Exception("Unknown UI element type $uiType for $fieldKey");
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
            $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GET);
            if (isset($abookId)) {
                $abookrow = $this->abMgr->getAddressbookConfig($abookId);
                $account = $this->abMgr->getAccountConfig($abookrow["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);

                // HIDDEN FIELDS
                $abookIdField = new \html_hiddenfield(['name' => "abookid", 'value' => $abookId]);
                $out .= $abookIdField->show();

                $out .= $this->makeSettingsForm(self::UI_FORM_ABOOK, $abookrow, $fixedAttributes, $attrib);
                $out = $rc->requestForm($attrib, $out);
            }
        } catch (\Exception $e) {
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
            $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_GET);
            if (isset($accountId)) {
                // HIDDEN FIELDS
                $accountIdField = new \html_hiddenfield(['name' => "accountid", 'value' => $accountId]);
                $out .= $accountIdField->show();

                if ($accountId == "new") {
                    $out .= $this->makeSettingsForm(self::UI_FORM_NEWACCOUNT, [], [], $attrib);
                } else {
                    $account = $this->abMgr->getAccountConfig($accountId);
                    $fixedAttributes = $this->getFixedSettings($account['presetname']);
                    $out .= $this->makeSettingsForm(self::UI_FORM_ACCOUNT, $account, $fixedAttributes, $attrib);
                }

                $out = $rc->requestForm($attrib, $out);
            }
        } catch (\Exception $e) {
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
                        } catch (\Exception $e) {
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
