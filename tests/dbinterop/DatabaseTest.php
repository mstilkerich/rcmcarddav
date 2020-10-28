<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\{Database};

final class DatabaseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();

        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        TestInfrastructureDB::initDatabase($db_dsnw);

        $dbh = Database::getDbHandle();
        TestData::initDatabase();
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    /**
     * Tests that the Database::sqlDateTimeToSeconds works for last_updated default value.
     */
    public function testLastUpdatedDefaultConvertsToTimestampCorrectly(): void
    {
        $abookrow = Database::get("Empty Addressbook", "last_updated", "addressbooks", true, "name");
        $this->assertArrayHasKey("last_updated", $abookrow);

        $ts_now = time();
        $delta = Database::sqlDateTimeToSeconds($abookrow["last_updated"], $ts_now);

        // we have different defaults, but anything greater 10 years in the past can be acceptable
        $this->assertLessThanOrEqual(86400, $delta);
    }

    public function datetimeTestdataProvider(): array
    {
        return [
            [ '00:00:00', 0, 0 ],
            [ '01:00:00', 0, 3600 ],
            [ '02:00:00', 800, 8000 ],
            [ '1:00:00', 0, null ],
            [ '1970-01-01 00:00:00', 1000, 0 ],
            [ '1970-01-01 01:00:00', 0, 3600 ],
        ];
    }

    /**
     * Tests the Database::sqlDateTimeToSeconds function with different datasets
     *
     * FIXME This test is not needed for every database, move it to a standard unit test
     *
     * @dataProvider datetimeTestdataProvider
     */
    public function testDatabaseConvertsDatetimeToTimestampCorrectly(string $dt, int $base, ?int $exp): void
    {
        if (!isset($exp)) {
            $this->expectException(\Exception::class);
        }
        $ts = Database::sqlDateTimeToSeconds($dt, $base);
        $this->assertEquals($exp, $ts);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
