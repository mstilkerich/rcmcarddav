<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\DBInteroperability;

use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavAddressbook4Roundcube\{Database};

final class DatabaseSyncTest extends TestCase
{
    /** @var resource[] */
    private $sockets;
    /** @var resource */
    private $commSock;

    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
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
        Database::delete("UNITTEST-SYNC%", "migrations", "%filename");
    }

    public function dbSettings(): array
    {
        return DatabaseAccounts::ACCOUNTS[$GLOBALS["TEST_DBTYPE"]];
    }

    public function testOverlappingWriteAborts(): void
    {
        $dbsettings = $this->dbSettings();
        $db_dsnw = $dbsettings[0];

        if ($this->split() === 0) {
            $this->initDatabase($db_dsnw);

            try {
                $this->barrierWait("P_TA_START");

                Database::startTransaction(false);

                // perform a SELECT so that DBMS has to assume the following update was computed based on this query
                // before we run our update, the parent will update, thus there is a serialization conflict
                [ "id" => $id, "filename" => $fn ] =
                    Database::get("UNITTEST-SYNC%", "id,filename", "migrations", true, "%filename");
                $this->barrierReached("C_TA_START");
                sleep(1);
                Database::update($id, ["filename"], ["$fn-CLD"], "migrations");

                Database::endTransaction();
            } catch (\Exception $e) {
                Database::rollbackTransaction();
                exit(1);
            }
            exit(0);
        } else {
            $this->initDatabase($db_dsnw);
            $recordId = Database::insert("migrations", ["filename"], ["UNITTEST-SYNC"]);

            try {
                Database::startTransaction(false);

                $this->barrierReached("P_TA_START");
                $this->barrierWait("C_TA_START");

                [ "id" => $id, "filename" => $fn ] =
                    Database::get("UNITTEST-SYNC%", "id,filename", "migrations", true, "%filename");
                Database::update($recordId, ["filename"], ["$fn-PAR"], "migrations");
                sleep(1);

                Database::endTransaction();
                $parWins = true;
            } catch (\Exception $e) {
                Database::rollbackTransaction();
                $parWins = false;
            }

            $cldWins = ($this->collectChild() === 0);
            [ "filename" => $fn ] = Database::get($recordId, "*", "migrations");
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

    private function initDatabase(string $db_dsnw, string $db_prefix = ""): void
    {
        $rcconfig = \rcube::get_instance()->config;
        $rcconfig->set("db_prefix", $db_prefix, false);
        $dbh = \rcube_db::factory($db_dsnw);
        /** @var \Psr\Log\LoggerInterface */
        $logger = TestInfrastructure::$logger;
        Database::init($logger, $dbh);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
