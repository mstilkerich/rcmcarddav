<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;

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

    public static function readVCard(string $vcfFile): VCard
    {
        TestCase::assertFileIsReadable($vcfFile);
        $vcard = VObject\Reader::read(fopen($vcfFile, 'r'));
        TestCase::assertInstanceOf(VCard::class, $vcard);
        return $vcard;
    }

    /**
     * Reads a file at a path relative to a given base file and returns its content.
     *
     * @param string $path Relative path of the file to read.
     * @param string $baseFile File relative to whose path $path is to be interpreted to.
     */
    public static function readFileRelative(string $path, string $baseFile): string
    {
        $comp = pathinfo($baseFile);
        $file = "{$comp["dirname"]}/$path";
        TestCase::assertFileIsReadable($file);
        $content = file_get_contents($file);
        TestCase::assertIsString($content, "$file could not be read");
        return $content;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
