<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use rcube_db;
use PHPUnit\Framework\TestCase;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\{DbAndCondition,DbOrCondition};

/**
 * Implementation of the database access interface to the roundcube DB for unit tests.
 *
 * It emulates the DB operations based on an initial dataset read from a Json file.
 */
class JsonDatabase extends AbstractDatabase
{
    /** @var bool $inTransaction Indicates whether we are currently inside a transaction */
    private $inTransaction = false;

    /** @var array The definition of DB tables and their columns */
    private $schema;

    /** @var array The data of all the tables */
    private $data;

    /**
     * Initializes a JsonDatabase instance.
     *
     * @param string[] $jsonDbData Paths to JSON files containing the initial data for the tables.
     * @param string $jsonDbSchema Path to a JSON file defining the tables.
     */
    public function __construct(
        array $jsonDbData = [],
        string $jsonDbSchema = "tests/unit/data/jsonDb/schema.json"
    ) {
        $this->schema = TestInfrastructure::readJsonArray($jsonDbSchema);
        $this->validateSchema();
        foreach (array_keys($this->schema) as $table) {
            $this->data[$table] = [];
        }

        foreach ($jsonDbData as $dataFile) {
            $this->importData($dataFile);
        }
    }

    /**
     * Validates the loaded database schema.
     *
     * It performs the following checks:
     *   - Foreign key references point to existing key columns
     */
    private function validateSchema(): void
    {
        foreach ($this->schema as $table => $tcols) {
            foreach ($tcols as $col => $coldef) {
                $coldef = $this->parseColumnDef($coldef);
                if (!empty($coldef["fktable"])) {
                    $fktable = $coldef["fktable"];
                    $fkcol = $coldef["fkcolumn"];
                    if (($this->schema[$fktable][$fkcol] ?? "") !== "key") {
                        throw new \Exception("Invalid foreign key ref $table.$col -> $fktable.$fkcol");
                    }
                }
            }
        }
    }

