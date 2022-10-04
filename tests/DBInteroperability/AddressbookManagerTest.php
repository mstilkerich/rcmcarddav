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

namespace MStilkerich\Tests\RCMCardDAV\DBInteroperability;

use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\RCMCardDAV\Config;
use MStilkerich\RCMCardDAV\Addressbook;
use MStilkerich\RCMCardDAV\Db\AbstractDatabase;
use MStilkerich\RCMCardDAV\Frontend\AddressbookManager;
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;

/**
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type DbGetResult from AbstractDatabase
 * @psalm-import-type TestDataKeyRef from TestData
 * @psalm-import-type TestDataRowWithKeyRef from TestData
 * @psalm-import-type AbookCfg from AddressbookManager
 * @psalm-import-type AbookFilter from AddressbookManager
 * @psalm-import-type Int1 from AddressbookManager
 *
 * @psalm-type AddressbookSettings = array{
 *   refresh_time?: numeric-string,
 *   active?: Int1,
 *   use_categories?: Int1,
 *   readonly?: Int1,
 *   require_always_email?: Int1
 * }
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
    private const ACCOUNT_COLS = [ "accountname", "username", "password", "discovery_url", "user_id", "presetname" ];

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
        "name", "url", "last_updated", "refresh_time", "sync_token", "account_id", "flags"
    ];

    /** @var list<string> addressbook application-level fields stored in flags (starting with bit0 ascending) */
    private const ABOOK_FLAGS = [
        'active', 'use_categories', 'discovered', 'readonly', 'require_always_email', 'template'
    ];

    /** @var list<string> */
    private const ABOOKCFG_FIELDS = [
        "name", "url", "last_updated", "refresh_time", "sync_token", "account_id", "flags",
        "active", "use_categories", "discovered", "readonly", "require_always_email", "template" // app-level flags
    ];

    /** @var list<list<?string>> Initial test addressbooks */
    private const ABOOK_ROWS = [
        [ "CA1",    "https://contacts.example.com/a1",     '123', '100', 'a1@1',  ["carddav_accounts", 0], '7' ],
        [ "CA2",    "https://contacts.example.com/a2",     '123', '100', 'a2@3',  ["carddav_accounts", 0], '6' ],
        [ "PA1",    "https://contacts.example.com/a1",     '123', '100', 'a1@1',  ["carddav_accounts", 1], '23' ],
        [ "PA2",    "https://contacts.example.com/a2",     '123', '100', 'a2@1',  ["carddav_accounts", 1], '16' ],
        [ "RM1",    "https://rm.example.com/rm1",          '123', '100', 'rm1@1', ["carddav_accounts", 3], '15' ],
        [ "U2-CA1", "https://contacts.example.com/a1",     '123', '100', 'a1@1',  ["carddav_accounts", 4], '7' ],
        [ "U2-PA1", "https://contacts.example.com/a1",     '123', '100', 'a1@1',  ["carddav_accounts", 5], '17' ],
        [ "CA3",    "https://contacts.example.com/a3",     '123', '100', 'a3@6',  ["carddav_accounts", 0], '5' ],
        [ "PAX",    "https://contacts.example.com/p/glb",  '123', '100', 'ax@9',  ["carddav_accounts", 1], '19' ],
        [ "PAX2",   "https://contacts.example.com/p/glb2", '123', '100', 'ax@9',  ["carddav_accounts", 1], '27' ],
        [ "CA4",    "https://contacts.example.com/a4",     '123', '100', 'a3@6',  ["carddav_accounts", 0], '1' ],
        [ "CATMPL", "",                                    '0',   '300', '',      ["carddav_accounts", 0], '33' ],
    ];

    /** @var array<string, Int1> Default settings for the addressbook flags */
    private const ABOOK_FLAG_DEFAULTS = [
        'active' => '1',
        'use_categories' => '0',
        'discovered' => '1',
        'readonly' => '0',
        'require_always_email' => '0',
        'template' => '0',
    ];

    public static function setUpBeforeClass(): void
    {
        // Initialize database
        [ $dsnw ] = TestInfrastructureDB::dbSettings();
        self::$db = TestInfrastructureDB::initDatabase($dsnw);
        TestInfrastructure::init(self::$db, __DIR__ . '/data/AddressbookManagerTest/config.inc.php');
    }

    public function setUp(): void
    {
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

        // must be called after testData->initDatabase, because initDatabase sets the SESSION variable
        self::$userId = (string) $_SESSION['user_id'];
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
     * @return array<string, array{int, AbookFilter, bool, list<int>}>
     */
    public function userIdProvider(): array
    {
        return [
            'All addressbooks of user' => [0, AddressbookManager::ABF_REGULAR, false,  [ 0, 1, 2, 3, 4, 7, 8, 9, 10 ]],
            'Active addressbooks of user' => [0, AddressbookManager::ABF_ACTIVE, false,  [ 0, 2, 4, 7, 8, 9, 10 ]],
            'Preset addressbooks of user' => [0, AddressbookManager::ABF_REGULAR, true,  [ 2, 3, 4, 8, 9 ]],
            'Active preset addressbooks of user' => [0, AddressbookManager::ABF_ACTIVE, true,  [ 2, 4, 8, 9 ]],
            'User without addressbooks'   => [ 2, AddressbookManager::ABF_REGULAR, false,  [ ] ],
        ];
    }

    /**
     * Tests that the correct addressbook IDs for a user can be retrieved.
     * @param AbookFilter $abFilter
     * @param list<int> $expAbookIdxs
     *
     * @dataProvider userIdProvider
     */
    public function testAddressbookIdsCanBeCorrectlyRetrieved(
        int $userIdx,
        array $abFilter,
        bool $presetOnly,
        array $expAbookIdxs
    ): void {
        // set the user ID in the session
        $_SESSION['user_id'] = self::$testData->getRowId('users', $userIdx, 'builtin');

        $abMgr = new AddressbookManager();
        $abookIds = $abMgr->getAddressbookIds($abFilter, $presetOnly);
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
            'Custom addressbook (vcard-style groups)' => [ 7, true ],
            'Custom addressbook (non-discovered)' => [ 10, true ],
            'Preset addressbook' => [ 2, true ],
            'Preset addressbook (inactive)' => [ 3, true ],
            'Preset addressbook (non-discovered)' => [ 8, true ],
            'Preset addressbook (non-discovered, read-only)' => [ 9, true ],
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
        $expCfg = $this->transformAbookFlagsTdRow(self::ABOOK_ROWS[$abookIdx]);
        $this->compareRow($cfg, self::ABOOKCFG_FIELDS, $expCfg);
    }

    /**
     * Transforms the flags DB field in an addressbook DB row to the application-level fields.
     *
     * @param TestDataRowWithKeyRef $testDataRow
     * @return TestDataRowWithKeyRef
     */
    private function transformAbookFlagsTdRow(array $testDataRow): array
    {
        $flagsIdx = array_search('flags', self::ABOOK_COLS);
        $this->assertIsInt($flagsIdx);

        $flags = intval($testDataRow[$flagsIdx]);

        // XXX this must match the order of the flags in the definition of ABOOKCFG_FIELDS
        $testDataRow[] = ($flags &  1) ? '1' : '0'; // active
        $testDataRow[] = ($flags &  2) ? '1' : '0'; // use_categories
        $testDataRow[] = ($flags &  4) ? '1' : '0'; // discovered
        $testDataRow[] = ($flags &  8) ? '1' : '0'; // readonly
        $testDataRow[] = ($flags & 16) ? '1' : '0'; // require_always_email
        $testDataRow[] = ($flags & 32) ? '1' : '0'; // template

        return $testDataRow;
    }

    /**
     * Adds the application-level flags to the given associative array.
     * @param list<string> $flagFields
     */
    private function addAppFlags(int $flagsVal, array $flagFields, array $row): array
    {
        $mask = 1;
        foreach ($flagFields as $attr) {
            $row[$attr] = ($flagsVal & $mask) ? '1' : '0';
            $mask <<= 1;
        }
        return $row;
    }

    /**
     * Transforms the application-level addressbook flag fields to a flags value in an associative array.
     *
     * @param DbGetResult $row
     * @return DbGetResult
     */
    private function transformAbookFlagsAssoc(array $row): array
    {
        $flags = 0;
        $mask  = 1;

        foreach (self::ABOOK_FLAGS as $attr) {
            if (($row[$attr] ?? self::ABOOK_FLAG_DEFAULTS[$attr])) {
                $flags |= $mask;
            }

            unset($row[$attr]); // unset so we can compare it to a DB row
            $mask <<= 1;
        }

        $row['flags'] = (string) $flags;
        return $row;
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
        $abookTd = array_combine(
            self::ABOOKCFG_FIELDS,
            self::$testData->resolveFkRefsInRow($this->transformAbookFlagsTdRow(self::ABOOK_ROWS[$abookIdx]))
        );
        $this->assertIsString($abookTd['account_id']);
        $this->assertSame($abookId, $abook->getId(), "Addressbook ID mismatch");
        $this->assertSame($abookTd['name'], $abook->get_name(), "Addressbook name mismatch");
        $this->assertEquals($abookTd['refresh_time'], $abook->getRefreshTime(), "Addressbook refresh time mismatch");
        $this->assertSame($abookTd['readonly'] == '1', $abook->readonly, "Addressbook readonly mismatch");
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

        foreach ([null, false, true] as $discoveryType) {
            $abFilter = is_null($discoveryType) ?
                AddressbookManager::ABF_REGULAR :
                ($discoveryType ? AddressbookManager::ABF_DISCOVERED : AddressbookManager::ABF_EXTRA);

            $cfgs = $abMgr->getAddressbookConfigsForAccount($accountId, $abFilter);

            $accountIdRefIdx = array_search('account_id', self::ABOOK_COLS);
            $flagsIdx = array_search('flags', self::ABOOK_COLS);
            $this->assertIsInt($accountIdRefIdx);
            $this->assertTrue($validId, "No exception for getAddressbookConfigsForAccount on other user's account");

            $testDataAbooksById = [];
            foreach (self::ABOOK_ROWS as $idx => $row) {
                $this->assertIsArray($row[$accountIdRefIdx]);

                // skip addressbooks of different account
                if ($row[$accountIdRefIdx][1] !== $accountIdx) {
                    continue;
                }

                // skip template addressbooks
                if ((intval($row[$flagsIdx]) & 32) !== 0) {
                    continue;
                }

                // only collect the addressbooks matching the current discovery type, null means all
                if (is_null($discoveryType) || (intval($row[$flagsIdx]) & 4) == ($discoveryType ? 4 : 0)) {
                    // resolve the account ID foreign key
                    $row[$accountIdRefIdx] = $accountId;

                    // replace flags with the app-level flags
                    $row = $this->transformAbookFlagsTdRow($row);
                    $row = array_combine(self::ABOOKCFG_FIELDS, $row);

                    // insert the id field
                    $abookId = self::$testData->getRowId('carddav_addressbooks', $idx);
                    $row['id'] = $abookId;
                    $testDataAbooksById[$abookId] = $row;
                }
            }

            $this->assertEquals(
                $testDataAbooksById,
                $cfgs,
                "Returned addressbook configs not as expected; discovery type: " . ($discoveryType ? 'true' : 'false')
            );
        }
    }

    /** @return array<string, array{array<string, null|string|int|TestDataKeyRef>, ?string}> */
    public function accountInsertDataProvider(): array
    {
        return [
            'All properties specified' => [
                [
                    'accountname' => 'New Account', 'username' => 'newusr', 'password' => 'newpass',
                    'discovery_url' => 'foo.com', 'rediscover_time' => 500, 'last_discovered' => '100',
                    'presetname' => null
                ],
                null
            ],
            'Only mandatory properties specified' => [
                [ 'accountname' => 'New Account', 'username' => 'newusr', 'password' => 'newpass' ],
                null
            ],
            'Include user_id of a different user' => [
                [
                    'accountname' => 'New Account', 'username' => 'newusr', 'password' => 'newpass',
                    'user_id' => [ 'users', 1, 'builtin' ]
                ],
                null
            ],
            'Include extra properties that must not be set (both unknown and unsettable ones)' => [
                [
                    'accountname' => 'New Account', 'username' => 'newusr', 'password' => 'newpass',
                    'presetname' => 'pres',
                    // not settable
                    'user_id' => 5,
                    // not existing
                    'extraattribute' => 'foo',
                ],
                null
            ],
            'Lacks mandatory attribute (accountname)' => [
                [ 'username' => 'newusr', 'password' => 'newpass' ], 'Mandatory field'
            ],
            'Lacks mandatory attribute (username)' => [
                [ 'accountname' => 'New Account', 'password' => 'newpass' ], 'Mandatory field'
            ],
            'Lacks mandatory attribute (password)' => [
                [ 'accountname' => 'New Account', 'username' => 'newusr' ], 'Mandatory field'
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
    public function testAccountIsInsertedProperly(array $accSettings, ?string $expExceptionMsg): void
    {
        $defaults = [
            // optional attributes with default values
            'discovery_url' => null,
            'rediscover_time' => '86400',
            'last_discovered' => '0',
            'presetname' => null,
        ];
        $notSettable = [
            // not settable by insert with initial values
            'user_id' => self::$userId,
        ];

        // resolve foreign key references
        $accSettings = $this->resolveFkRefsInRow($accSettings);
        if (isset($accSettings['user_id'])) {
            $this->assertNotEquals(self::$userId, $accSettings['user_id'], "Test should attempt insert for other user");
        }

        if (isset($expExceptionMsg)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expExceptionMsg);
        }

        $abMgr = new AddressbookManager();
        $accountIdsBefore = $abMgr->getAccountIds();

        // insert the new account
        /** @psalm-suppress InvalidArgument For test purposes, we may feed invalid data */
        $accountId = $abMgr->insertAccount($accSettings);
        $this->assertNull($expExceptionMsg, "Expected exception was not thrown for missing mandatory attributes");
        $accountIdsAfter = $abMgr->getAccountIds();

        // check that new account is reported with getAccountIds
        $this->assertCount(
            count($accountIdsBefore) + 1,
            $accountIdsAfter,
            "new accounts not one element more than before insert"
        );
        $this->assertEqualsCanonicalizing(
            array_merge($accountIdsBefore, [$accountId]),
            $accountIdsAfter,
            "getAccountIds did not return new account"
        );

        // check that the row in the database is as expected
        $dbRow = self::$db->lookup($accountId, [], 'accounts');
        $this->assertIsString($accSettings['password']);
        $expDbRow = $this->prepRowForDbRowComparison($accSettings, $defaults, $notSettable);
        $expDbRow['id'] = $accountId;
        $expDbRow['password'] = '{BASE64}' . base64_encode($accSettings['password']);
        $this->assertEquals($expDbRow, $dbRow, "Row not stored as expected in database");

        // check that the account can also be retrieved using getAccountConfig
        $accountCfg = $abMgr->getAccountConfig($accountId);
        $expDbRow['password'] = $accSettings['password'];
        $this->assertEquals($expDbRow, $accountCfg, "New account config not returned as expected");
    }

    /**
     * @return array<
     *     string,
     *     array{
     *         TestDataKeyRef,
     *         array<string, null|string|int|TestDataKeyRef>,
     *         ?FullAccountRow,
     *         ?string
     *     }
     * >
     */
    public function accountUpdateDataProvider(): array
    {
        $account0Base = array_combine(self::ACCOUNT_COLS, self::ACCOUNT_ROWS[0]);

        // these IDs are filled by the test
        $account0Base = array_merge($account0Base, [ 'id' => '0', 'user_id' => '0' ]);

        // defaults that are not part of the test data row
        $account0Base = array_merge($account0Base, [ 'rediscover_time' => '86400', 'last_discovered' => '0' ]);

        /** @psalm-var FullAccountRow $account0Base */

        return [
            'All updatable properties updated' => [
                [ 'carddav_accounts', 0 ],
                [
                    'accountname' => 'Updated Account', 'username' => 'updusr', 'password' => 'updpass',
                    'discovery_url' => 'cdav.com', 'rediscover_time' => 5000, 'last_discovered' => 1000
                ],
                [
                    'id' => '0', 'user_id' => '0', // these IDs are filled by the test
                    'accountname' => 'Updated Account', 'username' => 'updusr', 'password' => 'updpass',
                    'discovery_url' => 'cdav.com', 'rediscover_time' => '5000', 'last_discovered' => '1000',
                    'presetname' => null
                ],
                null
            ],
            'Nothing updated' => [
                [ 'carddav_accounts', 0 ],
                [ ],
                $account0Base,
                null
            ],
            'Only a single property updated' => [
                [ 'carddav_accounts', 0 ],
                [ 'last_discovered' => 554433 ],
                [ 'last_discovered' => '554433' ] + $account0Base,
                null
            ],
            'Try to update account to different user ID' => [
                [ 'carddav_accounts', 0 ],
                [ 'user_id' => ['users', 1, 'builtin'] ],
                // user_id is expected to be a not settable property, so no update should be performed and no exception
                // is expected, as this would require special handling of the user_id.
                $account0Base,
                null
            ],
            'Try changing not updatable attribute (presetname)' => [
                [ 'carddav_accounts', 0 ],
                [ 'presetname' => 'foo' ],
                // preset can only be set on insert, but not updated afterwards - exception expected
                null,
                "Attempt to update non-updatable field presetname"
            ],
            'Try to update account with an extra unknown attribute' => [
                [ 'carddav_accounts', 0 ],
                [ 'extraattribute' => 'foo' ],
                $account0Base,
                null
            ],
        ];
    }

    /**
     * Tests that update of an account works.
     *
     * This must consider that the updated account config is also returned in subsequent invocations of
     * getAccountConfig().
     *
     * Password must be stored according to the encryption scheme in the DB.
     *
     * It must not be possible to change the user_id to that of a different user.
     *
     * It must not be possible the update an account of a different user.
     *
     * @param TestDataKeyRef $accountFkRef
     * @param array<string, null|string|int|TestDataKeyRef> $accUpd
     * @param ?FullAccountRow $accExpResult
     * @dataProvider accountUpdateDataProvider
     */
    public function testAccountIsUpdatedProperly(
        array $accountFkRef,
        array $accUpd,
        ?array $accExpResult,
        ?string $expExceptionMsg
    ): void {
        // resolve foreign key references
        $accountId = self::$testData->resolveFkRef($accountFkRef);

        $accUpd = $this->resolveFkRefsInRow($accUpd);

        if (isset($accUpd['user_id'])) {
            $this->assertNotEquals(self::$userId, $accUpd['user_id'], "Test should attempt update to other user");
        }

        if (isset($expExceptionMsg)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expExceptionMsg);
        }

        $abMgr = new AddressbookManager();

        // update the account
        /** @psalm-suppress InvalidArgument For test purposes, we may feed invalid data */
        $abMgr->updateAccount($accountId, $accUpd);
        $this->assertNull($expExceptionMsg, "Expected exception was not thrown for missing mandatory attributes");
        $this->assertNotNull($accExpResult, "Expected result was not defined");
        $accExpResult['id'] = $accountId;
        $accExpResult['user_id'] = self::$userId;

        // check that the updated account can also be retrieved using getAccountConfig
        $accountCfg = $abMgr->getAccountConfig($accountId);
        $this->assertEquals($accExpResult, $accountCfg, "New account config not returned as expected");

        // check that the row in the database is as expected
        $dbRow = self::$db->lookup($accountId, [], 'accounts');
        $accExpResult['password'] = '{BASE64}' . base64_encode($accExpResult['password']);
        $this->assertEquals($accExpResult, $dbRow, "Row not stored as expected in database");
    }

    /** @return array<string, array{array<string, null|string|bool|int|TestDataKeyRef>, ?string}> */
    public function abookInsertDataProvider(): array
    {
        return [
            'All properties specified' => [
                [
                    'name' => 'New Abook', 'url' => 'https://c.ex.com/abook1/',
                    'refresh_time' => 500, 'sync_token' => 's@123', 'last_updated' => 100,
                    'active' => '1', 'use_categories' => '0', 'discovered' => '1', 'readonly' => '1',
                    'require_always_email' => '1',
                    'account_id' => ['carddav_accounts', 0]
                ],
                null
            ],
            'Bools for booleans' => [
                [
                    'name' => 'New Abook', 'url' => 'https://c.ex.com/abook1/', 'active' => '2',
                    'refresh_time' => 500, 'use_categories' => false, 'discovered' => true, 'sync_token' => 's@123',
                    'account_id' => ['carddav_accounts', 0]
                ],
                null
            ],
            'Ints for booleans' => [
                [
                    'name' => 'New Abook', 'url' => 'https://c.ex.com/abook1/', 'active' => 2,
                    'refresh_time' => 500, 'use_categories' => 1, 'discovered' => 0, 'sync_token' => 's@123',
                    'account_id' => ['carddav_accounts', 0]
                ],
                null
            ],
            'Only mandatory properties specified' => [
                [
                    'name' => 'New Account', 'url' => 'https://c.ex.com/a1/', 'account_id' => ['carddav_accounts', 0],
                    'sync_token' => 's@123',
                ],
                null
            ],
            'Include account_id of a different user' => [
                [
                    'name' => 'New Abook', 'url' => 'https://c.ex.com/a1/', 'account_id' => ['carddav_accounts', 4],
                    'sync_token' => 's@123',
                ],
                'No carddav account with ID'
            ],
            'Include extra properties that must not be set (both unknown and unsettable ones)' => [
                [
                    'name' => 'New Account', 'url' => 'https://c.ex.com/a1/', 'account_id' => ['carddav_accounts', 0],
                    'sync_token' => 's@123',
                    // not settable - currently none
                    // not existing
                    'extraattribute' => 'foo',
                ],
                null
            ],
            'Lacks mandatory attribute (name)' => [
                [ 'url' => 'https://c.ex.com/a1/', 'account_id' => ['carddav_accounts', 0], 'sync_token' => 's@123' ],
                'Mandatory field'
            ],
            'Lacks mandatory attribute (url)' => [
                [ 'name' => 'New Account', 'account_id' => ['carddav_accounts', 0], 'sync_token' => 's@123' ],
                'Mandatory field'
            ],
            'Lacks mandatory attribute (account_id)' => [
                [ 'name' => 'New Account', 'url' => 'https://c.ex.com/a1/', 'sync_token' => 's@123' ],
                'Mandatory field'
            ],
            'Lacks mandatory attribute (sync_token)' => [
                [ 'name' => 'New Account', 'url' => 'https://c.ex.com/a1/', 'account_id' => ['carddav_accounts', 0] ],
                'Mandatory field'
            ],
        ];
    }

    /**
     * Tests that insertion of a new addressbook works.
     *
     * This must consider that the new addressbook is also returned in subsequent invocations of getAddressbookIds().
     * It must also consider that the DB row is created as expected, including the proper default values for optional
     * columns if omitted.
     *
     * It must not be possible to insert an addressbook for an account of a different user.
     *
     * @param array<string, null|string|int|bool|TestDataKeyRef> $abookSettings
     * @dataProvider abookInsertDataProvider
     */
    public function testAddressbookIsInsertedProperly(array $abookSettings, ?string $expExceptionMsg): void
    {
        $defaults = [
            // optional attributes with default values
            'refresh_time' => '3600',
            'last_updated' => '0',
            'active' => '1',
            'use_categories' => '0',
            'discovered' => '1',
            'readonly' => '0',
            'require_always_email' => '0',
        ];
        $notSettable = [
            // not settable by insert with initial values
        ];

        // resolve foreign key references
        $abookSettings = $this->resolveFkRefsInRow($abookSettings);

        if (isset($expExceptionMsg)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expExceptionMsg);
        }

        $abMgr = new AddressbookManager();
        $abookIdsBefore = $abMgr->getAddressbookIds(AddressbookManager::ABF_REGULAR);

        // insert the new addressbook
        /** @psalm-suppress InvalidArgument For test purposes, we may feed invalid data */
        $abookId = $abMgr->insertAddressbook($abookSettings);
        $this->assertNull($expExceptionMsg, "Expected exception was not thrown");
        $abookIdsAfter = $abMgr->getAddressbookIds(AddressbookManager::ABF_REGULAR);

        // check that new addressbooks is reported with getAddressbookIds
        $this->assertCount(
            count($abookIdsBefore) + 1,
            $abookIdsAfter,
            "new addressbooks not one element more than before insert"
        );
        $this->assertEqualsCanonicalizing(
            array_merge($abookIdsBefore, [$abookId]),
            $abookIdsAfter,
            "getAddressbookIds did not return new addressbook $abookId"
        );

        // check that the row in the database is as expected
        $expDbRow = $this->prepRowForDbRowComparison($abookSettings, $defaults, $notSettable);
        $expDbRow['id'] = $abookId;
        $expDbRow = $this->transformAbookFlagsAssoc($expDbRow); // adds the flags field

        $dbRow = self::$db->lookup($abookId, [], 'addressbooks');
        $this->assertEquals($expDbRow, $dbRow, "Row not stored as expected in database");

        // check that the addressbook can also be retrieved using getAddressbookConfig
        $expCfg = $this->addAppFlags(intval($expDbRow['flags']), self::ABOOK_FLAGS, $expDbRow);
        $abookCfg = $abMgr->getAddressbookConfig($abookId);
        $this->assertEquals($expCfg, $abookCfg, "New addressbook config not returned as expected");
    }

    /**
     * @return array<
     *     string,
     *     array{
     *         TestDataKeyRef,
     *         array<string, null|bool|string|int|TestDataKeyRef>,
     *         ?FullAbookRow,
     *         ?string
     *     }
     * >
     */
    public function abookUpdateDataProvider(): array
    {
        $abook0Base = array_combine(self::ABOOK_COLS, self::ABOOK_ROWS[0]);

        // these IDs are filled by the test
        $abook0Base = array_merge($abook0Base, [ 'id' => '0' ]);

        /** @psalm-var FullAbookRow $abook0Base */

        return [
            'All updatable properties updated' => [
                [ 'carddav_addressbooks', 0 ],
                [
                    'name' => 'Updated Abook', 'active' => '0', 'last_updated' => 998877, 'refresh_time' => 42,
                    'sync_token' => 'abc', 'use_categories' => '0', 'readonly' => '1', 'require_always_email' => '1'
                ],
                [
                    'name' => 'Updated Abook', 'flags' => '28', 'last_updated' => '998877', 'refresh_time' => '42',
                    'sync_token' => 'abc',
                ] + $abook0Base,
                null
            ],
            'Nothing updated' => [
                [ 'carddav_addressbooks', 0 ],
                [ ],
                $abook0Base,
                null
            ],
            'Only a single property updated' => [
                [ 'carddav_addressbooks', 0 ],
                [ 'last_updated' => 923 ],
                [ 'last_updated' => '923' ] + $abook0Base,
                null
            ],
            'Try changing not updatable attribute (url)' => [
                [ 'carddav_addressbooks', 0 ],
                [ 'url' => 'http://a.com/abook1' ],
                null,
                "Attempt to update non-updatable field url"
            ],
            'Try changing not updatable attribute (discovered)' => [
                [ 'carddav_addressbooks', 0 ],
                [ 'discovered' => '0' ],
                null,
                "Attempt to update non-updatable field discovered"
            ],
            'Try changing not updatable attribute (account_id - same user)' => [
                [ 'carddav_addressbooks', 0 ],
                [ 'account_id' => ['carddav_accounts', 1] ],
                null,
                "Attempt to update non-updatable field account_id"
            ],
            'Try changing not updatable attribute (account_id - other user)' => [
                [ 'carddav_addressbooks', 0 ],
                [ 'account_id' => ['carddav_accounts', 5] ],
                null,
                "Attempt to update non-updatable field account_id"
            ],
            'Try to update addressbook with an extra unknown attribute' => [
                [ 'carddav_addressbooks', 0 ],
                [ 'extraattribute' => 'foo' ],
                $abook0Base,
                null
            ],
        ];
    }

    /**
     * Tests that update of an addressbook works.
     *
     * This must consider that the updated addressbook config is also returned in subsequent invocations of
     * getAddressbookConfig().
     *
     * It must not be possible to change non-updatable settings, especially account_id.
     *
     * @param TestDataKeyRef $abookFkRef
     * @param array<string, null|string|bool|int|TestDataKeyRef> $abookUpd
     * @param ?FullAbookRow $abookExpResult
     * @dataProvider abookUpdateDataProvider
     */
    public function testAddressbookIsUpdatedProperly(
        array $abookFkRef,
        array $abookUpd,
        ?array $abookExpResult,
        ?string $expExceptionMsg
    ): void {
        // resolve foreign key references
        $abookId = self::$testData->resolveFkRef($abookFkRef);

        $abookUpd = $this->resolveFkRefsInRow($abookUpd);

        if (isset($expExceptionMsg)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expExceptionMsg);
        }

        $abMgr = new AddressbookManager();

        // update the addressbook
        /** @psalm-suppress InvalidArgument For test purposes, we may feed invalid data */
        $abMgr->updateAddressbook($abookId, $abookUpd);
        $this->assertNull($expExceptionMsg, "Expected exception was not thrown for missing mandatory attributes");
        $this->assertNotNull($abookExpResult, "Expected result was not defined");
        $abookExpResult = $this->resolveFkRefsInRow($abookExpResult);
        $abookExpResult['id'] = $abookId;

        // check that the row in the database is as expected
        $dbRow = self::$db->lookup($abookId, [], 'addressbooks');
        $this->assertEquals($abookExpResult, $dbRow, "Row not stored as expected in database");

        // check that the updated addressbook can also be retrieved using getAddressbookConfig
        $expCfg = $this->addAppFlags(intval($abookExpResult['flags']), self::ABOOK_FLAGS, $abookExpResult);
        $abookCfg = $abMgr->getAddressbookConfig($abookId);
        $this->assertEquals($expCfg, $abookCfg, "New addressbook config not returned as expected");
    }

    /**
     * @return array<string, array{TestDataKeyRef, ?string}>
     */
    public function accountDeleteDataProvider(): array
    {
        return [
            'Valid account of user' => [ ['carddav_accounts', 0], null ],
            'Account of different user' => [ ['carddav_accounts', 5], 'No carddav account with ID' ],
        ];
    }

    /**
     * Tests that deletion of an account works.
     *
     * @param TestDataKeyRef $accountFkRef
     * @dataProvider accountDeleteDataProvider
     */
    public function testAccountIsDeletedProperly(array $accountFkRef, ?string $expExceptionMsg): void
    {
        // resolve foreign key references
        $accountId = self::$testData->resolveFkRef($accountFkRef);

        if (isset($expExceptionMsg)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expExceptionMsg);
        }

        $abMgr = new AddressbookManager();
        $accountIdsBefore = $abMgr->getAccountIds();

        // delete the account
        $abMgr->deleteAccount($accountId);
        $this->assertNull($expExceptionMsg, "Expected exception was not thrown for invalid account id argument");
        $this->assertEmpty(self::$db->get($accountId, [], 'accounts'));
        $this->assertEmpty(self::$db->get(['account_id' => $accountId], [], 'addressbooks'));

        // check that account IDs returned now lack the account ID
        $accountIdsAfter = $abMgr->getAccountIds();
        $this->assertCount(count($accountIdsBefore) - 1, $accountIdsAfter, "There should be one account ID less now");
        $this->assertEquals([$accountId], array_values(array_diff($accountIdsBefore, $accountIdsAfter)));

        // check that the deleted account cannot be retrieved anymore
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No carddav account with ID');
        $abMgr->getAccountConfig($accountId);
    }

    /**
     * @return array<string, array{list<int>, bool, ?string}>
     */
    public function abookDeleteDataProvider(): array
    {
        return [
            'Valid addressbooks of user' => [ [0], false, null ],
            'Valid addressbooks of user (cache only)' => [ [0], true, null ],
            '2 valid addressbooks of user' => [ [0,1], false, null ],
            'Addressbook of other user' => [
                [6],
                false,
                'request with IDs not referring to addressbooks of current user'
            ],
            'Mixed valid/invalid IDs' => [
                [0,6],
                false,
                'request with IDs not referring to addressbooks of current user'
            ],
            'Empty delete list' => [ [], false, null ],
        ];
    }

    /**
     * Tests that deletion of addressbooks works.
     *
     * @param list<int> $abookFkIdxs
     * @dataProvider abookDeleteDataProvider
     */
    public function testAddressbookIsDeletedProperly(
        array $abookFkIdxs,
        bool $cacheOnly,
        ?string $expExceptionMsg
    ): void {
        $abookIds = [];

        // insert some contacts / groups; to save some work, we use rows of the standard test data which add entries to
        // addressbook with index 1
        $testTables = [
            [ "carddav_contacts", TestData::CONTACTS_COLUMNS ],
            [ "carddav_groups", TestData::GROUPS_COLUMNS ],
            [ "carddav_group_user", TestData::GROUP_USER_COLUMNS ]
        ];

        foreach ($testTables as [$tbl, $cols]) {
            foreach (TestData::INITDATA[$tbl] as $row) {
                self::$testData->insertRow($tbl, $cols, $row);
            }
        }

        // resolve foreign key references
        foreach ($abookFkIdxs as $abookFkIdx) {
            $abookIds[] = self::$testData->resolveFkRef(['carddav_addressbooks', $abookFkIdx]);
        }

        if (isset($expExceptionMsg)) {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage($expExceptionMsg);
        }

        $abMgr = new AddressbookManager();
        $abookIdsBefore = $abMgr->getAddressbookIds(AddressbookManager::ABF_REGULAR);

        // delete the addressbooks
        $abMgr->deleteAddressbooks($abookIds, false, $cacheOnly);
        $this->assertNull($expExceptionMsg, "Expected exception was not thrown for invalid abook id argument");
        if (!empty($abookIds)) {
            if ($cacheOnly) {
                $this->assertEqualsCanonicalizing(
                    $abookIds,
                    array_column(self::$db->get(['id' => $abookIds], [], 'addressbooks'), 'id')
                );
            } else {
                $this->assertEmpty(self::$db->get(['id' => $abookIds], [], 'addressbooks'));
            }
            $this->assertEmpty(self::$db->get(['abook_id' => $abookIds], [], 'contacts'));
            $this->assertEmpty(self::$db->get(['abook_id' => $abookIds], [], 'groups'));
            $this->assertEmpty(self::$db->get(['abook_id' => $abookIds], [], 'xsubtypes'));
        }

        // check that addressbook IDs returned now lack the deleted addressbooks' IDs
        $abookIdsAfter = $abMgr->getAddressbookIds(AddressbookManager::ABF_REGULAR);
        if ($cacheOnly) {
            $this->assertEqualsCanonicalizing($abookIdsBefore, $abookIdsAfter);
        } else {
            $this->assertCount(
                count($abookIdsBefore) - count($abookIds),
                $abookIdsAfter,
                "The new list of addressbooks should be smaller by the amount of deleted addressbooks than the old list"
            );
            $this->assertEqualsCanonicalizing($abookIds, array_diff($abookIdsBefore, $abookIdsAfter));
        }

        // check that the deleted addressbooks cannot be retrieved anymore
        // we use a loop to handle the case that no addressbooks were deleted
        foreach ($abookIds as $abookId) {
            if ($cacheOnly === false) {
                // should fail on first loop iteration
                $this->expectException(\Exception::class);
                $this->expectExceptionMessage('No carddav addressbook with ID');
            }
            $abMgr->getAddressbookConfig($abookId);
            $this->assertTrue($cacheOnly, "No exception was thrown when querying the first addressbook");
        }
    }

    /**
     * Tests that addressbook manager properly delegates a resync to the backend addressbook.
     *
     * As a specific, addressbook manager is expected to move the last_updated DB settings 5 minutes into the future to
     * reduce the chance of parallel resyncs on the same addressbook.
     */
    public function testAddressbookResyncProperlyDelegated(): void
    {
        $expDuration = 12;
        $expRefreshTime = 200;

        // we use addressbook 1 for testing
        $abookId = self::$testData->resolveFkRef(['carddav_addressbooks', 1]);

        // create an addressbook mock
        $abook = $this->createMock(Addressbook::class);
        $abook->expects($this->once())->method("getId")->will($this->returnValue($abookId));
        $abook->expects($this->once())->method("getRefreshTime")->will($this->returnValue($expRefreshTime));

        // we expect that the resync method of the backend addressbook is called
        $abook->expects($this->once())
               ->method("resync")
               ->will($this->returnValue($expDuration));

        // Call the test method
        $abMgr = new AddressbookManager();
        $this->assertEquals($expDuration, $abMgr->resyncAddressbook($abook));

        // now the database should have a last_updated value 5 minutes in the future, plus the refresh time
        [ 'last_updated' => $lastUpdated] = self::$db->lookup($abookId, ['last_updated'], 'addressbooks');
        $this->assertIsString($lastUpdated);
        $lastUpdated = intval($lastUpdated);
        $lastUpdatedExpected = time() + 300 - $expRefreshTime;
        $this->assertLessThanOrEqual(1 /* tolerance */, abs($lastUpdatedExpected - intval($lastUpdated)));
    }

    /** @return array<string, array{int,?TestDataKeyRef,AddressbookSettings,list<string>,int}> */
    public function newAbookSettingsProvider(): array
    {
        return [
            '2 new' => [
                2,
                [ 'carddav_accounts', 2 ], // empty account
                [
                    'active' => '1', 'refresh_time' => '160', 'use_categories' => '1', 'readonly' => '1',
                    'require_always_email' => '0'
                ],
                [ 'a0', 'New 1' ],
                15, // expected flags for new addressbooks
            ],
            '2 new, 3 existing, 1 existing but non-discovered' => [
                5,
                [ 'carddav_accounts', 0 ],
                [
                    'active' => '0', 'refresh_time' => '60', 'use_categories' => '0', 'readonly' => '0',
                    'require_always_email' => '1', 'name' => '%c - %a (%D)'
                ],
                [
                    'a0 - Custom Account (Desc 0)',
                    'a4 - Custom Account (Desc 4)',
                    'CA1',
                    'CA2',
                    'CA3',
                    'CA4',
                ], // New 1-3 have same URL as discovered abooks CA1-3
                20,
            ],
            'all discovered addressbooks removed' => [
                0,
                [ 'carddav_accounts', 0 ],
                [ ],
                [ 'CA4' ], // CA4 is non-discovered and therefore must be retained
                5,
            ],
            'empty remains empty' => [
                0,
                [ 'carddav_accounts', 2 ], // empty account
                [ ],
                [ ],
                5,
            ],
            'new account with 1 addressbook' => [
                1,
                null, // new account
                [ 'refresh_time' => '60' ],
                [ 'a0' ],
                5,
            ],
        ];
    }

    /**
     * Tests that new addressbooks are properly created in the database.
     *
     * The result of the Discovery service is emulated to provide a test-vector-dependent number of addressbooks under
     * the URL https://www.example.com/dav/abook<Index>.
     *
     * @param int $numAbooks The number of addressbooks that the discovery stub shall "discover"
     * @param ?TestDataKeyRef $accountFkRef
     * @param AddressbookSettings $abookTmpl
     * @param list<string> $expAbookNames
     * @dataProvider newAbookSettingsProvider
     */
    public function testAddressbooksRediscoveredCorrectly(
        int $numAbooks,
        ?array $accountFkRef,
        array $abookTmpl,
        array $expAbookNames,
        int $expNewAbookFlags
    ): void {
        $db = TestInfrastructure::$infra->db();

        // create some test addressbooks to be discovered
        $abookObjs = [];
        for ($i = 0; $i < $numAbooks; ++$i) {
            $abookObjs[] = $this->makeAbookCollStub(
                ($i % 2) === 1 ? "New $i" : null,
                "https://contacts.example.com/a$i",
                "Desc $i"
            );
        }

        if (isset($accountFkRef)) {
            $accountId = self::$testData->resolveFkRef($accountFkRef);
            /** @var FullAccountRow */
            $accountCfg = $db->lookup($accountId, [], 'accounts');
        } else {
            $accountCfg = [
                'accountname' => 'New Acc', 'username' => 'usr', 'password' => 'p', 'discovery_url' => 'http://foo.bar'
            ];
        }

        // create a Discovery mock that "discovers" our test addressbooks
        $username = $accountCfg['username'] == '%u' ? 'testuser@example.com' : $accountCfg['username'];
        $password = $accountCfg['password'] == '%p' ? 'test' : $accountCfg['password'];
        $this->assertNotNull($accountCfg['discovery_url']);
        $account = new Account($accountCfg['discovery_url'], $username, $password);
        $discovery = $this->createMock(Discovery::class);
        $discovery->expects($this->once())
            ->method("discoverAddressbooks")
            ->with($this->equalTo($account))
            ->will($this->returnValue($abookObjs));
        TestInfrastructure::$infra->discovery = $discovery;

        // Run the test object
        $abMgr = new AddressbookManager();
        $accountIdRet = $abMgr->discoverAddressbooks($accountCfg, $abookTmpl);

        if (isset($accountId)) {
            $this->assertSame($accountId, $accountIdRet);
        } else {
            // check that the new account was inserted
            /** @var FullAccountRow */
            $accountCfg = $db->lookup($accountIdRet, [], 'accounts');
            // in the DB the password is encoded, but from getAccountConfig we get the decoded password - "decode" it.
            $accountCfg['password'] = 'p';

            // also check that the new account can be queried from AddressbookManager
            $accountCfg2 = $abMgr->getAccountConfig($accountIdRet);
            $this->assertSame($accountCfg, $accountCfg2);
        }

        // check DB records
        /** @var array<string, FullAbookRow> */
        $abooks = array_column(
            array_filter(
                $db->get(['account_id' => $accountIdRet], [], 'addressbooks', ['order' => ['name']]),
                function (array $v): bool {
                    return ((intval($v["flags"]) & 32) === 0);
                }
            ),
            null,
            'name'
        );

        // Check the set of addressbooks after rediscovery is what we expect (we compare by name)
        $this->assertCount(count($expAbookNames), $abooks, "Expected number of new addressbooks not found in DB");
        $this->assertSame($expAbookNames, array_column($abooks, 'name'));

        // check that the properties of the new addressbooks are taken from the template
        foreach ($expAbookNames as $abookName) {
            if (strpos($abookName, 'New ') === false && preg_match('/^a\d+$/', $abookName) != 1) {
                continue;
            }

            $this->assertArrayHasKey($abookName, $abooks);
            $this->assertSame($abookTmpl['refresh_time'] ?? '3600', $abooks[$abookName]['refresh_time']);
            $this->assertSame((string) $expNewAbookFlags, $abooks[$abookName]['flags']);
            $this->assertSame('', $abooks[$abookName]['sync_token']);
            $this->assertSame('0', $abooks[$abookName]['last_updated']);
        }

        // check that the last_discovered timestamp has been updated
        [ 'last_discovered' => $lastDiscovered] = self::$db->lookup($accountIdRet, ['last_discovered'], 'accounts');
        $this->assertIsString($lastDiscovered);
        $lastDiscovered = intval($lastDiscovered);
        $this->assertLessThanOrEqual(1 /* tolerance */, abs(time() - $lastDiscovered));

        TestInfrastructure::$infra->discovery = null;
    }

    public function testRediscoverWithoutUrlThrowsException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("lacking a discovery URI");

        // Run the test object
        $abMgr = new AddressbookManager();
        $accountCfg = [ 'accountname' => 'New Acc', 'username' => 'usr', 'password' => 'p' ];
        $abMgr->discoverAddressbooks($accountCfg, []);
    }

    /**
     * Creates an AddressbookCollection stub that implements getUri() and getName().
     */
    private function makeAbookCollStub(?string $name, string $url, ?string $desc): AddressbookCollection
    {
        $davobj = $this->createStub(AddressbookCollection::class);
        $urlComp = explode('/', rtrim($url, '/'));
        $baseName = $urlComp[count($urlComp) - 1];
        $davobj->method('getName')->will($this->returnValue($name ?? $baseName));
        $davobj->method('getBasename')->will($this->returnValue($baseName));
        $davobj->method('getDisplayname')->will($this->returnValue($name));
        $davobj->method('getDescription')->will($this->returnValue($desc));
        $davobj->method('getUri')->will($this->returnValue($url));
        return $davobj;
    }

    /**
     * Prepares a settings array as passed to insertAddressbook/insertAccount for comparison with the resulting db row.
     *
     * - All attributes are converted to string types
     * - Default values for optional attributes are added
     * - Initial values for not settable attributes are added
     * - Unsets the special attribute 'extraattribute' used by this test for unsupported extra attribute
     *
     * @param array<string, null|string|int|bool> $row
     * @param array<string, ?string> $defaults
     * @param array<string, ?string> $notSettable
     * @return array<string, ?string>
     */
    private function prepRowForDbRowComparison(array $row, array $defaults, array $notSettable): array
    {
        unset($row['extraattribute']);

        foreach ($defaults as $attr => $defaultVal) {
            $row[$attr] = $row[$attr] ?? $defaultVal;
        }

        foreach ($notSettable as $attr => $initVal) {
            $row[$attr] = $initVal;
        }

        $result = [];

        // normalize bools, convert all attributes to string
        foreach ($row as $attr => $val) {
            if (isset($val)) {
                $result[$attr] = (string) $val;
            } else {
                $result[$attr] = null;
            }
        }

        return $result;
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
     * @param array<string, null|int|string|bool|TestDataKeyRef> $row
     * @return array<string, null|int|string|bool>
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
