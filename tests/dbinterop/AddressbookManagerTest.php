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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\Config;
use MStilkerich\CardDavAddressbook4Roundcube\Addressbook;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Frontend\AddressbookManager;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;

/**
 * @psalm-import-type DbGetResult from AbstractDatabase
 * @psalm-import-type TestDataKeyRef from TestData
 * @psalm-import-type TestDataRowWithKeyRef from TestData
 */
final class AddressbookManagerTest extends TestCase
{
    /** @var AbstractDatabase */
    private static $db;

    /** @var TestData */
    private static $testData;

    /** @var string $userId */
    private static $userId;

    /** @var list<string> */
    private const ACCOUNT_COLS = [ "name", "username", "password", "url", "user_id", "presetname" ];

    /** @var list<list<?string>> Initial test accounts */
    private const ACCOUNT_ROWS = [
        [ "Custom Account", "u1", "p1", "https://contacts.example.com/", [ "users", 0, 'builtin' ], null ],
        [ "Preset Account", "%u", "%p", "https://contacts.example.com/", [ "users", 0, 'builtin' ], "admpreset" ],
        [ "Account w/o addressbooks", "u1", "p1", "https://contacts.example.com/", [ "users", 0, 'builtin' ], null ],
        [ "Removed preset", "user", "pass", "https://rm.example.com/", [ "users", 0, 'builtin' ], "rmpreset" ],
        [ "U2 Custom Account", "usr2", "pass", "http://ex.com/abooks", [ "users", 1, 'builtin' ], null ],
        [ "U2 Preset Account", "%u", "%p", "https://contacts.example.com/", [ "users", 1, 'builtin' ], "admpreset" ],
    ];

    /** @var list<string> */
    private const ABOOK_COLS = [
        "name", "url", "active", "last_updated", "refresh_time", "sync_token", "use_categories", "discovered",
        "account_id"
    ];

    /** @var list<list<?string>> Initial test accounts */
    private const ABOOK_ROWS = [
        [ "CA1", "https://contacts.example.com/a1", '1', '123', '100', 'a1@1', '1', '1', [ "carddav_accounts", 0 ] ],
        [ "CA2", "https://contacts.example.com/a2", '0', '123', '100', 'a2@3', '0', '0', [ "carddav_accounts", 0 ] ],
        [ "PA1", "https://contacts.example.com/a1", '1', '123', '100', 'a1@1', '1', '1', [ "carddav_accounts", 1 ] ],
        [ "PA2", "https://contacts.example.com/a2", '0', '123', '100', 'a2@1', '0', '0', [ "carddav_accounts", 1 ] ],
        [ "RM1", "https://rm.example.com/rm1", '1', '123', '100', 'rm1@1', '1', '1', [ "carddav_accounts", 3 ] ],
        [ "U2-CA1", "https://contacts.example.com/a1", '1', '123', '100', 'a1@1', '1', '1', [ "carddav_accounts", 4 ] ],
        [ "U2-PA1", "https://contacts.example.com/a1", '1', '123', '100', 'a1@1', '0', '0', [ "carddav_accounts", 5 ] ],
    ];

    public static function setUpBeforeClass(): void
    {
        // Initialize database
        [ $dsnw ] = TestInfrastructureDB::dbSettings();
        self::$db = TestInfrastructureDB::initDatabase($dsnw);
        TestInfrastructure::init(self::$db, __DIR__ . '/data/AddressbookManagerTest/config.inc.php');

        self::$testData = new TestData(TestInfrastructureDB::getDbHandle());
        $testData = self::$testData;
        $testData->initDatabase(true);
        $testData->setCacheKeyPrefix('AddressbookManagerTest');

        // insert test data
        foreach (self::ACCOUNT_ROWS as $row) {
            // store the password base64 encoded so we can test it is decrypted by addressbook manager
            $passwordIdx = array_search('password', self::ACCOUNT_COLS);
            TestCase::assertIsInt($passwordIdx);
            TestCase::assertIsString($row[$passwordIdx]);
            $row[$passwordIdx] = '{BASE64}' . base64_encode($row[$passwordIdx]);

            $testData->insertRow('carddav_accounts', self::ACCOUNT_COLS, $row);
        }
        foreach (self::ABOOK_ROWS as $row) {
            $testData->insertRow('carddav_addressbooks', self::ABOOK_COLS, $row);
        }

        self::$userId = (string) $_SESSION['user_id'];
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        $_SESSION['user_id'] = self::$userId;
        TestInfrastructure::logger()->reset();
    }

    /**
     * @return array<string, array{int, bool, list<int>}>
     */
    public function userIdProviderAcc(): array
    {
        return [
            'All accounts of user' => [ 0, false,  [ 0, 1, 2, 3 ] ],
            'Preset accounts of user' => [ 0, true,  [ 1, 3 ] ],
        ];
    }

