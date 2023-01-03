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

namespace MStilkerich\Tests\RCMCardDAV\Unit;

use Exception;
use DOMDocument;
use DOMXPath;
use DOMNodeList;
use DOMNode;
use DOMNamedNodeMap;
use MStilkerich\CardDavClient\{AddressbookCollection,WebDavResource};
use MStilkerich\RCMCardDAV\Frontend\{AddressbookManager,UI};
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
use PHPUnit\Framework\TestCase;

/**
 * Tests parts of the AdminSettings class using test data in JsonDatabase.
 */
final class UITest extends TestCase
{
    /** @var JsonDatabase */
    private $db;

    public static function setUpBeforeClass(): void
    {
        $_SESSION['user_id'] = 105;
        $_SESSION['username'] = 'johndoe';
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    public function testUiSettingsActionGeneratesProperSectionInfo(): void
    {
        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $settingsEntry = $ui->addSettingsAction(['attrib' => ['foo' => 'bar'], 'actions' => [['action' => 'test']]]);

        $this->assertSame(['foo' => 'bar'], $settingsEntry['attrib'] ?? []);
        $this->assertCount(2, $settingsEntry['actions']);
        $this->assertSame(['action' => 'test'], $settingsEntry['actions'][0]);
        $this->assertSame('plugin.carddav', $settingsEntry['actions'][1]['action'] ?? '');
        $this->assertSame('cd_preferences', $settingsEntry['actions'][1]['class'] ?? '');
        $this->assertSame('CardDAV_rclbl', $settingsEntry['actions'][1]['label'] ?? '');
        $this->assertSame('CardDAV_rctit', $settingsEntry['actions'][1]['title'] ?? '');
        $this->assertSame('carddav', $settingsEntry['actions'][1]['domain'] ?? '');
    }

    /**
     * Tests that the list of accounts/addressbooks in the carddav settings pane is properly generated.
     *
     * - Accounts and addressbooks are sorted alphabetically
     * - Preset accounts are tagged with preset class
     * - Accounts with hide=true are hidden
     * - Active toggle of the addressbooks is set to the correct initial value
     * - Active toggle for preset accounts' addressbooks with active=fixed is disabled
     */
    public function testAddressbookListIsProperlyCreated(): void
    {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);

        $html = $ui->tmplAddressbooksList(['id' => 'addressbooks-table']);
        $this->assertNotEmpty($html);

        /**
         * Expected accounts in the expected order
         * @psalm-var list<list{string,int,list<string>}> $expAccounts
         */
        $expAccounts = [
            //  name        , ID, Preset?
            [ 'iCloud', 43, false ], // the lowercase initial must sort alphabetically with the uppercase initials
            [ 'Preset Contacts', 44, true ],
            [ 'Test Account', 42, false ],
            // HiddenPreset is excluded as it must not be shown in the settings pane
        ];

        /**
         * Expected addressbooks per account in the expected order
         * @psalm-var array<string, list<list{string,int,bool,bool}>> $expAbooks
         */
        $expAbooks = [
            //  name        , ID, active?, activeFixed?
            'iCloud' => [
            ],
            'Preset Contacts' => [
                ['Public readonly contacts', 51, true, true],
            ],
            'Test Account' => [
                ['additional contacts', 43, true, false],
                ['Additional contacts - Inactive', 44, false, false],
                ['Basic contacts', 42, true, false],
            ],
        ];

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadHTML($html));
        $xpath = new DOMXPath($dom);

        // for each account, there must be an li node with the id rcmli_acc<ID>
        $accItems = $xpath->query("//ul[@id='addressbooks-table']/li");
        $this->assertInstanceOf(DOMNodeList::class, $accItems);
        $this->assertCount(count($expAccounts), $accItems);
        for ($i = 0; $i < count($expAccounts); $i++) {
            [ $accName, $accId, $isPreset ] = $expAccounts[$i];
            $accItem = $accItems->item($i);
            $this->assertInstanceOf(DOMNode::class, $accItem);

            // Check attributes
            $this->checkAttribute($accItem, 'id', "rcmli_acc$accId");
            $this->checkAttribute($accItem, 'class', 'account', 'contains');
            $this->checkAttribute($accItem, 'class', 'preset', $isPreset ? 'contains' : 'containsnot');

            // Check account name, which is stored in a span element
            $this->checkAccAbName($xpath, $accItem, $accName);

            // check the addressbooks shown are as expected, including order, classes and active toggle status
            $abookItems = $xpath->query("ul/li", $accItem);
            $expAbookRecords = $expAbooks[$accName];
            $this->assertCount(count($expAbookRecords), $abookItems);
            for ($j = 0; $j < count($expAbookRecords); $j++) {
                [ $abName, $abId, $abAct, $abActFixed ] = $expAbookRecords[$j];
                $abookItem = $abookItems->item($j);
                $this->assertInstanceOf(DOMNode::class, $abookItem);

                // Check attributes
                $this->checkAttribute($abookItem, 'id', "rcmli_abook$abId");
                $this->checkAttribute($abookItem, 'class', 'addressbook', 'contains');

                // Check displayed addressbook name
                $this->checkAccAbName($xpath, $abookItem, $abName);

                // Check active toggle
                $actToggle = $xpath->query("a/input[@type='checkbox']", $abookItem);
                $this->assertInstanceOf(DOMNodeList::class, $actToggle);
                $this->assertCount(1, $actToggle);
                $actToggle = $actToggle->item(0);
                $this->assertInstanceOf(DOMNode::class, $actToggle);
                $this->checkAttribute($actToggle, 'value', "$abId");
                $this->checkAttribute($actToggle, 'name', "_active[]");
                $this->checkAttribute($actToggle, 'checked', $abAct ? 'checked' : null);
                $this->checkAttribute($actToggle, 'disabled', $abActFixed ? 'disabled' : null);
            }
        }
    }

    /**
     * @psalm-param 'equals'|'contains'|'containsnot' $matchType
     * @param ?string $val Expected value of the attribute. If null, the node must not have the given attribute.
     */
    private function checkAttribute(DOMNode $node, string $attr, ?string $val, string $matchType = 'equals'): void
    {
        if (is_null($val)) {
            // Check that the node does not have the given attribute; this is met if the node has no attributes at all,
            // or if the given attribute is not one of the existing attributes
            $this->assertFalse(
                is_a($node->attributes, DOMNamedNodeMap::class) && !is_null($node->attributes->getNamedItem($attr))
            );
            return;
        }

        $this->assertInstanceOf(DOMNamedNodeMap::class, $node->attributes);
        $attrNode = $node->attributes->getNamedItem($attr);
        $this->assertInstanceOf(DOMNode::class, $attrNode);

        if ($matchType === 'equals') {
            $this->assertSame($val, $attrNode->nodeValue);
        } else {
            // contains match
            $vals = explode(' ', $attrNode->nodeValue ?? '');
            if ($matchType === 'contains') {
                $this->assertContains($val, $vals);
            } else {
                $this->assertNotContains($val, $vals);
            }
        }
    }

    /**
     * Checks the name displayed in the given list item node for an account or addressbook matches the expectation.
     *
     * The name is nested inside a span element, which is nested inside an a element inside the given li.
     */
    private function checkAccAbName(DOMXPath $xpath, DOMNode $li, string $name): void
    {
        // Check name, which is stored in a span element
        $span = $xpath->query("a/span", $li);
        $this->assertInstanceOf(DOMNodeList::class, $span);
        $this->assertCount(1, $span);
        $span = $span->item(0);
        $this->assertInstanceOf(DOMNode::class, $span);
        $this->assertSame($name, $span->textContent);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
