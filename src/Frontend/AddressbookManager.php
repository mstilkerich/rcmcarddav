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

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\{Account, AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * Describes for each database field of an addressbook / account: type, mandatory on insert, updateable
 * @psalm-type SettingSpecification = array{'int'|'string'|'bool', bool, bool}
 *
 * The data types AccountSettings / AbookSettings describe the attributes of an account / addressbook row in the
 * corresponding DB table, that can be used for inserting / updating the addressbook. Contrary to the  FullAccountRow /
 * FullAbookRow types:
 *   - all keys are optional (for use of update of individual columns, others are not specified)
 *   - DB managed columns (particularly: id) are missing
 *
 * @psalm-type AccountSettings = array{
 *     name?: string,
 *     username?: string,
 *     password?: string,
 *     url?: ?string,
 *     rediscover_time?: int,
 *     last_discovered?: int,
 *     active?: bool,
 *     presetname?: string
 * }
 * @psalm-type AbookSettings = array{
 *     account_id?: string,
 *     name?: string,
 *     url?: string,
 *     active?: bool,
 *     use_categories?: bool,
 *     refresh_time?: int,
 *     last_updated?: int,
 *     discovered?: bool,
 *     sync_token?: string
 * }
 *
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type FullAbookRow from AbstractDatabase
 */
class AddressbookManager
{
    /**
     * @var array<string,SettingSpecification>
     *      List of user-/admin-configurable settings for an account. Note: The array must contain all fields of the
     *      AccountSettings type. Only fields listed in the array can be set via the insertAccount() / updateAccount()
     *      methods.
     */
    private const ACCOUNT_SETTINGS = [
        'name' => [ 'string', true, true ],
        'username' => [ 'string', true, true ],
        'password' => [ 'string', true, true ],
        'url' => [ 'string', false, true ], // discovery URI can be NULL, disables discovery
        'rediscover_time' => [ 'int', false, true ],
        'last_discovered' => [ 'int', false, true ],
        'active' => [ 'bool', false, true ],
        'presetname' => [ 'string', false, false ],
    ];

    /**
     * @var array<string,SettingSpecification>
     *      AbookSettings List of user-/admin-configurable settings for an addressbook. Note: The array must contain all
     *      fields of the AbookSettings type. Only fields listed in the array can be set via the insertAddressbook()
     *      / updateAddressbook() methods.
     */
    private const ABOOK_SETTINGS = [
        'account_id' => [ 'string', true, false ],
        'name' => [ 'string', true, true ],
        'url' => [ 'string', true, false ],
        'active' => [ 'bool', false, true ],
        'use_categories' => [ 'bool', false, true ],
        'refresh_time' => [ 'int', false, true ],
        'last_updated' => [ 'int', false, true ],
        'discovered' => [ 'int', false, false ],
        'sync_token' => [ 'string', true, true ],
    ];

    /** @var ?array<string, FullAccountRow> $accountsDb
     *    Cache of the user's account DB entries. Associative array mapping account IDs to DB rows.
     */
    private $accountsDb = null;

    /** @var ?array<string, FullAbookRow> $abooksDb
     *    Cache of the user's addressbook DB entries. Associative array mapping addressbook IDs to DB rows.
     */
    private $abooksDb = null;

    /**
     * Returns the IDs of all the user's accounts, optionally filtered.
     *
     * @param $presetsOnly If true, only the accounts created from an admin preset are returned.
     * @return list<string> The IDs of the user's accounts.
     */
    public function getAccountIds(bool $presetsOnly = false): array
    {
        $db = Config::inst()->db();

        if (!isset($this->accountsDb)) {
            $this->accountsDb = [];
            /** @var FullAccountRow $accrow */
            foreach ($db->get(['user_id' => (string) $_SESSION['user_id']], [], 'accounts') as $accrow) {
                $this->accountsDb[$accrow["id"]] = $accrow;
            }
        }

        $result = $this->accountsDb;

        if ($presetsOnly) {
            $result = array_filter($result, function (array $v): bool {
                return (strlen($v["presetname"] ?? "") > 0);
            });
        }

        return array_column($result, 'id');
    }

    /**
     * Retrieves an account configuration (database row) by its database ID.
     *
     * @param string $accountId ID of the account
     * @return FullAccountRow The addressbook config.
     * @throws \Exception If no account with the given ID exists for this user.
     */
    public function getAccountConfig(string $accountId): array
    {
        // make sure the cache is loaded
        $this->getAccountIds();

        // check that this addressbook ID actually refers to one of the user's addressbooks
        if (isset($this->accountsDb[$accountId])) {
            $accountCfg = $this->accountsDb[$accountId];
            $accountCfg["password"] = Utils::decryptPassword($accountCfg["password"]);
            return $accountCfg;
        }

        throw new \Exception("No carddav account with ID $accountId");
    }

    /**
     * Inserts a new account into the database.
     *
     * @param AccountSettings $pa Array with the settings for the new account
     * @return string Database ID of the newly created account
     */
    public function insertAccount(array $pa): string
    {
        $db = Config::inst()->db();

        // check parameters
        if (isset($pa['password'])) {
            $pa['password'] = Utils::encryptPassword($pa['password']);
        }

        [ $cols, $vals ] = $this->prepareDbRow($pa, self::ACCOUNT_SETTINGS, true);

        $cols[] = 'user_id';
        $vals[] = (string) $_SESSION['user_id'];

        $accountId = $db->insert("accounts", $cols, [$vals]);
        $this->accountsDb = null;
        return $accountId;
    }

    /**
     * Updates some settings of an account in the database.
     *
     * If the given account ID does not refer to an account of the logged in user, nothing is changed.
     *
     * @param string $accountId ID of the account
     * @param AccountSettings $pa Array with the settings to update
     */
    public function updateAccount(string $accountId, array $pa): void
    {
        // encrypt the password before storing it
        if (isset($pa['password'])) {
            $pa['password'] = Utils::encryptPassword($pa['password']);
        }

        [ $cols, $vals ] = $this->prepareDbRow($pa, self::ACCOUNT_SETTINGS, false);

        $userId = (string) $_SESSION['user_id'];
        if (!empty($cols) && !empty($userId)) {
            $db = Config::inst()->db();
            $db->update(['id' => $accountId, 'user_id' => $userId], $cols, $vals, "accounts");
            $this->accountsDb = null;
        }
    }

    /**
     * Deletes the given account from the database.
     * @param string $accountId ID of the account
     */
    public function deleteAccount(string $accountId): void
    {
        $infra = Config::inst();
        $db = $infra->db();

        try {
            $db->startTransaction(false);

            // getAccountConfig() throws an exception if the ID is invalid / no account of the current user
            $this->getAccountConfig($accountId);

            $abookIds = array_column($this->getAddressbookConfigsForAccount($accountId), 'id');

            // we explicitly delete all data belonging to the account, since
            // cascaded deletes are not supported by all database backends
            $this->deleteAddressbooks($abookIds, true);

            $db->delete($accountId, 'accounts');

            $db->endTransaction();
        } catch (\Exception $e) {
            $db->rollbackTransaction();
            throw $e;
        } finally {
            $this->accountsDb = null;
            $this->abooksDb = null;
        }
    }

    /**
     * Returns the IDs of all the user's addressbooks, optionally filtered.
     *
     * @psalm-assert !null $this->abooksDb
     * @param bool $activeOnly If true, only the active addressbooks of the user are returned.
     * @param bool $presetsOnly If true, only the addressbooks created from an admin preset are returned.
     * @return list<string>
     */
    public function getAddressbookIds(bool $activeOnly = true, bool $presetsOnly = false): array
    {
        $db = Config::inst()->db();

        if (!isset($this->abooksDb)) {
            $allAccountIds = $this->getAccountIds();
            $this->abooksDb = [];

            if (!empty($allAccountIds)) {
                /** @var FullAbookRow $abookrow */
                foreach ($db->get(['account_id' => $allAccountIds], [], 'addressbooks') as $abookrow) {
                    $this->abooksDb[$abookrow["id"]] = $abookrow;
                }
            }
        }

        $result = $this->abooksDb;

        // filter out the addressbooks of the accounts matching the filter conditions
        if ($presetsOnly) {
            $accountIds = $this->getAccountIds($presetsOnly);
            $result = array_filter($result, function (array $v) use ($accountIds): bool {
                return in_array($v["account_id"], $accountIds);
            });
        }

        if ($activeOnly) {
            $result = array_filter($result, function (array $v): bool {
                return $v["active"] == "1";
            });
        }

        return array_column($result, 'id');
    }

    /**
     * Retrieves an addressbook configuration (database row) by its database ID.
     *
     * @param string $abookId ID of the addressbook
     * @return FullAbookRow The addressbook config.
     * @throws \Exception If no addressbook with the given ID exists for this user.
     */
    public function getAddressbookConfig(string $abookId): array
    {
        // make sure the cache is loaded
        $this->getAddressbookIds(false);

        // check that this addressbook ID actually refers to one of the user's addressbooks
        if (isset($this->abooksDb[$abookId])) {
            return $this->abooksDb[$abookId];
        }

        throw new \Exception("No carddav addressbook with ID $abookId");
    }

    /**
     * Returns the addressbooks for the given account.
     *
     * @param string $accountId
     * @return array<string, FullAbookRow> The addressbook configs, indexed by addressbook id.
     */
    public function getAddressbookConfigsForAccount(string $accountId): array
    {
        // make sure the given account is an account of this user - otherwise, an exception is thrown
        $this->getAccountConfig($accountId);

        // make sure the cache is filled
        $this->getAddressbookIds(false);

        $abooks = array_filter(
            $this->abooksDb,
            function (array $v) use ($accountId): bool {
                return $v["account_id"] == $accountId;
            }
        );

        return $abooks;
    }

    /**
     * Retrieves an addressbook by its database ID.
     *
     * @param string $abookId ID of the addressbook
     * @return Addressbook The addressbook object.
     * @throws \Exception If no addressbook with the given ID exists for this user.
     */
    public function getAddressbook(string $abookId): Addressbook
    {
        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();

        $config = $this->getAddressbookConfig($abookId);
        $account = $this->getAccountConfig($config["account_id"]);

        $readonly = false;
        $requiredProps = [];

        if (isset($account["presetname"])) {
            try {
                $preset = $admPrefs->getPreset($account["presetname"], $config['url']);
                $readonly = $preset["readonly"];
                $requiredProps = $preset["require_always"];
            } catch (\Exception $e) {
                // preset may not exist anymore, addressbook will be removed on next login. For now provide it as
                // readonly addressbook
                $readonly = true;
            }
        }

        $config['username'] = Utils::replacePlaceholdersUsername($account["username"]);
        $config['password'] = Utils::replacePlaceholdersPassword($account["password"]);

        $abook = new Addressbook($abookId, $config, $readonly, $requiredProps);
        return $abook;
    }

    /**
     * Inserts a new addressbook into the database.
     * @param AbookSettings $pa Array with the settings for the new addressbook
     * @return string Database ID of the newly created addressbook
     */
    public function insertAddressbook(array $pa): string
    {
        $db = Config::inst()->db();

        [ $cols, $vals ] = $this->prepareDbRow($pa, self::ABOOK_SETTINGS, true);

        // getAccountConfig() throws an exception if the ID is invalid / no account of the current user
        $this->getAccountConfig($pa['account_id'] ?? '');

        $abookId = $db->insert("addressbooks", $cols, [$vals]);
        $this->abooksDb = null;
        return $abookId;
    }

    /**
     * Updates some settings of an addressbook in the database.
     *
     * If the given addresbook ID does not refer to an addressbook of the logged in user, nothing is changed.
     *
     * @param string $abookId ID of the addressbook
     * @param AbookSettings $pa Array with the settings to update
     */
    public function updateAddressbook(string $abookId, array $pa): void
    {
        [ $cols, $vals ] = $this->prepareDbRow($pa, self::ABOOK_SETTINGS, false);

        $accountIds = $this->getAccountIds();
        if (!empty($cols) && !empty($accountIds)) {
            $db = Config::inst()->db();
            $db->update(['id' => $abookId, 'account_id' => $accountIds], $cols, $vals, "addressbooks");
            $this->abooksDb = null;
        }
    }

    /**
     * Deletes the given addressbooks from the database.
     *
     * @param list<string> $abookIds IDs of the addressbooks
     * @param bool $skipTransaction If true, perform the operations without starting a transaction. Useful if the
     *                                operation is called as part of an enclosing transaction.
     * @throws \Exception If any of the given addressbook IDs does not refer to an addressbook of the user.
     */
    public function deleteAddressbooks(array $abookIds, bool $skipTransaction = false): void
    {
        $infra = Config::inst();
        $db = $infra->db();

        if (empty($abookIds)) {
            return;
        }

        try {
            if (!$skipTransaction) {
                $db->startTransaction(false);
            }

            $userAbookIds = $this->getAddressbookIds(false);
            if (count(array_diff($abookIds, $userAbookIds)) > 0) {
                throw new \Exception("request with IDs not referring to addressbooks of current user");
            }

            // we explicitly delete all data belonging to the addressbook, since
            // cascaded deletes are not supported by all database backends
            // ...custom subtypes
            $db->delete(['abook_id' => $abookIds], 'xsubtypes');

            // ...groups and memberships
            /** @psalm-var list<string> $delgroups */
            $delgroups = array_column($db->get(['abook_id' => $abookIds], ['id'], 'groups'), "id");
            if (!empty($delgroups)) {
                $db->delete(['group_id' => $delgroups], 'group_user');
            }

            $db->delete(['abook_id' => $abookIds], 'groups');

            // ...contacts
            $db->delete(['abook_id' => $abookIds], 'contacts');

            $db->delete(['id' => $abookIds], 'addressbooks');

            if (!$skipTransaction) {
                $db->endTransaction();
            }
        } catch (\Exception $e) {
            if (!$skipTransaction) {
                $db->rollbackTransaction();
            }
            throw $e;
        } finally {
            $this->abooksDb = null;
        }
    }

    /**
     * Resyncs the given addressbook and displays a popup message about duration.
     *
     * @param Addressbook $abook The addressbook object
     */
    public function resyncAddressbook(Addressbook $abook): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        try {
            // To avoid unneccessary work followed by roll back with other time-triggered refreshes, we temporarily
            // set the last_updated time such that the next due time will be five minutes from now
            $ts_delay = time() + 300 - $abook->getRefreshTime();
            $this->updateAddressbook($abook->getId(), ["last_updated" => $ts_delay]);
            $duration = $abook->resync();

            $rc->showMessage(
                $rc->locText(
                    'cd_msg_synchronized',
                    [ 'name' => $abook->get_name(), 'duration' => (string) $duration ]
                ),
                'notice',
                false
            );
        } catch (\Exception $e) {
            $logger = $infra->logger();
            $logger->error("Failed to sync addressbook: {$e->getMessage()}");
            $rc->showMessage(
                $rc->locText(
                    'cd_msg_syncfailed',
                    [ 'name' => $abook->get_name(), 'errormsg' => $e->getMessage() ]
                ),
                'warning',
                false
            );
        }
    }

    /**
     * Prepares the row for a database insert or update operation from addressbook / account fields.
     *
     * Optionally checks that the given $settings contain values for all mandatory fields.
     *
     * @param array<string, null|string|int|bool> $settings
     *   The settings and their values.
     * @param array<string,SettingSpecification> $fieldspec
     *   The field specifications. Note that only fields that are part of this specification will be taken from
     *   $settings, others are ignored.
     * @param bool $isInsert
     *   True if the row is prepared for insertion, false if row is prepared for update. For insert, the row will be
     *   checked to include all mandatory attributes. For update, the row will be checked to not include non-updateable
     *   attributes.
     *
     * @return array{list<string>, list<string>}
     *   An array with two members: The first is an array of column names for insert/update. The second is the matching
     *   array of values.
     */
    private function prepareDbRow(array $settings, array $fieldspec, bool $isInsert): array
    {
        $cols = []; // column names
        $vals = []; // columns values

        foreach ($fieldspec as $col => $spec) {
            [ $type, $mandatory, $updateable ] = $spec;

            if (isset($settings[$col])) {
                if ($isInsert || $updateable) {
                    $cols[] = $col;

                    if ($type == 'bool') {
                        $vals[] = ($settings[$col] ? '1' : '0');
                    } else {
                        $vals[] = (string) $settings[$col];
                    }
                } else {
                    throw new \Exception(__METHOD__ . ": Attempt to update non-updateable field $col");
                }
            } elseif ($mandatory && $isInsert) {
                throw new \Exception(__METHOD__ . ": Mandatory field $col missing");
            }
        }

        return [ $cols, $vals ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
