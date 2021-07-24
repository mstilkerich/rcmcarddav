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

use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use PHPUnit\Framework\TestCase;

/**
 * This class provides functionality to manage data in test databases.
 *
 * It allows to clear tables and insert rows into tables. For row insertion, it remembers the assigned row ID by the
 * database and enables to resolve foreign key references in subsequently inserted rows.
 *
 * @psalm-type TestDataKeyRef = array{0: string, 1: int, 2?: string}
 * @psalm-type TestDataRowWithKeyRef = array<int, null|string|TestDataKeyRef>
 * @psalm-type TestDataRow = array<int, null|string>
 * @psalm-type TestDataTableDef = list<string>
 * @psalm-type TableName = string
 * @psalm-type CacheKeyPrefix = string
 */
final class TestData
{
    /** @var TestDataTableDef Column names of the users table */
    public const USERS_COLUMNS = [ "username", "mail_host" ];

    /** @var TestDataTableDef Column names of the carddav_accounts table */
    public const ACCOUNTS_COLUMNS = [ "name", "username", "password", "url", "user_id" ];

    /** @var TestDataTableDef Column names of the carddav_addressbooks table */
    public const ADDRESSBOOKS_COLUMNS = [ "name", "url", "account_id", "sync_token" ];

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
     *             The value arrays must match the column descriptions in self::TABLES.
     *             To reference the primary key of a record in a different table, insert an array as value where the
     *             first value names the target table, the second value the index in the initialization data of that
     *             table of the record that should be referenced.
     */
    public const INITDATA = [
        "users" => [
            ["testuser@example.com", "mail.example.com"],
            ["otheruser@example.com", "mail.example.com"],
            ["userWithoutAddressbooks@example.com", "mail.example.com"],
        ],
        "carddav_accounts" => [
            [ "First Account", "u1", "p1", "https://contacts.example.com/", [ "users", 0 ] ],
            [ "Second Account", "u2", "p2", "https://contacts.example.com/", [ "users", 0 ] ],
        ],
        "carddav_addressbooks" => [
            [ "Empty Addressbook", "https://contacts.example.com/u1/empty/", [ "carddav_accounts", 0 ], "" ],
            [ "Small Addressbook", "https://contacts.example.com/u2/small/", [ "carddav_accounts", 1 ], "" ],
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

    /** @var list<array{string, TestDataTableDef}> List of tables to initialize and their columns.
     *             Tables will be initialized in the order given here. Initialization data is taken from self::INITDATA.
     */
    private const TABLES = [
        // table name,            table columns to insert
        [ "users", self::USERS_COLUMNS ],
        [ "carddav_accounts", self::ACCOUNTS_COLUMNS ],
        [ "carddav_addressbooks", self::ADDRESSBOOKS_COLUMNS ],
        [ "carddav_contacts", self::CONTACTS_COLUMNS ],
        [ "carddav_groups", self::GROUPS_COLUMNS ],
        [ "carddav_xsubtypes", self::XSUBTYPES_COLUMNS ],
        [ "carddav_migrations", self::MIGRATIONS_COLUMNS ],
    ];

    /**
     * @var array<TableName, array<CacheKeyPrefix, list<string>>> Remember DB ids for inserted rows.
     */
    private $idCache = [];

    /** @var \rcube_db */
    private $dbh;

    /** @var string A prefix used in creating cache keys. Used to decouple indexes from multiple test data sets. */
    private $cacheKeyPrefix = 'builtin';

    public function __construct(\rcube_db $dbh)
    {
        $this->dbh = $dbh;
    }

    public function setDbHandle(\rcube_db $dbh): void
    {
        $this->dbh = $dbh;
    }

    /**
     * Initializes the database with the test data.
     *
     * It initializes all tables listed in self::TABLES in the given order. Table data is cleared in reverse order
     * listed before inserting of data is started.
     *
     * @param bool $skipInitData If true, only the users table is populated, the carddav tables are left empty.
     */
    public function initDatabase(bool $skipInitData = false): void
    {
        foreach (array_column(array_reverse(self::TABLES), 0) as $tbl) {
            $this->purgeTable($tbl);
        }

        $this->idCache = [];
        $this->setCacheKeyPrefix('builtin');

        foreach (self::TABLES as $tbldesc) {
            [ $tbl, $cols ] = $tbldesc;
            TestCase::assertArrayHasKey($tbl, self::INITDATA, "No init data for table $tbl");

            if ($skipInitData && $tbl != "users") {
                continue;
            }

            foreach (self::INITDATA[$tbl] as $row) {
                $this->insertRow($tbl, $cols, $row);
            }
        }

        $userId = $this->idCache['users']['builtin'][0] ?? null;
        TestCase::assertIsString($userId);
        $_SESSION["user_id"] = $userId;

        // we need to set these session variables in case the placeholder replacement functions for username/password
        // are invoked by the test execution
        $_SESSION["username"] = self::INITDATA['users'][0][0];
        $_SESSION["password"] = \rcube::get_instance()->encrypt('test');
    }

    /**
     * Inserts the given row with test data into the DB, and resolves foreign key references within the row.
     *
     * @param TestDataTableDef $cols
     * @param TestDataRowWithKeyRef $row
     * @param-out TestDataRow $row
     * @return string ID of the inserted row
     */
    public function insertRow(string $tbl, array $cols, array &$row): string
    {
        $dbh = $this->dbh;

        $cols = array_map([$dbh, "quote_identifier"], $cols);
        TestCase::assertCount(count($cols), $row, "Column count mismatch of $tbl row " . print_r($row, true));

        $sql = "INSERT INTO " . $dbh->table_name($tbl)
            . " (" . implode(",", $cols) . ") "
            . "VALUES (" . implode(",", array_fill(0, count($cols), "?")) . ")";

        $newrow = [];
        foreach ($row as $val) {
            if (is_array($val)) {
                // resolve foreign key reference
                [ $dtbl, $didx ] = $val;
                $val = $this->getRowId($dtbl, $didx, $val[2] ?? null);
            }

            $newrow[] = $val;
        }
        $row = $newrow;

        $dbh->query($sql, $row);
        TestCase::assertNull($dbh->is_error(), "Error inserting row to $tbl: " . $dbh->is_error());
        $id = $dbh->insert_id($tbl);
        TestCase::assertIsString($id, "Error acquiring ID for last inserted row on $tbl: " . $dbh->is_error());
        $this->idCache[$tbl][$this->cacheKeyPrefix][] = $id;
        return $id;
    }

    /**
     * Purges all rows from the given table.
     */
    public function purgeTable(string $tbl): void
    {
        $dbh = $this->dbh;
        $dbh->query("DELETE FROM " . $dbh->table_name($tbl));
        TestCase::assertNull($dbh->is_error(), "Error clearing table $tbl " . $dbh->is_error());
        unset($this->idCache[$tbl]);
    }

    public function setCacheKeyPrefix(string $prefix): void
    {
        $this->cacheKeyPrefix = $prefix;
    }

    public function getRowId(string $tbl, int $idx, ?string $prefix = null): string
    {
        if (!isset($prefix)) {
            $prefix = $this->cacheKeyPrefix;
        }

        TestCase::assertTrue(
            isset($this->idCache[$tbl][$prefix][$idx]),
            "Reference to {$prefix}.{$tbl}[$idx] cannot be resolved"
        );

        return $this->idCache[$tbl][$prefix][$idx];
    }

    /**
     * @param TestDataKeyRef $fkRef
     */
    public function resolveFkRef(array $fkRef): string
    {
        [ $dtbl, $didx ] = $fkRef;
        $prefix = $fkRef[2] ?? null;
        return $this->getRowId($dtbl, $didx, $prefix);
    }

    /**
     * Resolves foreign key references in a row of test data.
     * @param TestDataRowWithKeyRef $row
     * @return TestDataRow
     */
    public function resolveFkRefsInRow(array $row): array
    {
        $result = [];
        foreach ($row as $cell) {
            if (is_array($cell)) {
                $result[] = $this->resolveFkRef($cell);
            } else {
                $result[] = $cell;
            }
        }
        return $result;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
