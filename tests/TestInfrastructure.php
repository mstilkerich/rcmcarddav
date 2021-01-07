<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;

final class TestInfrastructure
{
    /** @var ?TestLogger Logger object used to store log messages produced during the tests */
    private static $logger;

    public static function init(): void
    {
        if (!isset(self::$logger)) {
            self::$logger = new TestLogger();
        }
    }

    public static function logger(): TestLogger
    {
        $logger = self::$logger;
        TestCase::assertNotNull($logger, "Call init() before asking for logger");
        return $logger;
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

    /**
     * Transforms a list of rows in associative array form to a list form.
     *
     * In other words, transforms the rows as returned by AbstractDatabase::get() to the format used internally.
     *
     * The function asserts that all fields listed in $cols actually have an entry in the provided associative rows.
     *
     * The order of rows is preserved.
     *
     * @param list<string> $cols The list of columns that defines fields and their order in the result rows.
     * @param list<array<string,?string>> $assocRows The associative row set where column names are keys.
     * @param bool $extraIsError If true, an assertion is done on that the associative rows contain now extra fields
     *                           that are not listed in $cols. If false, such fields will be discarded.
     * @return list<list<?string>>
     */
    public static function xformDatabaseResultToRowList(array $cols, array $assocRows, bool $extraIsError): array
    {
        $result = [];

        foreach ($assocRows as $aRow) {
            $lRow = [];

            foreach ($cols as $col) {
                TestCase::assertArrayHasKey($col, $aRow, "cols requires field not existing in associative row");
                $lRow[] = $aRow[$col];
                unset($aRow[$col]);
            }

            $result[] = $lRow;
            if ($extraIsError) {
                TestCase::assertEmpty($aRow, "Associative row has fields not listed in cols");
            }
        }

        return $result;
    }

    /**
     * Compares two database rows.
     *
     * One row is given in the format as returned by AbstractDatabase::get() (associative array).
     * The other is given as separate arrays for columns and associated data.
     */
    public static function compareRow(): void
    {
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
