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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database,AbstractDatabase};
use PHPUnit\Framework\TestCase;
use rcube_db;

final class TestInfrastructureDB
{
    /** @var ?rcube_db The roundcube database handle */
    private static $dbh;

    public static function initDatabase(string $db_dsnw, string $db_prefix = ""): AbstractDatabase
    {
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
        TestCase::assertIsString($GLOBALS["TEST_DBTYPE"]);
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
