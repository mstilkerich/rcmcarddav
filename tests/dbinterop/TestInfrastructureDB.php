<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use Psr\Log\LoggerInterface;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\{Database};

final class TestInfrastructureDB
{
    public static function initDatabase(string $db_dsnw, string $db_prefix = ""): Database
    {
        $_SESSION["user_id"] = "1";

        $rcconfig = \rcube::get_instance()->config;
        $rcconfig->set("db_prefix", $db_prefix, false);
        $rcconfig->set("db_dsnw", $db_dsnw, false);
        $dbh = \rcube_db::factory($db_dsnw);
        /** @var \Psr\Log\LoggerInterface */
        $logger = TestInfrastructure::$logger;
        $db = new Database($logger, $dbh);
        return $db;
    }

    public static function dbSettings(): array
    {
        return DatabaseAccounts::ACCOUNTS[$GLOBALS["TEST_DBTYPE"]];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
