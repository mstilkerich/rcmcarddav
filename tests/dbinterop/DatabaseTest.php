<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DatabaseException;

/**
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
        [ "Max Mustermann", "max@muster.de", "Max", "Mustermann", "vcard", "123", "uri1", "1" ],
        [ "John Doe", "john@doe.com", null, "Doe", "vcard", "123", "uri2", "2" ],
        [ "Jane Doe", "jane@doe.com", null, null, "vcard", "123", "uri3", "3" ],
    ];

    /** @var int */
    private static $cnt;

    /** @var string */
    private static $abookId;

    public static function setUpBeforeClass(): void
    {
        self::$cnt = 0;

        TestInfrastructure::init();

        $dbsettings = TestInfrastructureDB::dbSettings();
        [, $migdb_dsnw] = $dbsettings;
        self::$migtestdb = TestInfrastructureDB::initDatabase($migdb_dsnw);
        self::setDbHandle();

        TestData::initDatabase(true);

        // insert test data rows
        $abookRow = [ "Test", "u1", "p1", "https://contacts.example.com/u1/empty/", [ "users", 0 ] ];
        self::$abookId = TestData::insertRow('carddav_addressbooks', TestData::ADDRESSBOOKS_COLUMNS, $abookRow);

        foreach (self::$rows as &$row) {
            $row[] = self::$abookId;
            TestData::insertRow('carddav_contacts', self::COMPARE_COLS, $row);
        }
    }

    public function setUp(): void
    {
        // set a fresh DB handle to ensure we have no open transactions from a previous test
        self::setDbHandle();
    }

    public function tearDown(): void
    {
        self::$db->rollbackTransaction(); // in case transaction left open by a test
        TestInfrastructure::logger()->reset();
    }

    private static function setDbHandle(): void
    {
        $dbsettings = TestInfrastructureDB::dbSettings();
        self::$db = TestInfrastructureDB::initDatabase($dbsettings[0]);
    }

    /**
     * Most basic test for get - check it contains all rows of the table, incl. all fields.
     */
    public function testDatabaseGet(): void
    {
        $db = self::$db;
        $records = $db->get([]);
        $records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, $records, false);
        $records = TestInfrastructure::sortRowList($records);

        $expRecords = TestInfrastructure::sortRowList(self::$rows);
        $this->assertEquals($expRecords, $records);
    }

    /**
     * Tests that the count options of get works as expected, for individual fields as well as all rows.
     */
    public function testDatabaseCountOperator(): void
    {
        $db = self::$db;
        $records = $db->get([], '*,name,firstname,surname', 'contacts', ['count' => true]);
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
            $db->get([], '*', 'migrations');
        } catch (DatabaseException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');

        // Perform the migrations - will trigger the error message about missing table again
        $db->checkMigrations("", $scriptdir);
        TestInfrastructure::logger()->expectMessage('error', 'carddav_migrations');

        $rows = $db->get([], '*', 'migrations');
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
        $recsOrig = array_column($db->get([], 'id', 'contacts'), 'id');
        sort($recsOrig);

        $db->startTransaction(false);
        $testrow = array_merge(
            ['TransactionRollbackTest'],
            array_fill(0, count(self::COMPARE_COLS) - 2, ''),
            [ self::$abookId ]
        );
        $newid = TestData::insertRow('carddav_contacts', self::COMPARE_COLS, $testrow);
        $recsInside = array_column($db->get([], 'id', 'contacts'), 'id');
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
        $recsAfter = array_column($db->get([], 'id', 'contacts'), 'id');
        sort($recsAfter);
        TestCase::assertEquals($recsOrig, $recsAfter, "Rows after rollback differ from original ones");
    }

    public function testExceptionOnNestedTransactionBegin(): void
    {
        $db = self::$db;
        $db->startTransaction(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Cannot start nested transaction");
        $db->startTransaction(false);
    }

    public function testExceptionOnCommitOutsideTransaction(): void
    {
        $db = self::$db;
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Attempt to commit a transaction while not within a transaction");
        $db->endTransaction();
    }

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
