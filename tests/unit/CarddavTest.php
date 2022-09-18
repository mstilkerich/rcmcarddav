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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use carddav;

final class CarddavTest extends TestCase
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
     * @return list<array{string,?int}>
     */
    public function timeStringProvider(): array
    {
        return [
            [ "01:00:00", 3600 ],
            [ "1:0:0", 3600 ],
            [ "1", 3600 ],
            [ "00:01", 60 ],
            [ "00:00:01", 1 ],
            [ "01:02:03", 3723 ],
            [ "", null ],
            [ "blah", null ],
            [ "1:2:3:4", null ],
            [ "1:60:01", null ],
            [ "1:60:010", null ],
            [ "1:00:60", null ],
            [ "1:00:500", null ],
            [ "100", 360000 ],
        ];
    }

    /**
     * Tests that the carddav::parseTimeParameter() methods converts time strings properly.
     *
     * @dataProvider timeStringProvider
     */
    public function testTimeParameterParsedCorrectly(string $timestr, ?int $seconds): void
    {
        $parseFn = new \ReflectionMethod(carddav::class, "parseTimeParameter");
        $parseFn->setAccessible(true);

        if (isset($seconds)) {
            $this->assertEquals($seconds, $parseFn->invokeArgs(null, [$timestr]));
        } else {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("Time string $timestr could not be parsed");
            $parseFn->invokeArgs(null, [$timestr]);
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