    /**
     * Tests that the correct account IDs for a user can be retrieved.
     * @param list<int> $expAccountIdxs
     * @dataProvider userIdProviderAcc
     */
    public function testAllAccountIdsCanBeRetrieved(int $userIdx, bool $presetOnly, array $expAccountIdxs): void
    {
        // set the user ID in the session
        $_SESSION['user_id'] = self::$testData->getRowId('users', $userIdx, 'builtin');

        $abMgr = new AddressbookManager();
        $accountIds = $abMgr->getAccountIds($presetOnly);
        sort($accountIds);

        $accountIdsExp = [];
        foreach ($expAccountIdxs as $idx) {
            $accountIdsExp[] = self::$testData->getRowId('carddav_accounts', $idx);
        }
        sort($accountIdsExp);

        $this->assertEquals($accountIdsExp, $accountIds, "AbMgr failed to return correct list of account ids");
    }

    /**
     * @return array<string, array{int, bool, bool, list<int>}>
     */
    public function userIdProvider(): array
    {
        return [
            'All addressbooks of user' => [ 0, false, false,  [ 0, 1, 2, 3, 4 ] ],
            'Active addressbooks of user' => [ 0, true, false,  [ 0, 2, 4 ] ],
            'Preset addressbooks of user' => [ 0, false, true,  [ 2, 3, 4 ] ],
            'Active preset addressbooks of user' => [ 0, true, true,  [ 2, 4 ] ],
            'User without addressbooks' => [ 2, false, false,  [ ] ],
        ];
    }

    /**
     * Tests that the correct addressbook IDs for a user can be retrieved.
     * @param list<int> $expAbookIdxs
     *
     * @dataProvider userIdProvider
     */
    public function testAddressbookIdsCanBeCorrectlyRetrieved(
        int $userIdx,
        bool $activeOnly,
        bool $presetOnly,
        array $expAbookIdxs
    ): void {
        // set the user ID in the session
        $_SESSION['user_id'] = self::$testData->getRowId('users', $userIdx, 'builtin');

        $abMgr = new AddressbookManager();
        $abookIds = $abMgr->getAddressbookIds($activeOnly, $presetOnly);
        sort($abookIds);

        $abookIdsExp = [];
        foreach ($expAbookIdxs as $idx) {
            $abookIdsExp[] = self::$testData->getRowId('carddav_addressbooks', $idx);
        }
        sort($abookIdsExp);

        $this->assertEquals($abookIdsExp, $abookIds, "AbMgr failed to return correct list of addressbook ids");
    }


    /** @return array<string, array{int,bool}> */
    public function accountIdProviderForCfgTest(): array
    {
        return [
            'Custom account' => [ 0, true ],
            'Preset account' => [ 1, true ],
            'Removed preset account' => [ 3, true ],
            'Account of different user' => [ 4, false ],
        ];
    }

    /**
     * Tests that the account configuration is provided as expected.
     *
     * Specifically, the password must be decrypted.
     *
     * @param bool $validId Indicates if the given account refers to a valid account of the user. If not, an exception
     *                      is expected.
     *
     * @dataProvider accountIdProviderForCfgTest
     */
    public function testAccountConfigCorrectlyProvided(int $accountIdx, bool $validId): void
    {
        $accountId = self::$testData->getRowId('carddav_accounts', $accountIdx);

        if (!$validId) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("No carddav account with ID $accountId");
        }

