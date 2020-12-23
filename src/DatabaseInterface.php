<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use rcube_db;
use Psr\Log\LoggerInterface;

/**
 * Access interface for the roundcube database.
 */
interface DatabaseInterface
{
    /**
     * Provides the lower level roundcube database handle.
     *
     * This is meant to support legacy parts of the plugin and should not be used for new code.
     */
    public function getDbHandle(): rcube_db;

    /**
     * Starts a transaction on the internal DB connection.
     *
     * Note that all queries in the transaction must be done using the same Database object, to make sure they use the
     * same database connection.
     *
     * @param bool $readonly True if the started transaction only queries, but does not modify data.
     */
    public function startTransaction(bool $readonly = true): void;

    /**
     * Commits the transaction on the internal DB connection.
     */
    public function endTransaction(): void;

    /**
     * Rolls back the transaction on the internal DB connection.
     */
    public function rollbackTransaction(): void;

    /**
     * Checks if the database schema is up to date and performs migrations if needed.
     *
     * @param string $dbPrefix The optional prefix to all database table names as configured in Roundcube.
     * @param string $scriptDir Path of the parent directory containing all the migration scripts, each in a subdir.
     */
    public function checkMigrations(string $dbPrefix, string $scriptDir): void;

    /**
     * Stores a contact to the local database.
     *
     * @param string $abookid Database ID of the addressbook the contact shall be inserted to
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
    );

    /**
     * Stores a group in the database.
     *
     * If the group is based on a KIND=group vcard, the record must be stored with ETag, URI and VCard. Otherwise, if
     * the group is derived from a CATEGORIES property of a contact VCard, the ETag, URI and VCard must be set to NULL
     * to indicate this.
     *
     * @param string $abookid Database ID of the addressbook the group shall be inserted to
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
    );

    /**
     * Inserts new rows to a database table.
     *
     * @param string $table The database table to store the row to.
     * @param string[] $cols Database column names of attributes to insert.
     * @param (?string)[][] $rows An array of rows to insert. Indexes of each row must match those in $cols.
     * @return string The database id of the last created database row. Empty string if the table has no ID column.
     */
    public function insert(string $table, array $cols, array $rows): string;

    /**
     * Updates records in a database table.
     *
     * @param ?string|(?string|string[])[] $conditions Either an associative array with database column names as keys
     *                            and their match criterion as value. Or a single string value that will be matched
     *                            against the id column of the given DB table. Or null to not filter at all.
     * @param string[] $cols      Database column names of attributes to update.
     * @param string[] $vals      The values to set into the column specified by $cols at the corresponding index.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @return int                The number of rows updated.
     * @see DatabaseInterface::getConditionQuery()
     */
    public function update($conditions, array $cols, array $vals, string $table = 'contacts'): int;

    /**
     * Gets rows from a database table.
     *
     * @param ?string|(?string|string[])[] $conditions Either an associative array with database column names as keys
     *                            and their match criterion as value. Or a single string value that will be matched
     *                            against the id column of the given DB table. Or null to not filter at all.
     * @param string $cols        A comma-separated list of database column names used in the SELECT clause of the SQL
     *                            statement. By default, all columns are selected.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @return array              If $retsingle no error occurred, returns an array of associative row arrays with the
     *                            matching rows. Each row array has the fieldnames as keys and the corresponding
     *                            database value as value.
     * @see getConditionQuery()
     */
    public function get($conditions, string $cols = '*', string $table = 'contacts'): array;

    /**
     * Like {@see get()}, but expects exactly a single row as result.
     *
     * If the query yields fewer or more than one row, an exception is thrown.
     *
     * @param ?string|(?string|string[])[] $conditions
     * @param bool $retsingle     If true, exactly one single row is expected as result. If false, any number of rows is
     *                            expected as result.
     * @return array              If no error occurred, returns an associative row array with the
     *                            matching row, where keys are fieldnames and their value is the corresponding database
     *                            value of the field in the result row.
     * @see get()
     * @see getConditionQuery()
     */
    public function lookup($conditions, string $cols = '*', string $table = 'contacts'): array;

    /**
     * Deletes rows from a database table.
     *
     * @param ?string|(?string|string[])[] $conditions Either an associative array with database column names as keys
     *                            and their match criterion as value. Or a single string value that will be matched
     *                            against the id column of the given DB table. Or null to not filter at all.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @return int                The number of rows deleted.
     * @see getConditionQuery()
     */
    public function delete($conditions, string $table = 'contacts'): int;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
