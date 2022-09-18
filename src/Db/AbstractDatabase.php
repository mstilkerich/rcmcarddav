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

namespace MStilkerich\CardDavAddressbook4Roundcube\Db;

use Psr\Log\LoggerInterface;

/**
 * Access interface for the roundcube database.
 *
 * @psalm-type DbConditions = string|array<string, null|string|string[]>|list<DbAndCondition>
 * @psalm-type DbGetResult = array<string,?string>
 * @psalm-type DbGetResults = list<DbGetResult>
 * @psalm-type DbInsRow = list<?string>
 * @psalm-type DbGetOptions = array{limit?: array{int,int}, order?: list<string>, count?: true}
 *
 * @psalm-type FullAbookRow = array{
 *     id: string, user_id: string, name: string,
 *     username: string, password: string, url: string,
 *     active: numeric-string, use_categories: numeric-string,
 *     last_updated: numeric-string, refresh_time: numeric-string, sync_token: string,
 *     presetname: ?string
 * }
 *
 * @psalm-import-type SaveDataFromDC from \MStilkerich\CardDavAddressbook4Roundcube\DataConversion
 * @psalm-import-type SaveDataMultiField from \MStilkerich\CardDavAddressbook4Roundcube\DataConversion
 */
abstract class AbstractDatabase
{
    /**
     * @var string Separator string used when several values are combined in one DB column.
     *
     * This is relevant to the email column of the contacts table, where several email addresses can be stored.
     */
    public const MULTIVAL_SEP = ", ";

    /**
     * Starts a transaction on the internal DB connection.
     *
     * Note that all queries in the transaction must be done using the same Database object, to make sure they use the
     * same database connection.
     *
     * @param bool $readonly True if the started transaction only queries, but does not modify data.
     */
    abstract public function startTransaction(bool $readonly = true): void;

    /**
     * Commits the transaction on the internal DB connection.
     */
    abstract public function endTransaction(): void;

    /**
     * Rolls back the transaction on the internal DB connection.
     */
    abstract public function rollbackTransaction(): void;

    /**
     * Checks if the database schema is up to date and performs migrations if needed.
     *
     * @param string $dbPrefix The optional prefix to all database table names as configured in Roundcube.
     * @param string $scriptDir Path of the parent directory containing all the migration scripts, each in a subdir.
     */
    abstract public function checkMigrations(string $dbPrefix, string $scriptDir): void;

    /**
     * Inserts new rows to a database table.
     *
     * @param string $table The database table to store the row to.
     * @param list<string> $cols Database column names of attributes to insert.
     * @param list<DbInsRow> $rows An array of rows to insert. Indexes of each row must match those in $cols.
     * @return string The database id of the last created database row. Empty string if the table has no ID column.
     */
    abstract public function insert(string $table, array $cols, array $rows): string;

    /**
     * Updates records in a database table.
     *
     * @param DbConditions $conditions Selects the rows to update.
     * @param list<string> $cols  Database column names of attributes to update.
     * @param DbInsRow     $vals  The values to set into the column specified by $cols at the corresponding index.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @return int                The number of rows updated.
     *
     * @see normalizeConditions() for a description of $conditions
     * @see Database::getConditionsQuery()
     */
    abstract public function update($conditions, array $cols, array $vals, string $table = 'contacts'): int;

    /**
     * Gets rows from a database table.
     *
     * @param DbConditions $conditions Selects the rows to get.
     * @param list<string> $cols Database column names to select. Empty array means all columns.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @param DbGetOptions $options Associative array with extra options, mapping option name => option setting
     *                            Currently supported:
     *                              - limit: Value is an array of two integers [ $offset, $numrows ]. Limits the
     *                                       returned rows to maximum $numrows starting at $offset of the full result.
     *                              - order: Value is a list of column names to order by in ascending order. Prefix the
     *                                       column name with "!" (e.g. "!firstname") to sort in descending order.
     *                              - count: Value must be true.
     *                                       Execute an aggregate query on the columns in $cols. The result will be a
     *                                       single row, where each column's value is the number of non-null values in
     *                                       the query result. The special column '*' can be used to count the rows.
     * @return DbGetResults       If no error occurred, returns an array of associative row arrays with the
     *                            matching rows. Each row array has the fieldnames as keys and the corresponding
     *                            database value as value.
     *
     * @see normalizeConditions() for a description of $conditions
     * @see Database::getConditionsQuery()
     */
    abstract public function get(
        $conditions,
        array $cols = [],
        string $table = 'contacts',
        array $options = []
    ): array;

