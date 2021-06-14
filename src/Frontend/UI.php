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
class UI
{
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
        $rc = $infra->rc();

        $rc->addHook('settings_actions', [$this, 'addSettingsAction']);

        $rc->registerAction('plugin.carddav', [$this, 'renderAddressbookList']);
        $rc->registerAction('plugin.carddav.activateabook', [$this, 'actionActivateAbook']);
        $rc->registerAction('plugin.carddav.deactivateabook', [$this, 'actionDeactivateAbook']);
        $rc->registerAction('plugin.carddav.ablist_selection', [$this, 'actionShowDetails']);
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
            'class'  => 'cd_preferences', // OK CSS style
            'label'  => 'cd_title', // OK text display
            'title'  => 'cd_title', // OK tooltip text
            'domain' => 'carddav',
        ];

        return $args;
    }

    public function renderAddressbookList(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $rc->setPagetitle($rc->locText('cd_title'));

        $rc->includeJS('treelist.js', true);
        $rc->addTemplateObjHandler('addressbookslist', [$this, 'tmplAddressbooksList']);
        $rc->sendTemplate('carddav.addressbooks');
    }

    /**
     * Template object for list of addressbooks.
     *
     * @psalm-param array{id?: string} $attrib
     * @param array $attrib Object attributes
     *
     * @return string HTML content
     */
    public function tmplAddressbooksList(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmcarddavaddressbookslist';
        }

        $abooks = $this->abMgr->getAddressbooks(false);

        $accountsByAbookHomeUri = [];
        foreach ($abooks as $abook) {
            $abookHomeUrl = dirname($abook["url"]);

            if (!isset($accountsByAbookHomeUri[$abookHomeUrl])) {
                $name = preg_replace("/ \(.*\)/", "", $abook["name"], 1);
                $accountsByAbookHomeUri[$abookHomeUrl] = [
                    'id' => $abook["id"] . "_acc",
                    'name' => $name,
                    'addressbooks' => []
                ];
            }

            $accountsByAbookHomeUri[$abookHomeUrl]["addressbooks"][] = $abook;
        }

        $checkboxActive = new \html_checkbox([
                'name'    => '_active[]',
                'title'   => $rc->locText('changeactive'),
                'onclick' => \rcmail_output::JS_OBJECT_NAME .
                  ".command(this.checked?'plugin.carddav-activate-abook':'plugin.carddav-deactivate-abook',this.value)",
        ]);

        $accounts = [];
        foreach ($accountsByAbookHomeUri as $account) {
            $content = \html::a(['href' => '#'], \rcube::Q($account["name"]));

            $addressbooks = [];
            foreach ($account["addressbooks"] as $abook) {
                $attribs = [
                    'id'    => 'rcmli' . $abook["id"],
                    'class' => 'addressbook'
                ];

                $abookName = preg_replace("/.* \((.*)\)$/", "$1", $abook["name"], 1);
                $abookHtml = \html::a(['href' => '#'], \rcube::Q($abookName));
                $abookHtml .= $checkboxActive->show($abook["active"] ? $abook['id'] : '', ['value' => $abook['id']]);
                $addressbooks[] = \html::tag('li', $attribs, $abookHtml);
            }


            if (!empty($addressbooks)) {
                $content .= \html::div('treetoggle expanded', '&nbsp;');
                $content .= \html::tag('ul', ['style' => null], implode("\n", $addressbooks));
            }

            $attribs = [
                'id'    => 'rcmli' . $account["id"],
                'class' => 'account'
            ];
            $accounts[] = \html::tag('li', $attribs, $content);
        }

        $rc->addGuiObject('addressbookslist', $attrib['id']);
        return \html::tag('ul', $attrib, implode('', $accounts));
    }

    public function actionActivateAbook(): void
    {
        $this->changeAddressbookActive(true);
    }

    public function actionDeactivateAbook(): void
    {
        $this->changeAddressbookActive(false);
    }

    private function changeAddressbookActive(bool $active): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $prefix = $active ? "" : "de";

        $abookId = $rc->inputValue("_abookid", false);
        if (isset($abookId)) {
            try {
                $this->abMgr->updateAddressbook($abookId, ['active' => $active ]);
                $rc->showMessage($rc->locText("${prefix}activateabook_success"), 'confirmation');
            } catch (\Exception $e) {
                $rc->showMessage("Activation failed!", 'error');
                $rc->clientCommand('carddav_reset_active', $abookId, !$active);
            }
        }
    }

    public function actionShowDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $objType = $rc->inputValue("_type", false, \rcube_utils::INPUT_GET);

        if ($objType == "addressbook") {
            $rc->setPagetitle($rc->locText('abookproperties'));
            $rc->addTemplateObjHandler('addressbookdetails', [$this, 'tmplAddressbookDetails']);
            $rc->sendTemplate('carddav.addressbookDetails');
        } elseif ($objType == "account") {
            $rc->addTemplateObjHandler('accountdetails', [$this, 'tmplAccountDetails']);
            $rc->sendTemplate('carddav.accountDetails');
        } else {
            return;
        }
    }

    // INFO: name, url, group type, refresh time, time of last refresh
    // ACTIONS: Refresh, Delete
    public function tmplAddressbookDetails(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $out = '';

        $table = new \html_table(['cols' => 2]);

        try {
            $abookId = $rc->inputValue("_id", false, \rcube_utils::INPUT_GET);
            if (isset($abookId)) {
                $abook = $this->abMgr->getAddressbook($abookId);

                $table->add(['class' => 'title'], \html::label([], $rc->locText('cd_name')));
                $table->add([], \rcube::Q($abook->get_name()));

                $out .= \html::tag(
                    'fieldset',
                    [],
                    \html::tag('legend', [], $rc->locText('basicinfo')) . $table->show($attrib)
                );
            }
        } catch (\Exception $e) {
        }

        return $out;
    }

    public function tmplAccountDetails(array $attrib): string
    {
        $out = '';
        return $out;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
