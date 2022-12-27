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
final class DatabaseTest extends TestCase
{
    /** @var list<string> COMPARE_COLS The list of columns in the test data sets to set and compare */
    private const COMPARE_COLS = ['name', 'email', 'firstname', 'surname', 'vcard', 'etag', 'uri', 'cuid', 'abook_id'];

    /** @var AbstractDatabase */
    private static $db;

    /** @var AbstractDatabase Database used for the schema migration test */
    private static $migtestdb;

    /** @var list<list<?string>> Test data, abook_id is auto-appended */
    private static $rows = [
        [ "Max Mustermann", "max1@muster.de", "Max", "Mustermann", "vcard", "123", "uri1", "1" ],
        [ "John Doe", "john@doe.com", null, "Doe", "vcard", "123", "uri2", "2" ],
        [ "Jane Doe", "jane@doe.com", null, null, "vcard", "123", "uri3", "3" ],
        [ "max mustermann", "max2@muster.de", "Max", "Mustermann", "vcard", "123", "uri4", "4" ],
        [ "Max Mustermann", "max0@muster.de", "Max", "Mustermann", "vcard", "123", "uri5", "5" ],
    ];

    /** @var int */
    private static $cnt;

    /** @var string */
    private static $abookId;

    public static function setUpBeforeClass(): void
    {
        self::$cnt = 0;

        $dbsettings = TestInfrastructureDB::dbSettings();
        [, $migdb_dsnw] = $dbsettings;
        self::$migtestdb = TestInfrastructureDB::initDatabase($migdb_dsnw);

        self::$db = self::setDbHandle();
        TestInfrastructure::init(self::$db);

        TestData::initDatabase(true);

        // insert test data rows
        $abookRow = [ "Test", "u1", "p1", "https://contacts.example.com/u1/empty/", [ "users", 0 ], "" ];
        self::$abookId = TestData::insertRow('carddav_addressbooks', TestData::ADDRESSBOOKS_COLUMNS, $abookRow);

        foreach (self::$rows as &$row) {
            $row[] = self::$abookId;
            TestData::insertRow('carddav_contacts', self::COMPARE_COLS, $row);
        }
    }

    public function setUp(): void
    {
        // set a fresh DB handle to ensure we have no open transactions from a previous test
        self::$db = self::setDbHandle();
        TestInfrastructure::$infra->setDb(self::$db);
    }

    public function tearDown(): void
    {
        self::$db->rollbackTransaction(); // in case transaction left open by a test
        TestInfrastructure::logger()->reset();
    }

    private static function setDbHandle(): AbstractDatabase
    {
        $dbsettings = TestInfrastructureDB::dbSettings();
        return TestInfrastructureDB::initDatabase($dbsettings[0]);
    }

    /**
     * @return array<string, array{DbConditions, list<string>}>
     */
    public function getConditionsProvider(): array
    {
        return [
            // 0: Filter conditions, 1: Expected result rows given by cuid from self::$rows
            'NoFilter' => [ [], ["1", "2", "3", "4", "5"] ],
            'SingleFieldExactMatchCaseSensitive' => [ ['name' => 'Max Mustermann'], ["1", "5"] ],
            'SingleFieldExactMatchCaseInsensitive' => [ ['%name' => 'max mustermann'], ["1", "4", "5"] ],
            'SingleFieldExactMatchFromSet' => [ ['name' => ['Max Mustermann', 'John Doe']], ["1", "2", "5"] ],
            'InvSingleFieldExactMatchCaseSensitive' => [ ['!name' => 'Max Mustermann'], ["2", "3", "4"] ],
            'InvSingleFieldExactMatchCaseInsensitive' => [ ['!%name' => 'Max Mustermann'], ["2", "3"] ],
            'InvSingleFieldExactMatchFromSet' => [ ['!name' => ['Max Mustermann', 'John Doe']], ["3", "4"] ],
            'SingleFieldMatchNull' => [ ['firstname' => null], ["2", "3"] ],
            'SingleFieldMatchNotNull' => [ ['!firstname' => null], ["1", "4", "5"] ],
            // 0 is not a valid ID, but it should result in an empty result set, not all rows
            'SimpleIDMatch' => [ '0', [] ],
            'StartsWithMatch' => [ [ '%name' => 'JANE%' ], ["3"] ],
            'ContainsMatch' => [ [ '%email' => '%@doe.%' ], ["2", "3"] ],
            'EndsWithMatch' => [ [ '%email' => '%.com' ], ["2", "3"] ],
            'TwoFieldMatch' => [ [ '%name' => '%doe', '!surname' => null, 'uri' => 'uri2' ], ["2"] ],
        ];
    }