    /**
     * Like {@see get()}, but expects exactly a single row as result.
     *
     * If the query yields fewer or more than one row, an exception is thrown.
     *
     * @param DbConditions $conditions Selects the row to lookup.
     * @param list<string> $cols Database column names to select. Empty array means all columns.
     * @return DbGetResult If no error occurred, returns an associative row array with the
     *                     matching row, where keys are fieldnames and their value is the corresponding database
     *                     value of the field in the result row.
     *
     * @see get() For other parameter descriptions
     * @see normalizeConditions() for a description of $conditions
     * @see Database::getConditionsQuery()
     */
    abstract public function lookup($conditions, array $cols = [], string $table = 'contacts'): array;

    /**
     * Deletes rows from a database table.
     *
     * @param DbConditions $conditions Selects the rows to delete.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @return int                The number of rows deleted.
     *
     * @see normalizeConditions() for a description of $conditions
     * @see Database::getConditionsQuery()
     */
    abstract public function delete($conditions, string $table = 'contacts'): int;

    /**
     * Stores a contact to the local database.
     *
     * @param string $abookid Database ID of the addressbook the contact shall be inserted to
     * @param string $etag of the VCard in the given version on the CardDAV server
     * @param string $uri path to the VCard on the CardDAV server
     * @param string $vcfstr string representation of the VCard
     * @param SaveDataFromDC $save_data associative array containing the roundcube save data for the contact
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
            /** @psalm-var SaveDataMultiField $save_data[$email_key] */
            $email_addrs = array_merge($email_addrs, $save_data[$email_key]);
        }
        $save_data['email'] = implode(', ', $email_addrs);

        // extra columns for the contacts table
        $xcol_all = array('firstname','surname','organization','showas','email');
        $xcol = [];
        $xval = [];
        foreach ($xcol_all as $k) {
            if (isset($save_data[$k])) {
                /** @var string $v */
                $v = $save_data[$k];
                $xcol[] = $k;
                $xval[] = $v;
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
     * @param string $abookid Database ID of the addressbook the group shall be inserted to
     * @param SaveDataFromDC $save_data associative array containing at least name and cuid (card UID)
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
     * @param SaveDataFromDC $save_data The Roundcube representation of the address object.
     * @param ?string $dbid If an existing object is updated, this specifies its database id.
     * @param list<string> $xcol Database column names of attributes to insert.
     * @param DbInsRow $xval The values to insert into the column specified by $xcol at the corresponding index.
     * @return string The database id of the created or updated card.
     */
    protected function storeAddressObject(
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
        $name = $save_data['name'];
        $xval[] = $name;

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
                $cuid = $save_data["cuid"];
                $xval[] = $cuid;
            }

            $dbid = $this->insert($table, $xcol, [$xval]);
        }

        return $dbid;
    }

    /**
     * Normalizes the short form of specifying DB filter conditions to DbAndCondition[].
     *
     * The following short forms are supported for $conditions:
     *   - Empty array: No filter at all
     *   - Single string value: matched against the "id" column
     *   - Associative array mapping "field specifier" => "value specifier": All match criteria must match.
     *
     * The normalized form is an array of DbAndCondition.
     *
     * @param DbConditions $conditions See above for description.
     * @return list<DbAndCondition>
     *
     * @see DbOrCondition For a description on the format of field/value specifiers.
     */
    protected function normalizeConditions($conditions): array
    {
        if (is_string($conditions)) {
            $cond = [ new DbAndCondition(new DbOrCondition("id", $conditions)) ];
        } elseif (isset($conditions[0]) && $conditions[0] instanceof DbAndCondition) {
            /** @var list<DbAndCondition> */
            $cond = $conditions;
        } else {
            $cond = [];
            /** @var array<string, null|string|string[]> $conditions */
            foreach ($conditions as $fieldSpec => $valueSpec) {
                $cond[] = new DbAndCondition(new DbOrCondition($fieldSpec, $valueSpec));
            }
        }

        return $cond;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
