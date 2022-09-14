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

use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook, Config};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type FullAbookRow from AbstractDatabase
 *
 * Describes for each database field of an addressbook / account: mandatory on insert, updateable
 * @psalm-type SettingSpecification = array{bool, bool}
 *
 * The data types AccountCfg / AbookCfg describe the configuration of an account / addressbook as stored in the
 * database, with mappings of bitfields to the individual attributes.
 *
 * @psalm-type AccountCfg = FullAccountRow
 *
 * @psalm-type Int1 = '0' | '1'
 * @psalm-type AbookCfg = array{
 *     id: string,
 *     account_id: string,
 *     name: string,
 *     url: string,
 *     last_updated: numeric-string,
 *     refresh_time: numeric-string,
 *     sync_token: string,
 *     flags: numeric-string,
 *     active: Int1,
 *     use_categories: Int1,
 *     discovered: Int1,
 *     readonly: Int1
 * }
 *
 * The data types AccountSettings / AbookSettings describe the attributes of an account / addressbook row in the
 * corresponding DB table, that can be used for inserting / updating the addressbook. Contrary to the  AccountCfg /
 * AbookCfg types:
 *   - all keys are optional (for use of update of individual columns, others are not specified)
 *   - DB managed columns (particularly: id) are missing
 *
 * @psalm-type AccountSettings = array{
 *     id?: string,
 *     accountname?: string,
 *     username?: string,
 *     password?: string,
 *     discovery_url?: ?string,
 *     rediscover_time?: int | numeric-string,
 *     last_discovered?: int | numeric-string,
 *     presetname?: ?string
 * }
 *
 * @psalm-type AbookSettings = array{
 *     account_id?: string,
 *     name?: string,
 *     url?: string,
 *     last_updated?: int | numeric-string,
 *     refresh_time?: int | numeric-string,
 *     sync_token?: string,
 *     active?: Int1,
 *     use_categories?: Int1,
 *     discovered?: Int1,
 *     readonly?: Int1
 * }
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
        // [mandatory, updateable]
        'accountname' => [ true, true ],
        'username' => [ true, true ],
        'password' => [ true, true ],
        'discovery_url' => [ false, true ], // discovery URI can be NULL, disables discovery
        'rediscover_time' => [ false, true ],
        'last_discovered' => [ false, true ],
        'presetname' => [ false, false ],
    ];

    /**
     * @var array<string,SettingSpecification>
     *      AbookSettings List of user-/admin-configurable settings for an addressbook. Note: The array must contain all
     *      fields of the AbookSettings type. Only fields listed in the array can be set via the insertAddressbook()
     *      / updateAddressbook() methods.
     */
    private const ABOOK_SETTINGS = [
        // [mandatory, updateable]
        'account_id' => [ true, false ],
        'name' => [ true, true ],
        'url' => [ true, false ],
        'refresh_time' => [ false, true ],
        'last_updated' => [ false, true ],
        'sync_token' => [ true, true ],

        'active'         => [ false, true ],
        'use_categories' => [ false, true ],
        'discovered'     => [ false, false ],
        'readonly'       => [ false, true ],
    ];

    /** @var ?array<string, AccountCfg> $accountsDb
     *    Cache of the user's account DB entries. Associative array mapping account IDs to DB rows.
     */
    private $accountsDb = null;

    /** @var ?array<string, AbookCfg> $abooksDb
     *    Cache of the user's addressbook DB entries. Associative array mapping addressbook IDs to DB rows.
     */
    private $abooksDb = null;

    public function __construct()
    {
        // CAUTION: expected to be empty as no initialized plugin environment available yet
    }

    /**
     * Returns the IDs of all the user's accounts, optionally filtered.
     *
     * @param bool $presetsOnly If true, only the accounts created from an admin preset are returned.
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
     * @return AccountCfg The addressbook config.
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
     * Converts an addressbook DB row to an addressbook config.
     *
     * This means that fields that are stored differently in the DB than presented at application level are converted
     * from DB format to application level. Currently, this conversion is only needed for bitfields.
     *
     * @param FullAbookRow $abookrow
     * @return AbookCfg
     */
    private function abookRow2Cfg(array $abookrow): array
    {
        // set the application-level fields from the DB-level fields
        foreach (AbstractDatabase::FLAGS_COLS['addressbooks']['fields'] as $cfgAttr => $bitPos) {
            $abookrow[$cfgAttr] = (($abookrow['flags'] & (1 << $bitPos)) ? '1' : '0');
        }

        /** @psalm-var AbookCfg $abookrow Psalm does not keep track of the type of individual array members above */
        return $abookrow;
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
                    $abookCfg = $this->abookRow2Cfg($abookrow);
                    $this->abooksDb[$abookrow["id"]] = $abookCfg;
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
     * @return AbookCfg The addressbook config.
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
     * @param ?bool  $discoveredType If set, only return discovered (true) / non-discovered (false) addressbooks
     * @return array<string, AbookCfg> The addressbook configs, indexed by addressbook id.
     */
    public function getAddressbookConfigsForAccount(string $accountId, ?bool $discoveredType = null): array
    {
        // make sure the given account is an account of this user - otherwise, an exception is thrown
        $this->getAccountConfig($accountId);

        // make sure the cache is filled
        $this->getAddressbookIds(false);

        $abooks = array_filter(
            $this->abooksDb,
            function (array $v) use ($accountId, $discoveredType): bool {
                return $v["account_id"] == $accountId &&
                    (is_null($discoveredType) || $v["discovered"] == $discoveredType) ;
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

        // username and password may be stored as placeholders in the database
        // the URL is always stored without placeholders and needs not be replaced
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

        [ 'default' => $flagsInit, 'fields' => $flagAttrs ] = AbstractDatabase::FLAGS_COLS['addressbooks'];
        [ $cols, $vals ] = $this->prepareDbRow($pa, self::ABOOK_SETTINGS, true, $flagAttrs, $flagsInit);

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
        $abookCfg = $this->getAddressbookConfig($abookId);
        $flagAttrs = AbstractDatabase::FLAGS_COLS['addressbooks']['fields'];
        $flagsInit = intval($abookCfg['flags']);
        [ $cols, $vals ] = $this->prepareDbRow($pa, self::ABOOK_SETTINGS, false, $flagAttrs, $flagsInit);

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
     *
     * @param bool $skipTransaction If true, perform the operations without starting a transaction. Useful if the
     *                                operation is called as part of an enclosing transaction.
     *
     * @param bool $cacheOnly If true, only the cached addressbook data is deleted and the sync reset, but the
     *                        addressbook itself is retained.
     *
     * @throws \Exception If any of the given addressbook IDs does not refer to an addressbook of the user.
     */
    public function deleteAddressbooks(array $abookIds, bool $skipTransaction = false, bool $cacheOnly = false): void
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

            // and finally the addressbooks themselves
            if ($cacheOnly) {
                $db->update(['id' => $abookIds], ['last_updated', 'sync_token'], ['0', ''], "addressbooks");
            } else {
                $db->delete(['id' => $abookIds], 'addressbooks');
            }

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
     * Discovers the addressbooks for the given account.
     *
     * The given account may be new or already exist in the database. In case of an existing account, it is expected
     * that the id field in $accountCfg is set to the corresponding ID.
     *
     * The function discovers the addressbooks for that account. Upon successful discovery, the account is
     * inserted/updated in the database, including setting of the last_discovered time. The auto-discovered addressbooks
     * of an existing account are updated accordingly, i.e. new addressboooks are inserted, and addressbooks that are no
     * longer discovered are also removed from the local db.
     *
     * @param AccountSettings $accountCfg Array with the settings for the account
     * @param AbookSettings $abookTmpl Array with default settings for new addressbooks
     * @return string The database ID of the account
     *
     * @throws \Exception If the discovery failed for some reason. In this case, the state in the db remains unchanged.
     */
    public function discoverAddressbooks(array $accountCfg, array $abookTmpl): string
    {
        $infra = Config::inst();

        if (!isset($accountCfg['discovery_url'])) {
            throw new \Exception('Cannot discover addressbooks for an account lacking a discovery URI');
        }

        $account = Config::makeAccount(
            Utils::replacePlaceholdersUrl($accountCfg['discovery_url']),
            Utils::replacePlaceholdersUsername($accountCfg['username'] ?? ''),
            Utils::replacePlaceholdersPassword($accountCfg['password'] ?? ''),
            null
        );
        $discover = $infra->makeDiscoveryService();
        $abooks = $discover->discoverAddressbooks($account);

        if (isset($accountCfg['id'])) {
            $accountId = $accountCfg['id'];
            $this->updateAccount($accountId, [ 'last_discovered' => time() ]);

            // get locally existing addressbooks for this account
            $newbooks = []; // AddressbookCollection[] with new addressbooks at the server side
            $dbbooks = array_column($this->getAddressbookConfigsForAccount($accountId, true), 'id', 'url');
            foreach ($abooks as $abook) {
                $abookUri = $abook->getUri();
                if (isset($dbbooks[$abookUri])) {
                    unset($dbbooks[$abookUri]); // remove so we can sort out the deleted ones
                } else {
                    $newbooks[] = $abook;
                }
            }

            // delete all addressbooks we cannot find on the server anymore
            $this->deleteAddressbooks(array_values($dbbooks));
        } else {
            $accountCfg['last_discovered'] = time();
            $accountId = $this->insertAccount($accountCfg);
            $newbooks = $abooks;
        }

        // store discovered addressbooks
        $abookTmpl['account_id'] = $accountId;
        $abookTmpl['discovered'] = '1';
        $abookTmpl['sync_token'] = '';
        foreach ($newbooks as $abook) {
            $abookTmpl['name'] = $abook->getName();
            $abookTmpl['url'] = $abook->getUri();
            $this->insertAddressbook($abookTmpl);
        }

        return $accountId;
    }

    /**
     * Resyncs the given addressbook.
     *
     * @param Addressbook $abook The addressbook object
     * @return int The duration in seconds that the sync took
     */
    public function resyncAddressbook(Addressbook $abook): int
    {
        // To avoid unneccessary work followed by roll back with other time-triggered refreshes, we temporarily
        // set the last_updated time such that the next due time will be five minutes from now
        $ts_delay = time() + 300 - $abook->getRefreshTime();
        $this->updateAddressbook($abook->getId(), ["last_updated" => $ts_delay]);
        $duration = $abook->resync();
        return $duration;
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
     * @param array<string,int> $flagAttrs Attributes mapped to flags field and their bit positions
     * @param int $flagsInit
     *   The start value of the flags field. Only the values of application-level attributes contained in $settings will
     *   be changed.
     *
     * @return array{list<string>, list<string>}
     *   An array with two members: The first is an array of column names for insert/update. The second is the matching
     *   array of values.
     */
    private function prepareDbRow(
        array $settings,
        array $fieldspec,
        bool $isInsert,
        array $flagAttrs = [],
        int $flagsInit = 0
    ): array {
        $cols = []; // column names
        $vals = []; // columns values

        $setFlags = false; // if true, append the flags column with flagsInit value

        foreach ($fieldspec as $col => [ $mandatory, $updateable ]) {
            if (isset($settings[$col])) {
                if ($isInsert || $updateable) {
                    if (isset($flagAttrs[$col])) {
                        $setFlags = true;
                        $mask = 1 << $flagAttrs[$col];
                        if ($settings[$col]) {
                            $flagsInit |= $mask;
                        } else {
                            $flagsInit &= ~$mask;
                        }
                    } else {
                        $cols[] = $col;
                        $vals[] = (string) $settings[$col];
                    }
                } else {
                    throw new \Exception(__METHOD__ . ": Attempt to update non-updateable field $col");
                }
            } elseif ($mandatory && $isInsert) {
                throw new \Exception(__METHOD__ . ": Mandatory field $col missing");
            }
        }

        if ($setFlags) {
            $cols[] = 'flags';
            $vals[] = (string) $flagsInit;
        }

        return [ $cols, $vals ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
