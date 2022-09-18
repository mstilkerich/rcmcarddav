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

/**
 * @psalm-import-type TestDataTableDef from TestData
 * @psalm-import-type TestDataRowWithKeyRef from TestData
 */
final class DatabaseCollationsTest extends TestCase
{
    /** @var AbstractDatabase */
    private static $db;

    public static function setUpBeforeClass(): void
    {
        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        self::$db = TestInfrastructureDB::initDatabase($db_dsnw);
        TestInfrastructure::init(self::$db);
        TestData::initDatabase();
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    /**
     * @return array<string, array{string, TestDataTableDef, list<TestDataRowWithKeyRef>}>
     */
    public function uniqueDataProviderDiffcase(): array
    {
        return [
            'xsubtypes' => [
                'carddav_xsubtypes',
                TestData::XSUBTYPES_COLUMNS,
                [
                    [ "email", "custommail", [ "carddav_addressbooks", 1 ] ],
                    [ "phone", "customphone", [ "carddav_addressbooks", 1 ] ],
                    [ "address", "customaddress", [ "carddav_addressbooks", 1 ] ],
                    [ "Email" , "customMail", [ "carddav_addressbooks", 1 ] ],
                    [ "Phone" , "customPhone", [ "carddav_addressbooks", 1 ] ],
                    [ "Address" , "customAddress", [ "carddav_addressbooks", 1 ] ],
                ]
            ],
            'migrations' => [
                'carddav_migrations',
                TestData::MIGRATIONS_COLUMNS,
                [
                    [ "0001-Categories" ], // different case
                ]
            ],
            'contacts' => [
                'carddav_contacts',
                TestData::CONTACTS_COLUMNS,
                [
                    [
                        [ "carddav_addressbooks", 1 ],
                        "Max Mustermann",
                        "max@mustermann.com, max.mustermann@company.com",
                        "Max",
                        "Mustermann",
                        "Company",
                        "INDIVIDUAL",
                        "FIXME INVALID VCARD",
                        "ex-etag-123",
                        "/U2/small/maxmuster.vcf", // different case
                        "2459ca8d-1b8e-465e-8e88-1034dc87c2ec-TEST"
                    ],
                    [
                        [ "carddav_addressbooks", 1 ],
                        "Max Mustermann",
                        "max@mustermann.com, max.mustermann@company.com",
                        "Max",
                        "Mustermann",
                        "Company",
                        "INDIVIDUAL",
                        "FIXME INVALID VCARD",
                        "ex-etag-123",
                        "/u2/small/maxmuster2.vcf",
                        "2459ca8d-1b8e-465e-8e88-1034dc87c2eC" // different case
                    ]
                ]
            ],
            'groups' => [
                'carddav_groups',
                TestData::GROUPS_COLUMNS,
                [
                    [
                        [ "carddav_addressbooks", 1 ],
                        "Test Gruppe Vcard-style",
                        "FIXME INVALID VCARD",
                        "ex-etag-1234",
                        "/U2/small/testgroup.vcf", // different case
                        "11b98f71-ada1-4a28-b6ab-28ad09be0203-TEST"
                    ],
                    [
                        [ "carddav_addressbooks", 1 ],
                        "Test Gruppe Vcard-style",
                        "FIXME INVALID VCARD",
                        "ex-etag-1234",
                        "/u2/small/testgroup2.vcf",
                        "11B98f71-ada1-4a28-b6ab-28ad09be0203" // different case
                    ],
                    [
                        [ "carddav_addressbooks", 1 ],
                        "Test Gruppe 2 CATEGORIES-style",
                        null,
                        null,
                        null,
                        null
                    ],
                ]
            ]
        ];
    }

    /**
     * Tests that our UNIQUE constraints in the database use case-insensitive semantics on the included key components.
     *
     * @param string $tbl Name of the table
     * @param TestDataTableDef $cols List of column names of the table
     * @param list<TestDataRowWithKeyRef> $datarows List of data rows matching the columns in $cols
     *
     * @dataProvider uniqueDataProviderDiffcase
     */
    public function testUniqueConstraintCaseSensitive(string $tbl, array $cols, array $datarows): void
    {
        $db = self::$db;
        foreach ($datarows as $row) {
            $tblshort = preg_replace('/^carddav_/', '', $tbl);
            $this->assertNotNull($tblshort);

            TestData::insertRow($tbl, $cols, $row);
            $row2 = $db->lookup(array_combine($cols, $row), $cols, $tblshort);
            $this->assertEquals(array_combine($cols, $row), $row2, "Inserted row not same as in DB");
        }
    }

    /**
     * @return array<string, array{string, TestDataTableDef, TestDataRowWithKeyRef, list<positive-int>}>
     */
    public function equalsDataProviderDiffcase(): array
    {
        return [
            'contacts' => [
                'carddav_contacts',
                TestData::CONTACTS_COLUMNS,
                [
                    [ "carddav_addressbooks", 1 ],
                    "Hans Wurst",
                    "hans.wurst@example.com",
                    "Hans",
                    "Wurst",
                    "Die Firma",
                    "INDIVIDUAL",
                    "FIXME INVALID VCARD",
                    "ex-etag-123",
                    "/u2/small/hanswurst.vcf",
                    "a98102ba-767e-4f62-a5b5-8f1300259a5a"
                ],
                [ 1, 2, 3, 4, 5, 9, 10 ] // indexes of columns to uppercase in 2nd record
            ],
        ];
    }

    /**
     * Tests that Database::get value match select criterion works case sensitive.
     *
     * @param string $tbl Name of the table
     * @param TestDataTableDef $cols List of column names of the table
     * @param TestDataRowWithKeyRef $row The data row
     * @param list<positive-int> $uccols List of column indexes to uppercase in 2nd record
     *
     * @dataProvider equalsDataProviderDiffcase
     */
    public function testEqualsOperatorIsCaseSensitive(string $tbl, array $cols, array $row, array $uccols): void
    {
        $db = self::$db;

        $tblshort = preg_replace('/^carddav_/', '', $tbl);
        $this->assertNotNull($tblshort);

        TestData::insertRow($tbl, $cols, $row);

        // insert row with specified columns uppercased
        $rowuc = $row;
        foreach ($uccols as $idx) {
            $this->assertIsString($rowuc[$idx]);
            $rowuc[$idx] = strtoupper($rowuc[$idx]);
        }
        $rowucId = TestData::insertRow($tbl, $cols, $rowuc);

        // check that select with equals on any uppercase column returns only the new uppercased record
        foreach ($uccols as $idx) {
            $rowuc2 = $db->lookup([$cols[$idx] => $rowuc[$idx]], ["id"], $tblshort);
            $this->assertEquals($rowucId, $rowuc2["id"], "Queried row not the uppercased one for $tbl $cols[$idx]");
        }
    }


    /**
     * @return array<string, array{string, TestDataTableDef, TestDataRowWithKeyRef, array<positive-int,?string>}>
     */
    public function ilikeDataProviderDiffcase(): array
    {
        return [
            'contacts' => [
                'carddav_contacts',
                TestData::CONTACTS_COLUMNS,
                [
                    [ "carddav_addressbooks", 1 ],
                    "Jane Doe",
                    "jane@doe.com",
                    "Jane",
                    "Doe",
                    "Doe Inc.",
                    "INDIVIDUAL",
                    "FIXME INVALID VCARD",
                    "ex-etag-123",
                    "/u2/small/janedoe.vcf",
                    "eb2802b5-db09-4e0e-8240-5423d10618e5"
                ],
                // indexes of columns to uppercase in 2nd record and ILIKE patterns (null to only uppercase)
                [ 1 => '%ne doe', 2 => '%nE@Do%', 3 => 'jANE', 4 => 'dOE', 5 => '%oe iNC%', 9 => null, 10 => null ],
            ],
        ];
    }

    /**
     * Tests that the Database::get ILIKE selector works case insensitive.
     *
     * @param string $tbl Name of the table
     * @param TestDataTableDef $cols List of column names of the table
     * @param TestDataRowWithKeyRef $row The data row
     * @param array<positive-int,?string> $uccols indexes of columns to uppercase in 2nd record and ILIKE patterns (null
     *                                            to only uppercase)
     *
     * @dataProvider ilikeDataProviderDiffcase
     */
    public function testIlikeSelectorIsCaseInsensitive(string $tbl, array $cols, array $row, array $uccols): void
    {
        $db = self::$db;

        $tblshort = preg_replace('/^carddav_/', '', $tbl);
        $this->assertNotNull($tblshort);

        $rowId = TestData::insertRow($tbl, $cols, $row);

        // insert row with specified columns uppercased
        $rowuc = $row;
        foreach ($uccols as $idx => $pattern) {
            $this->assertIsString($rowuc[$idx]);
            if (isset($pattern)) {
                $rowuc[$idx] = strtoupper($rowuc[$idx]);
            } else {
                // just add a random string to avoid unique index clash, this field will not be tested
                $rowuc[$idx] = $rowuc[$idx] . "-" . bin2hex(random_bytes(5));
            }
        }
        $rowucId = TestData::insertRow($tbl, $cols, $rowuc);

        // check that select with equals on any uppercase column returns only the new uppercased record
        $expectedIds = [$rowId, $rowucId];
        sort($expectedIds);

        foreach ($uccols as $idx => $pattern) {
            if (isset($pattern)) {
                $rows = $db->get(["%$cols[$idx]" => $pattern], ["id"], $tblshort);
                $this->assertCount(2, $rows, "Number of returned $tbl rows not as expected ($cols[$idx])");

                $gotIds = array_column($rows, "id");
                sort($gotIds);

                $this->assertEquals(
                    $expectedIds,
                    $gotIds,
                    "Queried rows not the original and uppercased one for $tbl $cols[$idx]"
                );
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
