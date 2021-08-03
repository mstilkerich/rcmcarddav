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
    }

    public function setUp(): void
    {
        $db = $this->createMock(Database::class);
        TestInfrastructure::init($db);
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
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
                    $this->assertEquals($presetExp, $presetUnknown, "Unkown abook URL should return base properties");
                } else {
                    $preset = $admPrefs->getPreset($presetName, $url);
                }

                $this->assertEquals($presetExp, $preset);
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
