<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use carddav;

final class CarddavTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
    }

    public function setUp(): void
    {
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
