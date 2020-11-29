<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use rcube_db;
use Psr\Log\LoggerInterface;

/**
 * Access module for the roundcube database.
 *
 * The purpose of this class is to decouple all access to the roundcube database from the rest of the plugin. The main
 * purpose of this is to set a ground for testing, where the actual access to the database (this class) could be
 * replaced by mocks. The methods of this class should be able to satisfy all query needs of the plugin without the need
 * to have SQL queries directly inside the plugin, as these would be difficult to parse in a test mock.
 *
 * @todo At the moment, this class is just a container for the already existing methods and only partially fulfills its
 *   purpose stated above.
 */
class Database
{
    /** @var string[] DBTABLES_WITHOUT_ID List of table names that have no single ID column. */
    private const DBTABLES_WITHOUT_ID = ['group_user'];

    /** @var LoggerInterface $logger */
    private $logger;

    /** @var rcube_db $dbHandle The roundcube database handle */
    private $dbHandle;

    /** @var bool $inTransaction Indicates whether we are currently inside a transaction */
    private $inTransaction = false;

    /**
     * Initializes a Database instance.
     *
     * @param rcube_db $dbh The roundcube database handle
     */
    public function __construct(LoggerInterface $logger, rcube_db $dbh)
    {
        $this->logger   = $logger;
        $this->dbHandle = $dbh;
    }

    /**
     * Provides the lower level roundcube database handle.
     *
     * This is meant to support legacy parts of the plugin and should not be used for new code.
     */
    public function getDbHandle(): rcube_db
    {
        return $this->dbHandle;
    }

