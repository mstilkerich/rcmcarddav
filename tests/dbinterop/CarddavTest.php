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

use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use carddav;

/**
 * @psalm-type AddressbookSettings = array{
 *   name?: string,
 *   username?: string,
 *   password?: string,
 *   url?: string,
 *   refresh_time?: string,
 *   active?: "1",
 *   use_categories?: "1",
 *   delete?: "1",
 *   resync?: "1"
 * }
 */
final class CarddavTest extends TestCase
{
    /** @var \carddav */
    private static $plugin;

    public static function setUpBeforeClass(): void
    {
        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        $db = TestInfrastructureDB::initDatabase($db_dsnw);
        TestInfrastructure::init($db);

        TestData::initDatabase();

        // Note: unfortunately, we can only instantiate the plugin once, because state inside roundcube will be
        // initialized that we cannot reset. Therefore, we have to be careful in these tests concerning state inside the
        // plugin object that is shared among all tests
        self::$plugin = new carddav(\rcube_plugin_api::get_instance());
        self::$plugin->init();
    }

    public function setUp(): void
    {
        // remove cached addressbooks list from plugin object
        TestInfrastructure::setPrivateProperty(self::$plugin, 'abooksDb', null);
        // reset DB data after each test
        TestData::initDatabase();
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
        TestInfrastructure::$infra->discovery = null;
    }

    public function testProvidesCorrectListOfAddressbooks(): void
    {
        $pseudoAbook = [ "id" => "foo", "name" => "foo", "groups" => true, "autocomplete" => true, "readonly" => true ];
        $res = self::$plugin->listAddressbooks(["sources" => ["foobar" => $pseudoAbook] ]);

        $this->assertArrayHasKey("foobar", $res["sources"], "Other addressbooks not preserved in list");

        foreach (TestData::$data["carddav_addressbooks"] as $abookrow) {
            $this->assertTrue(isset($abookrow["id"]));
            $id = "carddav_" . $abookrow["id"];
            $this->assertArrayHasKey($id, $res["sources"]);

            $this->assertEquals($abookrow[0], $res["sources"][$id]["name"]);
            $this->assertFalse($res["sources"][$id]["readonly"]);
            $this->assertTrue($res["sources"][$id]["autocomplete"]);
            $this->assertTrue($res["sources"][$id]["groups"]);
        }
    }

    /** @return array<string, array{int,AddressbookSettings,string}> */
    public function newAbookSettingsProvider(): array
    {
        $base = [
            'name' => 'ExampleAcc',
            'username' => 'testNewAddressbookIsCorrectlySaved',
            'password' => 'thePassword',
            'url' => 'https://example.com',
            'active' => "1",
        ];

        return [
            '1 New with empty refresh time' => [ 1, ['active' => '1'] + $base, "3600" ],
            '2 New inactive ones' => [ 2, ['refresh_time' => '1:02:3'] + $base, "3723" ],
        ];
    }

    /**
     * @param AddressbookSettings $settings
     * @dataProvider newAbookSettingsProvider
     */
    public function testNewAddressbookIsCorrectlySaved(int $numAbooks, array $settings, string $expRt): void
    {
        // create some test addressbooks
        $abookObjs = [];
        for ($i = 0; $i < $numAbooks; ++$i) {
            $abookObjs[] = $this->makeAddressbookStub("New $i", "https://www.example.com/dav/abook$i");
        }

        // create a Discovery mock that "discovers" our test addressbooks
        $username = $settings['username'] ?? '';
        $password = $settings['password'] ?? '';
        $account = new Account($settings['url'] ?? '', $username, $password);
        $discovery = $this->createMock(Discovery::class);
        $discovery->expects($this->once())
            ->method("discoverAddressbooks")
            ->with($this->equalTo($account))
            ->will($this->returnValue($abookObjs));
        TestInfrastructure::$infra->discovery = $discovery;

        // Set data for POST form
        $this->setPOSTforAbook("new", $settings);

        // Run the test object
        $args = [ 'section' => 'cd_preferences' ];
        $args = self::$plugin->savePreferences($args);
        $this->assertArrayNotHasKey('abort', $args);
        $this->assertArrayNotHasKey('message', $args);

        // check DB record
        $db = TestInfrastructure::$infra->db();
        $abooks = $db->get(['username' => $username], [], 'addressbooks', ['order' => ['name']]);
        $this->assertCount(count($abookObjs), $abooks, "Expected number of new addressbooks not found in DB");

        foreach ($abookObjs as $i => $davobj) {
            $this->assertSame("ExampleAcc ({$davobj->getName()})", $abooks[$i]['name']);
            $this->assertSame($username, $abooks[$i]['username']);
            $this->assertStringStartsWith('{DES_KEY}', $abooks[$i]['password'] ?? "");
            $this->assertSame($davobj->getUri(), $abooks[$i]['url']);
            $this->assertSame($settings["active"] ?? "0", $abooks[$i]['active']);
            $this->assertSame($_SESSION["user_id"], $abooks[$i]['user_id']);
            $this->assertSame("0", $abooks[$i]['last_updated']);
            $this->assertSame($expRt, $abooks[$i]['refresh_time']);
            $this->assertSame("", $abooks[$i]['sync_token']);
            $this->assertNull($abooks[$i]['presetname']);
            $this->assertSame($settings["use_categories"] ?? "0", $abooks[$i]['use_categories']);
        }

        TestInfrastructure::$infra->discovery = null;
    }

    /**
     * @param AddressbookSettings $settings
     */
    private function setPOSTforAbook(string $id, array $settings): void
    {
        foreach ($settings as $k => $v) {
            $_POST["${id}_cd_$k"] = $v;
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