    /**
     * Imports the data rows contained in the given JSON file to the corresponding tables.
     *
     * Data can be important from external sources (currently from an external file) if the data item of a cell is not a
     * string value but an array. The array has two members: A type (currently only "file") and a parameter (the
     * filename, relative to the JSON file).
     *
     * The function uses the insert() function to add each imported row to the table, and therefore the data validation
     * that is performed by insert() also applies to data imported from JSON files.
     *
     * @param string $dataFile Path to a JSON file containing the row definitions.
     */
    private function importData(string $dataFile): void
    {
        $data = TestInfrastructure::readJsonArray($dataFile);

        foreach ($data as $table => $rows) {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $vals = [];
                foreach ($cols as $col) {
                    if (isset($row[$col])) {
                        if (is_array($row[$col])) {
                            [ $type, $param ] = $row[$col];
                            if ($type === "file") {
                                $vals[] = TestInfrastructure::readFileRelative($param, $dataFile);
                            } else {
                                throw new \Exception("Unknown data input type $type with param $param");
                            }
                        } else {
                            $vals[] = (string) $row[$col];
                        }
                    } else {
                        $vals[] = null;
                    }
                }
                $this->insert($table, $cols, [$vals]);
            }
        }
    }

    public function getDbHandle(): rcube_db
    {
        throw new \Exception("getDbHandle() is not implemented - adapt the tested class to not use it");
    }

    /**
     * This function compares two rows of a table, possibly from different databases.
     *
     * It returns an integer that defines an order between the rows and therefore can also be used to sort table rows
     * for easier comparison of two entire tables.
     *
     * The comparison considers that key values may differ in rows of different databases. The values of key columns are
     * ignored in the comparison. For foreign key columns, this function is called recursively to compare the referenced
     * rows to determine equality, not the value of the foreign key.
     *
     * @param string $table The table the given rows belong to.
     * @param JsonDatabase $row2Db The JsonDatabase object $row2 belongs to ($row1 always belong to $this)
     * @param string[] $row1 A row from the given table in $this JsonDatabase object
     * @param string[] $row2 A row from the given table in $row2Db JsonDatabase object
     * @return int Negative/Zero/Positive if $row1 is smaller/equal/greater than $row2
     */
    public function compareRows(string $table, JsonDatabase $row2Db, array $row1, array $row2): int
    {
        // check parameters
        TestCase::assertArrayHasKey($table, $this->schema, "compareRows of unknown table $table");
        $tcols = $this->schema[$table];

        TestCase::assertCount(count($tcols), $row1, "compareRows for row1 with columns not matching schema");
        TestCase::assertCount(count($tcols), $row2, "compareRows for row2 with columns not matching schema");

        // compare all the columns
        foreach ($tcols as $col => $coldef) {
            $coldef = $this->parseColumnDef($coldef);

            TestCase::assertArrayHasKey($col, $row1, "compareRows: row1 lacks column $col");
            TestCase::assertArrayHasKey($col, $row2, "compareRows: row2 lacks column $col");

            if (!empty($coldef["fktable"])) {
                $fktable = $coldef["fktable"];
                $fkcol = $coldef["fkcolumn"];
                $frow1 = $this->lookup([$fkcol => $row1[$col]], '*', $fktable);
                $frow2 = $row2Db->lookup([$fkcol => $row2[$col]], '*', $fktable);

                $result = $this->compareRows($fktable, $row2Db, $frow1, $frow2);
            } elseif ($coldef["type"] === "key") {
                $result = 0;
            } else {
                if (isset($row1[$col]) && isset($row2[$col])) {
                    $result = strcmp($row1[$col], $row2[$col]);
                } elseif (isset($row1[$col])) {
                    $result = 1;
                } elseif (isset($row2[$col])) {
                    $result = -1;
                } else {
                    $result = 0;
                }
            }

            if ($result !== 0) {
                return $result;
            }
        }

        return 0;
    }

    /**
     * Compares two tables of different databases with identical schema.
     *
     * The result is reported via JUnit assertions, i.e. a test calling this function should expect equality, therefore
     * the test will fail in case of inequality of the two tables.
     *
     * @param string $table Name of the table to compare
     * @param JsonDatabase $otherDb The other database whose table $table should be compared to the one in $this.
     */
    public function compareTables(string $table, JsonDatabase $otherDb): void
    {
        // check parameters
        TestCase::assertArrayHasKey($table, $this->schema, "compareTables of unknown table $table");
        TestCase::assertArrayHasKey($table, $otherDb->schema, "compareTables of unknown table $table");
        TestCase::assertEquals($this->schema[$table], $otherDb->schema[$table], "Schema of table $table mismatch");
        TestCase::assertArrayHasKey($table, $this->data, "compareTables of unknown table $table");
        TestCase::assertArrayHasKey($table, $otherDb->data, "compareTables of unknown table $table");

        $compareFn1 = function (array $row1, array $row2) use ($table): int {
            return $this->compareRows($table, $this, $row1, $row2);
        };
        $compareFn2 = function (array $row1, array $row2) use ($table, $otherDb): int {
            return $otherDb->compareRows($table, $otherDb, $row1, $row2);
        };

        $t1sorted = $this->data[$table];
        $t2sorted = $otherDb->data[$table];
        usort($t1sorted, $compareFn1);
        usort($t2sorted, $compareFn2);

        TestCase::assertCount(count($t1sorted), $t2sorted, "Compare tables $table with different number of rows");
        for ($i = 0; $i < count($t1sorted); ++$i) {
            $diff = $this->compareRows($table, $otherDb, $t1sorted[$i], $t2sorted[$i]);
            if ($diff !== 0) {
                TestCase::assertEquals($t1sorted[$i], $t2sorted[$i], "$table row $i differs");
            }
        }
    }

    public function startTransaction(bool $readonly = true): void
    {
        if ($this->inTransaction) {
            throw new \Exception("Cannot start nested transaction");
        } else {
            $this->inTransaction = true;
        }
    }

    public function endTransaction(): void
    {
        if ($this->inTransaction) {
            $this->inTransaction = false;
        } else {
            throw new \Exception("Attempt to commit a transaction while not within a transaction");
        }
    }

    public function rollbackTransaction(): void
    {
        $this->inTransaction = false;
    }

    public function checkMigrations(string $dbPrefix, string $scriptDir): void
    {
        throw new \Exception("checkMigrations() is not implemented");
    }

    public function insert(string $table, array $cols, array $rows): string
    {
        // check parameters
        TestCase::assertArrayHasKey($table, $this->schema, "Insert for unknown table $table");

        $numCols = count($cols);
        if (empty($rows)) {
            throw new \Exception(__METHOD__ . " on $table called without rows to insert");
        }

        $dbid = "";
        // insert rows
        foreach ($rows as $row) {
            if (count($row) != $numCols) {
                throw new \Exception(__METHOD__ . " on $table: row given that does not match $numCols columns");
            }

            // build record from given data
            $record = [];
            for ($i = 0; $i < $numCols; ++$i) {
                $col = $cols[$i];
                TestCase::assertArrayHasKey($col, $this->schema[$table], "insert on $table with unknown column $col");
                $record[$col] = $row[$i];
            }

            // check / complete record for insertion
            $record = $this->validateRecord($table, $record);
            $this->data[$table][] = $record;
            $dbid = $record["id"] ?? "";
        }

        return $dbid;
    }

    public function update(
        $conditions,
        array $cols,
        array $vals,
        string $table = 'contacts'
    ): int {
        // check parameters
        TestCase::assertArrayHasKey($table, $this->data, "Update for unknown table $table");
        TestCase::assertSame(count($cols), count($vals), __METHOD__ . " called with non-matching cols/vals");

        $rowsAffected = 0;
        foreach ($this->data[$table] as &$row) {
            if ($this->checkRowMatch($conditions, $row)) {
                ++$rowsAffected;

                foreach (array_combine($cols, $vals) as $col => $val) {
                    $this->validateValue($table, $col, $val, false);
                    $row[$col] = $val;
                }
            }
        }

        return $rowsAffected;
    }

    public function delete($conditions, string $table = 'contacts'): int
    {
        // check parameters
        TestCase::assertArrayHasKey($table, $this->data, "Update for unknown table $table");

        $rowsAffected = 0;
        $newRows = [];
        foreach ($this->data[$table] as &$row) {
            if ($this->checkRowMatch($conditions, $row)) {
                ++$rowsAffected;
            } else {
                $newRows[] = $row;
            }
        }

        $this->data[$table] = $newRows;
        return $rowsAffected;
    }

    private function validateRecord(string $table, array $record): array
    {
        foreach (array_keys($this->schema[$table]) as $col) {
            if (key_exists($col, $record)) {
                $this->validateValue($table, $col, $record[$col], true);
            } else {
                $record[$col] = $this->defaultValue($table, $col);
            }
        }

        return $record;
    }

    private function validateValue(string $table, string $col, ?string $value, bool $newRecord): void
    {
        $coldef = $this->parseColumnDef($this->schema[$table][$col]);

        if (!empty($coldef["fktable"])) {
            $this->checkKey($coldef["fktable"], $coldef["fkcolumn"], $value, false);
        } elseif ($coldef["type"] === "key") {
            $this->checkKey($table, $col, $value, $newRecord);
        } else {
            if (!$coldef["nullable"]) {
                TestCase::assertNotNull($value);
            }
            if (isset($value)) {
                if ($coldef["type"] === "int") {
                    TestCase::assertStringMatchesFormat("%i", $value, "column $table.$col must be int ($value)");
                }
            }
        }
    }

    /**
     * Parses a column definition.
     *
     * Returns an associative array with indexes:
     * nullable: true if nullable, false otherwise
     * type: Type of the column (string, int, key)
     * fktable: For a foreign key reference, table of the foreign key, empty string otherwise
     * fkcolumn: For a foreign key reference, column name of the foreign key, empty string otherwise
     * hasdefault: true if default value defined, false otherwise
     * defaultval: Default value; only valid if hasdefault is true
     */
    private function parseColumnDef(string $coldef): array
    {
        if (
            preg_match(
                '/^(\?)?' .
                '(key(\[([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)\])?|int|string)' .
                '(=?)(.*)?$/',
                $coldef,
                $matches
            )
        ) {
            $ret = [
                'nullable' => (($matches[1] ?? "") === "?"),
                'type' => $matches[2] ?? "",
                'fktable' => $matches[4] ?? "",
                'fkcolumn' => $matches[5] ?? "",
                'hasdefault' => (($matches[6] ?? "") === "="),
                'defaultval' => $matches[7] ?? ""
            ];

            // key[fktable.fkcol] -> key
            $ret['type'] = preg_replace('/^key\[.*/', 'key', $ret['type']);

            return $ret;
        } else {
            throw new \Exception("Unparsable column definition $coldef");
        }
    }

    private function checkKey(string $table, string $col, ?string $value, bool $newRecord): void
    {
        TestCase::assertNotNull($value, "key column $table.$col must not be null");
        TestCase::assertStringMatchesFormat("%d", $value, "key column $table.$col must be unsigned int ($value)");
        $usedKeys = array_column($this->data[$table], $col);
        if ($newRecord) {
            TestCase::assertNotContains($value, $usedKeys, "$table.$col=$value already exists for new record");
        } else {
            TestCase::assertContains($value, $usedKeys, "$table.$col=$value not found when expected");
        }
    }

    private function defaultValue(string $table, string $col): ?string
    {
        $coldef = $this->parseColumnDef($this->schema[$table][$col]);

        if (!empty($coldef["fktable"])) {
            throw new \Exception("Default value for foreign key references not supported ($table.$col)");
        } elseif ($coldef["type"] === "key") {
            $usedKeys = array_column($this->data[$table], $col);
            $defaultValue = (string) (empty($usedKeys) ? 1 : (max($usedKeys) + 1));
        } else {
            if ($coldef["hasdefault"]) {
                $defaultValue = $coldef["defaultval"];
            } elseif ($coldef["nullable"]) {
                $defaultValue = null;
            } else {
                throw new \Exception("No default value for non-null column $table.$col");
            }
        }

        return $defaultValue;
    }

    public function lookup($conditions, string $cols = '*', string $table = 'contacts'): array
    {
        $rows = $this->get($conditions, $cols, $table);

        $numRows = count($rows);
        if ($numRows !== 1) {
            throw new \Exception("Single-row query on $table with $numRows results: " . print_r($conditions, true));
        }

        return $rows[0];
    }

    public function get($conditions, string $cols = '*', string $table = 'contacts', array $options = []): array
    {
        TestCase::assertArrayHasKey($table, $this->data, "Get for unknown table $table");
        $columns = $cols == '*' ? array_keys($this->schema[$table]) : preg_split('/\s*,\s*/', $cols);
        $filteredRows = [];

        $unknownCols = array_diff($columns, array_keys($this->schema[$table]));
        TestCase::assertCount(0, $unknownCols, "Get for unknown columns in table $table: " . join(', ', $unknownCols));

        $columns = array_flip($columns);
        foreach ($this->data[$table] as $row) {
            if ($this->checkRowMatch($conditions, $row)) {
                $filteredRows[] = array_intersect_key($row, $columns);
            }
        }

        // ORDER
        if (is_array($options['order'] ?? null)) {
            $orderDef = [];
            foreach ($options['order'] as $col) {
                if ($col[0] === "!") {
                    $orderDef[] = [ substr($col, 1), -1 ];
                } else {
                    $orderDef[] = [ $col, 1 ];
                }
            }

            if (!empty($orderDef)) {
                usort(
                    $filteredRows,
                    function (array $a, array $b) use ($orderDef): int {
                        $res = 0;
                        foreach ($orderDef as $od) {
                            [ $col, $orderAsc ] = $od;
                            $res = strcmp($a[$col], $b[$col]) * $orderAsc;
                            if ($res !== 0) {
                                break;
                            }
                        }
                        return $res;
                    }
                );
            }
        }

        // COUNT
        if ($options['count'] ?? false) {
            $cntcols = preg_split("/\s*,\s*/", $cols);
            $result = [];

            foreach ($cntcols as $col) {
                if ($col === '*') {
                    $result['*'] = count($filteredRows);
                } else {
                    $nonNull = array_filter(
                        array_column($filteredRows, $col),
                        function (?string $v): bool {
                            return isset($v);
                        }
                    );
                    $result[$col] = count($nonNull);
                }
            }

            $filteredRows = [ $result ];
        }

        // LIMIT
        if (isset($options['limit'])) {
            $l = $options['limit'];
            $err = false;
            if (is_array($l) && count($l) == 2) {
                [ $offset, $limit ] = $l;
                if (is_int($offset) && is_int($limit) && $offset >= 0 && $limit > 0) {
                    $filteredRows = array_slice($filteredRows, $offset, $limit);
                } else {
                    $err = true;
                }
            } else {
                $err = true;
            }

            if ($err) {
                $msg = "The limit option needs an array parameter of two unsigned integers [offset,limit]; got: ";
                $msg .= print_r($l, true);
                throw new \Exception($msg);
            }
        }

        return $filteredRows;
    }

    /**
     * Checks if the given row matches the given condition.
     *
     * @param DbOrCondition $orCond
     * @param (?string)[] $row The DB row to match with the condition
     *
     * @return bool Match result of condition against row
     *
     * @see DbOrCondition For a description on the format of field/value specifiers.
     */
    private function checkRowMatchSingleCondition(DbOrCondition $orCond, array $row): bool
    {
        $invertCondition = false;
        $ilike = false;

        $field = $orCond->fieldSpec;
        $value = $orCond->valueSpec;

        if ($field[0] === "!") {
            $field = substr($field, 1);
            $invertCondition = true;
        }
        if ($field[0] === "%") {
            $field = substr($field, 1);
            $ilike = true;
        }

        TestCase::assertArrayHasKey($field, $row, "DB record lacks queried field $field");

        if (!isset($value)) {
            // match NULL / NOT NULL
            return isset($row[$field]) === $invertCondition;
        } elseif (is_array($value)) {
            if (count($value) > 0) {
                if ($ilike) {
                    throw new \Exception(__METHOD__ . " $field - ILIKE match only supported for single pattern");
                }

                return in_array($row[$field], $value) !== $invertCondition;
            } else {
                throw new \Exception(__METHOD__ . " $field - empty values array provided");
            }
        } else {
            if ($ilike) {
                $matchPattern = str_replace('%', '.*', $value);
                return preg_match("/^$matchPattern$/i", $value) !== $invertCondition;
            } else {
                $equals = $row[$field] == $value;
                return $equals != $invertCondition;
            }
        }
    }

    /**
     * Checks if the given row matches all given conditions.
     *
     * @param string|(?string|string[])[]|DbAndCondition[] $conditions
     * @param (?string)[] $row The DB row to match with the condition
     * @return bool Match result of conditions against row
     *
     * @see AbstractDatabase::normalizeConditions() for a description of $conditions
     */
    private function checkRowMatch($conditions, array $row): bool
    {
        $conditions = $this->normalizeConditions($conditions);

        foreach ($conditions as $andCond) {
            $andCondMatched = false;

            foreach ($andCond->orConditions as $orCond) {
                if ($this->checkRowMatchSingleCondition($orCond, $row)) {
                    $andCondMatched = true;
                    break;
                }
            }

            if ($andCondMatched === false) {
                return false;
            }
        }

        return true;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