    /**
     * Starts a transaction on the internal DB connection.
     *
     * Note that all queries in the transaction must be done using the same Database object, to make sure they use the
     * same database connection.
     *
     * @param bool $readonly True if the started transaction only queries, but does not modify data.
     */
    public function startTransaction(bool $readonly = true): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        if ($this->inTransaction) {
            throw new \Exception("Cannot start nested transaction");
        } else {
            // SQLite3 always has Serializable isolation of transactions, and does not support
            // the SET TRANSACTION command.
            $level = $readonly ? 'REPEATABLE READ' : 'SERIALIZABLE';
            $mode  = $readonly ? "READ ONLY" : "READ WRITE";

            switch ($dbh->db_provider) {
                case "mysql":
                    $ret = $dbh->query("SET TRANSACTION ISOLATION LEVEL $level, $mode");
                    break;
                case "sqlite":
                    $ret = true;
                    break;
                case "postgres":
                    $ret = $dbh->query("SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL $level, $mode");
                    break;
                default:
                    $logger->critical("Unsupported database backend: " . $dbh->db_provider);
                    return;
            }

            if ($ret !== false) {
                $ret = $dbh->startTransaction();
            }

            if ($ret === false) {
                $logger->error(__METHOD__ . " ERROR: " . $dbh->is_error());
                throw new DatabaseException($dbh->is_error());
            }

            $this->inTransaction = true;
        }
    }

    /**
     * Commits the transaction on the internal DB connection.
     */
    public function endTransaction(): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        if ($this->inTransaction) {
            $this->inTransaction = false;

            if ($dbh->endTransaction() === false) {
                $logger->error("Database::endTransaction ERROR: " . $dbh->is_error());
                throw new DatabaseException($dbh->is_error());
            }

            $this->resetTransactionSettings();
        } else {
            throw new \Exception("Attempt to commit a transaction while not within a transaction");
        }
    }

    /**
     * Rolls back the transaction on the internal DB connection.
     */
    public function rollbackTransaction(): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        if ($this->inTransaction) {
            $this->inTransaction = false;
            if ($dbh->rollbackTransaction() === false) {
                $logger->error("Database::rollbackTransaction ERROR: " . $dbh->is_error());
                throw new \Exception($dbh->is_error());
            }

            $this->resetTransactionSettings();
        } else {
            // not throwing an exception here facilitates usage of the interface at caller side. The caller
            // can issue rollback without having to keep track whether an error occurred before/after a
            // transaction was started/ended.
            $logger->notice("Ignored request to rollback a transaction while not within a transaction");
        }
    }

    /**
     * Resets database transaction settings to defaults that should apply to autocommit transactions.
     */
    private function resetTransactionSettings(): void
    {
        $logger = $this->logger;
        $dbh = $this->dbHandle;
        switch ($dbh->db_provider) {
            case "postgres":
                $ret = $dbh->query(
                    "SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ WRITE"
                );
                break;
            default:
                return;
        }

        // reset default session characteristics to read/write for autocommit single-statement transactions
        if ($ret === false) {
            $logger->warning(__METHOD__ . " ERROR: " . $dbh->is_error());
        }
    }

    /**
     * Checks if the database schema is up to date and performs migrations if needed.
     *
     * If this function encounters an error, it will abort execution of the migrations. The database will be
     * in a potentially broken state, causing further errors when the plugin is executed. Unfortunately, I do not see a
     * better way to handle errors. Throwing an exception would result in Roundcube not being usable at all for the user
     * in case of errors.
     *
     * @param string $dbPrefix The optional prefix to all database table names as configured in Roundcube.
     * @param string $scriptDir Path of the parent directory containing all the migration scripts, each in a subdir.
     */
    public function checkMigrations(string $dbPrefix, string $scriptDir): void
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        // We only support the non-commercial database types supported by roundcube, so quit with an error
        switch ($dbh->db_provider) {
            case "mysql":
                $db_backend = "mysql";
                break;
            case "sqlite":
                $db_backend = "sqlite3";
                break;
            case "postgres":
                $db_backend = "postgres";
                break;
            default:
                $logger->critical("Unsupported database backend: " . $dbh->db_provider);
                return;
        }

        // (1) Determine which migration scripts are available, in alphabetical ascending order
        $migrationsAvailable = [];
        foreach (scandir($scriptDir, SCANDIR_SORT_ASCENDING) as $migrationDir) {
            if (preg_match("/^\d{4}-/", $migrationDir)) {
                $migrationsAvailable[] = $migrationDir;
            }
        }

        // (2) Determine which migration scripts have already been executed. This must handle the initial case that
        //     the plugin's database tables to not exist yet, in which case they will be initialized.
        $migrationsDone = [];
        $dbh->set_option('ignore_key_errors', true);
        $sql_result = $dbh->query('SELECT filename FROM ' . $dbh->table_name('carddav_migrations')) ?: [];
        while ($processed = $dbh->fetch_assoc($sql_result)) {
            $migrationsDone[$processed['filename']] = true;
        }
        $dbh->set_option('ignore_key_errors', null);

        // (3) Execute the migration scripts that have not been executed before
        foreach ($migrationsAvailable as $migration) {
            // skip migrations that have already been done
            if (key_exists($migration, $migrationsDone)) {
                continue;
            }

            $logger->notice("In migration: $migration");

            $phpMigrationScript = "$scriptDir/$migration/migrate.php";
            $sqlMigrationScript = "$scriptDir/$migration/$db_backend.sql";

            if (file_exists($phpMigrationScript)) {
                include $phpMigrationScript;
                $migrationClass = "\MStilkerich\CardDavAddressbook4Roundcube\DBMigrations\Migration"
                    . substr($migration, 0, 4); // the 4-digit number

                /**
                 * @psalm-suppress InvalidStringClass
                 * @var DBMigrationInterface $migrationObj
                 */
                $migrationObj = new $migrationClass();
                if ($migrationObj->migrate($dbh, $logger) === false) {
                    return; // error already logged
                }
            } elseif (file_exists($sqlMigrationScript)) {
                if ($this->performSqlMigration($sqlMigrationScript, $dbPrefix) === false) {
                    return; // error already logged
                }
            } else {
                $logger->warning("No migration script found for: $migration");
                // do not continue with other scripts that may depend on this one
                return;
            }

            $dbh->query(
                "INSERT INTO " . $dbh->table_name("carddav_migrations") . " (filename) VALUES (?)",
                $migration
            );

            if ($dbh->is_error()) {
                $logger->error("Recording exec of migration $migration failed: " . $dbh->is_error());
                return;
            }
        }
    }

    /**
     * Executes an SQL migration script.
     *
     * @param string $migrationScript The path to the migration script.
     * @param string $dbPrefix The optional prefix to all database table names as configured in Roundcube.
     */
    private function performSqlMigration(string $migrationScript, string $dbPrefix): bool
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;
        $queries_raw = file_get_contents($migrationScript);

        if ($queries_raw === false) {
            $logger->error("Failed to read migration script: $migrationScript - aborting");
            return false;
        }

        $queryCount = preg_match_all('/.+?;/s', $queries_raw, $queries);
        $logger->info("Found $queryCount queries in $migrationScript");
        if ($queryCount > 0) {
            foreach ($queries[0] as $query) {
                $query = str_replace("TABLE_PREFIX", $dbPrefix, $query);
                $dbh->query($query);

                if ($dbh->is_error()) {
                    $logger->error("Migration query ($query) failed: " . $dbh->is_error());
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Stores a contact to the local database.
     *
     * @param string $abookid Database of the addressbook the contact shall be inserted to
     * @param string $etag of the VCard in the given version on the CardDAV server
     * @param string $uri path to the VCard on the CardDAV server
     * @param string $vcfstr string representation of the VCard
     * @param array  $save_data associative array containing the roundcube save data for the contact
     * @param ?string $dbid optionally, database id of the contact if the store operation is an update
     *
     * @return string The database id of the created or updated card.
     */
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

    /**
     * Stores a group in the database.
     *
     * If the group is based on a KIND=group vcard, the record must be stored with ETag, URI and VCard. Otherwise, if
     * the group is derived from a CATEGORIES property of a contact VCard, the ETag, URI and VCard must be set to NULL
     * to indicate this.
     *
     * @param string $abookid Database of the addressbook the contact shall be inserted to
     * @param array  $save_data  associative array containing at least name and cuid (card UID)
     * @param ?string $dbid optionally, database id of the group if the store operation is an update
     * @param ?string $etag of the VCard in the given version on the CardDAV server
     * @param ?string $uri path to the VCard on the CardDAV server
     * @param ?string $vcfstr string representation of the VCard
     *
     * @return string The database id of the created or updated card.
     */
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
        $logger = $this->logger;

        $carddesc = $uri ?? "(entry not backed by card)";
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
            $logger->debug("UPDATE card $dbid/$carddesc in $table");

            $this->update($dbid, $xcol, $xval, $table, 'id');
        } else {
            $logger->debug("INSERT card $carddesc to $table");

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

            $dbid = $this->insert($table, $xcol, $xval);
        }

        return $dbid;
    }

    /**
     * Stores a new entity to the database.
     *
     * @param string $table The database table to store the entity to.
     * @param string[] $cols Database column names of attributes to insert.
     * @param string[] $vals The values to insert into the column specified by $cols at the corresponding index.
     * @return string The database id of the created database record. Empty string if the table has no ID column.
     */
    public function insert(string $table, array $cols, array $vals): string
    {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = 'INSERT INTO ' . $dbh->table_name("carddav_$table") .
            '(' . implode(",", $cols)  . ') ' .
            'VALUES (?' . str_repeat(',?', count($cols) - 1) . ')';

        $dbh->query($sql, $vals);

        if (in_array($table, self::DBTABLES_WITHOUT_ID)) {
            $dbid = "";
        } else {
            $dbid = $dbh->insert_id("carddav_$table");
            $dbid = is_bool($dbid) ? "" /* error thrown below */ : (string) $dbid;
        }
        $logger->debug("INSERT $table ($sql) -> $dbid");

        if ($dbh->is_error()) {
            $logger->error("Database::insert ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $dbid;
    }

    /**
     * Updates records in a database table.
     *
     * @param string|string[] $id A single database ID (string) or an array of database IDs if several records should be
     *                            updated. These IDs are queried against the database column specified by $idfield. Can
     *                            be a single or multiple values, null is not permitted.
     * @param string[] $cols      Database column names of attributes to update.
     * @param string[] $vals      The values to set into the column specified by $cols at the corresponding index.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @param string $idfield     The name of the column against which $id is matched.
     * @param array  $other_conditions An associative array with database column names as keys and their match criterion
     *                                 as value.
     * @return int                The number of rows updated.
     * @see getConditionQuery()
     */
    public function update(
        $id,
        array $cols,
        array $vals,
        string $table = 'contacts',
        string $idfield = 'id',
        array $other_conditions = []
    ): int {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = 'UPDATE ' . $dbh->table_name("carddav_$table") . ' SET ' . implode("=?,", $cols) . '=? WHERE ';

        // Main selection condition
        $sql .= $this->getConditionQuery($idfield, $id);

        // Append additional conditions
        $sql .= $this->getOtherConditionsQuery($other_conditions);

        $logger->debug("UPDATE $table ($sql)");
        $sql_result = $dbh->query($sql, $vals);

        if ($dbh->is_error()) {
            $logger->error("Database::update ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $dbh->affected_rows($sql_result);
    }

    /**
     * Gets rows from a database table.
     *
     * @param string|string[] $id A single database ID (string) or an array of database IDs if several records should be
     *                            queried. These IDs are queried against the database column specified by $idfield. Can
     *                            be a single or multiple values, null is not permitted.
     * @param string $cols        A comma-separated list of database column names used in the SELECT clause of the SQL
     *                            statement. By default, all columns are selected.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @param bool $retsingle     If true, exactly one single row is expected as result. If false, any number of rows is
     *                            expected as result.
     * @param string $idfield     The name of the column against which $id is matched.
     * @param array  $other_conditions An associative array with database column names as keys and their match criterion
     *                                 as value.
     * @return array              If $retsingle is true and no error occurred, returns an associative row array with the
     *                            matching row, where keys are fieldnames and their value is the corresponding database
     *                            value of the field in the result row. If $retsingle is false, a possibly empty array
     *                            of such row-arrays is returned.
     * @see getConditionQuery()
     */
    public function get(
        $id,
        string $cols = '*',
        string $table = 'contacts',
        bool $retsingle = true,
        string $idfield = 'id',
        array $other_conditions = []
    ): array {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = "SELECT $cols FROM " . $dbh->table_name("carddav_$table") . ' WHERE ';

        // Main selection condition
        $sql .= $this->getConditionQuery($idfield, $id);

        // Append additional conditions
        $sql .= $this->getOtherConditionsQuery($other_conditions);

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            $logger->error("Database::get ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        // single result row expected?
        if ($retsingle) {
            $ret = $dbh->fetch_assoc($sql_result);
            if ($ret === false) {
                throw new \Exception("Single-row query ($sql) without result");
            }
            return $ret;
        } else {
            // multiple rows requested
            $ret = [];
            while ($row = $dbh->fetch_assoc($sql_result)) {
                $ret[] = $row;
            }
            return $ret;
        }
    }

    /**
     * Deletes rows from a database table.
     *
     * @param string|string[] $id A single database ID (string) or an array of database IDs if several records should be
     *                            deleted. These IDs are queried against the database column specified by $idfield. Can
     *                            be a single or multiple values, null is not permitted.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @param string $idfield     The name of the column against which $id is matched.
     * @param array  $other_conditions An associative array with database column names as keys and their match criterion
     *                                 as value.
     * @return int                The number of rows deleted.
     * @see getConditionQuery()
     */
    public function delete(
        $id,
        string $table = 'contacts',
        string $idfield = 'id',
        array $other_conditions = []
    ): int {
        $dbh = $this->dbHandle;
        $logger = $this->logger;

        $sql = "DELETE FROM " . $dbh->table_name("carddav_$table") . " WHERE ";

        // Main selection condition
        $sql .= $this->getConditionQuery($idfield, $id);

        // Append additional conditions
        $sql .= $this->getOtherConditionsQuery($other_conditions);

        $logger->debug("Database::delete $sql");

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            $logger->error("Database::delete ($sql) ERROR: " . $dbh->is_error());
            throw new DatabaseException($dbh->is_error());
        }

        return $dbh->affected_rows($sql_result);
    }

    /**
     * Creates a condition query on a database column to be used in an SQL WHERE clause.
     *
     * @param string $field Name of the database column.
     *                      Prefix with ! to invert the condition.
     *                      Prefix with % to indicate that the value is a pattern to be matched with ILIKE.
     *                      Prefixes can be combined but must be given in the order listed here.
     * @param ?string|string[] $value The value to check field for. Can be one of the following:
     *          - null: Assert that the field is NULL (or not NULL if inverted)
     *          - string: Assert that the field value matches $value (or does not match value, if inverted)
     *          - string[]: Assert that the field matches one of the strings in values (or none, if inverted)
     */
    private function getConditionQuery(string $field, $value): string
    {
        $dbh = $this->dbHandle;
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

        $sql = $dbh->quote_identifier($field);
        if (!isset($value)) {
            $sql .= $invertCondition ? ' IS NOT NULL' : ' IS NULL';
        } elseif (is_array($value)) {
            if (count($value) > 0) {
                if ($ilike) {
                    throw new \Exception("getConditionQuery $field - ILIKE match only supported for single pattern");
                }
                $quoted_values = array_map([ $dbh, 'quote' ], $value);
                $sql .= $invertCondition ? " NOT IN" : " IN";
                $sql .= " (" . implode(",", $quoted_values) . ")";
            } else {
                throw new \Exception("getConditionQuery $field - empty values array provided");
            }
        } else {
            if ($ilike) {
                if ($dbh->db_provider === "mysql") {
                    $sql .= " COLLATE utf8mb4_unicode_ci ";
                }
                $ilikecmd = ($dbh->db_provider === "postgres") ? "ILIKE" : "LIKE";
                $sql .= $invertCondition ? " NOT $ilikecmd " : " $ilikecmd ";
            } else {
                $sql .= $invertCondition ? " <> " : " = ";
            }
            $sql .= $dbh->quote($value);
        }

        return $sql;
    }

    private function getOtherConditionsQuery(array $other_conditions): string
    {
        $sql = "";

        foreach ($other_conditions as $field => $value) {
            $sql .= ' AND ';
            $sql .= $this->getConditionQuery($field, $value);
        }

        return $sql;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
