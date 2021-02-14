<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use carddav;

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
        self::$plugin = new carddav(\rcube_plugin_api::get_instance());
        self::$plugin->init();
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
