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

use PHasher;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavAddressbook4Roundcube\DataConversion;
use MStilkerich\CardDavAddressbook4Roundcube\DelayedPhotoLoader;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;

/**
 * @psalm-import-type SaveData from DataConversion
 */
class Utils
{
    /**
     * Can be used as emulation of WebDavResource::downloadResource in stubs.
     * @return array{body: string}
     */
    public static function downloadResource(string $uri): array
    {
        if (preg_match(',^http://localhost/(.+),', $uri, $matches) && isset($matches[1])) {
            $filename = "tests/unit/data/srv/$matches[1]";
            TestCase::assertFileIsReadable($filename);
            return [ 'body' => file_get_contents($filename) ];
        }
        throw new \Exception("URI $uri not known to stub");
    }


    /**
     * @return SaveData
     */
    public static function readSaveDataFromJson(string $jsonFile): array
    {
        /** @var SaveData Assume our test samples are valid */
        $phpArray = TestInfrastructure::readJsonArray($jsonFile);

        // special handling for photo as we cannot encode binary data in json
        if (isset($phpArray['photo'])) {
            TestCase::assertIsString($phpArray['photo']);
            $photoFile = $phpArray['photo'];
            if (!empty($photoFile) && $photoFile[0] == '@') {
                $photoFile = substr($photoFile, 1);
                $phpArray['photo'] = TestInfrastructure::readFileRelative($photoFile, $jsonFile);
            }
        }

        return $phpArray;
    }

    /**
     * Compares two roundcube save data arrays with special handling of photo.
     */
    public static function compareSaveData(array $saveDataExp, array $saveDataRc, string $msg): void
    {
        TestCase::assertSame(isset($saveDataExp['photo']), isset($saveDataRc['photo']), $msg);
        if (isset($saveDataExp['photo'])) {
            TestCase::assertIsString($saveDataExp["photo"]);

            // Check that save data contains a pristine photo loader
            TestCase::assertInstanceOf(DelayedPhotoLoader::class, $saveDataRc["photo"]);
            TestCase::assertTrue($saveDataRc["photo"]->pristine(), "Photo loader not pristine");

            self::comparePhoto($saveDataExp['photo'], (string) $saveDataRc['photo']);
            unset($saveDataExp['photo']);
            unset($saveDataRc['photo']);
        }

        if (isset($saveDataRc["vcard"])) {
            TestCase::assertIsString($saveDataRc["vcard"]);
            unset($saveDataRc["vcard"]);
        }

        if (isset($saveDataRc["_carddav_vcard"])) {
            TestCase::assertInstanceOf(VCard::class, $saveDataRc["_carddav_vcard"]);
            unset($saveDataRc["_carddav_vcard"]);
        }

        TestCase::assertEquals($saveDataExp, $saveDataRc, $msg);
    }

    /**
     * Compares two photos given as binary strings.
     *
     * @param string $pExpStr The expected photo data
     * @param string $pRcStr The photo data produced by the test object
     */
    public static function comparePhoto(string $pExpStr, string $pRcStr): void
    {
        TestCase::assertTrue(function_exists('gd_info'), "php-gd required");

        // shortcut that also covers URI - if identical strings, save the comparison
        if (empty($pExpStr) || empty($pRcStr) || str_contains($pExpStr, "http") || str_contains($pExpStr, "data:")) {
            TestCase::assertSame($pExpStr, $pRcStr, "PHOTO comparison on URI value failed");
            return;
        }

        // dimensions must be the same
        /** @psalm-var false|array{int,int,int} */
        $pExp = getimagesizefromstring($pExpStr);
        TestCase::assertNotFalse($pExp, "Exp Image could not be identified");
        /** @psalm-var false|array{int,int,int} */
        $pRc = getimagesizefromstring($pRcStr);
        TestCase::assertNotFalse($pRc, "RC Image could not be identified");

        TestCase::assertSame($pExp[0], $pRc[0], "X dimension of PHOTO differs");
        TestCase::assertSame($pExp[1], $pRc[1], "Y dimension of PHOTO differs");
        TestCase::assertSame($pExp[2], $pRc[2], "Image type of PHOTO differs");

        // store to temporary files for comparison
        $expFile = tempnam("testreports", "imgcomp_") . image_type_to_extension($pExp[2]);
        $rcFile = tempnam("testreports", "imgcomp_") . image_type_to_extension($pRc[2]);

        TestCase::assertNotFalse(file_put_contents($expFile, $pExpStr), "Cannot write $expFile");
        TestCase::assertNotFalse(file_put_contents($rcFile, $pRcStr), "Cannot write $rcFile");

        // compare
        /** @psalm-var PHasher $phasher */
        $phasher = PHasher::Instance();
        // similarity is returned as percentage
        $compResult = intval($phasher->Compare($expFile, $rcFile));
        TestCase::assertSame(100, $compResult, "Image comparison returned too little similarity $compResult%");
    }

    /**
     * Delete temporary files from image comparison
     */
    public static function cleanupTempImages(): void
    {
        $tmpimgs = glob("testreports/imgcomp_*");
        if (!empty($tmpimgs)) {
            foreach ($tmpimgs as $tmpimg) {
                unlink($tmpimg);
            }
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
