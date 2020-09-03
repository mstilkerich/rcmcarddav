<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\CardDavAddressbook4Roundcube\{Database};
use PHPUnit\Framework\TestCase;

final class TestData
{
    /** @var array Data to initialize the tables with.
     *             Keys are table names, values are arrays of value arrays, each of which contains the data for one row.
     *             The value arrays must match the column descriptions in self::$tables.
     *             To reference the primary key of a record in a different table, insert an array as value where the
     *             first value names the target table, the second value the index in the initialization data of that
     *             table of the record that should be referenced.
     */
    public static $data = [
        "users" => [
            ["testuser@example.com", "mail.example.com"],
        ],
        "carddav_addressbooks" => [
            [ "Empty Addressbook", "u1", "p1", "https://contacts.example.com/u1/empty/", [ "users", 0 ] ],
            [ "Small Addressbook", "u2", "p1", "https://contacts.example.com/u2/small/", [ "users", 0 ] ],
        ],
    ];

    /** @var array List of tables to initialize and their columns.
     *             Tables will be initialized in the order given here. Initialization data is taken from self::$data.
     */
    private static $tables = [
        // table name,            table columns to insert
        [ "users",                [ "username", "mail_host" ] ],
        [ "carddav_addressbooks", [ "name", "username", "password", "url", "user_id" ] ],
    ];

    /**
     * Initializes the database with the test data.
     *
     * It initializes all tables listed in self::$tables in the given order. Table data is cleared in reverse order
     * listed before inserting of data is started.
     */
    public static function initDatabase(): void
    {
        $dbh = Database::getDbHandle();

        foreach (array_column(array_reverse(self::$tables), 0) as $tbl) {
            $dbh->query("DELETE FROM " . $dbh->table_name($tbl));
            TestCase::assertNull($dbh->is_error(), "Error clearing table $tbl " . $dbh->is_error());
        }

        foreach (self::$tables as $tbldesc) {
            [ $tbl, $cols ] = $tbldesc;

            $cols = array_map([$dbh, "quote_identifier"], $cols);

            $sql = "INSERT INTO " . $dbh->table_name($tbl)
                . " (" . implode(",", $cols) . ") "
                . "VALUES (" . implode(",", array_fill(0, count($cols), "?")) . ")";

            TestCase::assertArrayHasKey($tbl, self::$data, "No init data for table $tbl");
            $rownum = 0;
            foreach (self::$data[$tbl] as &$row) {
                TestCase::assertEquals(count($cols), count($row), "Column count mismatch of $tbl[$rownum]");

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
                TestCase::assertNull($dbh->is_error(), "Error inserting row $tbl[$rownum]: " . $dbh->is_error());

                $row["id"] = $dbh->insert_id($tbl);
                ++$rownum;
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
