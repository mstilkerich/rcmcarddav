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

namespace MStilkerich\CardDavAddressbook4Roundcube\DBMigrations;

use rcmail;
use rcube_db;
use carddav;
use Psr\Log\LoggerInterface;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DBMigrationInterface;

/**
 * Replaces all placeholder in the URL fields of addressbooks in the database.
 */
class Migration0007 implements DBMigrationInterface
{
    public function migrate(rcube_db $dbh, LoggerInterface $logger): bool
    {
        $users_table = $dbh->table_name("users");
        $abook_table = $dbh->table_name("carddav_addressbooks");

        $for_update = ($dbh->db_provider === "sqlite") ? "" : " FOR UPDATE";

        if (
            $dbh->startTransaction() === false
            || ($sql_result = $dbh->query(
                "SELECT a.id,a.url,u.username "
                . "FROM $abook_table as a, $users_table as u "
                . "WHERE a.user_id = u.user_id"
                . $for_update
            )) === false
        ) {
            $logger->error("Error in PHP-based migration Migration0007: " . $dbh->is_error());
            $dbh->rollbackTransaction();
            return false;
        }

        while ($row = $dbh->fetch_assoc($sql_result)) {
            $url = self::replacePlaceholdersUrl((string) $row["url"]);
            if ($url != $row["url"]) {
                if ($dbh->query("UPDATE $abook_table SET url=? WHERE id=?", $url, $row["id"]) === false) {
                    $logger->error(
                        "Error in PHP-based migration Migration0007 (UPDATE {$row['id']}): "
                        . $dbh->is_error()
                    );
                    $dbh->rollbackTransaction();
                    return false;
                }
            }
        }

        if ($dbh->endTransaction() === false) {
            $logger->error("Error in PHP-based migration Migration0007 (COMMIT): " . $dbh->is_error());
            return false;
        }

        return true;
    }

    private static function replacePlaceholdersUrl(string $url): string
    {
        $rcmail = rcmail::get_instance();
        return strtr($url, [
            '%u' => (string) $_SESSION['username'],
            '%l' => $rcmail->user->get_username('local'),
            '%d' => $rcmail->user->get_username('domain'),
            '%V' => strtr((string) $_SESSION['username'], "@.", "__")
        ]);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
