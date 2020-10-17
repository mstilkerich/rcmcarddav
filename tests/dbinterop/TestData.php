<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\CardDavAddressbook4Roundcube\{Database};
use PHPUnit\Framework\TestCase;

final class TestData
{
    /** @var array Column names of the users table */
    public const USERS_COLUMNS = [ "username", "mail_host" ];

    /** @var array Column names of the carddav_addressbooks table */
    public const ADDRESSBOOKS_COLUMNS = [ "name", "username", "password", "url", "user_id" ];

    /** @var array Column names of the carddav_xsubtypes table */
    public const XSUBTYPES_COLUMNS = [ "typename", "subtype", "abook_id" ];

    /** @var array Column names of the carddav_contacts table */
    public const CONTACTS_COLUMNS = [
        "abook_id", "name", "email", "firstname", "surname", "organization", "showas", "vcard", "etag", "uri", "cuid"
    ];

    /** @var array Column names of the carddav_groups table */
    public const GROUPS_COLUMNS = [ "abook_id", "name", "vcard", "etag", "uri", "cuid" ];

    /** @var array Column names of the carddav_migrations table */
    public const MIGRATIONS_COLUMNS = [ "filename" ];

    /** @var array Data to initialize the tables with.
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
            [ "Empty Addressbook", "u1", "p1", "https://contacts.example.com/u1/empty/", [ "users", 0 ] ],
            [ "Small Addressbook", "u2", "p1", "https://contacts.example.com/u2/small/", [ "users", 0 ] ],
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

    /** @var array */
    public static $data;

    /** @var array List of tables to initialize and their columns.
     *             Tables will be initialized in the order given here. Initialization data is taken from self::$data.
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
     */
    public static function initDatabase(): void
    {
        self::$data = self::INITDATA;
        $dbh = Database::getDbHandle();

        foreach (array_column(array_reverse(self::$tables), 0) as $tbl) {
            $dbh->query("DELETE FROM " . $dbh->table_name($tbl));
            TestCase::assertNull($dbh->is_error(), "Error clearing table $tbl " . $dbh->is_error());
        }

        foreach (self::$tables as $tbldesc) {
            [ $tbl, $cols ] = $tbldesc;
            TestCase::assertArrayHasKey($tbl, self::$data, "No init data for table $tbl");

            foreach (self::$data[$tbl] as &$row) {
                $dbid = self::insertRow($tbl, $cols, $row);
                $row["id"] = $dbid;
            }
        }
    }

    /**
     */
    public static function insertRow(string $tbl, array $cols, array &$row): string
    {
        $dbh = Database::getDbHandle();
        $cols = array_map([$dbh, "quote_identifier"], $cols);
        TestCase::assertEquals(count($cols), count($row), "Column count mismatch of $tbl row" . join(",", $row));

        $sql = "INSERT INTO " . $dbh->table_name($tbl)
            . " (" . implode(",", $cols) . ") "
            . "VALUES (" . implode(",", array_fill(0, count($cols), "?")) . ")";

        foreach ($row as &$val) {
            if (is_array($val)) {
                [ $dt, $di ] = $val;
                TestCase::assertArrayHasKey(
                    "id",
                    self::$data[$dt][$di],
                    "Reference to $dt[$di] cannot be resolved"
                );
                $val = self::$data[$dt][$di]["id"];
            }
        }

        $dbh->query($sql, $row);
        TestCase::assertNull($dbh->is_error(), "Error inserting row to $tbl: " . $dbh->is_error());
        return $dbh->insert_id($tbl);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
