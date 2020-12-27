<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook,AbstractDatabase,DataConversion,SyncHandlerRoundcube};

final class SyncHandlerRoundcubeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
        $_SESSION['user_id'] = 105;
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    private function vcardSamplesProvider(string $basedir): array
    {
        $vcfFiles = glob("$basedir/*.vcf");

        $result = [];
        foreach ($vcfFiles as $vcfFile) {
            $comp = pathinfo($vcfFile);
            $jsonFile = "{$comp["dirname"]}/{$comp["filename"]}.json";
            $result[$comp["basename"]] = [ $vcfFile, $jsonFile ];
        }

        return $result;
    }

    // GetExistingVCards: Provide array URI => ETAG
    //   - Case: Empty addressbook
    //   - Case: Addressbook with contacts AND vcard-style groups AND CATEGORIES-style groups [not contained]
    // Changes: URI, ETAG, ?Card
    //   - Card is NULL
    //   - New contact
    //     - with CATEGORIES
    //     - with PHOTO referenced by URI (no download from CardDAV server)
    //   - Updated contact, with CATEGORIES
    //      - New CATEGORIES
    //      - Removed CATEGORIES
    //   - New group
    //      - Without members
    //      - With members provided in previous Change invocations
    //      - With members provided in later Change invocations
    //      - With members already locally available, but not changed
    //      - Error: With member UIDs that are unknown
    // Deleted: URI
    //      - Existing contact card
    //      - Existing group card
    //      - Unknown URI
    // Finalize:
    public function vcardImportSamplesProvider(): array
    {
        return $this->vcardSamplesProvider('tests/unit/data/vcardImport');
    }

    /**
     * Tests that an empty local cache is properly reported as empty array by the sync handler.
     */
    public function testSyncHandlerProvidesExistingCacheStateEmpty(): void
    {
        $db = new JsonDatabase();
        [ $logger, $dc, $abook, $rcAbook ] = $this->initStubs($db);

        $synch = new SyncHandlerRoundcube($rcAbook, $db, $logger, $dc, $abook);

        $cache = $synch->getExistingVCardETags();
        $this->assertCount(0, $cache);
    }

    /**
     * Tests that a non-empty local cache is properly reported by the sync handler.
     */
    public function testSyncHandlerProvidesExistingCacheState(): void
    {
        $db = new JsonDatabase(['tests/unit/data/syncHandlerTest/db.json']);
        [ $logger, $dc, $abook, $rcAbook ] = $this->initStubs($db);

        $synch = new SyncHandlerRoundcube($rcAbook, $db, $logger, $dc, $abook);

        $cache = $synch->getExistingVCardETags();

        $entries = $db->get(['abook_id' => '42']);
        $this->assertCount(count($entries), $cache);
    }

    public function testInitialSyncOnEmptyDatabase(): void
    {
        $db = new JsonDatabase(['tests/unit/data/syncHandlerTest/initialdb.json']);
        $expDb = new JsonDatabase(['tests/unit/data/syncHandlerTest/db.json']);
        [ $logger, $dc, $abook, $rcAbook ] = $this->initStubs($db);

        $synch = new SyncHandlerRoundcube($rcAbook, $db, $logger, $dc, $abook);

        // Report all VCards of the test DB as changed
        $vcfFiles = glob("tests/unit/data/syncHandlerTest/*.vcf");
        foreach ($vcfFiles as $vcfFile) {
            $base = basename($vcfFile, ".vcf");
            $synch->addressObjectChanged(
                "/book42/$base.vcf",
                "etag@${base}_1",
                TestInfrastructure::readVCard($vcfFile)
            );
        }
        $synch->finalizeSync();

        // Compare database with expected state
        $expDb->compareTables('contacts', $db);
        $expDb->compareTables('groups', $db);
        $expDb->compareTables('group_user', $db);
        $expDb->compareTables('xsubtypes', $db);
    }

    private function initStubs(AbstractDatabase $db): array
    {
        $logger = TestInfrastructure::$logger;
        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $rcAbook = $this->createStub(Addressbook::class);
        $rcAbook->method('getId')->will($this->returnValue("42"));

        $abook = $this->createStub(AddressbookCollection::class);
        $cache = $this->createMock(\rcube_cache::class);

        $dc = new DataConversion("42", $db, $cache, $logger);
        return [ $logger, $dc, $abook, $rcAbook ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
