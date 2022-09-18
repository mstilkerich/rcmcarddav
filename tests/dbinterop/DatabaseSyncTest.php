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

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

final class DatabaseSyncTest extends TestCase
{
    /** @var AbstractDatabase */
    private static $db;

    /** @var resource[] */
    private $sockets;
    /** @var resource */
    private $commSock;

    public static function setUpBeforeClass(): void
    {
        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        self::$db = TestInfrastructureDB::initDatabase($db_dsnw);
        TestInfrastructure::init(self::$db);
    }

    public function setUp(): void
    {
        $this->sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertTrue(is_array($this->sockets), "Socket creation failed");
    }

    public function tearDown(): void
    {
        fclose($this->sockets[0]);
        fclose($this->sockets[1]);
        self::$db->delete(["%filename" => "UNITTEST-SYNC%"], "migrations");

        TestInfrastructure::logger()->reset();
    }

    public function testOverlappingWriteAborts(): void
    {
        if ($this->split() === 0) {
            // Create a new database handle for the child so it uses a separate connection
            $dbsettings = TestInfrastructureDB::dbSettings();
            $db_dsnw = $dbsettings[0];
            $db = TestInfrastructureDB::initDatabase($db_dsnw);

            try {
                $this->barrierWait("P_TA_START");

                $db->startTransaction(false);

                // perform a SELECT so that DBMS has to assume the following update was computed based on this query
                // before we run our update, the parent will update, thus there is a serialization conflict
                [ "id" => $id, "filename" => $fn ] =
                    $db->lookup(["%filename" => "UNITTEST-SYNC%"], ["id", "filename"], "migrations");
                $this->barrierReached("C_TA_START");
                $this->assertIsString($id);
                $this->assertIsString($fn);
                sleep(1);
                $db->update($id, ["filename"], ["$fn-CLD"], "migrations");

                $db->endTransaction();
            } catch (\Exception $e) {
                $db->rollbackTransaction();
                exit(1);
            }
            exit(0);
        } else {
            $db = self::$db;

            $recordId = $db->insert("migrations", ["filename"], [["UNITTEST-SYNC"]]);

            try {
                $db->startTransaction(false);

                $this->barrierReached("P_TA_START");
                $this->barrierWait("C_TA_START");

                [ "filename" => $fn ] = $db->lookup(
                    ["%filename" => "UNITTEST-SYNC%"],
                    ["id", "filename"],
                    "migrations"
                );
                $db->update($recordId, ["filename"], ["$fn-PAR"], "migrations");
                sleep(1);

                $db->endTransaction();
                $parWins = true;
            } catch (\Exception $e) {
                $db->rollbackTransaction();
                $parWins = false;
            }

            $cldWins = ($this->collectChild() === 0);
            [ "filename" => $fn ] = $db->lookup($recordId, [], "migrations");
            // it would also be ok if both failed with no changes to the DB or both succeeded with a result matching
            // serial execution of the two transactions, but these are not expected by any of the three DBs
            $this->assertTrue($parWins xor $cldWins, "Exactly one transaction must succeed ($parWins/$cldWins, $fn)");
            $this->assertEquals("UNITTEST-SYNC-" . ($parWins ? "PAR" : "CLD"), $fn, "Winner's update not visible");
        }
    }

    private function split(): int
    {
        $pid = pcntl_fork();

        if ($pid == 0) {
            $this->commSock = $this->sockets[0];
        } elseif ($pid > 0) {
            $this->commSock = $this->sockets[1];
        } else {
            $this->assertGreaterThanOrEqual(0, $pid, "fork failed");
        }

        return $pid;
    }

    private function collectChild(): int
    {
        pcntl_wait($status);
        $this->assertTrue(pcntl_wifexited($status), "Child did not exit itself");
        return pcntl_wexitstatus($status);
    }

    private function barrierReached(string $id): void
    {
        //fwrite(STDERR, "REACHED: $id\n");
        fwrite($this->commSock, "$id\n");
    }

    private function barrierWait(string $id): void
    {
        //fwrite(STDERR, "WAIT: $id\n");
        $recv = fgets($this->commSock);
        if ($recv !== "$id\n") {
            throw new \Exception("Barrier did not return ($recv) with expected ID ($id)");
        }
    }

    /**
     * Tests that after a read-only transaction it is possible to do read-write autocommit transactions.
     */
    public function testWritePossibleAfterReadOnlyTransaction(): void
    {
        $db = self::$db;

        try {
            $recordId = $db->insert("migrations", ["filename"], [["UNITTEST-SYNC-WPAROT"]]);
            $db->startTransaction(true); // read-only transaction
            [ 'id' => $recordId2] = $db->lookup(["filename" => "UNITTEST-SYNC-WPAROT"], ["id"], "migrations");
            $db->endTransaction();

            $this->assertSame($recordId, $recordId2);

            // now try a read-write operation
            $numdel = $db->delete($recordId, 'migrations');
            $this->assertSame(1, $numdel, "Different number of rows deleted than expected");
        } catch (\Exception $e) {
            $db->rollbackTransaction();
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
