<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\AddressbookCollection;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook,DataConversion,SyncHandlerRoundcube};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

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
        TestInfrastructure::logger()->reset();
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
        $logger = TestInfrastructure::logger();
        [ $dc, $abook, $rcAbook ] = $this->initStubs($db);

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
        $logger = TestInfrastructure::logger();
        [ $dc, $abook, $rcAbook ] = $this->initStubs($db);

        $synch = new SyncHandlerRoundcube($rcAbook, $db, $logger, $dc, $abook);

        $cache = $synch->getExistingVCardETags();

        $centries = $db->get(['abook_id' => '42']);
        $gentries = $db->get(['abook_id' => '42', '!vcard' => null], 'id', 'groups');
        $this->assertCount(count($centries) + count($gentries), $cache);
    }

    /**
     * Tests an initial sync operation into an empty database.
     *
     * The initial data set contains everything that manifests in table columns outside vcard:
     * - Several contacts, including contacts with set ORG property
     * - CATEGORIES-type groups embedded in the contacts
     * - A KIND=group vcard that contains several of the contacts and invalid member references
     * - Use of a special label with the X-ABLABEL extension
     * - A company card using X-ABSHOWAS:COMPANY
     */
    public function testInitialSyncOnEmptyDatabase(): void
    {
        $vcfFiles = (glob("tests/unit/data/syncHandlerTest/*.vcf"));
        $this->assertTrue(sort($vcfFiles));
        $this->initialSyncTestHelper($vcfFiles);
    }

    /**
     * Tests an initial sync operation into an empty database, giving cards in reverse order.
     *
     * This test ensures that either in this test or in testInitialSyncOnEmptyDatabase(), a KIND=group vcard will be
     * reported to the sync handler before all members are known to the sync handler. The sync handler must be able to
     * cope with this, i.e. check group memberships only after it has an up-to-date view of the contacts.
     */
    public function testInitialSyncOnEmptyDatabaseInReverseOrder(): void
    {
        $vcfFiles = (glob("tests/unit/data/syncHandlerTest/*.vcf"));
        $this->assertTrue(rsort($vcfFiles));
        $this->initialSyncTestHelper($vcfFiles);
    }

    private function initialSyncTestHelper(array $vcfFiles): void
    {
        $db = new JsonDatabase(['tests/unit/data/syncHandlerTest/initialdb.json']);
        $expDb = new JsonDatabase(['tests/unit/data/syncHandlerTest/db.json']);
        $logger = TestInfrastructure::logger();
        [ $dc, $abook, $rcAbook ] = $this->initStubs($db);

        $synch = new SyncHandlerRoundcube($rcAbook, $db, $logger, $dc, $abook);

        // Report all VCards of the test DB as changed
        foreach ($vcfFiles as $vcfFile) {
            $base = basename($vcfFile, ".vcf");
            $synch->addressObjectChanged(
                "/book42/$base.vcf",
                "etag@${base}_1",
                TestInfrastructure::readVCard($vcfFile)
            );
        }
        $synch->finalizeSync();

        // check emitted warnings
        $logger->expectMessage(
            "warning",
            "don't know how to interpret group membership: urn:unknown:9e1c1cf8-51f8-42b1-9314-44e34a7d148f"
        );
        $logger->expectMessage(
            "warning",
            "cannot find DB ID for group member: 11111111-2222-3333-4444-555555555555"
        );

        // Compare database with expected state
        $expDb->compareTables('contacts', $db);
        $expDb->compareTables('groups', $db);
        $expDb->compareTables('group_user', $db);
        $expDb->compareTables('xsubtypes', $db);
    }

    private function initStubs(AbstractDatabase $db): array
    {
        $logger = TestInfrastructure::logger();
        $rcAbook = $this->createStub(Addressbook::class);
        $rcAbook->method('getId')->will($this->returnValue("42"));

        $abook = $this->createStub(AddressbookCollection::class);
        $cache = $this->createMock(\rcube_cache::class);

        $dc = new DataConversion("42", $db, $cache, $logger);
        return [ $dc, $abook, $rcAbook ];
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
