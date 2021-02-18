<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use PHPUnit\Framework\TestCase;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook,DataConversion};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\CardDavClient\AddressbookCollection;

/**
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type SaveData from DataConversion
 */
final class AddressbookTest extends TestCase
{
    /** @var JsonDatabase */
    private $db;

    public static function setUpBeforeClass(): void
    {
        $_SESSION['user_id'] = 105;
    }

    public function setUp(): void
    {
        $this->db = new JsonDatabase();
        $cache = $this->createMock(\rcube_cache::class);
        TestInfrastructure::init($this->db);
        TestInfrastructure::$infra->setCache($cache);
    }

    public function tearDown(): void
    {
        Utils::cleanupTempImages();
        TestInfrastructure::logger()->reset();
    }

    /**
     * @param list<string> $reqCols
     */
    private function createAbook(array $reqCols = []): Addressbook
    {
        $db = $this->db;
        $db->importData('tests/unit/data/syncHandlerTest/initial/db.json');

        /** @var FullAbookRow */
        $abookcfg = $db->lookup("42", '*', 'addressbooks');

        $abook = new Addressbook("42", $abookcfg, false, $reqCols);
        $davobj = $this->createStub(AddressbookCollection::class);
        $davobj->method('downloadResource')->will($this->returnCallback([Utils::class, 'downloadResource']));
        TestInfrastructure::setPrivateProperty($abook, 'davAbook', $davobj);

        return $abook;
    }

    /**
     * Tests that a newly constructed addressbook has the expected values in its public properties.
     */
    public function testAddressbookHasExpectedPublicPropertyValues(): void
    {
        $db = $this->db;

        $abook = $this->createAbook();

        $this->assertSame('id', $abook->primary_key);
        $this->assertSame(true, $abook->groups);
        $this->assertSame(true, $abook->export_groups);
        $this->assertSame(false, $abook->readonly);
        $this->assertSame(false, $abook->searchonly);
        $this->assertSame(false, $abook->undelete);
        $this->assertSame(true, $abook->ready);
        $this->assertSame('name', $abook->sort_col);
        $this->assertSame('ASC', $abook->sort_order);
        $this->assertSame(['birthday', 'anniversary'], $abook->date_cols);
        $this->assertNull($abook->group_id);

        $this->assertIsArray($abook->coltypes['email']);
        $this->assertIsArray($abook->coltypes['email']['subtypes']);
        $this->assertContains("SpecialLabel", $abook->coltypes['email']['subtypes']);

        $this->assertSame("Test Addressbook", $abook->get_name());
        $this->assertSame("42", $abook->getId());

        /** @var FullAbookRow */
        $abookcfg = $db->lookup("42", '*', 'addressbooks');
        $roAbook = new Addressbook("42", $abookcfg, true, []);
        $this->assertSame(true, $roAbook->readonly);
    }

    /**
     * @return list<array{int,string,?string,int,int,null|0|string,?list<string>,list<string>,int,list<string>}>
     */
    public function listRecordsDataProvider(): array
    {
        return [
            // subset, sort_col, sort_order, page, pagesize, group, cols, expCount, expRecords
            [ 0, 'name', 'ASC', 1, 10, 0, null, [], 6, ["56", "51", "50", "52", "53", "54"] ],
            [ 0, 'name', 'DESC', 1, 10, "0", null, [], 6, ["54", "53", "52", "50", "51", "56"] ],
            [ 0, 'firstname', null, 1, 10, null, null, [], 6, ["51", "52", "53", "56", "50", "54"] ],
            [ 0, 'name', 'ASC', 1, 4, 0, null, [], 6, ["56", "51", "50", "52"] ],
            [ 0, 'name', 'ASC', 2, 4, 0, null, [], 6, ["53", "54"] ],
            [ 0, 'name', 'DESC', 3, 2, 0, null, [], 6, ["51", "56"] ],
            [ 1, 'name', 'DESC', 2, 2, 0, null, [], 6, ["52"] ],
            [ 2, 'name', 'DESC', 2, 2, 0, null, [], 6, ["52", "50"] ],
            [ 3, 'name', 'DESC', 2, 2, 0, null, [], 6, ["52", "50"] ],
            [ -1, 'name', 'DESC', 2, 2, 0, null, [], 6, ["50"] ],
            [ -2, 'name', 'DESC', 2, 2, 0, null, [], 6, ["52", "50"] ],
            [ -3, 'name', 'DESC', 2, 2, 0, null, [], 6, ["52", "50"] ],
            [ 0, 'name', 'ASC', 1, 10, "500", null, [], 2, ["56", "50"] ],
            [ 0, 'name', 'ASC', 1, 10, 0, ['name','email'], [], 6, ["56", "51", "50", "52", "53", "54"] ],
            [ 0, 'name', 'ASC', 1, 10, 0, ['organization', 'firstname'], [], 6, ["56", "51", "50", "52", "53", "54"] ],
            [ 0, 'name', 'ASC', 1, 10, 0, null, ['email'], 5, ["51", "50", "52", "53", "54"] ],
            [ 0, 'name', 'ASC', 1, 10, 0, null, ['email', 'surname'], 2, ["50", "54"] ],
            [ 0, 'name', 'ASC', 2, 2, 0, null, ['email'], 5, ["52", "53"] ],
//            [ 0, 'name', 'ASC', 1, 10, 0, null, ['jobtitle'], 1, ["50"] ],
        ];
    }

