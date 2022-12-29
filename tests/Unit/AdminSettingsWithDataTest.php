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

    /**
     * Test for AdminSettings::getAddressbookTemplate()
     *
     * Situations to test:
     * - DB template addressbook has different value for a fixed setting than the preset - preset value is taken
     *    - account 42, refresh_time
     * - DB template addressbook has different value for a non-fixed setting than the preset - DB value is taken
     *    - account 42, name
     * - Template addressbook for a non-preset account is queried and DB entry exists - DB entry is provided
     *    - account 43
     * - Template addressbook for a non-preset account is queried and no DB entry exists - empty array returned
     *    - account 44
     * - Template addressbook for a preset account is queried and no DB entry exists - values from preset provided
     *    - account 45
     */
    public function testTemplateAddressbookProvidedCorrectly(): void
    {
        $this->db = new JsonDatabase(['tests/Unit/data/adminSettingsWithDataTest/templateAbookDb.json']);
        TestInfrastructure::init($this->db, 'tests/Unit/data/adminSettingsWithDataTest/templateAbook.php');

        $infra = TestInfrastructure::$infra;
        $admPrefs = $infra->admPrefs();
        $abMgr = new AddressbookManager();

        //  Test fixed vs. non-fixed values and user overrides
        $tmpl = $admPrefs->getAddressbookTemplate($abMgr, '42');

        $this->assertSame('1800', $tmpl['refresh_time'] ?? '');
        $this->assertSame('New abook (%N)', $tmpl['name'] ?? '');
        $this->assertSame('0', $tmpl['active'] ?? '');
        $this->assertSame('1', $tmpl['use_categories'] ?? '');
        $this->assertSame('0', $tmpl['discovered'] ?? '');
        $this->assertSame('1', $tmpl['readonly'] ?? '');
        $this->assertSame('1', $tmpl['require_always_email'] ?? '');


        // Test user-defined account with template addressbook in DB
        $tmpl = $admPrefs->getAddressbookTemplate($abMgr, '43');

        $this->assertSame('60', $tmpl['refresh_time'] ?? '');
        $this->assertSame('%D', $tmpl['name'] ?? '');
        $this->assertSame('0', $tmpl['active'] ?? '');
        $this->assertSame('1', $tmpl['use_categories'] ?? '');
        $this->assertSame('0', $tmpl['discovered'] ?? '');
        $this->assertSame('0', $tmpl['readonly'] ?? '');
        $this->assertSame('0', $tmpl['require_always_email'] ?? '');

        // Test user-defined account with NO template addressbook in DB
        $tmpl = $admPrefs->getAddressbookTemplate($abMgr, '44');
        $this->assertCount(0, $tmpl);

        // Test preset account with NO template addressbook in DB
        $tmpl = $admPrefs->getAddressbookTemplate($abMgr, '45');

        $this->assertSame('600', $tmpl['refresh_time'] ?? '');
        $this->assertSame('%N - %D', $tmpl['name'] ?? '');

        $this->assertSame('1', $tmpl['active'] ?? '');
        $this->assertSame('0', $tmpl['use_categories'] ?? '');
        // discovered is not part of preset
        $this->assertArrayNotHasKey('discovered', $tmpl);
        $this->assertSame('0', $tmpl['readonly'] ?? '');
        $this->assertSame('0', $tmpl['require_always_email'] ?? '');
        // template is not part of preset
        $this->assertArrayNotHasKey('template', $tmpl);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
