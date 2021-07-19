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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use PHPUnit\Framework\TestCase;

/**
 * Contains utility functions used by the test cases.
 */
class Utils
{
    public static function copyDir(string $src, string $dst): void
    {
        TestCase::assertDirectoryIsReadable($src, "Source directory $src cannot be read");
        TestCase::assertDirectoryDoesNotExist($dst, "Destination directory $dst already exists");

        // create destination
        TestCase::assertTrue(mkdir($dst, 0755, true), "Destination directory $dst could not be created");

        // process all directory entries in source
        $dirh = opendir($src);
        TestCase::assertIsResource($dirh, "Source directory could not be opened");
        while (false !== ($entry = readdir($dirh))) {
            if ($entry != "." && $entry != "..") {
                $entryp = "$src/$entry";
                $targetp = "$dst/$entry";

                if (is_dir($entryp)) {
                    self::copyDir($entryp, $targetp);
                } elseif (is_file($entryp)) {
                    TestCase::assertTrue(copy($entryp, $targetp), "Copy failed: $entryp -> $targetp");
                } else {
                    TestCase::assertFalse(true, "$entryp: copyDir only supports files/directories");
                }
            }
        }
        closedir($dirh);
    }

    public static function rmDirRecursive(string $dir): void
    {
        TestCase::assertDirectoryIsWritable($dir, "Target directory $dir not writeable");

        // 1: purge directory contents
        $dirh = opendir($dir);
        TestCase::assertIsResource($dirh, "Target directory could not be opened");
        while (false !== ($entry = readdir($dirh))) {
            if ($entry != "." && $entry != "..") {
                $entryp = "$dir/$entry";

                if (is_dir($entryp)) {
                    self::rmDirRecursive($entryp);
                } else {
                    TestCase::assertTrue(unlink($entryp), "Unlink failed: $entryp");
                }
            }
        }
        closedir($dirh);

        // 2: delete directory
        TestCase::assertTrue(rmdir($dir), "rmdir failed: $dir");
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