    /**
     * Tests get() operation with various conditions.
     *
     * @param DbConditions $conditions
     * @param list<string> $expCuids
     *
     * @dataProvider getConditionsProvider
     */
    public function testDatabaseGetSelectReturnsExpectedRows($conditions, array $expCuids): void
    {
        $db = self::$db;
        $records = $db->get($conditions);
        $records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, $records, false);
        $records = TestInfrastructure::sortRowList($records);

        $expRows = self::selectRows($expCuids);
        $this->assertEquals($expRows, $records);
    }

    /**
     * Tests that Database::get() returns the selected columns (only).
     */
    public function testDatabaseGetSelectReturnsExpectedColumns(): void
    {
        $db = self::$db;

        // special case [] - all columns
        $records = $db->get([], []);
        $records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, $records, false);
        $records = TestInfrastructure::sortRowList($records);
        $this->assertEquals($records, TestInfrastructure::sortRowList(self::$rows));

        // selection of columns
        $records = $db->get([], ['name', 'firstname', 'email']);
        $records = TestInfrastructure::xformDatabaseResultToRowList(['name', 'firstname', 'email'], $records, true);
        $records = TestInfrastructure::sortRowList($records);

        $expRows = TestInfrastructure::arrayColumns(self::COMPARE_COLS, ['name', 'firstname', 'email'], self::$rows);
        $expRows = TestInfrastructure::sortRowList($expRows);

        $this->assertEquals($expRows, $records);
    }

    /**
     * Tests lookup() operation.
     *
     * @param DbConditions $conditions
     * @param list<string> $expCuids
     *
     * @dataProvider getConditionsProvider
     */
    public function testDatabaseLookupReturnsExpectedRowOrError($conditions, array $expCuids): void
    {
        $db = self::$db;

        if (count($expCuids) != 1) {
            $this->expectException(\Exception::class);

            if (count($expCuids) == 0) {
                $this->expectExceptionMessage("without result/with error");
            } else {
                $this->expectExceptionMessage("with multiple results");
            }
        }

        $row = $db->lookup($conditions);
        $this->assertCount(1, $expCuids);
        $records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, [$row], false);
        $expRows = self::selectRows($expCuids);
        $this->assertEquals($expRows, $records);
    }

    /**
     * @return array<string, array{DbConditions, string}>
     */
    public function invalidConditionsProvider(): array
    {
        return [
            // IN query with empty value set
            'InNoValues' => [ [ 'name' => []], 'empty values array' ],
            // NOT IN query with empty value set
            'NotInNoValues' => [ [ '!name' => []], 'empty values array' ],
            // IN query with ILIKE match
            'InLikeMatch' => [ [ '%name' => ["foo"]], 'ILIKE match only supported for single pattern' ],
        ];
    }

    /**
     * Tests get() with various invalid conditions parameters.
     *
     * An exception is expected for these cases.
     *
     * @param DbConditions $conditions
     * @param string $expExMsg Part of the expected exception message
     *
     * @dataProvider invalidConditionsProvider
     */
    public function testDatabaseGetExceptionOnInvalidConditions($conditions, string $expExMsg): void
    {
        $db = self::$db;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($expExMsg);

        $db->get($conditions);
    }

    /**
     * Tests a get() operation with two alternative (OR) conditions.
     */
    public function testDatabaseGetWithTwoOrConditionsReturnsExpectedRows(): void
    {
        $db = self::$db;
        // Two OR conditions
        $johnOrJane = new DbAndCondition();
        $johnOrJane->add('%name', 'jane%');
        $johnOrJane->add('%name', 'john%');

        $records = $db->get([$johnOrJane]);
        $records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, $records, false);
        $records = TestInfrastructure::sortRowList($records);

        $expRows = self::selectRows(["2", "3"]);
        $this->assertEquals($expRows, $records);
    }

    /**
     * Tests a get() operation with a list of two AndConditions already constructed by the caller.
     *
     * It also tests the functions of DbAndCondition related to adding conditions to an existing DbAndCondition.
     */
    public function testDatabaseGetWithTwoAndConditionsReturnsExpectedRows(): void
    {
        $db = self::$db;

        // Two OR conditions
        $johnOrJane = new DbAndCondition();
        $johnOrJane->add('%name', 'jane%');
        $johnOrJane->add('%name', 'john%');

        // Check that append filters duplicates
        $johnOrCond = new DbOrCondition('%name', 'john%');
        $john = new DbAndCondition($johnOrCond);
        $john->add('uri', 'uri2');
        $johnOrJane->append($john);
        $this->assertCount(3, $johnOrJane->orConditions, "Equal OrCondition appended again");

        $records = $db->get([$johnOrJane, $john]);
        $records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, $records, false);
        $records = TestInfrastructure::sortRowList($records);

        $expRows = self::selectRows(["2"]);
        $this->assertEquals($expRows, $records);
    }

    /**
     * Tests that the count options of get works as expected, for individual fields as well as all rows.
     */
    public function testDatabaseCountOperator(): void
    {
        $db = self::$db;
        $records = $db->get([], ['*', 'name', 'firstname', 'surname'], 'contacts', ['count' => true]);
        $this->assertCount(1, $records);
        $row = $records[0];

        $this->assertSame((string) count(self::$rows), $row['*']);
        $this->assertSame((string) self::countNonNullRows('name'), $row['name']);
        $this->assertSame((string) self::countNonNullRows('firstname'), $row['firstname']);
        $this->assertSame((string) self::countNonNullRows('surname'), $row['surname']);

        // this is to check that the test on specific column count has some null values
        $this->assertLessThan(count(self::$rows), self::countNonNullRows('firstname'));
    }

    /**
     * Provides datasets for order tests.
     *
     * Each data set consists of a setting for the Database::get() order option, and a list of row cuids of the test
     * data rows that gives the expected order of the resulting records.
     *
     * @return array<string, array{list<string>, list<string>}>
     */
    public function orderTestDataProvider(): array
    {
        return [
            // order cols,      expected row cuid values from self::$rows
            'Ascending' => [ ['name', 'email'], ["3", "2", "5", "1", "4"] ],
            'Descending' => [ ['!name', '!email'], ["4", "1", "5", "2", "3"] ],
            'Mixed' => [ ['name', '!email'], ["3", "2", "4", "1", "5"] ],
        ];
    }

    /**
     * Tests that row ordering works, case-insensitive.
     *
     * @param list<string> $orderSetting
     * @param list<string> $expOrder
     *
     * @dataProvider orderTestDataProvider
     */
    public function testDatabaseOrderOperator(array $orderSetting, array $expOrder): void
    {
        $db = self::$db;
        $records = array_column($db->get([], ['cuid'], 'contacts', ['order' => $orderSetting]), 'cuid');
        $this->assertCount(count(self::$rows), $records);
        $this->assertSame($expOrder, $records);
    }

    /**
     * Provides datasets for order tests.
     *
     * Each data set consists of a setting for the Database::get() order option, and a list of row cuids of the test
     * data rows that gives the expected order of the resulting records.
     *
     * @return array<string, array{list<string>, array{int,int}, ?list<string>}>
     */
    public function limitTestDataProvider(): array
    {
        return [
            // order cols,      expected row cuid values from self::$rows
            'FirstRow' => [ ['name', 'email'], [0,1], ["3"] ],
            'First3Rows' => [ ['name', 'email'], [0,3], ["3", "2", "5"] ],
            'Middle2Rows' => [ ['name', 'email'], [2,2], ["5", "1"] ],
            'BeyondEnd' => [ ['name', 'email'], [4,2], ["4"] ],
            'NegativeLimit' => [ ['name', 'email'], [4,-1], null ],
            'ZeroLimit' => [ ['name', 'email'], [4,0], null ],
            'NegativeOffset' => [ ['name', 'email'], [-1,1], null ],
        ];
    }

    /**
     * Tests that row ordering works, case-insensitive.
     *
     * @param list<string> $orderSetting
     * @param array{int,int} $limitSetting
     * @param ?list<string> $expOrder
     *
     * @dataProvider limitTestDataProvider
     */
    public function testDatabaseLimitOperator(array $orderSetting, array $limitSetting, ?array $expOrder): void
    {
        $db = self::$db;

        if (!isset($expOrder)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage(
                "The limit option needs an array parameter of two unsigned integers [offset,limit]"
            );
        }

        $records = array_column(
            $db->get([], ['cuid'], 'contacts', ['order' => $orderSetting, 'limit' => $limitSetting]),
            'cuid'
        );

        $this->assertNotNull($expOrder);
        [ $offset, $numrows ] = $limitSetting;

        $expCount = min(count(self::$rows) - $offset, $numrows);

        $this->assertCount($expCount, $records);
        $this->assertSame($expOrder, $records);
    }

    /**
     * Tests the schema migration.
     *
     * Note that the actual schema comparison is performed outside PHP unit by the surrounding make recipe.
     */
    public function testSchemaMigrationYieldsCurrentSchema(): void
    {
        $db = self::$migtestdb;
        $scriptdir = __DIR__ . "/../../dbmigrations";

        // Preconditions
        $this->assertDirectoryExists("$scriptdir/INIT-currentschema");
        $exception = null;
        try {
            $db->get([], [], 'migrations');
        } catch (DatabaseException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');

        // Perform the migrations - will trigger the error message about missing table again
        $db->checkMigrations("", $scriptdir);
        TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');

        $rows = $db->get([], [], 'migrations');
        $migsdone = array_column($rows, 'filename');
        sort($migsdone);

        $migsavail = array_map('basename', glob("$scriptdir/0???-*"));
        $this->assertSame($migsavail, $migsdone);
    }

    /**
     * Tests that rollback of a transaction undos the changes of the transaction.
     */
    public function testTransactionRollbackWorks(): void
    {
        $db = self::$db;
        $recsOrig = array_column($db->get([], ['id'], 'contacts'), 'id');
        sort($recsOrig);

        $db->startTransaction(false);
        $testrow = array_merge(
            ['TransactionRollbackTest'],
            array_fill(0, count(self::COMPARE_COLS) - 2, ''),
            [ self::$abookId ]
        );
        $newid = TestData::insertRow('carddav_contacts', self::COMPARE_COLS, $testrow);
        $recsInside = array_column($db->get([], ['id'], 'contacts'), 'id');
        sort($recsInside);

        $recsInsideExp = array_merge($recsOrig, [$newid]);
        sort($recsInsideExp);

        TestCase::assertEquals(
            $recsInsideExp,
            $recsInside,
            "Rows inside transaction do not contain original plus new inserted row"
        );
        $db->rollbackTransaction();

        /** @var list<string> */
        $recsAfter = array_column($db->get([], ['id'], 'contacts'), 'id');
        sort($recsAfter);
        TestCase::assertEquals($recsOrig, $recsAfter, "Rows after rollback differ from original ones");
    }

    /**
     * Tests that an exception is thrown on attempt to start a nested transaction.
     */
    public function testExceptionOnNestedTransactionBegin(): void
    {
        $db = self::$db;
        $db->startTransaction(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Cannot start nested transaction");
        $db->startTransaction(false);
    }

    /**
     * Tests that an exception is thrown on attempt to start a nested transaction.
    public function testExceptionOnNestedTransactionBeginDirect(): void
    {
        $dbh = TestInfrastructureDB::getDbHandle();
        $dbh->startTransaction();

        // now try to start another transaction
        $this->expectException(DatabaseException::class);
        $dbh->startTransaction();
    }
     */

    public static function errStartTransaction(Database $db): void
    {
        $db->startTransaction();
    }

    public static function errEndTransaction(Database $db): void
    {
        TestInfrastructure::setPrivateProperty($db, 'inTransaction', true);
        $db->endTransaction();
    }

    public static function errRollbackTransaction(Database $db): void
    {
        TestInfrastructure::setPrivateProperty($db, 'inTransaction', true);
        $db->rollbackTransaction();
    }

    public static function errDelete(Database $db): void
    {
        $db->delete('notexist', 'notexist');
    }

    public static function errUpdate(Database $db): void
    {
        $db->update('notexist', ['notexist'], ['notexist'], 'notexist');
    }

    public static function errInsert(Database $db): void
    {
        $db->insert('notexist', ['notexist'], [['notexist']]);
    }

    /**
     * @return array<string, array{callable(Database):void}>
     */
    public function connectToDbErrFuncProvider(): array
    {
        $tests = [
            'StartTransaction' => [ [self::class, 'errStartTransaction'] ],
            'EndTransaction' => [ [self::class, 'errEndTransaction'] ],
            'RollbackTransaction' => [ [self::class, 'errRollbackTransaction'] ],
            'Insert' => [ [self::class, 'errInsert'] ],
            'Update' => [ [self::class, 'errUpdate'] ],
            'Delete' => [ [self::class, 'errDelete'] ],
        ];

        return $tests;
    }

    /**
     * @param callable(Database):void $errFunc
     * @dataProvider connectToDbErrFuncProvider
     */
    public function testExceptionOnFailureToConnectToDb($errFunc): void
    {
        if ($GLOBALS["TEST_DBTYPE"] == "sqlite3") {
            $dbh = \rcube_db::factory("sqlite:///" . __DIR__ . "/../../testreports/does/not/doesNotExist.db");
            $expErrMsg = 'doesNotExist.db';
        } elseif ($GLOBALS["TEST_DBTYPE"] == "postgres") {
            $dbh = \rcube_db::factory("pgsql://a@unix(" . __DIR__ . "/../../testreports/does/not/doesNotExist)/db");
            $expErrMsg = 'doesNotExist';
        } elseif ($GLOBALS["TEST_DBTYPE"] == "mysql") {
            $dbh = \rcube_db::factory("mysql://a@unix(" . __DIR__ . "/../../testreports/does/not/doesNotExist)/db");
            $expErrMsg = 'No such file or directory';
        } else {
            $this->fail("unsupported DB");
        }

        $db = new Database(TestInfrastructure::logger(), $dbh);

        try {
            call_user_func($errFunc, $db);
            $this->assertFalse(true, "Exception expected to be thrown");
        } catch (DatabaseException $e) {
            $this->assertStringContainsString($expErrMsg, $e->getMessage());
        }
        TestInfrastructure::logger()->expectMessage('error', $expErrMsg);
    }

    /**
     * @return array<string, array{callable(Database):void}>
     */
    public function connectToDbUnsuppDbProvider(): array
    {
        $tests = [
            'StartTransaction' => [ [self::class, 'errStartTransaction'] ],
            'CheckMigrations' => [ [self::class, 'unsuppDbCheckMigrations'] ],
        ];

        return $tests;
    }

    public static function unsuppDbCheckMigrations(Database $db): void
    {
        $scriptdir = __DIR__ . "/../../dbmigrations";
        $db->checkMigrations("", $scriptdir);
    }

    /**
     * Tests that an error message is logged when using an unsupported DBMS.
     *
     * We only support MySQL, Postgres and SQLite3. For most operations, this does not matter, but some require
     * DBMS-specific SQL. These operations are expected to log an error message, which is verified by this test.
     *
     * @param callable(Database):void $errFunc
     * @dataProvider connectToDbUnsuppDbProvider
     */
    public function testErrorMessageOnUnsupportedDbProvider($errFunc): void
    {
        //$dbh = \rcube_db::factory("oracle://scott/tiger@//localhost:59999/oracle");
        $dbh = \rcube_db::factory("oracle://a@unix(" . __DIR__ . "/../../testreports/does/not/doesNotExist)/db");
        $db = new Database(TestInfrastructure::logger(), $dbh);

        call_user_func($errFunc, $db);
        TestInfrastructure::logger()->expectMessage('critical', 'Unsupported database backend');
    }

    /**
     * Tests that an exception is thrown on attempt to commit while no transaction was started.
     */
    public function testExceptionOnCommitOutsideTransaction(): void
    {
        $db = self::$db;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attempt to commit a transaction while not within a transaction");
        $db->endTransaction();
    }

    /**
     * For DBMS supporting read-only transactions, test that an exception is thrown when attempting to modify data
     * during a read-only transaction.
     */
    public function testExceptionOnInsertDuringReadonlyTransaction(): void
    {
        TestCase::assertIsString($GLOBALS["TEST_DBTYPE"]);
        if ($GLOBALS["TEST_DBTYPE"] == "sqlite3") {
            $this->markTestSkipped("SQLite does not support readonly transactions");
        }

        $expErrMsg = $GLOBALS["TEST_DBTYPE"] == "postgres" ? 'read-only' : 'READ ONLY' /* mysql */;

        $db = self::$db;

        $db->startTransaction();
        $testrow = array_fill(0, count(self::COMPARE_COLS) - 1, '');
        $testrow[] = self::$abookId;

        try {
            $ret = $db->insert('contacts', self::COMPARE_COLS, [$testrow]);
            $this->assertFalse(true, "Exception expected to be thrown - $ret");
        } catch (DatabaseException $e) {
            $this->assertStringContainsString($expErrMsg, $e->getMessage());
        }

        TestInfrastructure::logger()->expectMessage('error', $expErrMsg);
    }

    /**
     * Select a subset of rows from self::$rows selected by cuid.
     *
     * @param list<string> $rowCuids A list of cuid fields to select the rows by.
     * @return list<list<?string>> The rows, alphabetically sorted.
     */
    private static function selectRows(array $rowCuids): array
    {
        $cuidIdx = array_search('cuid', self::COMPARE_COLS);
        TestCase::assertIsInt($cuidIdx);

        $rows = [];
        foreach (self::$rows as $r) {
            if (in_array($r[$cuidIdx], $rowCuids)) {
                $rows[] = $r;
            }
        }
        TestCase::assertCount(count($rowCuids), $rows, "rowCuids references unknown cuids: " . join(",", $rowCuids));
        return TestInfrastructure::sortRowList($rows);
    }

    /**
     * Counts the number of rows in self::$rows that have a non-null value in the given field.
     */
    private static function countNonNullRows(string $field): int
    {
        $fieldidx = array_search($field, self::COMPARE_COLS);
        TestCase::assertIsInt($fieldidx, "Field must be in COMPARE_COLS");

        $cnt = 0;
        foreach (self::$rows as $row) {
            if (isset($row[$fieldidx])) {
                ++$cnt;
            }
        }

        return $cnt;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