        $abMgr = new AddressbookManager();
        $cfg = $abMgr->getAccountConfig($accountId);
        $this->compareRow($cfg, self::ACCOUNT_COLS, self::ACCOUNT_ROWS[$accountIdx]);
    }

    /** @return array<string, array{int,bool}> */
    public function abookIdProviderForCfgTest(): array
    {
        return [
            'Custom addressbook' => [ 0, true ],
            'Custom addressbook (inactive)' => [ 1, true ],
            'Preset addressbook' => [ 2, true ],
            'Preset addressbook (inactive)' => [ 3, true ],
            'Removed preset addressbook' => [ 4, true ],
            'Addressbook of other user' => [ 5, false ],
        ];
    }

    /**
     * Tests that the addressbook configuration is provided as expected.
     *
     * @param bool $validId Indicates if the given addressbook belongs to a valid account of the user. If not, an
     *                      exception is expected.
     *
     * @dataProvider abookIdProviderForCfgTest
     */
    public function testAddressbookConfigCorrectlyProvided(int $abookIdx, bool $validId): void
    {
        $abookId = self::$testData->getRowId('carddav_addressbooks', $abookIdx);

        if (!$validId) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("No carddav addressbook with ID $abookId");
        }

        $abMgr = new AddressbookManager();
        $cfg = $abMgr->getAddressbookConfig($abookId);
        $this->compareRow($cfg, self::ABOOK_COLS, self::ABOOK_ROWS[$abookIdx]);
    }

    /**
     * Tests that the addressbook object is provided as expected.
     *
     * @param bool $validId Indicates if the given addressbook belongs to a valid account of the user. If not, an
     *                      exception is expected.
     *
     * @dataProvider abookIdProviderForCfgTest
     */
    public function testAddressbookCorrectlyProvided(int $abookIdx, bool $validId): void
    {
        $abookId = self::$testData->getRowId('carddav_addressbooks', $abookIdx);

        if (!$validId) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("No carddav addressbook with ID $abookId");
        }

        // attempt to fetch the addressbook instance
        $abMgr = new AddressbookManager();
        $abook = $abMgr->getAddressbook($abookId);
        $this->assertTrue($validId, "Expected exception on invalid addressbook it was not thrown");
        $this->assertInstanceOf(Addressbook::class, $abook);

        // if that worked, check the properties of the addressbook
        $abookTd = array_combine(self::ABOOK_COLS, self::$testData->resolveFkRefsInRow(self::ABOOK_ROWS[$abookIdx]));
        $this->assertIsString($abookTd['account_id']);
        $accountCfg = $abMgr->getAccountConfig($abookTd['account_id']);

        $readonly = false;
        $requiredProps = [];
        if (isset($accountCfg["presetname"])) {
            $presetName = $accountCfg["presetname"];

            if ($presetName == "rmpreset") {
                $readonly = true;
            } else {
                $admPrefs = Config::inst()->admPrefs();
                $presetCfg = $admPrefs->getPreset($accountCfg["presetname"], $abookTd["url"]);
                [ 'readonly' => $readonly, 'require_always' => $requiredProps ] = $presetCfg;
            }
        }

        $this->assertSame($abookId, $abook->getId(), "Addressbook ID mismatch");
        $this->assertSame($abookTd['name'], $abook->get_name(), "Addressbook name mismatch");
        $this->assertEquals($abookTd['refresh_time'], $abook->getRefreshTime(), "Addressbook refresh time mismatch");
        $this->assertSame($readonly, $abook->readonly, "Addressbook readonly mismatch");
        $this->assertSame(
            TestInfrastructure::getPrivateProperty($abook, 'requiredProps'),
            $requiredProps,
            "Addressbook requires properties mismatch"
        );
    }

    /**
     * Tests that the addressbook configurations for a given account are provided correctly.
     *
     * @param bool $validId Indicates if the given account refers to a valid account of the user. If not, an
     *                      exception is expected.
     *
     * @dataProvider accountIdProviderForCfgTest
     */
    public function testAddressbookConfigForAccountCorrectlyProvided(int $accountIdx, bool $validId): void
    {
        $accountId = self::$testData->getRowId('carddav_accounts', $accountIdx);

        if (!$validId) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("No carddav account with ID $accountId");
        }

        $abMgr = new AddressbookManager();
        $cfgs = $abMgr->getAddressbookConfigsForAccount($accountId);

        $accountIdRefIdx = array_search('account_id', self::ABOOK_COLS);
        $this->assertIsInt($accountIdRefIdx);
        $this->assertTrue($validId, "No exception thrown for getAddressbookConfigsForAccount on other user's account");

        $testDataAbooksById = [];
        foreach (self::ABOOK_ROWS as $idx => $row) {
            $this->assertIsArray($row[$accountIdRefIdx]);
            if ($row[$accountIdRefIdx][1] == $accountIdx) {
                $row[$accountIdRefIdx] = $accountId;
                $row = array_combine(self::ABOOK_COLS, $row);

                $abookId = self::$testData->getRowId('carddav_addressbooks', $idx);
                $row['id'] = $abookId;
                $testDataAbooksById[$abookId] = $row;
            }
        }

        $this->assertEquals($testDataAbooksById, $cfgs, "Returned addressbook configs for account not as expected");
    }

    /** @return array<string, array{array<string, null|string|int|TestDataKeyRef>, bool}> */
    public function accountInsertDataProvider(): array
    {
        return [
            'All properties specified' => [
                [
                    'name' => 'New Account', 'username' => 'newusr', 'password' => 'newpass', 'url' => 'foo.com',
                    'rediscover_time' => 500, 'presetname' => null
                ],
                false
            ],
            'Only mandatory properties specified' => [
                [ 'name' => 'New Account', 'username' => 'newusr', 'password' => 'newpass' ],
                false
            ],
            'Include user_id of a different user' => [
                [
                    'name' => 'New Account', 'username' => 'newusr', 'password' => 'newpass',
                    'user_id' => [ 'users', 1, 'builtin' ]
                ],
                false
            ],
            'Include extra properties that must not be set (both unknown and unsettable ones)' => [
                [
                    'name' => 'New Account', 'username' => 'newusr', 'password' => 'newpass',
                    // not settable
                    'last_discovered' => '100', 'user_id' => 5,
                    // not existing
                    'extraattribute' => 'foo',
                ],
                false
            ],
            'Lacks mandatory attribute (name)' => [
                [ 'username' => 'newusr', 'password' => 'newpass' ], true
            ],
            'Lacks mandatory attribute (username)' => [
                [ 'name' => 'New Account', 'password' => 'newpass' ], true
            ],
            'Lacks mandatory attribute (password)' => [
                [ 'name' => 'New Account', 'username' => 'newusr' ], true
            ],
        ];
    }
    /**
     * Tests that insertion of a new account works.
     *
     * This must consider that the new account is also returned in subsequent invocations of getAccountIds().
     * It must also consider that the DB row is created as expected, including the proper default values for optional
     * columns if omitted.
     *
     * Password must be stored according to the encryption scheme in the DB.
     *
     * It must not be possible to insert an account for a different user.
     *
     * @param array<string, null|string|int|TestDataKeyRef> $accSettings
     * @dataProvider accountInsertDataProvider
     */
    public function testAccountIsInsertedProperly(array $accSettings, bool $missMandatory): void
    {
        // resolve foreign key references
        $accSettings = $this->resolveFkRefsInRow($accSettings);
        if (isset($accSettings['user_id'])) {
            $this->assertNotEquals(self::$userId, $accSettings['user_id'], "Test should attempt insert for other user");
        }

        if ($missMandatory) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("Mandatory field");
        }

        $abMgr = new AddressbookManager();
        $accountsIdsBefore = $abMgr->getAccountIds();

        // insert the new account
        /** @psalm-suppress ArgumentTypeCoercion For test purposes, we may feed invalid data */
        $accountId = $abMgr->insertAccount($accSettings);
        $this->assertFalse($missMandatory, "Expected exception was not thrown for missing mandatory attributes");
        $accountIdsAfter = $abMgr->getAccountIds();

        // check that new account is reported with getAccountIds
        $this->assertCount(
            count($accountsIdsBefore) + 1,
            $accountIdsAfter,
            "new accounts not one element more than before insert"
        );
        $this->assertSame(
            [$accountId],
            array_values(array_diff($accountIdsAfter, $accountsIdsBefore)),
            "getAccountIds did not return new account"
        );

        // check that the row in the database is as expected
        $dbRow = self::$db->lookup($accountId, [], 'accounts');
        $this->assertIsString($accSettings['password']);
        $expDbRow = $accSettings;
        unset($expDbRow['extraattribute']);
        $expDbRow['id'] = $accountId;
        $expDbRow['user_id'] = self::$userId;
        $expDbRow['password'] = '{BASE64}' . base64_encode($accSettings['password']);

        $expDbRow['url'] = $accSettings['url'] ?? null;
        $expDbRow['rediscover_time'] = $accSettings['rediscover_time'] ?? '86400';
        $expDbRow['presetname'] = $accSettings['presetname'] ?? null;
        $expDbRow['last_discovered'] = '0'; // cannot be set with insertAccount

        foreach ($expDbRow as $idx => $val) {
            if (is_int($val)) {
                $expDbRow[$idx] = (string) $val;
            }
        }
        $this->assertEquals($expDbRow, $dbRow, "Row not stored as expected in database");

        // check that the account can also be retrieved using getAccountConfig
        $accountCfg = $abMgr->getAccountConfig($accountId);
        $expDbRow['password'] = $accSettings['password'];
        $this->assertEquals($expDbRow, $accountCfg, "New account config not returned as expected");

        // clean up
        self::$db->delete($accountId, 'accounts');
    }

    /**
     * Compares a row from the test data with a row from the Database.
     *
     * @param DbGetResult $dbRow
     * @param list<string> $cols
     * @param TestDataRowWithKeyRef $testDataRow
     */
    private function compareRow(array $dbRow, array $cols, array $testDataRow): void
    {
        foreach ($cols as $idx => $col) {
            if (is_array($testDataRow[$idx])) {
                $testDataRow[$idx] = self::$testData->resolveFkRef($testDataRow[$idx]);
            }
            $this->assertEquals($testDataRow[$idx], $dbRow[$col], "Unexpected value for $col in database row");
        }
    }

    /**
     * Resolves foreign key references in an associative row of test data.
     * @param array<string, null|int|string|TestDataKeyRef> $row
     * @return array<string, null|int|string>
     */
    private function resolveFkRefsInRow(array $row): array
    {
        $result = [];
        foreach ($row as $idx => $cell) {
            if (is_array($cell)) {
                $result[$idx] = self::$testData->resolveFkRef($cell);
            } else {
                $result[$idx] = $cell;
            }
        }
        return $result;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
