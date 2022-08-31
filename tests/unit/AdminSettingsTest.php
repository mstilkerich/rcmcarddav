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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use MStilkerich\CardDavAddressbook4Roundcube\RoundcubeLogger;
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\CardDavAddressbook4Roundcube\Frontend\AdminSettings;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;

final class AdminSettingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // needed for URL placeholder replacements when admin settings are read
        $_SESSION['username'] = 'user@example.com';
    }

    public function setUp(): void
    {
        $db = $this->createMock(Database::class);
        TestInfrastructure::init($db);
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
        self::cleanupTempConfigs();
    }

    /**
     * @return array<string, array{string}>
     */
    public function configFileProvider(): array
    {
        $base = 'tests/unit/data/adminSettingsTest';

        return [
            'Non-existent config file' => [ "$base/notExistent" ],
            'Valid config file with all settings' => [ "$base/fullconfig" ],
        ];
    }

    /**
     * Tests that config.inc.php file is correctly parsed.
     *
     * @dataProvider configFileProvider
     */
    public function testConfigFileParsedCorrectly(string $cfgFileBase): void
    {
        $expPrefs = TestInfrastructure::readJsonArray("$cfgFileBase.json");
        $loggerMock = $this->createMock(RoundcubeLogger::class);
        $loggerHttpMock = $this->createMock(RoundcubeLogger::class);

        if (isset($expPrefs["loglevel"])) {
            $loggerMock->expects($this->once())
                       ->method("setLogLevel")
                       ->with($this->equalTo($expPrefs['loglevel']));
        }
        if (isset($expPrefs["loglevel_http"])) {
            $loggerHttpMock->expects($this->once())
                           ->method("setLogLevel")
                           ->with($this->equalTo($expPrefs['loglevel_http']));
        }

        $admPrefs = new AdminSettings("$cfgFileBase.inc.php", $loggerMock, $loggerHttpMock);

        $this->assertSame($expPrefs['pwStoreScheme'], $admPrefs->pwStoreScheme);
        $this->assertSame($expPrefs['forbidCustomAddressbooks'], $admPrefs->forbidCustomAddressbooks);
        $this->assertSame($expPrefs['hidePreferences'], $admPrefs->hidePreferences);

        $this->assertEquals($expPrefs["presets"], TestInfrastructure::getPrivateProperty($admPrefs, 'presets'));
        $this->assertEquals(
            $expPrefs["specialAbookMatchers"],
            TestInfrastructure::getPrivateProperty($admPrefs, 'specialAbookMatchers')
        );
    }

    /**
     * Tests that getPreset() returns the expected preset data, merging account settings with those of extra
     * addressbooks.
     */
    public function testGetPresetReturnsAddressbookSpecificConfig(): void
    {
        $cfgFileBase = 'tests/unit/data/adminSettingsTest/fullconfig';
        /** @var array<string, array<string, array>> */
        $expPrefs = TestInfrastructure::readJsonArray("$cfgFileBase-getPreset.json");

        $loggerMock = $this->createMock(RoundcubeLogger::class);
        $admPrefs = new AdminSettings("$cfgFileBase.inc.php", $loggerMock, $loggerMock);

        foreach ($expPrefs as $presetName => $presetBooks) {
            foreach ($presetBooks as $url => $presetExp) {
                if (strlen($url) == 0) {
                    $preset = $admPrefs->getPreset($presetName);
                    $presetUnknown = $admPrefs->getPreset($presetName, "http://not.a.known.url/of/an/abook");
                    $this->assertEquals($presetExp, $presetUnknown, "Unknown abook URL should return base properties");
                } else {
                    $preset = $admPrefs->getPreset($presetName, $url);
                }

                $this->assertEquals($presetExp, $preset);
            }
        }
    }

    /**
     * @return array<string, array{
     *     callable(array):array,
     *     callable(array):array,
     *     callable(AdminSettings, RoundcubeLogger, RoundcubeLogger):void,
     *     string
     * }>
     */
    public function errorsInAdminConfigProvider(): array
    {
        $ret = [
            'Invalid loglevel value' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['_GLOBAL']['loglevel'] = 'foo';
                    return $prefs;
                },
                function (array $expPrefs): array {
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                    TestCase::assertSame(
                        5,
                        TestInfrastructure::getPrivateProperty($rcLogger, 'loglevel')
                    );
                },
                'unknown loglevel'
            ],
            'Invalid loglevel value (HTTP)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['_GLOBAL']['loglevel_http'] = 'foo';
                    return $prefs;
                },
                function (array $expPrefs): array {
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $rcLoggerHttp): void {
                    TestCase::assertSame(
                        5,
                        TestInfrastructure::getPrivateProperty($rcLoggerHttp, 'loglevel')
                    );
                },
                'unknown loglevel'
            ],
            'Invalid loglevel type' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['_GLOBAL']['loglevel'] = 5;
                    return $prefs;
                },
                function (array $expPrefs): array {
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                    TestCase::assertSame(
                        5,
                        TestInfrastructure::getPrivateProperty($rcLogger, 'loglevel')
                    );
                },
                'unknown loglevel'
            ],
            'Invalid loglevel type (HTTP)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['_GLOBAL']['loglevel_http'] = 5;
                    return $prefs;
                },
                function (array $expPrefs): array {
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $rcLoggerHttp): void {
                    TestCase::assertSame(
                        5,
                        TestInfrastructure::getPrivateProperty($rcLoggerHttp, 'loglevel')
                    );
                },
                'unknown loglevel'
            ],
            'Invalid pwstore scheme' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['_GLOBAL']['pwstore_scheme'] = 'foo';
                    return $prefs;
                },
                function (array $expPrefs): array {
                    $expPrefs['pwStoreScheme'] = 'encrypted'; // default should be used
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "Invalid pwStoreScheme foo in config.inc.php - using default 'encrypted'"
            ],
            'Invalid preset key (empty string)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs[''] = [ 'name' => 'Invalid' ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "A preset key must be a non-empty string - ignoring preset"
            ],
            'Invalid preset key (integer)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs[0] = [ 'name' => 'Invalid' ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "A preset key must be a non-empty string - ignoring preset"
            ],
            'Invalid preset key (not an array)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = false;
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "preset definition must be an array"
            ],
            'Invalid preset, mandatory attribute missing (name)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = [ 'url' => 'example.com' ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "required setting name is not set"
            ],
            'Invalid preset, mandatory attribute missing (extraabook.url)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = [ 'name' => 'Test', 'extra_addressbooks' => [['active' => true]] ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "required setting url is not set"
            ],
            'Invalid preset, wrong type (extraabooks not an array)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = [ 'name' => 'Test', 'extra_addressbooks' => "example.com" ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "setting extra_addressbooks must be an array"
            ],
            'Invalid preset, wrong type (extraabooks[x] not an array)' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = [
                        'name' => 'Test',
                        'extra_addressbooks' => [
                            ['url' => 'foo.com'],
                            "example.com"
                        ]
                    ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "setting extra_addressbooks\[1\] must be an array"
            ],
            'Invalid preset referenced in collected senders' => [
                function (array $prefs): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['_GLOBAL']['collected_senders'] = [
                        'preset' => 'InvalidKey',
                    ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid special addressbook matcher must be ignored
                    if (
                        isset($expPrefs['specialAbookMatchers'])
                        && is_array($expPrefs['specialAbookMatchers'])
                        && isset($expPrefs['specialAbookMatchers']['collected_senders'])
                    ) {
                        unset($expPrefs['specialAbookMatchers']['collected_senders']);
                    }
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "Setting for collected_senders must include a valid preset attribute"
            ],
        ];

        foreach (['username', 'password', 'url', 'rediscover_time', 'refresh_time'] as $stringAttr) {
            $ret["Wrong type for string attribute ($stringAttr)"] = [
                function (array $prefs) use ($stringAttr): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = [ 'name' => 'Invalid', $stringAttr => 1 ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "setting $stringAttr must be a string"
            ];
        }

        foreach (['rediscover_time', 'refresh_time'] as $timeStrAttr) {
            $ret["Wrong type for timestring attribute ($timeStrAttr)"] = [
                function (array $prefs) use ($timeStrAttr): array {
                    TestCase::assertIsArray($prefs['_GLOBAL']);
                    $prefs['Invalid'] = [ 'name' => 'Invalid', $timeStrAttr => 'foo' ];
                    return $prefs;
                },
                function (array $expPrefs): array {
                    // invalid preset must be ignored
                    return $expPrefs;
                },
                function (AdminSettings $_admPrefs, RoundcubeLogger $_rcLogger, RoundcubeLogger $_rcLoggerHttp): void {
                },
                "Time string foo could not be parsed"
            ];
        }

        foreach (['fixed', 'require_always'] as $strArrayAttr) {
            foreach ([ true, 'foo', 1, [ 'foo', 1 ] ] as $idx => $errVal) {
                $ret["Wrong type for string array attribute ($strArrayAttr $idx)"] = [
                    function (array $prefs) use ($strArrayAttr, $errVal): array {
                        TestCase::assertIsArray($prefs['_GLOBAL']);
                        $prefs['Invalid'] = [ 'name' => 'Invalid', $strArrayAttr => $errVal ];
                        return $prefs;
                    },
                    function (array $expPrefs): array {
                        // invalid preset must be ignored
                        return $expPrefs;
                    },
                    function (
                        AdminSettings $_admPrefs,
                        RoundcubeLogger $_rcLogger,
                        RoundcubeLogger $_rcLoggerHttp
                    ): void {
                    },
                    is_array($errVal) ? "must be string" : "setting $strArrayAttr must be array"
                ];
            }
        }

        return $ret;
    }

    /**
     * Tests that errors in the admin configuration are detected and, if possible, handled without a fatal error, i.e.
     * using roundcube should still be possible.
     *
     * If the error affects a single preset, the preset will be ignored, i.e. not present in the resulting preset list.
     * As a side effect, if the preset happened to work before, it may cause deletion of related addressbooks for users
     * that already had them added earlier. This is acceptable, since no data is lost (everything is on the CardDAV
     * server) and the preset will be added again. The only drawback is that some server traffic will be generated for
     * re-downloading the addressbook.
     *
     * The following errors are tested:
     *
     * - wrong data type for a global configuration setting - except bool, where we interpret different types according
     *                                                        to PHP's understanding of true/false
     * - wrong data type for a preset configuration setting - except bool, see above
     * - wrong value for a configuration setting (e.g. non-existent loglevel, invalid time string)
     * - wrong preset key referenced in a special addressbook matcher
     *
     * As basis, we use a valid configuration and inject one error at a time.
     *
     * @param callable(array):array $modifyPrefsFunc
     * @param callable(array):array $modifyExpResultFunc
     * @param callable(AdminSettings, RoundcubeLogger, RoundcubeLogger):void $validateFunc
     *
     * @dataProvider errorsInAdminConfigProvider
     */
    public function testErrorsInAdminConfigAreDetected(
        $modifyPrefsFunc,
        $modifyExpResultFunc,
        $validateFunc,
        string $expLogMsg
    ): void {
        $prefs = TestInfrastructure::readPhpPrefsArray('tests/unit/data/adminSettingsTest/fullconfig.inc.php');

        // modify prefs
        $prefs = $modifyPrefsFunc($prefs);

        // write modified prefs to temporary file
        $tmpfile = tempnam("testreports", "adminSettingsTest_");
        $this->assertIsString($tmpfile);
        file_put_contents($tmpfile, "<?php\n\$prefs = " . var_export($prefs, true) . ';');

        // load modified settings
        $rcubecfg = \rcube::get_instance()->config;
        $rcubecfg->set('log_dir', __DIR__ . '/../../testreports/');

        $this->assertNotFalse(file_put_contents('testreports/adminSettingsTest_log.log', ''));
        $logger = new RoundcubeLogger('adminSettingsTest_log');
        $loggerHttp = new RoundcubeLogger('adminSettingsTest_logHttp');

        $admPrefs = new AdminSettings($tmpfile, $logger, $loggerHttp);

        // compare to expected settings
        $expPrefs = TestInfrastructure::readJsonArray("tests/unit/data/adminSettingsTest/fullconfig.json");
        $expPrefs = $modifyExpResultFunc($expPrefs);
        $this->assertSame($expPrefs['pwStoreScheme'], $admPrefs->pwStoreScheme);
        $this->assertSame($expPrefs['forbidCustomAddressbooks'], $admPrefs->forbidCustomAddressbooks);
        $this->assertSame($expPrefs['hidePreferences'], $admPrefs->hidePreferences);
        $this->assertEquals($expPrefs["presets"], TestInfrastructure::getPrivateProperty($admPrefs, 'presets'));

        // extra validation function
        $validateFunc($admPrefs, $logger, $loggerHttp);

        // check that expected error message was logged
        $logEntries = file_get_contents('testreports/adminSettingsTest_log.log');
        $this->assertMatchesRegularExpression("/\[5 ERR\] .*$expLogMsg/", $logEntries, "expected error log not found");
    }

    /**
     * Delete temporary files from testErrorsInAdminConfigAreDetected
     */
    public static function cleanupTempConfigs(): void
    {
        $tmpfs = glob("testreports/adminSettingsTest_*");
        if (!empty($tmpfs)) {
            foreach ($tmpfs as $tmpf) {
                unlink($tmpf);
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
