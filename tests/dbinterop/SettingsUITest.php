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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\CardDavClient\{Account,AddressbookCollection,WebDavResource,WebDavCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\CardDavAddressbook4Roundcube\Frontend\{AddressbookManager,SettingsUI};
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use carddav;

/**
 * @psalm-type AddressbookSettings = array{
 *   accountname?: string,
 *   username?: string,
 *   password?: string,
 *   discovery_url?: string,
 *   refresh_time?: string,
 *   active?: "1",
 *   use_categories?: "1",
 *   delete?: "1",
 *   resync?: "1"
 * }
 */
final class SettingsUITest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Set a session password so that encryption/decryption of password with scheme "encrypted" works
        $rcube = \rcube::get_instance();
        $_SESSION['password'] = $rcube->encrypt('theRoundcubePassword');

        // Setup the roundcube database handle
        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        $db = TestInfrastructureDB::initDatabase($db_dsnw);

        // Initializes the infrastructure (central Config class with test-specific implementation)
        TestInfrastructure::init($db);
    }

    public function setUp(): void
    {
        $rc = TestInfrastructure::$infra->rcTestAdapter();
        $rc->postInputs = [];

        // reset DB data after each test
        //TestData::initDatabase();
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
        TestInfrastructure::$infra->discovery = null;
    }

    /** @return array<string, array{?class-string<WebDavResource>,int,AddressbookSettings,bool,string}> */
    public function newAbookSettingsProvider(): array
    {
        $base = [
            'accountname' => 'ExampleAcc',
            'username' => 'testNewAddressbookIsCorrectlySaved',
            'password' => 'thePassword',
            'discovery_url' => 'https://example.com',
        ];

        return [
            '1 new with empty refresh time' => [ null, 1, ['active' => '1'] + $base, false, "3600" ],
            '2 new inactive ones' => [ null, 2, ['refresh_time' => '1:02:3'] + $base, false, "3723" ],
            'Direct URI to user addressbook' => [
                AddressbookCollection::class,
                2,
                ['active' => '1', 'discovery_url' => 'https://www.example.com/dav/abook1'] + $base,
                false, // all user addressbooks should be discovered
                "3600"
            ],
            'URI to non-addressbook resource' => [
                WebDavCollection::class,
                2,
                ['active' => '1', 'discovery_url' => 'https://www.example.com/dav'] + $base,
                false, // all user addressbooks should be discovered
                "3600"
            ],
            'URI to non-personal addressbook (public/shared)' => [
                AddressbookCollection::class,
                2,
                ['active' => '1', 'discovery_url' => 'https://www.example.com/public/book'] + $base,
                true, // only the non-personal addressbook should be discovered
                "3600"
            ],
            'Direct URI to user addressbook with different host' => [
                AddressbookCollection::class,
                2,
                ['active' => '1', 'discovery_url' => 'https://dav.example.com/dav/abook1'] + $base,
                false, // all user addressbooks should be discovered
                "3600"
            ],
            'URI to non-personal addressbook (public/shared) with different host' => [
                AddressbookCollection::class,
                2,
                ['active' => '1', 'discovery_url' => 'https://dav.example.com/public/book'] + $base,
                true, // only the non-personal addressbook should be discovered
                "3600"
            ],
            'URI to non-personal addressbook (public/shared) without protocol in URI' => [
                AddressbookCollection::class,
                2,
                ['active' => '1', 'discovery_url' => 'dav.example.com/public/book'] + $base,
                true, // only the non-personal addressbook should be discovered
                "3600"
            ],
        ];
    }

    /**
     * Tests that new addressbooks are properly created in the database as posted from a preferences page.
     *
     * The result of the Discovery service is emulated to provide a test-vector-dependent number of addressbooks under
     * the URL https://www.example.com/dav/abook<Index>.
     *
     * @param ?class-string<WebDavResource> $uriResType
     *   The type of the discovery URI WebDavResource, null to throw Exception
     * @param int $numAbooks The number of addressbooks that the discovery stub shall "discover"
     * @param AddressbookSettings $settings The settings for the addressbook, e.g. as determined from preferences.
     * @param bool $isSharedAbook True if the URI points to an addressbook outside the user's addressbook home
     * @param string $expRt The expected refresh_time in the data base (result of conversion of the user-entered time)
     * @dataProvider newAbookSettingsProvider
     */
    public function testNewAddressbookIsCorrectlySaved(
        ?string $uriResType,
        int $numAbooks,
        array $settings,
        bool $isSharedAbook,
        string $expRt
    ): void {
        /*
        // create some test addressbooks to be discovered
        $abookObjs = [];
        for ($i = 0; $i < $numAbooks; ++$i) {
            $abookObjs[] = $this->makeAddressbookStub("New $i", "https://www.example.com/dav/abook$i");
        }

        // create a Discovery mock that "discovers" our test addressbooks
        $username = $settings['username'] ?? '';
        $password = $settings['password'] ?? '';
        $discoveryUrl = $settings['discovery_url'] ?? '';
        if (!str_contains($discoveryUrl, '://')) {
            $discoveryUrl = "https://$discoveryUrl";
        }
        $account = new Account($discoveryUrl, $username, $password);
        $discovery = $this->createMock(Discovery::class);
        $discovery->expects($this->once())
            ->method("discoverAddressbooks")
            ->with($this->equalTo($account))
            ->will($this->returnValue($abookObjs));
        TestInfrastructure::$infra->discovery = $discovery;

        // create a stub to return for the discovery URI resource
        if (isset($uriResType)) {
            if ($uriResType == AddressbookCollection::class) {
                $discResource = $this->makeAddressbookStub("Shared Abook", $discoveryUrl);

                // a shared addressbook should be the only thing discovered
                if ($isSharedAbook) {
                    $abookObjs = [$discResource];
                }
            } else {
                $discResource = $this->createStub($uriResType);
                $discResource->method('getUri')->will($this->returnValue($discoveryUrl));
            }

            TestInfrastructure::$infra->webDavResource = $discResource;
        } else {
            TestInfrastructure::$infra->webDavResource = new \Exception("Emulate non-accessible WebDAV resource");
        }

        // Set data for POST form
        $this->setPOSTforAbook("new", $settings);

        // Run the test object
        $abMgr = new AddressbookManager();
        $ui = new SettingsUI($abMgr);

        $args = [ 'section' => 'cd_preferences' ];
        $args = $ui->savePreferences($args);
        $this->assertArrayNotHasKey('abort', $args);
        $this->assertArrayNotHasKey('message', $args);

        // check DB record
        $db = TestInfrastructure::$infra->db();
        $abooks = $db->get(['username' => $username], [], 'addressbooks', ['order' => ['name']]);

        $this->assertCount(count($abookObjs), $abooks, "Expected number of new addressbooks not found in DB");

        foreach ($abookObjs as $i => $davobj) {
            $this->assertSame("ExampleAcc ({$davobj->getName()})", $abooks[$i]['name'], "Unexpected setting for name");
            $this->assertSame($username, $abooks[$i]['username'], "Unexpected setting for username");
            $this->assertStringStartsWith('{ENCRYPTED}', $abooks[$i]['password'] ?? "");
            $this->assertSame($davobj->getUri(), $abooks[$i]['url'], "Unexpected setting for url");
            $this->assertSame($settings["active"] ?? "0", $abooks[$i]['active'], "Unexpected setting for active");
            $this->assertSame($_SESSION["user_id"], $abooks[$i]['user_id'], "Unexpected setting for user_id");
            $this->assertSame("0", $abooks[$i]['last_updated'], "Unexpected setting for last_updatee");
            $this->assertSame($expRt, $abooks[$i]['refresh_time'], "Unexpected setting for refresh_time");
            $this->assertSame("", $abooks[$i]['sync_token'], "Unexpected setting for sync_token");
            $this->assertNull($abooks[$i]['presetname'], "Unexpected setting for presetname");
            $this->assertSame(
                $settings["use_categories"] ?? "0",
                $abooks[$i]['use_categories'],
                "Unexpected setting for use_categories"
            );
        }

        TestInfrastructure::$infra->discovery = null;
        TestInfrastructure::$infra->webDavResource = null;
         */
    }

    /**
     * @param AddressbookSettings $settings
     */
    private function setPOSTforAbook(string $id, array $settings): void
    {
        $rc = TestInfrastructure::$infra->rcTestAdapter();

        foreach ($settings as $k => $v) {
            $rc->postInputs["${id}_cd_$k"] = $v;
        }
    }

    private function makeAddressbookStub(string $name, string $url): AddressbookCollection
    {
        $davobj = $this->createStub(AddressbookCollection::class);
        $davobj->method('getName')->will($this->returnValue($name));
        $davobj->method('getUri')->will($this->returnValue($url));
        return $davobj;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
