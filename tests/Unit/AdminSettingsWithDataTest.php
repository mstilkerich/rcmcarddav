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

namespace MStilkerich\Tests\RCMCardDAV\Unit;

use MStilkerich\RCMCardDAV\Frontend\AddressbookManager;
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
use PHPUnit\Framework\TestCase;

/**
 * Tests parts of the AdminSettings class using test data in JsonDatabase.
 */
final class AdminSettingsWithDataTest extends TestCase
{
    /** @var JsonDatabase */
    private $db;

    public static function setUpBeforeClass(): void
    {
        $_SESSION['user_id'] = 105;
        $_SESSION['username'] = 'johndoe';
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    /**
     * @return array<string, list{string, list{?string,?string}}>
     */
    public function specialAbookTestProvider(): array
    {
        $base = 'tests/Unit/data/adminSettingsWithDataTest';

        return [
            'Two matchers, one match' => [ "$base/matchBoth.php", ['43', '43'] ],
            'No matches (invalid preset, AND condition eval)' => [
                "$base/noMatch.php",
                [
                    'Setting for collected_recipients must include a valid preset attribute',
                    'Cannot set special addressbook collected_senders, there are 0 candidates (need: 1)'
                ]
            ],
            'Multiple matches' => [
                "$base/matchMultiple.php",
                [
                    'Cannot set special addressbook collected_recipients, there are 2 candidates (need: 1)',
                    'Cannot set special addressbook collected_senders, there are 2 candidates (need: 1)'
                ]
            ],
            'Preset not yet in DB' => [ "$base/presetNotInDbYet.php", [null, null] ],
        ];
    }

    /**
     * @param string $admSettingsPath Path name of config.inc.php file
     * @param list{?string,?string} $expIds Expected abook IDs for 0: collected_recipients, 1: collected_senders
     * @dataProvider specialAbookTestProvider
     */
    public function testSpecialAddressbooksReturnedCorrectly(string $admSettingsPath, array $expIds): void
    {
        $this->db = new JsonDatabase(['tests/Unit/data/adminSettingsWithDataTest/db.json']);
        $logger = TestInfrastructure::logger();
        TestInfrastructure::init($this->db, $admSettingsPath);

        $infra = TestInfrastructure::$infra;
        $admPrefs = $infra->admPrefs();
        $abMgr = new AddressbookManager();

        $specialAbooks = $admPrefs->getSpecialAddressbooks($abMgr, $infra);

        $i = 0;
        foreach (['collected_recipients', 'collected_senders'] as $abookType) {
            if (isset($expIds[$i])) {
                if (strpos($expIds[$i], ' ') === false) {
                    $this->assertArrayHasKey($abookType, $specialAbooks);
                    $this->assertSame($specialAbooks[$abookType], $expIds[$i]);
                } else {
                    $this->assertArrayNotHasKey($abookType, $specialAbooks);
                    $logger->expectMessage("error", $expIds[$i]);
                }
            } else {
                $this->assertArrayNotHasKey($abookType, $specialAbooks);
            }

            $i = 1;
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
