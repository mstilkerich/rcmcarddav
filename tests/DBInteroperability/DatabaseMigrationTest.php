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
use MStilkerich\RCMCardDAV\Db\Database;
use MStilkerich\RCMCardDAV\Db\DatabaseException;
use MStilkerich\RCMCardDAV\Db\DbAndCondition;
use MStilkerich\RCMCardDAV\Db\DbOrCondition;

/**
 * @psalm-import-type TestDataRow from TestData
 * @psalm-import-type TestDataRowWithKeyRef from TestData
 * @psalm-import-type TestDataKeyRef from TestData
 *
 * @psalm-type InsertRowsSpec = array{
 *   table: string,
 *   cols: list<string>,
 *   rows: list<TestDataRowWithKeyRef>,
 * }
 * @psalm-type MigtestDatasetSpec = array{
 *   insertRows?: list<InsertRowsSpec>,
 *   checkRows?: list<InsertRowsSpec>,
 * }
 */
final class DatabaseMigrationTest extends TestCase
{
    private const SCRIPTDIR = __DIR__ . "/../../dbmigrations";

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
     *
     * @var array<string, MigtestDatasetSpec>
     */
    private const MIGTEST_DATASETS = [
        // Initially, the DB is empty. Even the carddav tables do not exist yet. Insert some roundcube users.
        '0000-dbinit' => [
            'insertRows' => [
                [
                    'table' => 'users',
                    'cols' => TestData::USERS_COLUMNS,
                    'rows' => [
                        ["testuser@example.com", "mail.example.com"],
                        ["another@user.com", "hermes.example.com"],
                    ],
                ],
            ],
        ],

        '0017-accountentities' => [
            // these rows are inserted before the migration is executed
            // datasets need to match the DB schema before the migration
            'insertRows' => [
                [
                    'table' => 'carddav_addressbooks',
                    'cols' => [
                        'name', 'username', 'password', 'url', 'active',
                        'user_id', 'last_updated', 'refresh_time', 'sync_token', 'presetname', 'use_categories',
                    ],
                    'rows' => [
                        [
                            'Nextcloud (Personal)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/personal', '1',
                            [ 'users', 0, '0000-dbinit' ], '1626690000', '1234', 'sync@123', null, '1'
                        ],
                        [
                            'Nextcloud (Work)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/work/', '0',
                            [ 'users', 0, '0000-dbinit' ], '1626690010', '4321', 'sync@321', null, '0'
                        ],
                        // user 0 has some extra addressbooks from a preset with same data as manually added ones
                        [
                            'Nextcloud (Personal)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/personal', '1',
                            [ 'users', 0, '0000-dbinit' ], '1626690000', '1234', 'sync@123', 'admPreset', '1'
                        ],
                        [
                            'Nextcloud  (Shared)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/shared', '1',
                            [ 'users', 0, '0000-dbinit' ], '1626690000', '1234', 'sync@234', 'admPreset', '1'
                        ],
                        // user 1 has the same addressbooks, but separate accounts must be created
                        [
                            'Nextcloud (Personal)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/personal', '1',
                            [ 'users', 1, '0000-dbinit' ], '1626690000', '1234', 'sync@123', null, '1'
                        ],
                        [
                            'Nextcloud (Work)', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/work/', '0',
                            [ 'users', 1, '0000-dbinit' ], '1626690010', '4321', 'sync@321', null, '0'
                        ],
                        // user 1 has another addressbook
                        [
                            'Radicale', 'ruser', 'rpass', 'https://radicale.example.com/dav/book1/', '1',
                            [ 'users', 1, '0000-dbinit' ], '1626680010', '5432', 'sync@Radicale1', null, '0'
                        ],
                    ],
                ],
            ],
        ],

        '0018-accountentities2' => [
            // these rows are checked against the listed values after the migration has been executed.
            // only the listed columns are checked
            'checkRows' => [
                [
                    'table' => 'carddav_accounts',
                    'cols' => [
                        'name', 'username', 'password', 'url',
                        'user_id', 'last_discovered', 'rediscover_time', 'presetname',
                    ],
                    'rows' => [
                        [
                            'Nextcloud', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/',
                            [ 'users', 0, '0000-dbinit' ], '0', '86400', null
                        ],
                        [
                            'Nextcloud', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/',
                            [ 'users', 0, '0000-dbinit' ], '0', '86400', 'admPreset'
                        ],
                        [
                            'Nextcloud', 'ncuser', 'ncpass', 'https://nc.cloud.com/c/',
                            [ 'users', 1, '0000-dbinit' ], '0', '86400', null
                        ],
                        [
                            'Radicale', 'ruser', 'rpass', 'https://radicale.example.com/dav/',
                            [ 'users', 1, '0000-dbinit' ], '0', '86400', null
                        ],
                    ],
                ],
                [
                    'table' => 'carddav_addressbooks',
                    'cols' => [
                        'name', 'url', 'active',
                        'account_id', 'last_updated', 'refresh_time', 'sync_token', 'use_categories',
                    ],
                    'rows' => [
                        [
                            'Personal', 'https://nc.cloud.com/c/personal', '1',
                            [ 'carddav_accounts', 0, '<checkRows>' ], '1626690000', '1234', 'sync@123', '1'
                        ],
                        [
                            'Work', 'https://nc.cloud.com/c/work/', '0',
                            [ 'carddav_accounts', 0, '<checkRows>' ], '1626690010', '4321', 'sync@321', '0'
                        ],
                        [
                            'Personal', 'https://nc.cloud.com/c/personal', '1',
                            [ 'carddav_accounts', 1, '<checkRows>' ], '1626690000', '1234', 'sync@123', '1'
                        ],
                        [
                            'Nextcloud  (Shared)', 'https://nc.cloud.com/c/shared', '1',
                            [ 'carddav_accounts', 1, '<checkRows>' ], '1626690000', '1234', 'sync@234', '1'
                        ],
                        [
                            'Personal', 'https://nc.cloud.com/c/personal', '1',
                            [ 'carddav_accounts', 2, '<checkRows>' ], '1626690000', '1234', 'sync@123', '1'
                        ],
                        [
                            'Work', 'https://nc.cloud.com/c/work/', '0',
                            [ 'carddav_accounts', 2, '<checkRows>' ], '1626690010', '4321', 'sync@321', '0'
                        ],
                        [
                            'Radicale', 'https://radicale.example.com/dav/book1/', '1',
                            [ 'carddav_accounts', 3, '<checkRows>' ], '1626680010', '5432', 'sync@Radicale1', '0'
                        ],
                    ],
                ],
            ],
        ],

        '0020-distinctcolumnnames' => [
            // these rows are checked against the listed values after the migration has been executed.
            // only the listed columns are checked
            'checkRows' => [
                [
                    'table' => 'carddav_accounts',
                    'cols' => [ 'accountname', 'discovery_url', 'presetname' ],
                    'rows' => [
                        [ 'Nextcloud', 'https://nc.cloud.com/c/', null ],
                        [ 'Nextcloud',  'https://nc.cloud.com/c/', 'admPreset' ],
                        [ 'Nextcloud', 'https://nc.cloud.com/c/', null ],
                        [ 'Radicale', 'https://radicale.example.com/dav/', null ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * Provides the migrations in the proper order to perform one by one.
     *
     * @return array<string, list{non-empty-list<string>}>
     */
    public function migrationsProvider(): array
    {
        $migsavail = array_map(
            function (string $s): string {
                return basename($s);
            },
            glob(self::SCRIPTDIR . "/0???-*")
        );

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
     * @param non-empty-list<string> $migs List of migrations. The last in the list shall be performed by the test, the
     *                                     others are expected to already have been performed.
     * @dataProvider migrationsProvider
     */
    public function testSchemaMigrationWorks(array $migs): void
    {
        $db = self::$db;
        $migScriptDir = __DIR__ . "/../../testreports/migtestScripts";
        $mig = $migs[count($migs) - 1];

        // check preconditions
        $this->checkTestPreconditions($migs);

        // prepare migration script directory
        $this->prepareMigScriptDir($migScriptDir, $migs);

        // prepare test data
        if (isset(self::MIGTEST_DATASETS[$mig]['insertRows'])) {
            $this->insertRows($mig, self::MIGTEST_DATASETS[$mig]['insertRows']);
        }

        // Perform the migrations - may trigger the error message about missing table again
        $db->checkMigrations("", $migScriptDir);
        if (count($migs) <= 1) {
            TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');
        }

        $migsDone = $this->getDoneMigrations();
        $this->assertSame($migs, $migsDone);

        // check proper data conversion if defined
        if (isset(self::MIGTEST_DATASETS[$mig]['checkRows'])) {
            $this->checkResultRows($mig, self::MIGTEST_DATASETS[$mig]['checkRows']);
        }
    }

    /**
     * Checks preconditions of testSchemaMigrationWorks.
     *
     * @param list<string> $migs List of migrations.
     */
    private function checkTestPreconditions(array $migs): void
    {
        $this->assertDirectoryExists(self::SCRIPTDIR . "/INIT-currentschema");

        // For the first migration, the carddav tables are expected to not exist yet
        // For later migrations, it is expected that the preceding ones have already been executed
        $exception = null;
        try {
            $migsDone = $this->getDoneMigrations();
            $this->assertSame(array_slice($migs, 0, -1), $migsDone);
        } catch (DatabaseException $e) {
            $exception = $e;
        }

        if (count($migs) <= 1) {
            $this->assertNotNull($exception);
            TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');
        }
    }

    /**
     * Prepare a directory containing only the migrations expected for this run.
     *
     * @param list<string> $migs List of migrations to copy to the directory.
     */
    private function prepareMigScriptDir(string $migScriptDir, array $migs): void
    {
        if (file_exists($migScriptDir)) {
            TestInfrastructure::rmDirRecursive($migScriptDir);
        }

        TestCase::assertTrue(mkdir($migScriptDir, 0755, true), "Directory $migScriptDir could not be created");
        foreach ($migs as $mig) {
            TestInfrastructure::copyDir(self::SCRIPTDIR . "/$mig", "$migScriptDir/$mig");
        }
    }

    /**
     * Inserts the test data before executing a migration.
     * @param list<InsertRowsSpec> $insertRowsSpec
     */
    private function insertRows(string $mig, array $insertRowsSpec): void
    {
        self::$testData->setCacheKeyPrefix($mig);

        foreach ($insertRowsSpec as $tblSpec) {
            [ 'table' => $tbl, 'cols' => $cols, 'rows' => $rows ] = $tblSpec;

            foreach ($rows as $row) {
                self::$testData->insertRow($tbl, $cols, $row);
            }
        }
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

    /**
     * Checks the given rows after a migration has been performed.
     *
     * For foreign key references, the special key prefix <checkRows> can be used to refer to a row that has been
     * checked by this function earlier. Otherwise foreign key references are resolved as defined by the TestData class.
     *
     * @param list<InsertRowsSpec> $checkRowsSpec
     */
    private function checkResultRows(string $mig, array $checkRowsSpec): void
    {
        $db = self::$db;

        foreach ($checkRowsSpec as $specIdx => &$tblSpec) {
            [ 'table' => $tbl, 'cols' => $cols, 'rows' => $rows ] = $tblSpec;
            $tblshort = preg_replace('/^carddav_/', '', $tbl);

            // get the records contained in the DB and normalize them in representation and order
            $records = $db->get([], [], $tblshort);
            $records = TestInfrastructure::xformDatabaseResultToRowList($cols, $records, false);
            $records = TestInfrastructure::sortRowList($records);

            // resolve foreign key references in the rows; this only works if the target is from a table that has been
            // checked in an earlier iteration of this loop
            $expRows = [];
            foreach ($rows as $row) {
                $resolvedRow = [];

                foreach ($row as $value) {
                    if (is_array($value)) {
                        $value = $this->resolveFkRef($mig, $checkRowsSpec, $value, $specIdx);
                    }
                    $resolvedRow[] = $value;
                }
                $expRows[] = $resolvedRow;
            }

            // store resolved rows to enable later resolving of internal references
            $tblSpec['rows'] = $expRows;

            // bring the expected rows to sorted form
            $expRows = TestInfrastructure::sortRowList($expRows);

            // finally: compare the expected rows equal the actual ones
            $this->assertSame(
                $expRows,
                $records,
                "checkRows failed $mig / $tbl: Exp: " . print_r($expRows, true) . "; got: " . print_r($records, true)
            );
        }
        unset($tblSpec);
    }

    /**
     * Resolves a foreign key reference.
     * @param list<InsertRowsSpec> $checkRowsSpec
     * @param TestDataKeyRef $fkRef
     */
    private function resolveFkRef(string $mig, array $checkRowsSpec, array $fkRef, int $specIdx): string
    {
        // record at which index in $checkRowsSpec we find the entry for a given table
        $tblToIndex = [];
        foreach ($checkRowsSpec as $idx => $tblSpec) {
            $tblToIndex[$tblSpec['table']] = $idx;
        }

        [ $dtbl, $didx ] = $fkRef;
        $prefix = $fkRef[2] ?? $mig;
        $dtblshort = preg_replace('/^carddav_/', '', $dtbl);

        if ($prefix == '<checkRows>') {
            $this->assertArrayHasKey($dtbl, $tblToIndex);
            $tblIdx = $tblToIndex[$dtbl];
            $this->assertLessThan(
                $specIdx,
                $tblIdx,
                "internal references are only allowed to earlier listed tables"
            );

            $dCols = $checkRowsSpec[$tblIdx]['cols'];
            /** @psalm-var TestDataRow $dRow We know this has already been resolved */
            $dRow = $checkRowsSpec[$tblIdx]['rows'][$didx];
            /** @psalm-var array{id: string} */
            $row = self::$db->lookup(array_combine($dCols, $dRow), ['id'], $dtblshort);
            $dbId = $row['id'];
        } else {
            $dbId = self::$testData->getRowId($dtbl, $didx, $prefix);
        }

        return $dbId;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
