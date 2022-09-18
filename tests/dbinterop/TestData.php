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

use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use PHPUnit\Framework\TestCase;

/**
 * @psalm-type TestDataKeyRef = array{string, int}
 * @psalm-type TestDataRowWithKeyRef = array<int, null|string|TestDataKeyRef>
 * @psalm-type TestDataRowWithId = array{id?: string} & array<int, null|string>
 * @psalm-type TestDataRow = array<int, null|string>
 * @psalm-type TestDataTableDef = list<string>
 */
final class TestData
{
    /** @var TestDataTableDef Column names of the users table */
    public const USERS_COLUMNS = [ "username", "mail_host" ];

    /** @var TestDataTableDef Column names of the carddav_addressbooks table */
    public const ADDRESSBOOKS_COLUMNS = [ "name", "username", "password", "url", "user_id", "sync_token" ];

    /** @var TestDataTableDef Column names of the carddav_xsubtypes table */
    public const XSUBTYPES_COLUMNS = [ "typename", "subtype", "abook_id" ];

    /** @var TestDataTableDef Column names of the carddav_contacts table */
    public const CONTACTS_COLUMNS = [
        "abook_id", "name", "email", "firstname", "surname", "organization", "showas", "vcard", "etag", "uri", "cuid"
    ];

    /** @var TestDataTableDef Column names of the carddav_groups table */
    public const GROUPS_COLUMNS = [ "abook_id", "name", "vcard", "etag", "uri", "cuid" ];

    /** @var TestDataTableDef Column names of the carddav_migrations table */
    public const MIGRATIONS_COLUMNS = [ "filename" ];

    /** @var array<string, list<TestDataRowWithKeyRef>> Data to initialize the tables with.
     *             Keys are table names, values are arrays of value arrays, each of which contains the data for one row.
     *             The value arrays must match the column descriptions in self::$tables.
     *             To reference the primary key of a record in a different table, insert an array as value where the
     *             first value names the target table, the second value the index in the initialization data of that
     *             table of the record that should be referenced.
     */
    private const INITDATA = [
        "users" => [
            ["testuser@example.com", "mail.example.com"],
        ],
        "carddav_addressbooks" => [
            [ "Empty Addressbook", "u1", "p1", "https://contacts.example.com/u1/empty/", [ "users", 0 ], "" ],
            [ "Small Addressbook", "u2", "p1", "https://contacts.example.com/u2/small/", [ "users", 0 ], "" ],
        ],
        "carddav_contacts" => [
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
                "/u2/small/maxmuster.vcf",
                "2459ca8d-1b8e-465e-8e88-1034dc87c2ec"
            ],
        ],
        "carddav_groups" => [
            [
                [ "carddav_addressbooks", 1 ],
                "Test Gruppe Vcard-style",
                "FIXME INVALID VCARD",
                "ex-etag-1234",
                "/u2/small/testgroup.vcf",
                "11b98f71-ada1-4a28-b6ab-28ad09be0203"
            ],
            [
                [ "carddav_addressbooks", 1 ],
                "Test Gruppe CATEGORIES-style",
                null,
                null,
                null,
                null
            ],
        ],
        "carddav_xsubtypes" => [
            [ "email" , "customMail", [ "carddav_addressbooks", 1 ] ],
            [ "phone" , "customPhone", [ "carddav_addressbooks", 1 ] ],
            [ "address" , "customAddress", [ "carddav_addressbooks", 1 ] ],
        ],
        "carddav_migrations" => [
            [ "0000-dbinit" ],
            [ "0001-categories" ],
            [ "0002-increasetextfieldlengths" ],
            [ "0003-fixtimestampdefaultvalue" ],
            [ "0004-fixtimestampdefaultvalue" ],
            [ "0005-changemysqlut8toutf8mb4" ],
            [ "0006-rmgroupsnotnull" ],
            [ "0007-replaceurlplaceholders" ],
            [ "0008-unifyindexes" ],
            [ "0009-dropauthschemefield" ],
        ],
    ];

    /** @var array<string, list<TestDataRowWithId>> Data to initialize the tables with. */
    public static $data;

    /** @var list<array{string, TestDataTableDef}> List of tables to initialize and their columns.
     *             Tables will be initialized in the order given here. Initialization data is taken from self::INITDATA.
     */
    private static $tables = [
        // table name,            table columns to insert
        [ "users", self::USERS_COLUMNS ],
        [ "carddav_addressbooks", self::ADDRESSBOOKS_COLUMNS ],
        [ "carddav_contacts", self::CONTACTS_COLUMNS ],
        [ "carddav_groups", self::GROUPS_COLUMNS ],
        [ "carddav_xsubtypes", self::XSUBTYPES_COLUMNS ],
        [ "carddav_migrations", self::MIGRATIONS_COLUMNS ],
    ];

    /**
     * Initializes the database with the test data.
     *
     * It initializes all tables listed in self::$tables in the given order. Table data is cleared in reverse order
     * listed before inserting of data is started.
     *
     * @param bool $skipInitData If true, only the users table is populated, the carddav tables are left empty.
     */
    public static function initDatabase(bool $skipInitData = false): void
    {
        $dbh = TestInfrastructureDB::getDbHandle();

        foreach (array_column(array_reverse(self::$tables), 0) as $tbl) {
            $dbh->query("DELETE FROM " . $dbh->table_name($tbl));
            TestCase::assertNull($dbh->is_error(), "Error clearing table $tbl " . $dbh->is_error());
        }

        self::$data = [];
        foreach (self::$tables as $tbldesc) {
            [ $tbl, $cols ] = $tbldesc;
            TestCase::assertArrayHasKey($tbl, self::INITDATA, "No init data for table $tbl");

            if ($skipInitData && $tbl != "users") {
                continue;
            }

            self::$data[$tbl] = [];
            foreach (self::INITDATA[$tbl] as $row) {
                $id = self::insertRow($tbl, $cols, $row);
                $row["id"] = $id;
                self::$data[$tbl][] = $row;
            }
        }

        $_SESSION["user_id"] = self::$data["users"][0]["id"];
    }

    /**
     * @param TestDataTableDef $cols
     * @param TestDataRowWithKeyRef $row
     * @param-out TestDataRow $row
     * @return string ID of the inserted row
     */
    public static function insertRow(string $tbl, array $cols, array &$row): string
    {
        $dbh = TestInfrastructureDB::getDbHandle();
        $cols = array_map([$dbh, "quote_identifier"], $cols);
        TestCase::assertCount(count($cols), $row, "Column count mismatch of $tbl row " . print_r($row, true));

        $sql = "INSERT INTO " . $dbh->table_name($tbl)
            . " (" . implode(",", $cols) . ") "
            . "VALUES (" . implode(",", array_fill(0, count($cols), "?")) . ")";

        $newrow = [];
        foreach ($row as $val) {
            if (is_array($val)) {
                [ $dt, $di ] = $val;
                TestCase::assertTrue(
                    isset(self::$data[$dt][$di]["id"]),
                    "Reference to $dt[$di] cannot be resolved"
                );
                $val = self::$data[$dt][$di]["id"];
            }
            $newrow[] = $val;
        }
        $row = $newrow;

        $dbh->query($sql, $row);
        TestCase::assertNull($dbh->is_error(), "Error inserting row to $tbl: " . $dbh->is_error());
        $id = $dbh->insert_id($tbl);
        TestCase::assertIsString($id, "Error acquiring ID for last inserted row on $tbl: " . $dbh->is_error());

        return $id;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
