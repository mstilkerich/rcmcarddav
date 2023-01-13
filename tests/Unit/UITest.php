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
use MStilkerich\CardDavClient\{Account,AddressbookCollection,WebDavResource};
use MStilkerich\CardDavClient\Services\{Discovery,Sync};
use MStilkerich\RCMCardDAV\Db\AbstractDatabase;
use MStilkerich\RCMCardDAV\Frontend\{AddressbookManager,UI};
use MStilkerich\RCMCardDAV\RoundcubeLogger;
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
use PHPUnit\Framework\TestCase;

/**
 * Tests parts of the AdminSettings class using test data in JsonDatabase.
 * @psalm-import-type PsrLogLevel from RoundcubeLogger
 * @psalm-import-type DbConditions from AbstractDatabase
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
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);

        $ui->renderAddressbookList();
        $this->assertContains('carddav.addressbooks', $rcStub->sentTemplates);

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
     * @return array<string, list{?string, ?string, list<list{string,?string,string,string, ?string}>}>
     */
    public function accountIdProvider(): array
    {
        $lblTime = 'AccAbProps_timestr_placeholder_lbl';
        $lblDUrl = 'AccProps_discoveryurl_placeholder_lbl';
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
                    //  name             val                          type      flags (RDP)  placeholder
                    [ 'accountid',       '42',                        'hidden',    '',       null ],
                    [ 'accountname',     'Test Account',              'text',      'R',      null ],
                    [ 'discovery_url',   'https://test.example.com/', 'text',      'P',      $lblDUrl ],
                    [ 'username',        'johndoe',                   'text',      '',       null ],
                    [ 'password',        null,                        'password',  '',       null ],
                    [ 'rediscover_time', '02:00:00',                  'text',      'RP',     $lblTime ],
                    [ 'last_discovered', date("Y-m-d H:i:s", 1672825163), 'plain', '',       null ],
                    [ 'name',            '%N, %D',                    'text',      'R',      null ],
                    [ 'active',          '1',                         'checkbox',  '',       null ],
                    [ 'refresh_time',    '00:10:00',                  'text',      'RP',     $lblTime ],
                    [ 'use_categories',  '0',                         'radio',     '',       null ],
                ]
            ],
            "New account" => [
                'new',
                null,
                [
                    //  name             val                          type        flags (RDP)  placeholder
                    [ 'accountid',       'new',                       'hidden',   '',          null ],
                    [ 'accountname',     '',                          'text',     'R',         null ],
                    [ 'discovery_url',   '',                          'text',     'RP',        $lblDUrl ],
                    [ 'username',        '',                          'text',     '',          null ],
                    [ 'password',        null,                        'password', '',          null ],
                    [ 'rediscover_time', '24:00:00',                  'text',     'RP',        $lblTime ],
                    [ 'name',            '%N',                        'text',     'R',         null ],
                    [ 'active',          '1',                         'checkbox', '',          null ],
                    [ 'refresh_time',    '01:00:00',                  'text',     'RP',        $lblTime ],
                    [ 'use_categories',  '1',                         'radio',    '',          null ],
                ]
            ],
            "Visible Preset account without template addressbook" => [
                '44',
                null,
                [
                    //  name             val                          type        flags (RDP)  placeholder
                    [ 'accountid',       '44',                        'hidden',   '',          null ],
                    [ 'accountname',     'Preset Contacts',           'text',     'R',         null ],
                    [ 'discovery_url',   'https://carddav.example.com/', 'text',  'P',         $lblDUrl ],
                    [ 'username',        'foodoo',                    'text',     'D',         null ],
                    [ 'password',        null,                        'password', 'D',         null ],
                    [ 'rediscover_time', '24:00:00',                  'text',     'RP',        $lblTime ],
                    [ 'last_discovered', 'DateTime_never_lbl',        'plain',    '',          null ],
                    [ 'name',            '%N (%D)',                   'text',     'D',         null ],
                    [ 'active',          '1',                         'checkbox', '',          null ],
                    [ 'refresh_time',    '00:30:00',                  'text',     'D',         $lblTime ],
                    [ 'use_categories',  '0',                         'radio',    '',          null ],
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
     *   - The template addressbook has precedence over the settings in the preset. For fixed settings, the template
     *     addressbook may be out of sync with the preset settings if the admin changed the value while the user was
     *     logged on. There is currently no handling for this and the outdated values will continued to be used.
     * - Default values for addressbook settings are shown if no template addressbook exists
     *   - For a preset, values included in the preset override the default values
     *
     * - Error cases:
     *   - Invalid account ID in GET parameters (error is logged, empty string is returned)
     *   - Account ID of different user in GET parameters (error is logged, empty string is returned)
     *
     * @param list<list{string,?string,string,string,?string}> $checkInputs
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

        $ui->actionAccDetails();
        if (is_null($getID)) {
            $logger->expectMessage('warning', 'no account ID found in parameters');
        } else {
            $this->assertContains('carddav.accountDetails', $rcStub->sentTemplates);
        }

        $html = $ui->tmplAccountDetails(['id' => 'accountdetails']);

        if (is_null($errMsg)) {
            $this->assertIsString($getID);
            $this->assertNotEmpty($html);

            $dom = new DOMDocument();
            $this->assertTrue($dom->loadHTML($html));

            // Check form fields exist and contain the expected values
            $this->checkInput($dom, $checkInputs);
        } else {
            $this->assertEmpty($html);

            if (strlen($errMsg) > 0) {
                $logger->expectMessage('error', $errMsg);
            }
        }
    }

    /**
     * GET: abook ID to set as GET parameter (null to not set one)
     * ERR: expected error message. Null if no error is expected, empty string if error case without error message
     *
     *                             GET      ERR     Check-inputs
     * @return array<string, list{?string, ?string, list<list{string,?string,string,string,?string}>}>
     */
    public function abookIdProvider(): array
    {
        $lblTime = 'AccAbProps_timestr_placeholder_lbl';
        return [
            //                        GET   ERR CHK-INP
            'Missing abook ID' => [ null, '', [] ],
            'Invalid abook ID' => [ '123', 'No carddav addressbook with ID 123', [] ],
            "Other user's addressbook ID" => [ '101', 'No carddav addressbook with ID 101', [] ],
            "Hidden Preset Addressbook" => [ '61', 'Account ID 45 refers to a hidden account', [] ],
            "User-defined addressbook" => [
                '42',
                null,
                [
                    //  name             val                          type         flags  placeholder
                    [ 'abookid',         '42',                        'hidden',    '',    null ],
                    [ 'name',            'Basic contacts',            'text',      'R',   null ],
                    [ 'url',             'https://test.example.com/books/johndoe/book42/', 'plain', '', null ],
                    [ 'refresh_time',    '00:06:00',                  'text',      'RP',  $lblTime ],
                    [ 'last_updated',    date("Y-m-d H:i:s", 1672825164), 'plain', '',    null ],
                    [ 'use_categories',  '1',                         'radio',     '',    null ],
                    [ 'srvname',         'Book 42 SrvName',           'plain',     '',    null ],
                    [ 'srvdesc',         "Hitchhiker's Guide",        'plain',     '',    null ],
                ]
            ],
            "Preset extra addressbook with custom fixed fields" => [
                '51',
                null,
                [
                    //  name             val                          type         flags  placeholder
                    [ 'abookid',         '51',                        'hidden',    '',    null ],
                    [ 'name',            'Public readonly contacts',  'text',      'D',   null ],
                    [ 'url',             'https://carddav.example.com/shared/Public/', 'plain', '', null ],
                    [ 'refresh_time',    '01:00:00',                  'text',      'RP',  $lblTime ],
                    [ 'last_updated',    'DateTime_never_lbl',        'plain',     '',    null ],
                    [ 'use_categories',  '0',                         'radio',     '',    null ],
                    [ 'srvname',         null,                        'plain',     '',    null ],
                    [ 'srvdesc',         null,                        'plain',     '',    null ],
                ]
            ],
        ];
    }

    /**
     * Tests that the addressbook details form is properly displayed.
     *
     * - Fixed fields of an addressbook belonging to a preset account are disabled
     *   - For an extra addressbook with specific fixed fields, these are properly considered
     * - For a preset, the addressbook DB settings may become out of sync with fixed settings in the config, if the
     *   config was changed by the admin while the user was still logged on. There is currently no special handling for
     *   this case and the DB values will be displayed.
     * - The server-side fields are displayed only when available. If querying from the server fails, they are not
     *   displayed at all.
     *
     * - Error cases:
     *   - Invalid addressbook ID in GET parameters (error is logged, empty string is returned)
     *   - Addressbook ID of different user in GET parameters (error is logged, empty string is returned)
     *   - Addressbook ID of addressbook belonging to a hidden preset in GET parameters (error is logged, empty string
     *     returned)
     *
     * @param list<list{string,?string,string,string,?string}> $checkInputs
     * @dataProvider abookIdProvider
     */
    public function testAddressbookDetailsFormIsProperlyCreated(
        ?string $getID,
        ?string $errMsg,
        array $checkInputs
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();
        if (is_string($getID)) {
            $rcStub->getInputs['abookid'] = $getID;
        }

        $this->setAddressbookStubs();

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);

        $ui->actionAbDetails();
        if (is_null($getID)) {
            $logger->expectMessage('warning', 'no addressbook ID found in parameters');
        } else {
            $this->assertContains('carddav.addressbookDetails', $rcStub->sentTemplates);
        }

        $html = $ui->tmplAddressbookDetails(['id' => 'addressbookdetails']);

        if (is_null($errMsg)) {
            $this->assertIsString($getID);
            $this->assertNotEmpty($html);

            $dom = new DOMDocument();
            $this->assertTrue($dom->loadHTML($html));

            // Check form fields exist and contain the expected values
            $this->checkInput($dom, $checkInputs);
        } else {
            $this->assertEmpty($html);

            if (strlen($errMsg) > 0) {
                $logger->expectMessage('error', $errMsg);
            }
        }
    }

    /**
     * @param list<list{string,?string,string,string,?string}> $checkInputs
     */
    private function checkInput(DOMDocument $dom, array $checkInputs): void
    {
        $xpath = new DOMXPath($dom);

        foreach ($checkInputs as $checkInput) {
            [ $iName, $iVal, $iType, $iFlags, $iPlaceholder ] = $checkInput;
            $iDisabled = (strpos($iFlags, 'D') !== false);
            $iRequired = (strpos($iFlags, 'R') !== false);
            $iPattern  = (strpos($iFlags, 'P') !== false);

            if ($iType === 'plain') {
                if (is_null($iVal)) { // null means the field should be omitted from the form
                    $span = $xpath->query("//tr[td/label[@for='$iName']]/td/span");
                    $this->assertInstanceOf(DOMNodeList::class, $span);
                    $this->assertCount(0, $span, "There is a form entry for empty plain attr $iName");
                } else {
                    $iNode = $this->getDomNode($xpath, "//tr[td/label[@for='$iName']]/td/span");
                    $this->assertSame($iVal, $iNode->textContent);
                }
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
                    $this->checkAttribute($radioItem, 'required', $iRequired ? 'required' : null);
                }
                $this->assertTrue($valueItemFound, "No radio button with the expected value exists for $iName");
            } else {
                $iNode = $this->getDomNode($xpath, "//input[@name='$iName']");
                $this->checkAttribute($iNode, 'value', $iVal);
                $this->checkAttribute($iNode, 'type', $iType);
                $this->checkAttribute($iNode, 'disabled', $iDisabled ? 'disabled' : null);
                $this->checkAttribute($iNode, 'required', $iRequired ? 'required' : null);
                $this->checkAttribute($iNode, 'pattern', $iPattern ? '' : null, 'exists');
                $this->checkAttribute($iNode, 'placeholder', $iPlaceholder);
            }
        }
    }

    /**
     * @psalm-param 'equals'|'contains'|'containsnot'|'exists' $matchType
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
        $this->assertInstanceOf(DOMNode::class, $attrNode, "expected attribute $attr not present");

        if ($matchType === 'equals') {
            $this->assertSame($val, $attrNode->nodeValue);
        } elseif (strpos($matchType, 'contains') === 0) {
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

    private function setAddressbookStubs(): void
    {
        TestInfrastructure::$infra->webDavResources = [
            'https://test.example.com/books/johndoe/book42/' => $this->makeAbookCollStub(
                'Book 42 SrvName',
                'https://test.example.com/books/johndoe/book42/',
                "Hitchhiker's Guide"
            ),
            'https://test.example.com/books/johndoe/book43/' => $this->makeAbookCollStub(
                'Book 43 SrvName',
                'https://test.example.com/books/johndoe/book43/',
                null
            ),
            'https://carddav.example.com/books/johndoe/book44/' => $this->makeAbookCollStub(
                null,
                'https://carddav.example.com/books/johndoe/book44/',
                null
            ),
            "https://carddav.example.com/shared/Public/" => $this->createStub(WebDavResource::class),
            "https://admonly.example.com/books/johndoe/book61/" => new \Exception('hidden preset was queried'),
        ];
    }

    private function setDiscoveryStub(int $numAbooks, string $baseUrl, string $username, string $password): void
    {
        // create some test addressbooks to be discovered
        $abookObjs = [];
        for ($i = 0; $i < $numAbooks; ++$i) {
            $abookUrl = $baseUrl . $username . "/addressbooks/book$i";
            $abookStub = $this->makeAbookCollStub("Book $i", $abookUrl, "Desc $i");
            $abookObjs[] = $abookStub;
            TestInfrastructure::$infra->webDavResources[$abookUrl] = $abookStub;
        }

        // create a Discovery mock that "discovers" our test addressbooks
        $account = new Account($baseUrl, $username, $password);
        $discovery = $this->createMock(Discovery::class);
        $discovery->expects($this->once())
                  ->method("discoverAddressbooks")
                  ->with($this->equalTo($account))
                  ->will($this->returnValue($abookObjs));
        TestInfrastructure::$infra->discovery = $discovery;
    }

    /**
     * Creates an AddressbookCollection stub that implements getUri() and getName().
     */
    private function makeAbookCollStub(?string $name, string $url, ?string $desc): AddressbookCollection
    {
        $davobj = $this->createStub(AddressbookCollection::class);
        $urlComp = explode('/', rtrim($url, '/'));
        $baseName = $urlComp[count($urlComp) - 1];
        $davobj->method('getName')->will($this->returnValue($name ?? $baseName));
        $davobj->method('getBasename')->will($this->returnValue($baseName));
        $davobj->method('getDisplayname')->will($this->returnValue($name));
        $davobj->method('getDescription')->will($this->returnValue($desc));
        $davobj->method('getUri')->will($this->returnValue($url));
        return $davobj;
    }

    /**
     * @return array<string, list{array<string,string>,?list{PsrLogLevel,string},?string}>
     */
    public function accountSaveFormDataProvider(): array
    {
        $basicData = [
            'accountname' => 'Updated account name',
            'discovery_url' => 'http://updated.discovery.url/',
            'username' => 'upduser',
            'password' => '', // normally the password will not be set and must be ignored in this case
            'rediscover_time' => '5:6:7',
            // template addressbook settings
            'name' => 'Updated name %N - %D', // placeholders must not be replaced when saving
            // active would be omitted when set to off
            'refresh_time' => '0:42',
            'use_categories' => '1',
        ];

        $epfx = 'Error saving account preferences:';
        return [
            'Missing account ID' => [ [], ['warning', 'no account ID found in parameters'], null ],
            'Invalid account ID' => [
                ['accountid' => '123'] + $basicData,
                ['error', "$epfx No carddav account with ID 123"],
                null,
            ],
            "Other user's account ID" => [
                ['accountid' => '101'] + $basicData,
                ['error', "$epfx No carddav account with ID 101"],
                null,
            ],
            'Hidden Preset Account'   => [
                ['accountid' => '45'] + $basicData,
                ['error', "$epfx Account ID 45 refers to a hidden account"],
                null,
            ],
            'Invalid radio button value'   => [
                ['accountid' => '42', 'use_categories' => '2'] + $basicData,
                ['error', "$epfx Invalid value 2 POSTed for use_categories"],
                null,
            ],
            "User-defined account with template addressbook (password not changed)" => [
                // last_discovered must be ignored in the input
                ['accountid' => '42', 'last_discovered' => '123'] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccSave-udefAcc.json'
            ],
            "User-defined account (password changed), template addressbook created" => [
                ['accountid' => '43', 'password' => 'new pass', 'use_categories' => '0'] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccSave-udefAcc-pwChange.json'
            ],
            "Preset account with fixed fields submitted, template abook created" => [
                // template must be ignored in the input
                ['accountid' => '44', 'password' => 'foo', 'active' => '1', 'template' => '0'] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccSave-presetAccFixedFields.json'
            ],
        ];
    }

    /**
     * Tests that an existing account is properly saved when the form is submitted (plugin.carddav.AccSave).
     *
     * - Sent values are properly saved
     *   - Both on / off value of checkboxes (toggles) are correctly evaluated (off = value not sent)
     *   - Radio button values are properly evaluated
     *   - Improperly formatted values (e.g. time strings) cause an error and the account is not saved
     * - For a preset account, fixed fields are not overwritten even if part of the form
     * - The template addressbook is created / updated
     *
     * - Error cases:
     *   - No account ID in POST parameters (error is logged, no action performed)
     *   - Invalid account ID in POST parameters (error is logged, error message sent to client, no action performed)
     *   - Account ID of different user in POST parameters (error is logged, error message sent to client, no action
     *     performed)
     *   - Account ID of addressbook belonging to a hidden preset in POST parameters (error is logged, error message
     *     sent to client, no action performed)
     *
     * @dataProvider accountSaveFormDataProvider
     * @param array<string,string> $postData
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testAccountIsProperlySaved(
        array $postData,
        ?array $errMsgExp,
        ?string $expDbFile
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();
        $rcStub->postInputs = $postData;

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAccSave();

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('confirmation', 'AccAbSave_msg_ok'));
        } else {
            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertTrue($rcStub->checkShownMessages('error', "AccAbSave_msg_fail"));
            }
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }
    /**
     * @return array<string, list{array<string,string>,?list{PsrLogLevel,string},?string}>
     */
    public function abookSaveFormDataProvider(): array
    {
        $basicData = [
            'name' => 'Updated name %N - %D', // placeholders are not replaced when saving addressbook
            'refresh_time' => '0:42',
            'use_categories' => '0',
        ];

        $epfx = 'Error saving addressbook preferences:';
        return [
            'Missing addressbook ID' => [ [], ['warning', 'no addressbook ID found in parameters'], null ],
            'Invalid addressbook ID' => [
                ['abookid' => '123'] + $basicData,
                ['error', "$epfx No carddav addressbook with ID 123"],
                null,
            ],
            "Other user's addressbook ID" => [
                ['abookid' => '101'] + $basicData,
                ['error', "$epfx No carddav addressbook with ID 101"],
                null,
            ],
            'Hidden Preset Addressbook'   => [
                ['abookid' => '61'] + $basicData,
                ['error', "$epfx Account ID 45 refers to a hidden account"],
                null,
            ],
            'Invalid radio button value'   => [
                ['abookid' => '42', 'use_categories' => '2'] + $basicData,
                ['error', "$epfx Invalid value 2 POSTed for use_categories"],
                null,
            ],
            "Addressbook of user-defined account" => [
                // discovered, url and active must be ignored in the input
                ['abookid' => '42', 'url' => 'http://new.url/x', 'active' => '0', 'discovered' => '0'] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AbSave-udefAcc.json'
            ],
            "Preset addressbook with fixed fields submitted" => [
                // name is fixed and must not be changed; refresh_time is not fixed for the extra addressbook
                ['abookid' => '51'] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AbSave-presetAccFixedFields.json'
            ],
            "Preset addressbook with only non-fixed fields submitted (normal case)" => [
                ['abookid' => '51', 'refresh_time' => '0:15', 'use_categories' => '1' ],
                null,
                'tests/Unit/data/uiTest/dbExp-AbSave-presetAcc.json'
            ],
        ];
    }

    /**
     * Tests that an addressbook is properly saved when the form is submitted (plugin.carddav.AbSave).
     *
     * - Sent values are properly saved
     *   - Radio button values are properly evaluated, incl. invalid values
     *   - Improperly formatted values (e.g. time strings) cause an error and the account is not saved
     * - For a preset account, fixed fields are not overwritten even if part of the form
     *
     * - Error cases:
     *   - No addressbook ID in POST parameters (error is logged, no action performed)
     *   - Invalid addressbook ID in POST parameters (error logged, error message sent to client, no action performed)
     *   - Addressbook ID of different user in POST parameters (error is logged, error message sent to client, no action
     *     performed)
     *   - Addressbook ID belonging to a hidden preset in POST parameters (error is logged, error message sent to
     *     client, no action performed)
     *
     * @dataProvider abookSaveFormDataProvider
     * @param array<string,string> $postData
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testAddressbookIsProperlySaved(
        array $postData,
        ?array $errMsgExp,
        ?string $expDbFile
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();
        $rcStub->postInputs = $postData;

        $this->setAddressbookStubs();

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAbSave();

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('confirmation', 'AccAbSave_msg_ok'));
        } else {
            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertTrue($rcStub->checkShownMessages('error', "AccAbSave_msg_fail"));
            }
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }

    /**
     * @return array<string, list{array<string,string>,?list{PsrLogLevel,string},?string,int}>
     */
    public function accountAddFormDataProvider(): array
    {
        $basicData = [
            'accountname' => 'New account',
            'discovery_url' => 'http://cdav.example.com/',
            'username' => 'user',
            'password' => 'pw',
            'rediscover_time' => '5:6:7',
            // template addressbook settings
            'name' => 'Name template %N - %D', // placeholders must not be replaced when saving
            // active would be omitted when set to off
            'refresh_time' => '0:30',
            'use_categories' => '1',
        ];

        $epfx = 'Error creating CardDAV account:';
        return [
            'Missing Discovery URL'   => [
                ['accountname' => 'New account'],
                ['error', "$epfx Cannot discover addressbooks for an account lacking a discovery URI"],
                null,
                0
            ],
            "Two addressbooks discovered (added inactive, with categories)" => [
                $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccAdd-2books.json',
                2
            ],
            "One addressbook discovered (added active, no categories)" => [
                [ 'active' => '1', 'use_categories' => '0' ] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccAdd-1book.json',
                1
            ],
            "No addressbook discovered" => [
                $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccAdd-0books.json',
                0
            ],
        ];
    }

    /**
     * Tests that a new account is properly created when the corresponding form is submitted.
     *
     * - Addressbooks returned by discovery are properly added incl. template addressbook
     * - Discovery returns no addressbooks, account without addressbooks is added (only template addressbook)
     *
     * Error cases:
     *  - Discovery URL not provided
     *
     * @dataProvider accountAddFormDataProvider
     * @param array<string,string> $postData
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testNewAccountIsProperlyCreated(
        array $postData,
        ?array $errMsgExp,
        ?string $expDbFile,
        int $numAbooks
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();
        $rcStub->postInputs = $postData;

        $this->setAddressbookStubs();

        if (is_null($errMsgExp)) {
            $this->setDiscoveryStub(
                $numAbooks,
                $postData['discovery_url'] ?? '',
                $postData['username'],
                $postData['password']
            );
        }

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAccAdd();

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('confirmation', 'AccAdd_msg_ok'));
        } else {
            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertTrue($rcStub->checkShownMessages('error', "AccAbSave_msg_fail"));
            }
        }

        if (is_null($errMsgExp)) {
            $this->fixTimestampCol(['accountname' => 'New account'], 'accounts', 'last_discovered', '0');
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }

    /**
     * @return array<string, list{array<string,string>,?list{PsrLogLevel,string},?string,?array}>
     */
    public function abookToggleFormDataProvider(): array
    {
        $epfx = 'Failure to toggle addressbook activation:';
        return [
            'Missing addressbook ID' => [
                ['active' => '1'],
                ['warning', 'invoked without required HTTP POST inputs'],
                null, // expDbFile
                null, // expClientCommandArgs
            ],
            'Missing active setting ID' => [
                ['abookid' => '42'],
                ['warning', 'invoked without required HTTP POST inputs'],
                null, // expDbFile
                null, // expClientCommandArgs
            ],
            'Invalid addressbook ID' => [
                ['abookid' => '123', 'active' => '1'],
                ['error', "$epfx No carddav addressbook with ID 123"],
                null, // expDbFile
                null, // expClientCommandArgs - no command expected because ID is invalid
            ],
            "Other user's addressbook ID" => [
                ['abookid' => '101', 'active' => '0'],
                ['error', "$epfx No carddav addressbook with ID 101"],
                null, // expDbFile
                null, // expClientCommandArgs - no command expected because this addressbook should not appear in UI
            ],
            'Addressbook of hidden preset account' => [
                ['abookid' => '61', 'active' => '0'],
                ['error', "$epfx Account ID 45 refers to a hidden account"],
                null, // expDbFile
                null, // expClientCommandArgs - no command expected because this addressbook should not appear in UI
            ],
            'Active addressbook where active attribute is fixed (try to deactivate)' => [
                ['abookid' => '51', 'active' => '0'],
                ['error', "$epfx active is a fixed setting for addressbook 51"],
                null, // expDbFile
                ['51', true], // expClientCommandArgs - UI toggle changed to wrong state and must be reset
            ],
            'Active addressbook where active attribute is fixed (try to activate)' => [
                ['abookid' => '51', 'active' => '1'],
                ['error', "$epfx active is a fixed setting for addressbook 51"],
                null, // expDbFile
                ['51', true], // expClientCommandArgs - UI toggle changed to wrong state and must be reset
            ],
            'Deactivate active addressbook' => [
                ['abookid' => '42', 'active' => '0'],
                null,
                'tests/Unit/data/uiTest/dbExp-AbToggleActive-DeactActive.json',
                null, // expClientCommandArgs
            ],
            'Deactivate inactive addressbook' => [
                ['abookid' => '44', 'active' => '0'],
                null,
                'tests/Unit/data/uiTest/db.json',
                null, // expClientCommandArgs
            ],
            'Activate inactive addressbook' => [
                ['abookid' => '44', 'active' => '1'],
                null,
                'tests/Unit/data/uiTest/dbExp-AbToggleActive-ActInactive.json',
                null, // expClientCommandArgs
            ],
            'Activate active addressbook' => [
                ['abookid' => '42', 'active' => '1'],
                null,
                'tests/Unit/data/uiTest/db.json',
                null, // expClientCommandArgs
            ],
        ];
    }

    /**
     * Tests that the AbToggleActive action invoked works properly.
     *
     * - Addressbook is activated (on both active and inactive addressbook)
     * - Addressbook is deactivated (on both active and inactive addressbook)
     *
     * Error cases:
     *   - No addressbook ID in parameters (warning is logged)
     *   - No active value in parameters (warning is logged)
     *
     *   - Invalid addressbook ID in parameters (error is logged, error shown to client)
     *   - Addressbook ID of different user in parameters (error is logged, error shown to client)
     *   - Addressbook ID belonging to a hidden preset in parameters (error is logged, error shown to client)
     *
     *   - Addressbook ID of an addressbook where the active attribute is fixed is sent (error is logged, error shown to
     *     client, toggle is reset on client)
     *
     * @dataProvider abookToggleFormDataProvider
     * @param array<string,string> $postData
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testAddressbookToggleActiveWorksProperly(
        array $postData,
        ?array $errMsgExp,
        ?string $expDbFile,
        ?array $expClientCommandArgs
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();
        $rcStub->postInputs = $postData;

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAbToggleActive();

        $suffix = ($postData['active'] ?? '') === '0' ? '_de' : '';

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('confirmation', "AbToggleActive_msg_ok$suffix"));
        } else {
            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertArrayHasKey('abookid', $postData);
                $this->assertTrue($rcStub->checkShownMessages('error', "AbToggleActive_msg_fail$suffix"));
            }
        }

        if (is_null($expClientCommandArgs)) {
            $this->assertCount(0, $rcStub->sentCommands);
        } else {
            $this->assertCount(1, $rcStub->sentCommands);
            $this->assertSame('carddav_AbResetActive', $rcStub->sentCommands[0][0]);
            $this->assertSame($expClientCommandArgs, $rcStub->sentCommands[0][1]);
        }
        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }

    /**
     * @return array<string, list{?string,?list{PsrLogLevel,string},?string}>
     */
    public function accountDeleteFormDataProvider(): array
    {
        $epfx = 'Error removing account:';
        return [
            'Missing account ID' => [ null, ['warning', 'no account ID found in parameters'], null ],
            'Invalid account ID' => [ '123', ['error', "$epfx No carddav account with ID 123"], null ],
            "Other user's account ID" => [ '101', ['error', "$epfx No carddav account with ID 101"], null ],
            'Hidden Preset Account' => [ '45', ['error', "$epfx Account ID 45 refers to a hidden account"], null ],
            'Preset Account' => [ '44', ['error', "$epfx Only the administrator can remove preset accounts"], null ],
            "User-defined account" => [ '42', null, 'tests/Unit/data/uiTest/dbExp-AccRm-udefAcc.json' ],
        ];
    }

    /**
     * Tests that an existing account is properly deleted when requested from UI.
     *
     * - User-defined account incl. all addressbooks is removed
     *
     * - Error cases:
     *   - No account ID in parameters (error is logged, no action performed)
     *   - Invalid account ID in parameters (error is logged, error message sent to client, no action performed)
     *   - Account ID of different user in parameters (error is logged, error message sent to client, no action
     *     performed)
     *   - Account ID of addressbook belonging to a preset in parameters (error is logged, error message sent to
     *     client, no action performed)
     *
     * @dataProvider accountDeleteFormDataProvider
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testAccountIsProperlyRemoved(
        ?string $accountId,
        ?array $errMsgExp,
        ?string $expDbFile
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();

        if (is_string($accountId)) {
            $rcStub->postInputs['accountid'] = $accountId;
        }

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAccRm();

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('confirmation', 'AccRm_msg_ok'));

            $this->assertCount(1, $rcStub->sentCommands);
            $this->assertSame('carddav_RemoveListElem', $rcStub->sentCommands[0][0]);
            $this->assertSame([$accountId], $rcStub->sentCommands[0][1]);
        } else {
            $this->assertCount(0, $rcStub->sentCommands);

            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertTrue($rcStub->checkShownMessages('error', "AccRm_msg_fail"));
            }
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }

    /**
     * @return array<string, list{?string,?list{PsrLogLevel,string},?string}>
     */
    public function accountRediscoverFormDataProvider(): array
    {
        $epfx = 'Error in account rediscovery:';
        return [
            'Missing account ID' => [ null, ['warning', 'no account ID found in parameters'], null ],
            'Invalid account ID' => [ '123', ['error', "$epfx No carddav account with ID 123"], null ],
            "Other user's account ID" => [ '101', ['error', "$epfx No carddav account with ID 101"], null ],
            'Hidden Preset Account' => [ '45', ['error', "$epfx Account ID 45 refers to a hidden account"], null ],
            "User-defined account" => [ '42', null, 'tests/Unit/data/uiTest/dbExp-AccRedisc-udefAcc.json' ],
        ];
    }

    /**
     * Tests that an existing account is properly rediscovered when requested from UI
     *
     * - Account with discovery_url is rediscovered
     *
     * - Error cases:
     *   - No account ID in parameters (error is logged, no action performed)
     *   - Invalid account ID in parameters (error is logged, error message sent to client, no action performed)
     *   - Account ID of different user in parameters (error is logged, error message sent to client, no action
     *     performed)
     *   - Account ID of addressbook belonging to a hidden preset in parameters (error is logged, error message sent to
     *     client, no action performed)
     *   - Rediscovery for account without rediscovery URL is requested
     *
     * @dataProvider accountRediscoverFormDataProvider
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testAccountIsProperlyRediscovered(
        ?string $accountId,
        ?array $errMsgExp,
        ?string $expDbFile
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();

        if (is_string($accountId)) {
            $rcStub->postInputs['accountid'] = $accountId;
        }

        if (is_string($expDbFile)) {
            $this->setDiscoveryStub(2, 'https://test.example.com/', 'johndoe', 'The password');
        }

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAccRedisc();

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('confirmation', 'AccRedisc_msg_ok'));

            $this->assertCount(2, $rcStub->sentCommands);
            $this->assertSame('carddav_RemoveListElem', $rcStub->sentCommands[0][0]);
            $this->assertEqualsCanonicalizing([$accountId, ['42','43','44']], $rcStub->sentCommands[0][1]);
            $this->assertSame('carddav_InsertListElem', $rcStub->sentCommands[1][0]);
            $this->assertCount(1, $rcStub->sentCommands[1][1]);
            $this->assertIsArray($rcStub->sentCommands[1][1][0]);
            $this->assertCount(2, $rcStub->sentCommands[1][1][0]); // two inserted expected
            // InsertListElem args not checked in detail because of complexity
        } else {
            $this->assertCount(0, $rcStub->sentCommands);

            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertTrue($rcStub->checkShownMessages('error', "AccRedisc_msg_fail"));
            }
        }

        if (is_null($errMsgExp)) {
            $this->assertIsString($accountId);
            $this->fixTimestampCol($accountId, 'accounts', 'last_discovered', '5555');
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }

    /**
     * @return array<string, list{array<string,string>,?list{PsrLogLevel,string},?string}>
     */
    public function abookResyncFormDataProvider(): array
    {
        $epfxS = 'Failed to sync (AbSync) addressbook:';
        $epfxC = 'Failed to sync (AbClrCache) addressbook:';
        return [
            'Missing addressbook ID' => [
                ['synctype' => 'AbSync'],
                ['warning', 'missing or unexpected values for HTTP POST parameters'],
                null
            ],
            'Missing sync type' => [
                ['abookid' => '42'],
                ['warning', 'missing or unexpected values for HTTP POST parameters'],
                null
            ],
            'Invalid sync type' => [
                ['abookid' => '42', 'synctype' => 'foo'],
                ['warning', 'missing or unexpected values for HTTP POST parameters'],
                null
            ],

            'Invalid addressbook ID' => [
                ['abookid' => '123', 'synctype' => 'AbSync'],
                ['error', "$epfxS No carddav addressbook with ID 123"],
                null
            ],
            "Other user's addressbook ID" => [
                ['abookid' => '101', 'synctype' => 'AbClrCache'],
                ['error', "$epfxC No carddav addressbook with ID 101"],
                null
            ],
            'Hidden Preset Account' => [
                ['abookid' => '61', 'synctype' => 'AbSync'],
                ['error', "$epfxS Account ID 45 refers to a hidden account"],
                null
            ],

            "User-defined addressbook resync" => [
                ['abookid' => '42', 'synctype' => 'AbSync'],
                null,
                'tests/Unit/data/uiTest/dbExp-AbSync-udefAcc.json'
            ],

            "User-defined addressbook clear cache" => [
                ['abookid' => '42', 'synctype' => 'AbClrCache'],
                null,
                'tests/Unit/data/uiTest/dbExp-AbClrCache-udefAcc.json'
            ],
        ];
    }

    /**
     * Tests that an addressbook is properly resynced / cache cleared when requested from UI
     *
     * - Valid addressbook resynced
     * - Cache clear on valid addressbook
     *
     * - Error cases:
     *   - No abook ID in parameters (warning is logged, no action performed)
     *   - No sync type in parameters (warning is logged, no action performed)
     *   - Invalid sync type (warning is logged, no action performed)
     *   - Invalid abook ID in parameters (error is logged, error message sent to client, no action performed)
     *   - Abook ID of different user in parameters (error is logged, error message sent to client, no action
     *     performed)
     *   - ID of addressbook belonging to a hidden preset in parameters (error is logged, error message sent to
     *     client, no action performed)
     *
     * @dataProvider AbookResyncFormDataProvider
     * @param array<string,string> $postData
     * @param ?list{PsrLogLevel,string} $errMsgExp
     */
    public function testAddressbookIsProperlyResynced(
        array $postData,
        ?array $errMsgExp,
        ?string $expDbFile
    ): void {
        $this->db = new JsonDatabase(['tests/Unit/data/uiTest/db.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/uiTest/config.inc.php');
        $logger = TestInfrastructure::logger();
        $infra = TestInfrastructure::$infra;
        $rcStub = $infra->rcTestAdapter();

        $rcStub->postInputs = $postData;
        $syncType = $postData['synctype'] ?? '';

        $this->setAddressbookStubs();

        $sync = $this->createMock(Sync::class);
        $infra->sync = $sync;

        if (is_null($errMsgExp) && $syncType === 'AbSync') {
            $this->assertSame('42', $postData['abookid'], 'Currently test is hardcoded for abook 42');
            $this->assertIsArray($infra->webDavResources);
            $this->assertArrayHasKey('https://test.example.com/books/johndoe/book42/', $infra->webDavResources);
            $abookObj = $infra->webDavResources['https://test.example.com/books/johndoe/book42/'];
            $sync->expects($this->once())
                 ->method('synchronize')
                 ->with($this->equalTo($abookObj), $this->anything(), $this->anything(), $this->equalTo('sync@3600'))
                 ->will($this->returnValue('sync@resynctime'));
        } else {
            $sync->expects($this->never())->method('synchronize');
        }

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);
        $ui->actionAbSync();

        if (is_null($errMsgExp)) {
            $this->assertIsString($expDbFile, 'for non-error cases, we need an expected database file to compare with');
            $this->assertTrue($rcStub->checkShownMessages('notice', "${syncType}_msg_ok"));

            $this->assertCount(1, $rcStub->sentCommands);
            $this->assertSame('carddav_UpdateForm', $rcStub->sentCommands[0][0]);
            $this->assertCount(1, $rcStub->sentCommands[0][1]);
            $this->assertIsArray($rcStub->sentCommands[0][1][0]);
            $this->assertArrayHasKey('last_updated', $rcStub->sentCommands[0][1][0]);
        } else {
            $this->assertCount(0, $rcStub->sentCommands);

            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
            if ($errMsgExp[0] === 'error') {
                $this->assertTrue($rcStub->checkShownMessages('error', "${syncType}_msg_fail"));
            }
        }

        if (is_null($errMsgExp) && (($postData['synctype'] ?? '') === 'AbSync')) {
            $this->assertArrayHasKey('abookid', $postData);
            // Before comparing, we need to fix the last_updated timestamp as it depends on the current time
            $this->fixTimestampCol($postData['abookid'], 'addressbooks', 'last_updated', '4242');
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
        $dbAfter->compareTables('contacts', $this->db);
        $dbAfter->compareTables('groups', $this->db);
        $dbAfter->compareTables('group_user', $this->db);
        $dbAfter->compareTables('xsubtypes', $this->db);
    }

    /**
     * @param DbConditions $conditions Selects the row to lookup.
     */
    private function fixTimestampCol($conditions, string $tbl, string $col, string $val): void
    {
        $row = $this->db->lookup($conditions, [], $tbl);
        $this->assertArrayHasKey('id', $row);
        $this->assertIsString($row['id']);
        $this->assertArrayHasKey($col, $row);
        $this->assertLessThan(2, time() - intval($row[$col])); // two seconds grace period
        $this->db->update($row['id'], [ $col ], [ $val ], $tbl);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
