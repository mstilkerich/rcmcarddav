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

        $rc->includeJS('treelist.js');
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
                $name = preg_replace("/ (.*)/", "", $abook["name"], 1);
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
                $abookHtml = \html::a(['href' => '#'], \rcube::Q($abook["name"]));
                $abookHtml .= $checkboxActive->show('', ['value' => $abook['id']]);
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
