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
                $actToggle = $this->getDomNode($xpath, "a/input[@type='checkbox']", $abookItem);
                $this->checkAttribute($actToggle, 'value', "$abId");
                $this->checkAttribute($actToggle, 'name', "_active[]");
                $this->checkAttribute($actToggle, 'checked', $abAct ? 'checked' : null);
                $this->checkAttribute($actToggle, 'disabled', $abActFixed ? 'disabled' : null);
            }
        }
    }

    /**
     * GET: account ID to set as GET parameter (null to not set one)
     * ERR: expected error message. Null if no error is expected, empty string if error case without error message
     *
     *                             GET      ERR     Check-inputs
     * @return array<string, list{?string, ?string, list<list{string,?string,string,bool}>}>
     */
    public function accountIdProvider(): array
    {
        return [
            //                        GET   ERR CHK-INP
            'Missing account ID' => [ null, '', [] ],
            'Invalid account ID' => [ '123', 'No carddav account with ID 123', [] ],
            "Other user's account ID" => [ '101', 'No carddav account with ID 101', [] ],
            "Hidden Preset Account" => [ '45', 'Account ID 45 refers to a hidden account', [] ],
            "User-defined account with template addressbook" => [
                '42',
                null,
                [
                    //  name             val                          type        disabled?
                    [ 'accountid',       '42',                        'hidden',   false ],
                    [ 'accountname',     'Test Account',              'text',    false ],
                    [ 'discovery_url',   'https://test.example.com/', 'text',     false ],
                    [ 'username',        'johndoe',                   'text',     false ],
                    [ 'password',        null,                        'password', false ],
                    [ 'rediscover_time', '02:00:00',                  'text',     false ],
                    [ 'last_discovered', date("Y-m-d H:i:s", 1672825163), 'plain', false ],
                    [ 'name',            '%N, %D',                    'text',     false ],
                    [ 'active',          '1',                         'checkbox', false ],
                    [ 'refresh_time',    '00:10:00',                  'text',     false ],
                    [ 'use_categories',  '0',                         'radio',    false ],
                ]
            ],
            "New account" => [
                'new',
                null,
                [
                    //  name             val                          type        disabled?
                    [ 'accountid',       'new',                       'hidden',   false ],
                    [ 'accountname',     '',                          'text',    false ],
                    [ 'discovery_url',   '',                          'text',     false ],
                    [ 'username',        '',                          'text',     false ],
                    [ 'password',        null,                        'password', false ],
                    [ 'rediscover_time', '24:00:00',                  'text',     false ],
                    [ 'name',            '%N',                        'text',     false ],
                    [ 'active',          '1',                         'checkbox', false ],
                    [ 'refresh_time',    '01:00:00',                  'text',     false ],
                    [ 'use_categories',  '1',                         'radio',    false ],
                ]
            ],
            "Visible Preset account" => [
                '44',
                null,
                [
                    //  name             val                          type        disabled?
                    [ 'accountid',       '44',                        'hidden',   false ],
                    [ 'accountname',     'Preset Contacts',           'text',     false ],
                    [ 'discovery_url',   'https://carddav.example.com/', 'text',  false ],
                    [ 'username',        'foodoo',                    'text',     true ],
                    [ 'password',        null,                        'password', true ],
                    [ 'rediscover_time', '24:00:00',                  'text',     false ],
                    [ 'last_discovered', 'DateTime_never_lbl',        'plain',    false ],
                    [ 'name',            '%N (%D)',                   'text',     true ],
                    [ 'active',          '1',                         'checkbox', false ],
                    [ 'refresh_time',    '00:30:00',                  'text',     true ],
                    [ 'use_categories',  '0',                         'radio',    false ],
                ]
            ],
        ];
    }

    /**
     * Tests that the account details form is properly displayed.
     *
     * - For account ID=new, the form is shown with default values
     * - Fixed fields of a preset account are disabled
     * - Values from a template addressbook are shown if one exists
     *   - For a preset, fixed fields are overridden by the preset's values if different from the template addressbook
     * - Default values for addressbook settings are shown if no template addressbook exists
     *   - For a preset, fixed fields are overridden by the preset's values if different from the default
     *
     * - Error cases:
     *   - Invalid account ID in GET parameters (error is logged, empty string is returned)
     *   - Account ID of different user in GET parameters (error is logged, empty string is returned)
     *
     * @param list<list{string,?string,string,bool}> $checkInputs
     * @dataProvider accountIdProvider
     */
    public function testAccountDetailsFormIsProperlyCreated(?string $getID, ?string $errMsg, array $checkInputs): void
    {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();
        if (is_string($getID)) {
            $rcStub->getInputs['accountid'] = $getID;
        }

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);

        $html = $ui->tmplAccountDetails(['id' => 'accountdetails']);

        if (is_null($errMsg)) {
            $this->assertIsString($getID);
            $this->assertNotEmpty($html);

            $dom = new DOMDocument();
            $this->assertTrue($dom->loadHTML($html));
            $xpath = new DOMXPath($dom);

            // Check form fields exist and contain the expected values
            foreach ($checkInputs as $checkInput) {
                [ $iName, $iVal, $iType, $iDisabled ] = $checkInput;

                if ($iType === 'plain') {
                    $iNode = $this->getDomNode($xpath, "//tr[td/label[@for='$iName']]/td/span");
                    $this->assertSame($iVal, $iNode->textContent);
                } elseif ($iType === 'radio') {
                    $radioItems = $xpath->query("//input[@name='$iName']");
                    $this->assertInstanceOf(DOMNodeList::class, $radioItems);
                    $this->assertGreaterThan(1, count($radioItems));
                    $valueItemFound = false;
                    foreach ($radioItems as $radioItem) {
                        $this->assertInstanceOf(DOMNode::class, $radioItem);
                        $this->checkAttribute($radioItem, 'type', 'radio');

                        $this->assertInstanceOf(DOMNamedNodeMap::class, $radioItem->attributes);
                        $attrNode = $radioItem->attributes->getNamedItem('value');
                        $this->assertInstanceOf(DOMNode::class, $attrNode);
                        $this->assertIsString($attrNode->nodeValue);
                        if ($attrNode->nodeValue === $iVal) {
                            $valueItemFound = true;
                            $this->checkAttribute($radioItem, 'checked', 'checked');
                        } else {
                            $this->checkAttribute($radioItem, 'checked', null);
                        }
                        $this->checkAttribute($radioItem, 'disabled', $iDisabled ? 'disabled' : null);
                    }
                    $this->assertTrue($valueItemFound, "No radio button with the expected value exists for $iName");
                } else {
                    $iNode = $this->getDomNode($xpath, "//input[@name='$iName']");
                    $this->checkAttribute($iNode, 'value', $iVal);
                    $this->checkAttribute($iNode, 'type', $iType);
                    $this->checkAttribute($iNode, 'disabled', $iDisabled ? 'disabled' : null);
                }
            }
        } else {
            $this->assertEmpty($html);

            if (strlen($errMsg) > 0) {
                $logger->expectMessage('error', $errMsg);
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
                is_a($node->attributes, DOMNamedNodeMap::class) && !is_null($node->attributes->getNamedItem($attr)),
                "Attribute $attr not expected to exist, but does exist"
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
        $span = $this->getDomNode($xpath, "a/span", $li);
        $this->assertSame($name, $span->textContent);
    }

    /**
     * Checks the existence of exactly one DOMNode with the given XPath query and returns that node.
     *
     * @return DOMNode The DOMNode; if it does not exist, an assertion in this function will fail and not return.
     */
    private function getDomNode(DOMXPath $xpath, string $xpathquery, ?DOMNode $context = null): DOMNode
    {
            $item = $xpath->query($xpathquery, $context);
            $this->assertInstanceOf(DOMNodeList::class, $item);
            $this->assertCount(1, $item, "Not exactly one node returned for $xpathquery");
            $item = $item->item(0);
            $this->assertInstanceOf(DOMNode::class, $item);
            return $item;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
