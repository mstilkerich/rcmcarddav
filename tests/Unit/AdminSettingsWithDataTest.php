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

use Exception;
use MStilkerich\CardDavClient\{AddressbookCollection,WebDavResource};
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
        // The addressbook template must not contain any account fields, only addressbook attributes
        $tmpl = $admPrefs->getAddressbookTemplate($abMgr, '45');

        $this->assertArrayNotHasKey('accountname', $tmpl);
        $this->assertArrayNotHasKey('username', $tmpl);
        $this->assertArrayNotHasKey('password', $tmpl);
        $this->assertArrayNotHasKey('discovery_url', $tmpl);
        $this->assertArrayNotHasKey('rediscover_time', $tmpl);
        $this->assertArrayNotHasKey('hide', $tmpl);
        $this->assertArrayNotHasKey('fixed', $tmpl);
        $this->assertArrayNotHasKey('extra_addressbooks', $tmpl);

        $this->assertSame('%N - %D', $tmpl['name'] ?? '');
        $this->assertSame('1', $tmpl['active'] ?? '');
        // readonly is not part of preset
        $this->assertArrayNotHasKey('readonly', $tmpl);
        $this->assertSame('600', $tmpl['refresh_time'] ?? '');
        $this->assertSame('0', $tmpl['use_categories'] ?? '');
        // discovered is not part of preset
        $this->assertArrayNotHasKey('discovered', $tmpl);
        $this->assertSame('0', $tmpl['require_always_email'] ?? '');
        // template is not part of preset
        $this->assertArrayNotHasKey('template', $tmpl);
    }

    /**
     * @return array<string, list{string, list{?string,?string}}>
     */
    public function initPresetDataProvider(): array
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
     * Tests that presets are initialized and updated properly by AdminSettings::initPresets().
     *
     * The following is tested:
     * - A new preset is added
     *   - Account is added to the DB with the proper settings
     *   - Extra addressbooks of the account are added to the DB, but no sync is attempted
     * - A preset account existing in the DB is removed - RemovedPreset
     *   - The account and all its addressbooks are purged from the database.
     * - A preset account existing in the DB is updated - UpdatedPreset
     *   - A new extra addressbook is added [NewXBook]
     *   - An extra addressbook existing in the DB is removed [RemovedBook]
     *   - Fixed settings for the account are updated [preemptive_basic_auth, ssl_noverify]
     *   - Fixed settings for existing addressbooks are updated [username, refresh_time, require_always_email]
     *     - For a fixed name w/ server-side vars, the updated value is fetched from the server [Discovered Addressbook]
     *     - For an extra addressbook, its specific settings are taken if available [UpdatedXBook, require_always_email]
     *     - For an extra addressbook, main settings are taken if no specific  available [UpdatedXBook, refresh_time]
     *   - An extra addressbook with invalid URL exists in the admin config, error is logged and remainder of the
     *     preset is properly processed [InvalidXBook]
     *   - A discovered addressbook does not exist on server anymore and server-side query is required to update name;
     *     it is not updated in the DB except for the name, and the rest of the preset is properly processed [book55]
     *   - Non-fixed settings with values different from the preset are retained [rediscover_time, use_categories]
     *
     * Note that the discovery of addressbooks belonging to a preset is out of scope of the AdminSettings::initPresets()
     * function and therefore this test.
     */
    public function testPresetsAreInitializedProperly(): void
    {
        $db = new JsonDatabase(['tests/Unit/data/adminSettingsWithDataTest/initPresetsDb.json']);
        $this->db = $db;
        $dbAfter  = new JsonDatabase(['tests/Unit/data/adminSettingsWithDataTest/initPresetsDbAfter.json']);
        $logger = TestInfrastructure::logger();
        TestInfrastructure::init($this->db, 'tests/Unit/data/adminSettingsWithDataTest/initPresets.php');

        $infra = TestInfrastructure::$infra;
        $invalidXBookUrl = 'https://carddav.example.com/global/InvalidXBook/';
        $infra->webDavResources = [
            'https://carddav.example.com/books/johndoe/book42/' => $this->makeAbookCollStub(
                'Book 42',
                'https://carddav.example.com/books/johndoe/book42/',
                "Hitchhiker's Guide"
            ),
            'https://carddav.example.com/global/UpdatedXBook/' => $this->makeAbookCollStub(
                'Updated XAbook',
                'https://carddav.example.com/global/UpdatedXBook/',
                'Public directory'
            ),
            'https://carddav.example.com/global/NewXBook/' => $this->makeAbookCollStub(
                'Added XAbook',
                'https://carddav.example.com/global/NewXBook/',
                'Newly added extra addressbook'
            ),
            'https://carddav.example.com/global/RemovedBook' => new Exception('RemovedBook not on server anymore'),
            $invalidXBookUrl => new Exception('InvalidXBook'),
            "https://carddav.example.com/books/johndoe/book55/" => $this->createStub(WebDavResource::class),

            'https://newcard.example.com/global/PublicAddrs' => $this->makeAbookCollStub(
                'New public addrs',
                'https://newcard.example.com/global/PublicAddrs',
                'New Public directory'
            ),

        ];
        $admPrefs = $infra->admPrefs();
        $abMgr = new AddressbookManager();

        $admPrefs->initPresets($abMgr, $infra);

        $dbAfter->compareTables('accounts', $db);
        $dbAfter->compareTables('addressbooks', $db);

        $logger->expectMessage(
            "error",
            "Failed to add extra addressbook $invalidXBookUrl for preset UpdatedPreset: InvalidXBook"
        );
        $logger->expectMessage("error", "Cannot update name of addressbook 55: no addressbook collection at given URL");
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
