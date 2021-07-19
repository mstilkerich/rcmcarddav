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

use MStilkerich\Tests\CardDavAddressbook4Roundcube\Utils;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DatabaseException;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DbAndCondition;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DbOrCondition;

/**
 * @psalm-import-type DbConditions from AbstractDatabase
 * @psalm-import-type DbGetResults from AbstractDatabase
 * @psalm-import-type FullAbookRow from AbstractDatabase
 */
final class DatabaseMigrationTest extends TestCase
{
    /** @var TestData */
    private static $testData;

    /** @var AbstractDatabase Database used for the schema migration test */
    private static $db;

    public static function setUpBeforeClass(): void
    {
        $dbsettings = TestInfrastructureDB::dbSettings();

        [, $migdb_dsnw] = $dbsettings;
        self::$db = TestInfrastructureDB::initDatabase($migdb_dsnw);
        $dbh = TestInfrastructureDB::getDbHandle();

        TestInfrastructure::init(self::$db);

        self::$testData = new TestData($dbh);
        self::$testData->setCacheKeyPrefix('DatabaseMigrationTest');
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    /**
     * Contains test data for migrations. It contains rows that are inserted to the DB before performing the migration,
     * and the expected rows that are expected after the migration.
     */
    private const MIGTEST_DATASETS = [
        '0016-accountentities' => [
            // these rows are inserted before the migration is executed
            // datasets need to match the DB schema before the migration
            'insertRows' => [
                [
                    'table' => 'carddav_addressbooks',
                    'cols' => [
                        'name', 'username', 'password', 'url', 'active',
                        'user_id',
                        'last_updated', 'refresh_time', 'sync_token', 'presetname', 'use_categories',
                    ],
                    'rows' => [
                        [
                            'Nextcloud (Personal)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/personal', '1',
                            [ 'users', 0, 'builtin' ],
                            '1626690000', '1234', 'sync@123', null, '1'
                        ],
                        [
                            'Nextcloud (Work)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/work', '0',
                            [ 'users', 0, 'builtin' ],
                            '1626690010', '4321', 'sync@321', null, '0'
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * Provides the migrations in the proper order to perform one by one.
     *
     * @return array<string, array{list<string>}>
     */
    public function migrationsProvider(): array
    {
        $scriptdir = __DIR__ . "/../../dbmigrations";
        $migsavail = array_map('basename', glob("$scriptdir/0???-*"));

        $result = [];
        $miglist = [];
        foreach ($migsavail as $mig) {
            $miglist[] = $mig;
            $result[$mig] = [ $miglist ];
        }

        return $result;
    }

    /**
     * Tests the schema migration.
     *
     * This test is invoked once for each available migration. It executes the migration. Optionally, if specified in
     * MIGTEST_DATASETS, it can check proper migration of data by a migration. After all migrations have been executed,
     * the schema in the migration database should be equal to the INIT schema in the main database.
     * Note that the actual schema comparison is performed outside PHP unit by the surrounding make recipe.
     *
     * @param list<string> $migs List of migrations. The last in the list shall be performed by the test, the others are
     *                           expected to already have been performed.
     * @dataProvider migrationsProvider
     */
    public function testSchemaMigrationWorks(array $migs): void
    {
        $db = self::$db;
        $scriptdir = __DIR__ . "/../../dbmigrations";
        $migScriptDir = __DIR__ . "/../../testreports/migtestScripts";

        // Preconditions
        $this->assertDirectoryExists("$scriptdir/INIT-currentschema");

        // For the first migration, the carddav tables are expected to not exist yet
        // For later migrations, it is expected that the preceding ones have already been executed
        $exception = null;
        try {
            $rows = $db->get([], [], 'migrations');
            $migsdone = array_column($rows, 'filename');
            sort($migsdone);
            $this->assertSame(array_slice($migs, 0, -1), $migsdone);
        } catch (DatabaseException $e) {
            $exception = $e;
        }

        if (count($migs) <= 1) {
            $this->assertNotNull($exception);
            TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');
        }

        // Prepare a directory containing only the migrations expected for this run
        if (file_exists($migScriptDir)) {
            Utils::rmDirRecursive($migScriptDir);
        }
        TestCase::assertTrue(mkdir($migScriptDir, 0755, true), "Directory $migScriptDir could not be created");
        foreach ($migs as $mig) {
            Utils::copyDir("$scriptdir/$mig", "$migScriptDir/$mig");
        }

        // Perform the migrations - may trigger the error message about missing table again
        $db->checkMigrations("", $migScriptDir);
        if (count($migs) <= 1) {
            TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');
        }

        $rows = $db->get([], [], 'migrations');
        $migsdone = array_column($rows, 'filename');
        sort($migsdone);
        $this->assertSame($migs, $migsdone);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
