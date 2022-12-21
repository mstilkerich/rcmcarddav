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

use PHPUnit\Framework\TestCase;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\{Addressbook,DataConversion,DelayedPhotoLoader};
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\CardDavAddressbook4Roundcube\Db\DbAndCondition;
use MStilkerich\CardDavClient\AddressbookCollection;
use rcube_addressbook;

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
     * @param array{refresh_time?: numeric-string, last_updated?: numeric-string} $cfgOverride
     */
    private function createAbook(array $reqCols = [], array $cfgOverride = []): Addressbook
    {
        $db = $this->db;
        $db->importData('tests/unit/data/syncHandlerTest/initial/db.json');

        /** @var FullAbookRow */
        $abookcfg = $db->lookup("42", [], 'addressbooks');
        /** @var FullAbookRow */
        $abookcfg = $cfgOverride + $abookcfg;

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
        $abookcfg = $db->lookup("42", [], 'addressbooks');
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
        $abook = $this->createAbookForSearchTest($sortCol, $sortOrder, $page, $pagesize, $gid, $reqCols);

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
     * Initializes an addressbook for the tests of list_records(), count() and search().
     *
     * @param null|0|string $gid
     * @param list<string> $reqCols
     */
    private function createAbookForSearchTest(
        string $sortCol,
        ?string $sortOrder,
        int $page,
        int $pagesize,
        $gid,
        array $reqCols
    ): Addressbook {
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

        return $abook;
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
        $abook = $this->createAbookForSearchTest($sortCol, $sortOrder, $page, $pagesize, $gid, $reqCols);
        $rset = $abook->count();
        $this->assertNull($abook->get_error());
        $this->assertSame(($page - 1) * $pagesize, $rset->first);
        $this->assertFalse($rset->searchonly);
        $this->assertSame($expCount, $rset->count);
        $this->assertCount(0, $rset->records);
    }

    /**
     * @return array<string, array{
     *     string|string[], string|string[], int, bool, bool,
     *     string,?string,int,int,
     *     null|0|string,
     *     list<string>,
     *     string|string[],
     *     int,list<string>
     * }>
     */
    public function searchDataProvider(): array
    {
        return [
            'Direct ID search single id' => [
                'ID', "50", 0, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["50"]
            ],
            'Direct ID search with key property from addressbook' => [
                'id', "50", 0, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["50"]
            ],
            'Direct ID search multiple id as string' => [
                'ID', "50,56,52,70", 0, true, false, // 70 belongs to a different addressbook and must not be returned
                'name', 'ASC', 1, 10,
                0,
                [], [],
                3, ["56", "50", "52"]
            ],
            'Direct ID search multiple id as array' => [
                'ID', ["50", "56", "52"], 0, true, false,
                'name', 'DESC', 1, 10,
                0,
                [], [],
                3, ["52", "50", "56"]
            ],
            'Single DB field search with prefix search' => [
                ['name'], ["ROB"], rcube_addressbook::SEARCH_PREFIX, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["53"]
            ],
            'Single Multivalue DB field search with contains search' => [
                ['email'], ["north@7kingdoms.com"], rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                2, ["60", "50"]
            ],
            'Single Multivalue DB structured-content field search with contains search' => [
                ['address'], ["Kanto"], rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["60"]
            ],
            'Single Multivalue DB field search with strict search' => [
                ['email'], ["north@7kingdoms.com"], rcube_addressbook::SEARCH_STRICT, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["60"]
            ],
            'Multi DB field search with contains search' => [
                ['name', 'email'], ["Lannister", "@example"], rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["51"]
            ],
            'Multi DB/vcard field search with contains search' => [
                ['name', 'jobtitle'], ["Stark", "Warden"], rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["50"]
            ],
            'Vcard field search with post-search drop' => [ // vcard as a whole matches, but not the asked for field
                ['assistant'], ["Wesker"], rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                0, []
            ],
            'Vcard field search with exact match' => [
                ['assistant'], ["Dr. Alexander Isaacs"], rcube_addressbook::SEARCH_STRICT, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["60"]
            ],
            'Vcard field search with exact match (no match)' => [
                ['assistant'], ["Dr. Alexander Isaac"], rcube_addressbook::SEARCH_STRICT, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                0, []
            ],
            'Multi Vcard field search with exact match' => [
                ['assistant', 'manager'], ["dr. alex", "no "],
                rcube_addressbook::SEARCH_PREFIX, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["60"]
            ],
            'All fields search' => [
                '*', 'Birkin',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                1, ["60"]
            ],
            'All fields search (no matches)' => [
                '*', 'Birkin22',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                0, []
            ],
            'Two fields OR search' => [
                ['organization', 'name'], 'the',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                3, ["50", "53", "54"]
            ],
            'Mixed DB/vcard fields OR search' => [
                ['notes', 'organization', 'name'], 'the',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], [],
                4, ["60", "50", "53", "54"]
            ],
            'Results for 2nd page only' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 2, 2,
                0,
                [], [],
                6, ["50", "52"]
            ],
            'Results for 2nd page only (select only)' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, true,
                'name', 'ASC', 2, 2,
                0,
                [], [],
                2, ["50", "52"]
            ],
            'Results for 2nd page only (count only)' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, false, false,
                'name', 'ASC', 2, 2,
                0,
                [], [],
                6, []
            ],
            'With required DB field' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], ['organization'],
                3, ["60", "50", "54"]
            ],
            'With required DB field (plus required abook field)' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                ['firstname'], ['organization'],
                2, ["50", "54"]
            ],
            'With required VCard field' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], ['jobtitle'],
                2, ["60", "50"]
            ],
            'With required VCard field (as string)' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                0,
                [], 'jobtitle',
                2, ["60", "50"]
            ],
            'With required VCard field and group filter' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                "500",
                [], 'jobtitle',
                1, ["50"]
            ],
            'With required VCard field and group filter for empty group' => [
                '*', 'example',
                rcube_addressbook::SEARCH_ALL, true, false,
                'name', 'ASC', 1, 10,
                "504",
                [], 'jobtitle',
                0, []
            ],
        ];
    }

    /**
     * Tests the search() function.
     *
     * @dataProvider searchDataProvider
     * @param string|string[] $fields
     * @param string|string[] $value
     * @param null|0|string $gid
     * @param list<string> $reqColsBk Required columns for the addressbook
     * @param string|string[] $reqColsCl Required columns search() parameter
     * @param list<string> $expRecords
     */
    public function testSearchReturnsExpectedRecords(
        $fields,
        $value,
        int $mode,
        bool $select,
        bool $nocount,
        string $sortCol,
        ?string $sortOrder,
        int $page,
        int $pagesize,
        $gid,
        array $reqColsBk,
        $reqColsCl,
        int $expCount,
        array $expRecords
    ): void {
        $abook = $this->createAbookForSearchTest($sortCol, $sortOrder, $page, $pagesize, $gid, $reqColsBk);
        $db = $this->db;
        $db->importData('tests/unit/data/addressbookTest/db2.json');

        // Try with search() and a second time with set_search_set() + list_records()
        for ($run = 0; $run < 2; ++$run) {
            if ($run == 0) {
                $rset = $abook->search($fields, $value, $mode, $select, $nocount, $reqColsCl);
            } else {
                // After the search, the search filter should be installed
                $filter = $abook->get_search_set();
                $this->assertNotEmpty($filter);
                $abook->reset();
                $this->assertEmpty($abook->get_search_set());
                $this->assertNull($abook->get_result());

                $abook->set_search_set($filter);
                $rset = $abook->list_records(null, 0, $nocount);
            }

            $this->assertNull($abook->get_error());
            $this->assertSame($rset, $abook->get_result(), "Search does not return last result set");
            $this->assertSame(($page - 1) * $pagesize, $rset->first);
            $this->assertFalse($rset->searchonly);

            if ($nocount) {
                $this->assertSame(count($rset->records), $rset->count);
            } else {
                $this->assertSame($expCount, $rset->count);
            }

            if ($run == 0 || $select) { // select=false can only be tested with search() (run 0)
                $this->assertCount(count($expRecords), $rset->records);

                $lrOrder = array_column($rset->records, 'ID');
                $this->assertSame($expRecords, $lrOrder, "Card order mismatch (run $run)");
                for ($i = 0; $i < count($expRecords); ++$i) {
                    $id = $expRecords[$i];
                    $fn = "tests/unit/data/addressbookTest/c{$id}.json";
                    $saveDataExp = Utils::readSaveDataFromJson($fn);
                    /** @var array $saveDataRc */
                    $saveDataRc = $rset->records[$i];
                    Utils::compareSaveData($saveDataExp, $saveDataRc, "Unexpected record data $id");

                    if (isset($saveDataExp['photo']) && empty($saveDataExp['photo'])) {
                        $this->assertPhotoDownloadWarning();
                    }
                }
            }
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public function invalidFilterProvider(): array
    {
        return [
            'SQL string' => [ 'WHERE name="foo"' ],
            'Mixed DbAndCondition array' => [ [ new DbAndCondition(), 'WHERE name="foo"' ] ],
        ];
    }

    /**
     * Tests that set_search_set() throws an error when given an invalid filter type.
     *
     * @dataProvider invalidFilterProvider
     * @param mixed $filter
     */
    public function testSetSearchSetThrowsErrorOnInvalidFilter($filter): void
    {
        $abook = $this->createAbook();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a DbAndCondition[] type filter');
        $abook->set_search_set($filter);
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

    /** @return array<string, array{string,bool,bool}> */
    public function getRecordProvider(): array
    {
        return [
            'Valid ID' => [ '50', true, false ],
            'Valid ID (rset)' => [ '50', false, false ],
            'Valid ID (different addressbook)' => [ '70', true, true ],
            'Invalid ID' => [ '500', true, true ],
            'Invalid ID (rset)' => [ '500', false, true ],
        ];
    }

    /**
     * Tests that get_record() returns expected record.
     *
     * @dataProvider getRecordProvider
     */
    public function testGetRecordProvidesExpectedRecord(string $id, bool $assoc, bool $expError): void
    {
        $abook = $this->createAbook();
        $db = $this->db;

        // import contact belonging to different addressbook
        $db->importData('tests/unit/data/addressbookTest/db2.json');

        $saveDataRc = $abook->get_record($id, $assoc);

        if ($expError) {
            if (!$assoc) {
                $this->assertInstanceOf(\rcube_result_set::class, $saveDataRc);
                $this->assertSame($saveDataRc->count, 0);
                $this->assertSame($saveDataRc->first, 0);
                $this->assertCount(0, $saveDataRc->records);
                $this->assertNull($abook->get_result());
            }
            $logger = TestInfrastructure::logger();
            $logger->expectMessage('error', "Could not get contact $id");

            $abookErr = $abook->get_error();
            $this->assertIsArray($abookErr);
            $this->assertSame(rcube_addressbook::ERROR_SEARCH, $abookErr['type']);
            $this->assertStringContainsString("Could not get contact $id", (string) $abookErr['message']);
        } else {
            if (!$assoc) {
                $this->assertInstanceOf(\rcube_result_set::class, $saveDataRc);
                $this->assertSame($saveDataRc, $abook->get_result());
                $this->assertSame($saveDataRc->count, 1);
                $this->assertSame($saveDataRc->first, 0);
                $this->assertCount(1, $saveDataRc->records);
                $this->assertIsArray($saveDataRc->records[0]);
                $saveDataRc = $saveDataRc->records[0];
            }

            $this->assertIsArray($saveDataRc);
            // we must use a record with an URI photo to check it remains wrapped in a photo loader
            $this->assertInstanceOf(DelayedPhotoLoader::class, $saveDataRc['photo'], "photo not wrapped");

            $fn = "tests/unit/data/addressbookTest/c{$id}.json";
            $saveDataExp = Utils::readSaveDataFromJson($fn);
            Utils::compareSaveData($saveDataExp, $saveDataRc, "Unexpected record data $id");

            if (isset($saveDataExp['photo']) && empty($saveDataExp['photo'])) {
                $this->assertPhotoDownloadWarning();
            }
        }
    }

    /**
     * @return list<array{?string, int, list<string>}>
     */
    public function groupFilterProvider(): array
    {
        return [
            [ null, 0, ["506", "501", "502", "500", "503", "504"] ],
            [ "House", rcube_addressbook::SEARCH_PREFIX, ["501", "502", "500"] ],
            [ "ar", 0, ["501", "500"] ],
            [ "ar", rcube_addressbook::SEARCH_ALL, ["501", "500"] ],
            [ "Kings", rcube_addressbook::SEARCH_STRICT, ["503"] ],
            [ "House", rcube_addressbook::SEARCH_STRICT, [] ],
        ];
    }

    /**
     * Tests that groups matching the given filter are listed.
     *
     * @dataProvider groupFilterProvider
     * @param list<string> $expRecords
     */
    public function testListGroupsProvidesExpectedGroups(?string $filter, int $searchmode, array $expRecords): void
    {
        $abook = $this->createAbook();
        $groups = $abook->list_groups($filter, $searchmode);

        $this->assertNull($abook->get_error());
        $this->assertCount(count($expRecords), $groups);

        $lrOrder = array_column($groups, 'ID');
        $this->assertSame($expRecords, $lrOrder, "Group order mismatch");
        for ($i = 0; $i < count($expRecords); ++$i) {
            $id = $expRecords[$i];
            $fn = "tests/unit/data/addressbookTest/g{$id}.json";
            $saveDataExp = Utils::readSaveDataFromJson($fn);
            $saveDataRc = $groups[$i];
            Utils::compareSaveData($saveDataExp, $saveDataRc, "Unexpected record data $id");
        }
    }

    /** @return array<string, array{string,bool}> */
    public function getGroupProvider(): array
    {
        return [
            'Valid ID' => [ '500', false ],
            'Valid ID (different addressbook)' => [ '700', true ],
            'Invalid ID' => [ '50', true ],
        ];
    }

    /**
     * Tests that get_group() returns expected record.
     *
     * @dataProvider getGroupProvider
     */
    public function testGetGroupProvidesExpectedRecord(string $id, bool $expError): void
    {
        $abook = $this->createAbook();
        $db = $this->db;

        // import contact belonging to different addressbook
        $db->importData('tests/unit/data/addressbookTest/db2.json');

        $saveDataRc = $abook->get_group($id);

        if ($expError) {
            $this->assertNull($saveDataRc);

            $logger = TestInfrastructure::logger();
            $logger->expectMessage('error', "Could not get group");

            $abookErr = $abook->get_error();
            $this->assertIsArray($abookErr);
            $this->assertSame(rcube_addressbook::ERROR_SEARCH, $abookErr['type']);
            $this->assertStringContainsString("Could not get group $id", (string) $abookErr['message']);
        } else {
            $this->assertIsArray($saveDataRc);

            $fn = "tests/unit/data/addressbookTest/g{$id}.json";
            $saveDataExp = Utils::readSaveDataFromJson($fn);
            Utils::compareSaveData($saveDataExp, $saveDataRc, "Unexpected record data $id");
        }
    }

    /**
     * @return list<array{int,numeric-string,numeric-string,int}>
     */
    public function resyncDueProvider(): array
    {
        $now = time();
        $nowStr = (string) $now;

        return [
            // now  refresh  lastup  expDue
            [ $now, "3600", $nowStr, 3600 ],
            [ $now, "0", "0", -$now ],
            [ $now, "0", $nowStr, 0 ],
            [ $now, "1", $nowStr, 1 ],
            [ $now, "1", (string) ($now - 1), 0 ],
            [ $now, "1", (string) ($now - 2), -1 ],
        ];
    }

    /**
     * Tests getRefreshTime() and checkResyncDue().
     *
     * @psalm-param numeric-string $rt
     * @psalm-param numeric-string $lu
     * @dataProvider resyncDueProvider
     */
    public function testCheckResyncDueProvidesExpDelta(int $now, string $rt, string $lu, int $expDue): void
    {
        $abook = $this->createAbook([], ['refresh_time' => $rt, 'last_updated' => $lu]);
        $this->assertSame(intval($rt), $abook->getRefreshTime());

        // allow a tolerance of 1 second
        $nowDelta = time() - $now; // delta to now in data provider
        $expDue -= $nowDelta;
        $this->assertLessThanOrEqual(1, $expDue - $abook->checkResyncDue());
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
