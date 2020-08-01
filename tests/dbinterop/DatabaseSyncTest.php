<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\{Database};

final class DatabaseSyncTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
    }

    public function setUp(): void
    {
    }

    public function dbProvider(): array
    {
        return DatabaseAccounts::ACCOUNTS;
    }

    /** @dataProvider dbProvider */
    public function testAllAddressbooksCanBeDiscovered(string $db_dsnw, string $db_prefix): void
    {
        $rcconfig = \rcube::get_instance()->config;
        $rcconfig->set("db_prefix", $db_prefix, false);
        $dbh = \rcube_db::factory($db_dsnw);
        /** @var \Psr\Log\LoggerInterface */
        $logger = TestInfrastructure::$logger;
        Database::init($logger, $dbh);

        $records = Database::get("1");
        echo $records["surname"] . "\n";

        $this->assertArrayHasKey('surname', $records);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
