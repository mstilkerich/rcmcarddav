#!/usr/bin/env php
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

// defines INSTALL_PATH which is needed for clisetup.php
require_once("autoload_defs.php");

/** @psalm-suppress UnresolvableInclude */
require_once INSTALL_PATH . "program/include/clisetup.php";

$dbh = rcmail_utils::db();

switch ($dbh->db_provider) {
    case "mysql":
    case "postgres":
        $db_backend = $dbh->db_provider;
        break;
    case "sqlite":
        $db_backend = "sqlite3";
        break;

    default:
        fwrite(STDERR, "DBMS not supported: " . $dbh->db_provider);
        exit(1);
}

echo "DBMS is '$db_backend'\n";

$config = rcube::get_instance()->config;
$dbprefix = (string) $config->get('db_prefix', "");

echo "DB Prefix is '$dbprefix'\n";

// determine all carddav tables from the current schema CREATE TABLE commands
$initscript = INSTALL_PATH . "plugins/carddav/dbmigrations/INIT-currentschema/$db_backend.sql";
$initscriptFH = fopen($initscript, 'r');

if ($initscriptFH === false) {
    fwrite(STDERR, "Could not open DB init script $initscript");
    exit(1);
}

// Tables
$tables = [];

// Sequences (currently only used with Postgres)
$seqs = [];

// Note: Indexes are dropped automatically with the table

while ($line = fgets($initscriptFH)) {
    if (preg_match('/CREATE\s+(TABLE|SEQUENCE)\s.*?TABLE_PREFIX([A-Za-z_]+)/i', $line, $matches)) {
        if (strcasecmp('TABLE', $matches[1]) === 0) {
            $tables[] = $matches[2];
        } elseif (strcasecmp('SEQUENCE', $matches[1]) === 0) {
            $seqs[] = $matches[2];
        }
    }
}

fclose($initscriptFH);

// We drop tables in reverse creation order, this should make sure we do not run into issues with foreign key
// constraints as we always drop the depending table first.
$tables = array_reverse($tables);

// Drop tables
foreach ($tables as $table) {
    $table = $dbh->table_name($table);
    echo "DROP TABLE $table\n";
    if ($dbh->query("DROP TABLE IF EXISTS $table") === false) {
        echo "Error: Failed to drop table $table: " . $dbh->is_error() . "\n";
        exit(1);
    }
}

// Drop sequences
foreach ($seqs as $seq) {
    $seq = $dbh->table_name($seq);
    echo "DROP SEQUENCE $seq\n";
    if ($dbh->query("DROP SEQUENCE IF EXISTS $seq") === false) {
        echo "Error: Failed to drop table $seq: " . $dbh->is_error() . "\n";
        exit(1);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