    /**
     * Tests list_records()
     *
     * @dataProvider listRecordsDataProvider
     * @param list<string> $expRecords
     * @param null|0|string $gid
     * @param ?list<string> $cols
     * @param list<string> $reqCols
     */
    public function testListRecordsReturnsExpectedRecords(
        int $subset,
        string $sortCol,
        ?string $sortOrder,
        int $page,
        int $pagesize,
        $gid,
        ?array $cols,
        array $reqCols,
        int $expCount,
        array $expRecords
    ): void {
        $abook = $this->createAbook($reqCols);
        $abook->set_page($page);
        $this->assertSame($page, $abook->list_page);
        $abook->set_pagesize($pagesize);
        $this->assertSame($pagesize, $abook->page_size);
        $abook->set_sort_order($sortCol, $sortOrder);
        $this->assertSame($sortCol, $abook->sort_col);
        $this->assertSame($sortOrder ?? 'ASC', $abook->sort_order);

        $abook->set_group($gid);
        if ($gid) {
            $this->assertSame($gid, $abook->group_id);
        } else {
            $this->assertNull($abook->group_id);
        }
        $rset = $abook->list_records($cols, $subset);
        $this->assertNull($abook->get_error());
        $this->assertSame($rset, $abook->get_result(), "Get result does not return last result set");
        $this->assertSame(($page - 1) * $pagesize, $rset->first);
        $this->assertFalse($rset->searchonly);
        $this->assertSame($expCount, $rset->count);
        $this->assertCount(count($expRecords), $rset->records);

        $lrOrder = array_column($rset->records, 'ID');
        $this->assertSame($expRecords, $lrOrder, "Card order mismatch");
        for ($i = 0; $i < count($expRecords); ++$i) {
            $id = $expRecords[$i];
            $fn = "tests/unit/data/addressbookTest/c{$id}.json";
            $saveDataExp = Utils::readSaveDataFromJson($fn);
            if (isset($cols)) {
                $saveDataExp = $this->stripSaveDataToDbColumns($saveDataExp, $cols);
            }
            /** @var array $saveDataRc */
            $saveDataRc = $rset->records[$i];
            Utils::compareSaveData($saveDataExp, $saveDataRc, "Unexpected record data $id");

            if (isset($saveDataExp['photo']) && empty($saveDataExp['photo'])) {
                $this->assertPhotoDownloadWarning();
            }
        }
    }

    /**
     * Tests count()
     *
     * @dataProvider listRecordsDataProvider
     * @param list<string> $expRecords
     * @param null|0|string $gid
     * @param ?list<string> $cols
     * @param list<string> $reqCols
     */
    public function testCountProvidesExpectedNumberOfRecords(
        int $subset,
        string $sortCol,
        ?string $sortOrder,
        int $page,
        int $pagesize,
        $gid,
        ?array $cols,
        array $reqCols,
        int $expCount,
        array $expRecords
    ): void {
        $abook = $this->createAbook($reqCols);
        $abook->set_page($page);
        $this->assertSame($page, $abook->list_page);
        $abook->set_pagesize($pagesize);
        $this->assertSame($pagesize, $abook->page_size);
        $abook->set_sort_order($sortCol, $sortOrder);
        $this->assertSame($sortCol, $abook->sort_col);
        $this->assertSame($sortOrder ?? 'ASC', $abook->sort_order);

        $abook->set_group($gid);
        if ($gid) {
            $this->assertSame($gid, $abook->group_id);
        } else {
            $this->assertNull($abook->group_id);
        }
        $rset = $abook->count();
        $this->assertNull($abook->get_error());
        $this->assertSame(($page - 1) * $pagesize, $rset->first);
        $this->assertFalse($rset->searchonly);
        $this->assertSame($expCount, $rset->count);
        $this->assertCount(0, $rset->records);
    }

    /**
     * Tests that set_group() ignores an invalid group id and logs an error.
     */
    public function testSetInvalidGroupIgnored(): void
    {
        $abook = $this->createAbook();
        $abook->set_group("12345");
        $this->assertNull($abook->group_id, "Group ID changed after set_group with invalid ID");
        $logger = TestInfrastructure::logger();
        $logger->expectMessage('error', 'set_group(12345)');
    }

    /**
     * Asserts that a warning message concerning failure to download the photo has been issued for VCards where an
     * invalid Photo URI is used.
     */
    private function assertPhotoDownloadWarning(): void
    {
        $logger = TestInfrastructure::logger();
        $logger->expectMessage(
            'warning',
            'downloadPhoto: Attempt to download photo from http://localhost/doesNotExist.jpg failed'
        );
    }

    /**
     * Given a full save_data array, it constrains/converts the data such that it only contains fields
     * that are present in the given columns.
     *
     * @param SaveData $saveData
     * @param list<string> $cols
     * @return SaveData
     */
    private function stripSaveDataToDbColumns(array $saveData, array $cols): array
    {
        $cols[] = 'ID'; // always keep ID in the result

        foreach ($saveData as $k => $v) {
            // strip subtype from multi-value objects
            $kgen = preg_replace('/:.*/', '', $k);
            if (in_array($kgen, $cols)) {
                if ($kgen != $k) {
                    /** @var list<string> $oldv */
                    $oldv = $saveData[$kgen] ?? [];
                    /** @var list<string> $v */
                    $saveData[$kgen] = array_merge($oldv, $v);
                    unset($saveData[$k]);
                }
            } else {
                unset($saveData[$k]);
            }
        }

        /** @var SaveData $saveData */
        return $saveData;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
