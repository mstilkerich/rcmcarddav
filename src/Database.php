<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use carddav;
use rcmail;
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
    /**
     * Stores a contact to the local database.
     *
     * @param string etag of the VCard in the given version on the CardDAV server
     * @param string path to the VCard on the CardDAV server
     * @param string string representation of the VCard
     * @param array  associative array containing the roundcube save data for the contact
     * @param ?string optionally, database id of the contact if the store operation is an update
     *
     * @return string The database id of the created or updated card.
     */
    public static function storeContact(
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

        return self::storeAddressObject('contacts', $abookid, $etag, $uri, $vcfstr, $save_data, $dbid, $xcol, $xval);
    }

    /**
     * Stores a group in the database.
     *
     * If the group is based on a KIND=group vcard, the record must be stored with ETag, URI and VCard. Otherwise, if
     * the group is derived from a CATEGORIES property of a contact VCard, the ETag, URI and VCard must be set to NULL
     * to indicate this.
     *
     * @param array   associative array containing at least name and cuid (card UID)
     * @param ?string optionally, database id of the group if the store operation is an update
     * @param ?string etag of the VCard in the given version on the CardDAV server
     * @param ?string path to the VCard on the CardDAV server
     * @param ?string string representation of the VCard
     *
     * @return string The database id of the created or updated card.
     */
    public static function storeGroup(
        string $abookid,
        array $save_data,
        ?string $dbid = null,
        ?string $etag = null,
        ?string $uri = null,
        ?string $vcfstr = null
    ) {
        return self::storeAddressObject('groups', $abookid, $etag, $uri, $vcfstr, $save_data, $dbid);
    }

    /**
     *
     * @return string The database id of the created or updated card.
     */
    private static function storeAddressObject(
        string $table,
        string $abookid,
        ?string $etag,
        ?string $uri,
        ?string $vcfstr,
        array $save_data,
        ?string $dbid,
        array $xcol = [],
        array $xval = []
    ) {
        $dbh = rcmail::get_instance()->db;

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
            carddav::$logger->debug("UPDATE card $dbid/$carddesc in $table");
            $xval[] = $dbid;
            $sql_result = $dbh->query('UPDATE ' .
                $dbh->table_name("carddav_$table") .
                ' SET ' . implode('=?,', $xcol) . '=?' .
                ' WHERE id=?', $xval);
        } else {
            carddav::$logger->debug("INSERT card $carddesc to $table");

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

            $sql_result = $dbh->query(
                'INSERT INTO ' .
                $dbh->table_name("carddav_$table") .
                ' (' . implode(',', $xcol) . ') VALUES (?' . str_repeat(',?', count($xcol) - 1) . ')',
                $xval
            );

            $dbid = $dbh->insert_id("carddav_$table");
            $dbid = is_bool($dbid) ? "" /* error thrown below */ : (string) $dbid;
        }

        if ($dbh->is_error()) {
            throw new \Exception($dbh->is_error());
        }

        return $dbid;
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
     * @see self::getConditionQuery()
     */
    public static function get(
        $id,
        string $cols = '*',
        string $table = 'contacts',
        bool $retsingle = true,
        string $idfield = 'id',
        array $other_conditions = []
    ): array {
        $dbh = rcmail::get_instance()->db;

        $sql = "SELECT $cols FROM " . $dbh->table_name("carddav_$table") . ' WHERE ';

        // Main selection condition
        $sql .= self::getConditionQuery($dbh, $idfield, $id);

        // Append additional conditions
        $sql .= self::getOtherConditionsQuery($dbh, $other_conditions);

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            carddav::$logger->error("Database::get ($sql) ERROR: " . $dbh->is_error());
            throw new \Exception($dbh->is_error());
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
     *                            queried. These IDs are queried against the database column specified by $idfield. Can
     *                            be a single or multiple values, null is not permitted.
     * @param string $table       Name of the database table to select from, without the carddav_ prefix.
     * @param string $idfield     The name of the column against which $id is matched.
     * @param array  $other_conditions An associative array with database column names as keys and their match criterion
     *                                 as value.
     * @return int                The number of rows deleted.
     * @see self::getConditionQuery()
     */
    public static function delete(
        $id,
        string $table = 'contacts',
        string $idfield = 'id',
        array $other_conditions = []
    ): int {
        $dbh = rcmail::get_instance()->db;

        $sql = "DELETE FROM " . $dbh->table_name("carddav_$table") . " WHERE ";

        // Main selection condition
        $sql .= self::getConditionQuery($dbh, $idfield, $id);

        // Append additional conditions
        $sql .= self::getOtherConditionsQuery($dbh, $other_conditions);

        carddav::$logger->debug("Database::delete $sql");

        $sql_result = $dbh->query($sql);

        if ($dbh->is_error()) {
            carddav::$logger->error("Database::delete ($sql) ERROR: " . $dbh->is_error());
            throw new \Exception($dbh->is_error());
        }

        return $dbh->affected_rows($sql_result);
    }

    public static function getAbookCfg(string $abookid): array
    {
        try {
            $dbh = rcmail::get_instance()->db;

            // cludge, agreed, but the MDB abstraction seems to have no way of
            // doing time calculations...
            $timequery = '(' . $dbh->now() . ' > ';
            if ($dbh->db_provider === 'sqlite') {
                $timequery .= ' datetime(last_updated,refresh_time))';
            } elseif ($dbh->db_provider === 'mysql') {
                $timequery .= ' date_add(last_updated, INTERVAL refresh_time HOUR_SECOND))';
            } else {
                $timequery .= ' last_updated+refresh_time)';
            }

            $abookrow = self::get(
                $abookid,
                'id as abookid,name,username,password,url,presetname,sync_token,authentication_scheme,'
                . $timequery . ' as needs_update',
                'addressbooks'
            );

            if ($dbh->db_provider === 'postgres') {
                // postgres will return 't'/'f' here for true/false, normalize it to 1/0
                $nu = $abookrow['needs_update'];
                $nu = ($nu == 1 || $nu == 't') ? 1 : 0;
                $abookrow['needs_update'] = $nu;
            }
        } catch (\Exception $e) {
            carddav::$logger->error("Error in processing configuration $abookid: " . $e->getMessage());
            throw $e;
        }

        return $abookrow;
    }

    /**
     * Creates a condition query on a database column to be used in an SQL WHERE clause.
     *
     * @param rcube_db $dbh The roundcube database handle.
     * @param string $field Name of the database column. Prefix with ! to invert the condition.
     * @param ?string|string[] $value The value to check field for. Can be one of the following:
     *          - null: Assert that the field is NULL (or not NULL if inverted)
     *          - string: Assert that the field value matches $value (or does not match value, if inverted)
     *          - string[]: Assert that the field matches one of the strings in values (or none, if inverted)
     */
    private static function getConditionQuery(rcube_db $dbh, string $field, $value): string
    {
        $invertCondition = false;
        if ($field[0] === "!") {
            $field = substr($field, 1);
            $invertCondition = true;
        }

        $sql = $dbh->quote_identifier($field);
        if (!isset($value)) {
            $sql .= $invertCondition ? ' IS NOT NULL' : ' IS NULL';
        } elseif (is_array($value)) {
            if (count($value) > 0) {
                $quoted_values = array_map([ $dbh, 'quote' ], $value);
                $sql .= $invertCondition ? " NOT IN" : " IN";
                $sql .= " (" . implode(",", $quoted_values) . ")";
            } else {
                throw new \Exception("getConditionQuery $field - empty values array provided");
            }
        } else {
            $sql .= $invertCondition ? ' <> ' : ' = ';
            $sql .= $dbh->quote($value);
        }

        return $sql;
    }

    private static function getOtherConditionsQuery(rcube_db $dbh, array $other_conditions): string
    {
        $sql = "";

        foreach ($other_conditions as $field => $value) {
            $sql .= ' AND ';
            $sql .= self::getConditionQuery($dbh, $field, $value);
        }

        return $sql;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
