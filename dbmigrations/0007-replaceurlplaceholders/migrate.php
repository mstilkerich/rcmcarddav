<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube\DBMigrations;

use rcmail;
use rcube_db;
use carddav;
use Psr\Log\LoggerInterface;
use MStilkerich\CardDavAddressbook4Roundcube\DBMigrationInterface;

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
            $url = self::replacePlaceholdersUrl($row["url"]);
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
            '%u' => $_SESSION['username'],
            '%l' => $rcmail->user->get_username('local'),
            '%d' => $rcmail->user->get_username('domain'),
            '%V' => strtr($_SESSION['username'], "@.", "__")
        ]);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
