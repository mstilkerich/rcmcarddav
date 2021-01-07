<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * @psalm-import-type DbGetResults from AbstractDatabase
 * @psalm-import-type FullAbookRow from AbstractDatabase
 */
final class DatabaseTest extends TestCase
{
    /** @var list<string> COMPARE_COLS The list of columns in the test data sets to set and compare */
    private const COMPARE_COLS = [ 'abook_id', 'name', 'firstname', 'surname', 'vcard', 'etag', 'uri', 'cuid' ];

    /** @var AbstractDatabase */
    private static $db;

    /** @var array */
    private static $rows;

    /** @var int */
    private static $cnt;

    /** @var string */
    private static $abookId;

    public static function setUpBeforeClass(): void
    {
        self::$cnt = 0;

        TestInfrastructure::init();

        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        self::$db = TestInfrastructureDB::initDatabase($db_dsnw);
        TestData::initDatabase();

        // insert rows for testing ordering, limit and count operations
        // We use the name, firstname, surname columns, the rest contains dummy data where needed
        // We generate data records with ABC, BCD, CDE etc. for surname
        // For each of those we have 3x variants with BCD, CDE etc. in firstname, 2x CDE, DEF etc. plus NULL in surname
        // The same rows are additionally generated in a lower case for name
        $mainstr = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        $abookRow = [ "Test", "u1", "p1", "https://contacts.example.com/u1/empty/", [ "users", 0 ] ];
        self::$abookId = TestData::insertRow('carddav_addressbooks', TestData::ADDRESSBOOKS_COLUMNS, $abookRow);

        for ($nIdx = 0; $nIdx < 10; ++$nIdx) { // 5..9 are for lowercase
            $name = substr($mainstr, $nIdx % 5, 3);
            if ($nIdx >= 5) {
                $name = strtolower($name);
            }

            for ($fnIdx = 3; $fnIdx > 0; --$fnIdx) {
                $firstname = substr($mainstr, $fnIdx, 3);
                for ($snIdx = 2; $snIdx < 4; ++$snIdx) {
                    $surname = substr($mainstr, $snIdx, 3);
                    self::insertRow($name, $firstname, $surname);
                }
                self::insertRow($name, $firstname, null);
            }
        }
    }

    private static function insertRow(string $name, string $firstname, ?string $surname): void
    {
        $cnt = self::$cnt;
        ++self::$cnt;

        $row = array_merge([ self::$abookId, $name, $firstname, $surname ], array_fill(0, 4, "dummy$cnt"));
        TestData::insertRow('carddav_contacts', self::COMPARE_COLS, $row);
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    /**
     * Tests get()
     */
    public function testDatabaseGet(): void
    {
        $db = self::$db;
        $records = $db->get(['abook_id' => self::$abookId], '*');
        $_records = TestInfrastructure::xformDatabaseResultToRowList(self::COMPARE_COLS, $records, false);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
