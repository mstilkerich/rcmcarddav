<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class TestInfrastructure
{
    /** @var ?LoggerInterface Logger object used to store log messages produced during the tests */
    public static $logger;

    public static function init(): void
    {
        if (!isset(self::$logger)) {
            $logfile = "testreports/tests-{$GLOBALS['TEST_TESTRUN']}.log";
            if (file_exists($logfile)) {
                unlink($logfile);
            }
            self::$logger = new \Wa72\SimpleLogger\FileLogger($logfile, \Psr\Log\LogLevel::DEBUG);
        }
    }

    public static function readJsonArray(string $jsonFile): array
    {
        TestCase::assertFileIsReadable($jsonFile);
        $json = file_get_contents($jsonFile);
        TestCase::assertNotFalse($json);
        $phpArray = json_decode($json, true);
        TestCase::assertTrue(is_array($phpArray));

        return $phpArray;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
