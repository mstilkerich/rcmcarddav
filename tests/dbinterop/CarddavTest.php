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

namespace MStilkerich\Tests\RCMCardDAV\DBInteroperability;

use MStilkerich\CardDavClient\{Account,AddressbookCollection,WebDavResource,WebDavCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
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
    /** @var TestData */
    private static $testData;

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

        // Setup test data in the  DB
        self::$testData = new TestData(TestInfrastructureDB::getDbHandle());
        self::$testData->initDatabase();
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    public function testProvidesCorrectListOfAddressbooks(): void
    {
        $plugin = new carddav(\rcube_plugin_api::get_instance());
        $plugin->init();

        $pseudoAbook = [ "id" => "foo", "name" => "foo", "groups" => true, "autocomplete" => true, "readonly" => true ];
        $res = $plugin->listAddressbooks(["sources" => ["foobar" => $pseudoAbook]]);

        $this->assertArrayHasKey("foobar", $res["sources"], "Other addressbooks not preserved in list");

        foreach (TestData::INITDATA["carddav_addressbooks"] as $idx => $abookrow) {
            $id = "carddav_" . self::$testData->getRowId("carddav_addressbooks", $idx);

            $this->assertArrayHasKey($id, $res["sources"], print_r($res, true));
            $this->assertEquals($abookrow[0], $res["sources"][$id]["name"]);
            $this->assertFalse($res["sources"][$id]["readonly"]);
            $this->assertTrue($res["sources"][$id]["autocomplete"]);
            $this->assertTrue($res["sources"][$id]["groups"]);
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
