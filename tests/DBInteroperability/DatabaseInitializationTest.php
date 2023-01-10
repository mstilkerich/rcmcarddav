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

use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\RCMCardDAV\Db\AbstractDatabase;

final class DatabaseInitializationTest extends TestCase
{
    private const SCRIPTDIR = __DIR__ . "/../../dbmigrations";

    /** @var AbstractDatabase Database used for the schema initialization test */
    private static $db;

    public static function setUpBeforeClass(): void
    {
        self::resetRcubeDb();
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();

        // We need to create a new rcube_db instance after each test (technically, each test that creates or drops
        // tables) so that the rcube_db::list_tables() cache is cleared. This is particularly important after the first
        // migration which creates the carddav tables.
        self::resetRcubeDb();
    }

    private static function resetRcubeDb(): void
    {
        $dbsettings = TestInfrastructureDB::dbSettings();
        $initdb_dsnw = $dbsettings[2];
        self::$db = TestInfrastructureDB::initDatabase($initdb_dsnw);
        TestInfrastructure::init(self::$db);
    }

    /**
     * Tests the schema initialization.
     *
     * This test is invoked once on an empty database (init database, only used for this test). The makefiles dump the
     * schema and compare it to a schema created from script-based execution of the init script.
     */
    public function testSchemaInitializationWorks(): void
    {
        $db = self::$db;

        // check preconditions
        $this->assertDirectoryExists(self::SCRIPTDIR . "/INIT-currentschema");

        // Perform the initialization
        $db->checkMigrations("", self::SCRIPTDIR);

        // After the initialization, the migrations table must contain all the available migrations
        $migsavail = array_map(
            function (string $s): string {
                return basename($s);
            },
            glob(self::SCRIPTDIR . "/0???-*")
        );
        $migsDone = $this->getDoneMigrations();
        $this->assertSame($migsavail, $migsDone);
    }

    /**
     * Gets a sorted list of migrations that have been recorded as done in the carddav_migrations table.
     *
     * @return list<string>
     */
    private function getDoneMigrations(): array
    {
        $db = self::$db;
        /** @var list<array{filename: string}> $rows */
        $rows = $db->get([], ['filename'], 'migrations');
        $migsDone = array_column($rows, 'filename');
        sort($migsDone);
        return $migsDone;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
