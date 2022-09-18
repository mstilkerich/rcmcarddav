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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DatabaseException;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DbAndCondition;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DbOrCondition;

/**
 * @psalm-import-type DbConditions from AbstractDatabase
 */
final class DatabaseForeignKeyConstraintsTest extends TestCase
{
    /** @var list<string> TABLES All carddav tables without carddav_ prefix except migrations table
     *                           These are the tables that only contain data assigned to some roundcube user.
     */
    private const TABLES = ['accounts', 'addressbooks', 'contacts', 'xsubtypes', 'groups', 'group_user' ];

    /** @var AbstractDatabase */
    private static $db;

    /** @var TestData */
    private static $testData;

    public static function setUpBeforeClass(): void
    {
        // Initialize database
        [ $dsnw ] = TestInfrastructureDB::dbSettings();
        self::$db = TestInfrastructureDB::initDatabase($dsnw);
        TestInfrastructure::init(self::$db);
        self::$testData = new TestData(TestInfrastructureDB::getDbHandle());
        self::$testData->initDatabase();
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    /**
     * Tests that the foreign key constraints cascaded delete works.
     *
     * We simply delete all users from the roundcube users table. This should result in empty carddav tables, i.e. all
     * accounts, addressbooks, etc. should be deleted if the user they belong to is deleted.
     *
     * Note that rcmcarddav currently does not rely on this property (because older SQLite versions do not support it).
     */
    public function testDeletionOfUserDeletesTheirCarddavObjectsByForeignKeyConstraints(): void
    {
        $db = self::$db;

        // first assert that all our carddav tables contain some data in the test data set
        foreach (self::TABLES as $tbl) {
            $this->assertNotEmpty($db->get([], [], $tbl), "Table $tbl contains no test data");
        }

        // now delete all users from roundcube
        self::$testData->purgeTable('users');

        // now assert that all carddav tables are also empty
        foreach (self::TABLES as $tbl) {
            $this->assertEmpty($db->get([], [], $tbl), "Table $tbl contains data after deleting all users");
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
