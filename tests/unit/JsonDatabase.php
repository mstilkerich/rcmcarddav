<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use rcube_db;
use PHPUnit\Framework\TestCase;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\DatabaseInterface;

/**
 * Implementation of the database access interface to the roundcube DB for unit tests.
 *
 * It emulates the DB operations based on an initial dataset read from a Json file.
 */
class JsonDatabase implements DatabaseInterface
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
     * @param string $jsonDbSchema Path to a JSON file defining the tables.
     * @param string[] $jsonDbData Paths to JSON files containing the initial data for the tables.
     */
    public function __construct(string $jsonDbSchema, array $jsonDbData = [])
    {
        $this->schema = TestInfrastructure::readJsonArray($jsonDbSchema);
        $this->validateSchema();
        foreach (array_keys($this->schema) as $table) {
            $this->data[$table] = [];
        }

        foreach ($jsonDbData as $dataFile) {
            $this->importData($dataFile);
        }
    }

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
                    $vals[] = isset($row[$col]) ? (string) $row[$col] : null;
                }
                $this->insert($table, $cols, [$vals]);
            }
        }
    }

    public function getDbHandle(): rcube_db
    {
        throw new \Exception("getDbHandle() is not implemented - adapt the tested class to not use it");
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

    public function storeContact(
        string $abookid,
        string $etag,
        string $uri,
        string $vcfstr,
        array $save_data,
        ?string $dbid = null
    ) {
        // build email search string
        $email_keys = preg_grep('/^email(:|$)/', array_keys($save_data));
        $email_addrs = [];
        foreach ($email_keys as $email_key) {
            $email_addrs = array_merge($email_addrs, (array) $save_data[$email_key]);
        }
        $save_data['email'] = implode(', ', $email_addrs);

        // extra columns for the contacts table
        $xcol_all = array('firstname','surname','organization','showas','email');
        $xcol = [];
        $xval = [];
        foreach ($xcol_all as $k) {
            if (key_exists($k, $save_data)) {
                $xcol[] = $k;
                $xval[] = $save_data[$k];
            }
        }

        return $this->storeAddressObject('contacts', $abookid, $etag, $uri, $vcfstr, $save_data, $dbid, $xcol, $xval);
    }

    public function storeGroup(
        string $abookid,
        array $save_data,
        ?string $dbid = null,
        ?string $etag = null,
        ?string $uri = null,
        ?string $vcfstr = null
    ) {
        return $this->storeAddressObject('groups', $abookid, $etag, $uri, $vcfstr, $save_data, $dbid);
    }

    /**
     * Inserts a new contact or group into the database, or updates an existing one.
     *
     * If the address object is not backed by an object on the server side (CATEGORIES-type groups), the parameters
     * $etag, $uri and $vcfstr are not applicable and shall be passed as NULL.
     *
     * @param string $table The target table, without carddav_ prefix (contacts or groups)
     * @param string $abookid The database ID of the addressbook the address object belongs to.
     * @param ?string $etag The ETag value of the CardDAV-server address object that this object is created from.
     * @param ?string $uri  The URI of the CardDAV-server address object that this object is created from.
     * @param ?string $vcfstr The VCard string of the CardDAV-server address object that this object is created from.
     * @param string[] $save_data The Roundcube representation of the address object.
     * @param ?string $dbid If an existing object is updated, this specifies its database id.
     * @param string[] $xcol Database column names of attributes to insert.
     * @param string[] $xval The values to insert into the column specified by $xcol at the corresponding index.
     * @return string The database id of the created or updated card.
     */
    private function storeAddressObject(
        string $table,
        string $abookid,
        ?string $etag,
        ?string $uri,
        ?string $vcfstr,
        array $save_data,
        ?string $dbid,
        array $xcol = [],
        array $xval = []
    ): string {
        $xcol[] = 'name';
        $xval[] = $save_data['name'];

        if (isset($etag)) {
            $xcol[] = 'etag';
            $xval[] = $etag;
        }

        if (isset($vcfstr)) {
            $xcol[] = 'vcard';
            $xval[] = $vcfstr;
        }

        if (isset($dbid)) {
            $this->update($dbid, $xcol, $xval, $table);
        } else {
            $xcol[] = 'abook_id';
            $xval[] = $abookid;

            if (isset($uri)) {
                $xcol[] = 'uri';
                $xval[] = $uri;
            }
            if (isset($save_data['cuid'])) {
                $xcol[] = 'cuid';
                $xval[] = $save_data['cuid'];
            }

            $dbid = $this->insert($table, $xcol, [$xval]);
        }

        return $dbid;
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

        if (count($rows) !== 1) {
            throw new \Exception("Single-row query with zero or several results");
        }

        return $rows[0];
    }

    /**
     * Query rows from the database matching conditions and extract specified columns.
     *
     * @param ?string|(?string|string[])[] $conditions Either an associative array with database column names as keys
     *                            and their match criterion as value. Or a single string value that will be matched
     *                            against the id column of the given DB table. Or null to not filter at all.
     */
    public function get($conditions, string $cols = '*', string $table = 'contacts'): array
    {
        TestCase::assertArrayHasKey($table, $this->data, "Get for unknown table $table");
        $cols = $cols == '*' ? array_keys($this->schema[$table]) : preg_split('/\s*,\s*/', $cols);
        $filteredRows = [];

        $unknownCols = array_diff($cols, array_keys($this->schema[$table]));
        TestCase::assertCount(0, $unknownCols, "Get for unknown columns in table $table: " . join(', ', $unknownCols));

        $cols = array_flip($cols);
        foreach ($this->data[$table] as $row) {
            if ($this->checkRowMatch($conditions, $row)) {
                $filteredRows[] = array_intersect_key($row, $cols);
            }
        }

        return $filteredRows;
    }

    /**
     * Checks if the given row matches the given condition.
     *
     * @param string $field Name of the database column.
     *                      Prefix with ! to invert the condition.
     *                      Prefix with % to indicate that the value is a pattern to be matched with ILIKE.
     *                      Prefixes can be combined but must be given in the order listed here.
     * @param ?string|string[] $value The value to check field for. Can be one of the following:
     *          - null: Assert that the field is NULL (or not NULL if inverted)
     *          - string: Assert that the field value matches $value (or does not match value, if inverted)
     *          - string[]: Assert that the field matches one of the strings in values (or none, if inverted)
     * @param (?string)[] $row The DB row to match with the condition
     *
     * @return bool Match result of condition against row
     */
    private function checkRowMatchSingleCondition(string $field, $value, array $row): bool
    {
        $invertCondition = false;
        $ilike = false;

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
     * @param ?string|(?string|string[])[] $conditions Either an associative array with database column names as keys
     *                            and their match criterion as value. Or a single string value that will be matched
     *                            against the id column of the given DB table. Or null to not filter at all.
     * @param (?string)[] $row The DB row to match with the condition
     * @return bool Match result of conditions against row
     */
    private function checkRowMatch($conditions, array $row): bool
    {
        if (isset($conditions)) {
            if (!is_array($conditions)) {
                $conditions = [ 'id' => $conditions ];
            }

            foreach ($conditions as $field => $value) {
                if (!$this->checkRowMatchSingleCondition($field, $value, $row)) {
                    return false;
                }
            }
        }

        return true;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
