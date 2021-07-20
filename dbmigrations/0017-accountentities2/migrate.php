<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
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
 * Initializes the carddav_accounts table from the carddav_addressbooks table using a heuristic to identify which
 * addressbooks belong to the same account.
 *
 * Addressbooks are considered to belong to the same account if they belong to the same user_id, and either:
 *   - a) They are created from the same preset (presetname is the same, but not NULL), or
 *   - b) username, and the addressbook home (i.e. parent collection, addressbook URI w/o last component) is the same
 *
 * Note: we do not check the password because to decrypt it we might need the password of the respective user. We cannot
 * compare encrypted passwords. However, the same username on the same server should always refer to the same server
 * account.
 *
 * @psalm-type FullAbookRow = array{
 *     id: string, user_id: string, name: string,
 *     username: string, password: string, url: string,
 *     active: numeric-string, use_categories: numeric-string,
 *     last_updated: numeric-string, refresh_time: numeric-string, sync_token: string,
 *     presetname: ?string
 * }
 */
class Migration0017 implements DBMigrationInterface
{
    public function migrate(rcube_db $dbh, LoggerInterface $logger): bool
    {
        $account_table = $dbh->table_name("carddav_accounts");
        $abook_table = $dbh->table_name("carddav_addressbooks");

        $for_update = ($dbh->db_provider === "sqlite") ? "" : " FOR UPDATE";

        try {
            if (
                $dbh->startTransaction() === false
                || ($sql_result = $dbh->query(
                    "SELECT user_id "
                    . "FROM $abook_table "
                    . $for_update
                )) === false
            ) {
                throw new \Exception($dbh->is_error());
            }

            // Fetch ids of users with carddav addressbooks; we migrate one user at a time to avoid dealing with
            // potentially huge amounts of data in memory at one time
            $userIds = [];

            while (
                /** @psalm-var false|array{user_id: string} $row */
                $row = $dbh->fetch_assoc($sql_result)
            ) {
                if (!in_array($row['user_id'], $userIds)) {
                    $userIds[] = $row['user_id'];
                }
            }

            // Migrate accounts for that user
            foreach ($userIds as $userId) {
                $sql_result = $dbh->query(
                    "SELECT * "
                    . "FROM $abook_table "
                    . "WHERE user_id = " . $dbh->quote($userId)
                );

                if ($sql_result === false) {
                    throw new \Exception($dbh->is_error());
                }

                // Identify accounts from addressbooks.
                $accountsCustom = [];
                $accountsFromPreset = [];

                while (
                    /** @psalm-var FullAbookRow $row */
                    $row = $dbh->fetch_assoc($sql_result)
                ) {
                    $presetName = $row['presetname'] ?? null;

                    if (isset($presetName)) {
                        // everything from a preset belongs to the same account
                        $accountsFromPreset[$presetName][] = $row;
                    } else {
                        // username and addressbook parent URL are used to identify addressbooks of the same account
                        $userName = $row['username'];
                        $abookHomeUrl = self::parentUrl($row['url']);
                        $accountsCustom[$userName][$abookHomeUrl][] = $row;
                    }
                }

                $accounts = array_values($accountsFromPreset);
                foreach ($accountsCustom as $accountsCustomByHome) {
                    $accounts = array_merge($accounts, array_values($accountsCustomByHome));
                }

                // Create Accounts
                foreach ($accounts as $abookRows) {
                    $abook0 = $abookRows[0];

                    // try to re-create the account name from what the user originally entered as addressbook
                    // name upon creation. Might not work in case the addressbook was renamed later, in this
                    // case we use the name of the first addressbook as account name, user can rename later.
                    $replacementCount = 0;
                    $accountName = preg_replace("/ \(.*\)/", "", $abook0["name"], 1, $replacementCount);
                    if ($replacementCount > 0) {
                        $accountNameQuoted = preg_quote($accountName);
                        foreach ($abookRows as $idx => $abookRow) {
                            $abookRows[$idx]["name"] = preg_replace(
                                "/^$accountNameQuoted \((.*)\)/",
                                '$1',
                                $abookRow["name"]
                            );
                        }
                    }

                    $presetName = $abook0["presetname"] ?? null;
                    $userName = $abook0["username"];
                    $abookHomeUrl = self::parentUrl($abook0['url']);
                    $cols = [$accountName, $userName, $abook0['password'], $abookHomeUrl, $userId, $presetName];
                    $sql_result = $dbh->query(
                        "INSERT INTO $account_table "
                        . "(name,username,password,url,user_id,presetname)"
                        . "VALUES (?,?,?,?,?,?)",
                        $cols
                    );

                    if ($sql_result === false) {
                        throw new \Exception(__METHOD__ . " account insert failed: " . $dbh->is_error());
                    }
                    /** @var string|false $accountId */
                    $accountId = $dbh->insert_id("carddav_accounts");
                    if ($accountId === false) {
                        throw new \Exception(__METHOD__ . " could not get account id: " . $dbh->is_error());
                    }

                    // set account_id in the addressbooks that belong to this account
                    foreach ($abookRows as $abookRow) {
                        $sql_result = $dbh->query(
                            "UPDATE $abook_table"
                            . " SET account_id=" . $dbh->quote($accountId) . ', name=' . $dbh->quote($abookRow['name'])
                            . " WHERE id=" . $dbh->quote($abookRow['id'])
                        );

                        if ($sql_result === false) {
                            throw new \Exception(__METHOD__ . " link abooks to account error: " . $dbh->is_error());
                        }
                    }
                }
            }

            if ($dbh->endTransaction() === false) {
                throw new \Exception($dbh->is_error());
            }
        } catch (\Exception $e) {
            $logger->error("Error in PHP-based migration Migration0017: " . $e->getMessage());
            $dbh->rollbackTransaction();
            return false;
        }

        return true;
    }

    /**
     * Determines the parent URL of the given URL.
     */
    private static function parentUrl(string $url): string
    {
        $urlComp = \Sabre\Uri\parse($url);
        [ $parent ] = \Sabre\Uri\split($urlComp["path"] ?? "");
        $urlComp["path"] = (string) $parent;
        $parentUrl = \Sabre\Uri\build($urlComp);
        if ($parentUrl[-1] != '/') {
            $parentUrl .= '/';
        }
        return $parentUrl;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
