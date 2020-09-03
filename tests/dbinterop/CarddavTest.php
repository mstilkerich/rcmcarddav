<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\{Database};
use Psr\Log\NullLogger;

final class CarddavTest extends TestCase
{
    /** @var \carddav */
    private static $plugin;

    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();

        $_SESSION["user_id"] = "1";

        $dbsettings = self::dbSettings();
        $db_dsnw = $dbsettings[0];
        self::initDatabase($db_dsnw);

        $dbh = Database::getDbHandle();
        TestData::initDatabase();

        /** @var \Psr\Log\LoggerInterface */
        $logger = TestInfrastructure::$logger;
        self::$plugin = new \carddav(\rcube_plugin_api::get_instance());
        self::$plugin->init([ "logger" => $logger, "logger_http" => $logger ]);
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function testProvidesCorrectListOfAddressbooks(): void
    {
        $res = self::$plugin->listAddressbooks(["sources" => ["foobar" => []] ]);

        $this->assertArrayHasKey("foobar", $res["sources"], "Other addressbooks not preserved in list");

        foreach (TestData::$data["carddav_addressbooks"] as $abookrow) {
            $this->assertArrayHasKey("id", $abookrow);
            $id = "carddav_" . $abookrow["id"];
            $this->assertArrayHasKey($id, $res["sources"]);

            $this->assertEquals($abookrow[0], $res["sources"][$id]["name"]);
            $this->assertFalse($res["sources"][$id]["readonly"]);
            $this->assertTrue($res["sources"][$id]["autocomplete"]);
            $this->assertTrue($res["sources"][$id]["groups"]);
        }
    }

    private static function dbSettings(): array
    {
        return DatabaseAccounts::ACCOUNTS[$GLOBALS["TEST_DBTYPE"]];
    }

    private static function initDatabase(string $db_dsnw, string $db_prefix = ""): void
    {
        $rcconfig = \rcube::get_instance()->config;
        $rcconfig->set("db_prefix", $db_prefix, false);
        $dbh = \rcube_db::factory($db_dsnw);
        /** @var \Psr\Log\LoggerInterface */
        $logger = TestInfrastructure::$logger;
        Database::init($logger, $dbh);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
