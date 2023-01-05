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
use Psr\Log\LogLevel;
use MStilkerich\CardDavClient\{AddressbookCollection,WebDavResource};
use MStilkerich\RCMCardDAV\Frontend\{AddressbookManager,UI};
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
use PHPUnit\Framework\TestCase;

/**
 * Tests parts of the AdminSettings class using test data in JsonDatabase.
 * @psalm-type LogLevel = LogLevel::*
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
            "Visible Preset account without template addressbook" => [
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
     * @return array<string, list{?string, ?string, list<list{string,?string,string,bool}>}>
     */
    public function abookIdProvider(): array
    {
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
                    //  name             val                          type        disabled?
                    [ 'abookid',         '42',                        'hidden',   false ],
                    [ 'name',            'Basic contacts',            'text',     false ],
                    [ 'url',             'https://test.example.com/books/johndoe/book42/', 'plain', false ],
                    [ 'refresh_time',    '00:06:00',                  'text',     false ],
                    [ 'last_updated',    date("Y-m-d H:i:s", 1672825164), 'plain', false ],
                    [ 'use_categories',  '1',                         'radio',    false ],
                    [ 'srvname',         'Book 42 SrvName',           'plain',    false ],
                    [ 'srvdesc',         "Hitchhiker's Guide",        'plain',    false ],
                ]
            ],
            "Preset extra addressbook with custom fixed fields" => [
                '51',
                null,
                [
                    //  name             val                          type        disabled?
                    [ 'abookid',         '51',                        'hidden',   false ],
                    [ 'name',            'Public readonly contacts',  'text',     true ],
                    [ 'url',             'https://carddav.example.com/shared/Public/', 'plain', false ],
                    [ 'refresh_time',    '01:00:00',                  'text',     false ],
                    [ 'last_updated',    'DateTime_never_lbl',        'plain',    false ],
                    [ 'use_categories',  '0',                         'radio',    false ],
                    [ 'srvname',         null,                        'plain',    false ],
                    [ 'srvdesc',         null,                        'plain',    false ],
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
     * @param list<list{string,?string,string,bool}> $checkInputs
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

        $infra->webDavResources = [
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

        $abMgr = new AddressbookManager();
        $ui = new UI($abMgr);

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
     * @param list<list{string,?string,string,bool}> $checkInputs
     */
    private function checkInput(DOMDocument $dom, array $checkInputs): void
    {
        $xpath = new DOMXPath($dom);

        foreach ($checkInputs as $checkInput) {
            [ $iName, $iVal, $iType, $iDisabled ] = $checkInput;

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
                }
                $this->assertTrue($valueItemFound, "No radio button with the expected value exists for $iName");
            } else {
                $iNode = $this->getDomNode($xpath, "//input[@name='$iName']");
                $this->checkAttribute($iNode, 'value', $iVal);
                $this->checkAttribute($iNode, 'type', $iType);
                $this->checkAttribute($iNode, 'disabled', $iDisabled ? 'disabled' : null);
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
        $this->assertInstanceOf(DOMNode::class, $attrNode, "expected attribute $attr not present");

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
     * @return array<string, list{array<string,string>,?list{LogLevel,string},?string}>
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
            "User-defined account with template addressbook (password not changed)" => [
                ['accountid' => '42'] + $basicData,
                null,
                'tests/Unit/data/uiTest/dbExp-AccSave-udefAcc.json'
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
     *   - Mandatory fields missing in POST data
     *
     * @dataProvider accountSaveFormDataProvider
     * @param array<string,string> $postData
     * @param ?list{LogLevel,string} $errMsgExp
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
        } else {
            // data must not be modified
            $expDbFile = 'tests/Unit/data/uiTest/db.json';
            $logger->expectMessage($errMsgExp[0], $errMsgExp[1]);
        }

        $dbAfter = new JsonDatabase([$expDbFile]);
        $dbAfter->compareTables('accounts', $this->db);
        $dbAfter->compareTables('addressbooks', $this->db);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
