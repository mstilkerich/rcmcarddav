<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database,AbstractDatabase};
use rcube_db;

final class TestInfrastructureDB
{
    /** @var ?rcube_db The roundcube database handle */
    private static $dbh;

    public static function initDatabase(string $db_dsnw, string $db_prefix = ""): AbstractDatabase
    {
        $_SESSION["user_id"] = "1";

        $rcconfig = \rcube::get_instance()->config;
        $rcconfig->set("db_prefix", $db_prefix, false);
        $rcconfig->set("db_dsnw", $db_dsnw, false);
        self::$dbh = rcube_db::factory($db_dsnw);
        $logger = TestInfrastructure::logger();
        $db = new Database($logger, self::$dbh);
        return $db;
    }

    /** @return list<string> */
    public static function dbSettings(): array
    {
        return DatabaseAccounts::ACCOUNTS[$GLOBALS["TEST_DBTYPE"]];
    }

    public static function getDbHandle(): rcube_db
    {
        if (isset(self::$dbh)) {
            return self::$dbh;
        }

        throw new \Exception('Call TestInfrastructureDB::initDatabase() first');
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
