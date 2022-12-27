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

use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use rcube_cache;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\{TestInfrastructure,TestLogger};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{DataConversion,DelayedPhotoLoader,DelayedVCardExporter};
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;

final class DataConversionTest extends TestCase
{
    /** @var rcube_cache & MockObject */
    private $cache;

    /** @var Database & MockObject */
    private $db;

    /** @var AddressbookCollection */
    private $abook;

    public static function setUpBeforeClass(): void
    {
    }

    public function setUp(): void
    {
        $_SESSION['user_id'] = 105;
        $abook = $this->createStub(AddressbookCollection::class);
        $abook->method('downloadResource')->will($this->returnCallback([Utils::class, 'downloadResource']));
        $this->abook = $abook;

        $this->db = $this->createMock(Database::class);
        $this->cache = $this->createMock(\rcube_cache::class);

        TestInfrastructure::init($this->db);
        TestInfrastructure::$infra->setCache($this->cache);
    }

    public function tearDown(): void
    {
        Utils::cleanupTempImages();
        TestInfrastructure::logger()->reset();
    }

    /**
     * @return array<string, list<string>>
     */
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

    /**
     * @return array<string, list<string>>
     */
    public function vcardImportSamplesProvider(): array
    {
        return $this->vcardSamplesProvider('tests/unit/data/vcardImport');
    }

    /**
     * Tests the conversion of VCards to roundcube's internal address data representation.
     *
     * @dataProvider vcardImportSamplesProvider
     */
    public function testCorrectConversionOfVcardToRoundcube(string $vcfFile, string $jsonFile): void
    {
        $logger = TestInfrastructure::logger();
        $dc = new DataConversion("42");
        $vcard = TestInfrastructure::readVCard($vcfFile);
        $saveDataExp = Utils::readSaveDataFromJson($jsonFile);
        $saveData = $dc->toRoundcube($vcard, $this->abook);
        Utils::compareSaveData($saveDataExp, $saveData, "Converted VCard does not result in expected roundcube data");
        $this->assertPhotoDownloadWarning($logger, $vcfFile);
    }

    /**
     * Tests that a new custom label is inserted into the database.
     */
    public function testNewCustomLabelIsInsertedToDatabase(): void
    {
        $db = $this->db;

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "Speciallabel"] ]));
        $db->expects($this->once())
            ->method("insert")
            ->with(
                $this->equalTo("xsubtypes"),
                $this->equalTo(["typename", "subtype", "abook_id"]),
                $this->equalTo([["email", "SpecialLabel", "42"]])
            )
            ->will($this->returnValue("49"));

        $dc = new DataConversion("42");
        $vcard = TestInfrastructure::readVCard("tests/unit/data/vcardImport/XAbLabel.vcf");
        $dc->toRoundcube($vcard, $this->abook);
    }

    /**
     * Tests that known custom labels are offered in coltypes.
     */
    public function testKnownCustomLabelPresentedToRoundcube(): void
    {
        $db = $this->db;
        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "SpecialLabel"] ]));

        $dc = new DataConversion("42");
        $coltypes = $dc->getColtypes();
        $this->assertTrue(isset($coltypes["email"]["subtypes"]));
        $this->assertContains("SpecialLabel", $coltypes["email"]["subtypes"], "SpecialLabel not contained in coltypes");
    }

    /**
     * Tests that a known custom label is not inserted into the database again.
     */
    public function testKnownCustomLabelIsNotInsertedToDatabase(): void
    {
        $db = $this->db;

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "SpecialLabel"] ]));
        $db->expects($this->never())
            ->method("insert");

        $dc = new DataConversion("42");
        $vcard = TestInfrastructure::readVCard("tests/unit/data/vcardImport/XAbLabel.vcf");
        $dc->toRoundcube($vcard, $this->abook);
    }

    /**
     * @return array<string, list<string>>
     */
    public function vcardCreateSamplesProvider(): array
    {
        return $this->vcardSamplesProvider('tests/unit/data/vcardCreate');
    }

    /**
     * Tests that a new VCard is created from Roundcube data properly.
     *
     * @dataProvider vcardCreateSamplesProvider
     */
    public function testCorrectCreationOfVcardFromRoundcube(string $vcfFile, string $jsonFile): void
    {
        $db = $this->db;
        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([
                ["typename" => "email", "subtype" => "SpecialLabel"],
                ["typename" => "phone", "subtype" => "0"],
                ["typename" => "website", "subtype" => "0"]
            ]));
        $dc = new DataConversion("42");
        $vcardExpected = TestInfrastructure::readVCard($vcfFile);
        $saveData = Utils::readSaveDataFromJson($jsonFile);
        $result = $dc->fromRoundcube($saveData);

        $this->compareVCards($vcardExpected, $result, true);
    }

    /**
     * Tests that errors in the save data are properly reported and handled.
     *
     * The offending parts of the save data should be dropped and an error message logged.
     */
    public function testErroneousAttributesInSaveDataAreIgnored(): void
    {
        $logger = TestInfrastructure::logger();
        $db = $this->db;
        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([]));
        $dc = new DataConversion("42");
        $vcardExpected = TestInfrastructure::readVCard('tests/unit/data/singleTest/Errors.vcf');
        $saveData = Utils::readSaveDataFromJson('tests/unit/data/singleTest/Errors.json');
        $result = $dc->fromRoundcube($saveData);

        $this->compareVCards($vcardExpected, $result, true);

        // check emitted warnings
        $logger->expectMessage("error", "save data nickname must be string");
    }

    /**
     * @return array<string, list<string>>
     */
    public function vcardUpdateSamplesProvider(): array
    {
        return $this->vcardSamplesProvider('tests/unit/data/vcardUpdate');
    }

    /**
     * Tests that an existing VCard is updated from Roundcube data properly.
     *
     * @dataProvider vcardUpdateSamplesProvider
     */
    public function testCorrectUpdateOfVcardFromRoundcube(string $vcfFile, string $jsonFile): void
    {
        $db = $this->db;
        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([
                ["typename" => "email", "subtype" => "SpecialLabel"],
                ["typename" => "email", "subtype" => "SpecialLabel2"]
            ]));

        $dc = new DataConversion("42");
        $vcardOriginal = TestInfrastructure::readVCard($vcfFile);
        $vcardExpected = TestInfrastructure::readVCard("$vcfFile.new");
        $saveData = Utils::readSaveDataFromJson($jsonFile);

        $result = $dc->fromRoundcube($saveData, $vcardOriginal);
        $this->compareVCards($vcardExpected, $result, false);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public function cachePhotosSamplesProvider(): array
    {
        return [
            "InlinePhoto.vcf" => ["tests/unit/data/vcardImport/InlinePhoto", false, false],
            "UriPhotoCrop.vcf" => ["tests/unit/data/vcardImport/UriPhotoCrop", true, true],
            "InvalidUriPhoto.vcf" => ["tests/unit/data/vcardImport/InvalidUriPhoto", true, false],
            "UriPhoto.vcf" => ["tests/unit/data/vcardImport/UriPhoto", true, true],
        ];
    }

    /**
     * Tests whether a PHOTO is stored/not stored to the roundcube cache as expected.
     *
     * @dataProvider cachePhotosSamplesProvider
     */
    public function testNewPhotoIsStoredToCacheIfNeeded(string $basename, bool $getExp, bool $storeExp): void
    {
        $logger = TestInfrastructure::logger();
        $cache = $this->cache;

        $vcard = TestInfrastructure::readVCard("$basename.vcf");
        $this->assertInstanceOf(VObject\Property::class, $vcard->PHOTO);

        $key = "photo_105_" . md5((string) $vcard->UID);
        $saveDataExpected = Utils::readSaveDataFromJson("$basename.json");

        // photo should be stored to cache if not already stored in vcard in the final form
        if ($getExp) {
            // simulate cache miss
            $cache->expects($this->once())
                  ->method("get")
                  ->with($this->equalTo($key))
                  ->will($this->returnValue(null));
        } else {
            $cache->expects($this->never())->method("get");
        }

        if ($storeExp) {
            $checkPhotoFn = function (array $cacheObj) use ($vcard, $saveDataExpected): bool {
                $this->assertNotNull($cacheObj['photoPropMd5']);
                $this->assertNotNull($cacheObj['photo']);
                $this->assertIsString($cacheObj['photo']);
                $this->assertSame(md5($vcard->PHOTO->serialize()), $cacheObj['photoPropMd5']);
                $this->assertTrue(isset($saveDataExpected["photo"]));
                $this->assertIsString($saveDataExpected["photo"]);
                Utils::comparePhoto($saveDataExpected["photo"], $cacheObj['photo']);
                return true;
            };
            $cache->expects($this->once())
               ->method("set")
               ->with(
                   $this->equalTo($key),
                   $this->callback($checkPhotoFn)
               )
               ->will($this->returnValue(true));
        } else {
            $cache->expects($this->never())->method("set");
        }

        $dc = new DataConversion("42");
        $saveData = $dc->toRoundcube($vcard, $this->abook);
        $this->assertTrue(isset($saveData['photo']));
        $this->assertTrue(isset($saveDataExpected["photo"]));
        $this->assertIsString($saveDataExpected["photo"]);
        Utils::comparePhoto($saveDataExpected["photo"], (string) $saveData["photo"]);

        $this->assertPhotoDownloadWarning($logger, $basename);
    }

    /**
     * Tests that a photo is retrieved from the roundcube cache if available, skipping processing.
     *
     * @dataProvider cachePhotosSamplesProvider
     */
    public function testPhotoIsUsedFromCacheIfAvailable(string $basename, bool $getExp, bool $storeExp): void
    {
        $cache = $this->cache;

        // we use this file as some placeholder for cached data that is not used in any of the vcards photos
        $cachedPhotoData = file_get_contents("tests/unit/data/srv/pixel.jpg");
        $this->assertNotFalse($cachedPhotoData);

        $vcard = TestInfrastructure::readVCard("$basename.vcf");
        $this->assertInstanceOf(VObject\Property::class, $vcard->PHOTO);

        $key = "photo_105_" . md5((string) $vcard->UID);
        $saveDataExpected = Utils::readSaveDataFromJson("$basename.json");

        if ($getExp) {
            // simulate cache hit
            $cache->expects($this->once())
                  ->method("get")
                  ->with($this->equalTo($key))
                  ->will(
                      $this->returnValue([
                          'photoPropMd5' => md5($vcard->PHOTO->serialize()),
                          'photo' => $cachedPhotoData
                      ])
                  );
        } else {
            $cache->expects($this->never())->method("get");
        }

        // no cache update expected
        $cache->expects($this->never())->method("set");

        $dc = new DataConversion("42");
        $saveData = $dc->toRoundcube($vcard, $this->abook);

        $this->assertTrue(isset($saveData['photo']));
        if ($getExp) {
            Utils::comparePhoto($cachedPhotoData, (string) $saveData["photo"]);

            // If we fetch it a second time, we should hit the internal cache of the photo loader and no processing
            // occurs again
            $cache->expects($this->never())->method("get");
            Utils::comparePhoto($cachedPhotoData, (string) $saveData["photo"]);
        } else {
            $this->assertTrue(isset($saveDataExpected["photo"]));
            $this->assertIsString($saveDataExpected["photo"]);
            Utils::comparePhoto($saveDataExpected["photo"], (string) $saveData["photo"]);
        }
    }

    /**
     * Tests that an outdated photo in the cache is replaced by a newly processed one.
     *
     * @dataProvider cachePhotosSamplesProvider
     */
    public function testOutdatedPhotoIsReplacedInCache(string $basename, bool $getExp, bool $storeExp): void
    {
        $logger = TestInfrastructure::logger();
        $cache = $this->cache;

        // we use this file as some placeholder for cached data that is not used in any of the vcards photos
        $cachedPhotoData = file_get_contents("tests/unit/data/srv/pixel.jpg");
        $this->assertNotFalse($cachedPhotoData);

        $vcard = TestInfrastructure::readVCard("$basename.vcf");
        $this->assertInstanceOf(VObject\Property::class, $vcard->PHOTO);

        $key = "photo_105_" . md5((string) $vcard->UID);
        $saveDataExpected = Utils::readSaveDataFromJson("$basename.json");

        if ($getExp) {
            // simulate cache hit with non-matching md5sum
            $cache->expects($this->once())
                  ->method("get")
                  ->with($this->equalTo($key))
                  ->will(
                      $this->returnValue([
                          'photoPropMd5' => md5("foo"), // will not match the current md5
                          'photo' => $cachedPhotoData
                      ])
                  );

            // expect that the old record is purged
            $cache->expects($this->once())
                  ->method("remove")
                  ->with($this->equalTo($key));
        } else {
            $cache->expects($this->never())->method("get");
        }

        // a new record should be inserted if photo requires caching
        if ($storeExp) {
            $checkPhotoFn = function (array $cacheObj) use ($vcard, $saveDataExpected): bool {
                $this->assertNotNull($cacheObj['photoPropMd5']);
                $this->assertNotNull($cacheObj['photo']);
                $this->assertIsString($cacheObj['photo']);
                $this->assertSame(md5($vcard->PHOTO->serialize()), $cacheObj['photoPropMd5']);
                $this->assertTrue(isset($saveDataExpected["photo"]));
                $this->assertIsString($saveDataExpected["photo"]);
                Utils::comparePhoto($saveDataExpected["photo"], $cacheObj['photo']);
                return true;
            };
            $cache->expects($this->once())
               ->method("set")
               ->with(
                   $this->equalTo($key),
                   $this->callback($checkPhotoFn)
               )
               ->will($this->returnValue(true));
        } else {
            $cache->expects($this->never())->method("set");
        }

        $dc = new DataConversion("42");
        $saveData = $dc->toRoundcube($vcard, $this->abook);
        $this->assertTrue(isset($saveData['photo']));
        $this->assertTrue(isset($saveDataExpected["photo"]));
        $this->assertIsString($saveDataExpected["photo"]);
        Utils::comparePhoto($saveDataExpected["photo"], (string) $saveData["photo"]);

        $this->assertPhotoDownloadWarning($logger, $basename);
    }

    /**
     * Tests that a delayed photo loader handles vcards lacking a PHOTO property.
     */
    public function testPhotoloaderHandlesVcardWithoutPhotoProperty(): void
    {
        $vcard = TestInfrastructure::readVCard("tests/unit/data/vcardImport/AllAttr.vcf");
        $this->assertNull($vcard->PHOTO);

        $proxy = new DelayedPhotoLoader($vcard, $this->abook);
        $this->assertEquals("", $proxy);
    }

    /**
     * Tests that DelayedPhotoLoader logs an error in case rcube cache usage is attempted without user logged on.
     */
    public function testPhotoloaderHandlesUnauthenticatedUsageError(): void
    {
        $logger = TestInfrastructure::logger();
        unset($_SESSION['user_id']);

        $vcard = TestInfrastructure::readVCard("tests/unit/data/vcardImport/UriPhoto.vcf");
        $this->assertNotNull($vcard->PHOTO);
        $proxy = new DelayedPhotoLoader($vcard, $this->abook);

        $this->assertEquals("", $proxy);
        $logger->expectMessage("error", "determineCacheKey: user must be logged on to use photo cache");
    }

    /**
     * Tests that DelayedPhotoLoader logs a warning if it encounters an unsupported PHOTO URI scheme
     */
    public function testPhotoloaderHandlesUnknownUriScheme(): void
    {
        $logger = TestInfrastructure::logger();

        $vcard = TestInfrastructure::readVCard("tests/unit/data/vcardImport/UriPhoto.vcf");
        $this->assertNotNull($vcard->PHOTO);
        $vcard->PHOTO->setValue('ftp://localhost/raven.jpg');
        $proxy = new DelayedPhotoLoader($vcard, $this->abook);

        $this->assertEquals("", $proxy);
        $logger->expectMessage("warning", "Unsupported URI scheme ftp for PHOTO property");
    }

    /**
     * Tests that the function properly reports single-value attributes.
     */
    public function testSinglevalueAttributesReportedAsSuch(): void
    {
        $dc = new DataConversion("42");

        $knownSingle  = ['name', 'firstname', 'surname', 'middlename', 'prefix', 'suffix', 'nickname', 'jobtitle',
            'organization', 'department', 'assistant', 'manager', 'gender', 'maidenname', 'spouse', 'birthday',
            'anniversary', 'notes', 'photo'];

        foreach ($knownSingle as $singleAttr) {
            $this->assertFalse($dc->isMultivalueProperty($singleAttr), "Attribute $singleAttr expected to be single");
        }
    }

    /**
     * Tests that the data converter properly reports multi-value attributes.
     */
    public function testMultivalueAttributesReportedAsSuch(): void
    {
        $dc = new DataConversion("42");

        $knownMulti  = ['email', 'phone', 'address', 'website', 'im'];

        foreach ($knownMulti as $multiAttr) {
            $this->assertTrue($dc->isMultivalueProperty($multiAttr), "Attribute $multiAttr expected to be multi");
        }
    }

    /**
     * Tests that the data converter throws an exception when asked for the type of an unknown attribute.
     */
    public function testExceptionWhenAskedForTypeOfUnknownAttribute(): void
    {
        $dc = new DataConversion("42");

        $this->expectExceptionMessage('not a known roundcube contact property');
        $dc->isMultivalueProperty("unknown");
    }

    /**
     * @return array<string, list<string>>
     */
    public function vcardExportSamplesProvider(): array
    {
        return $this->vcardSamplesProvider('tests/unit/data/vcardExport');
    }

    /**
     * Tests that a VCard converted to roundcube can be properly exported as vcard again.
     *
     *
     * @dataProvider vcardExportSamplesProvider
     */
    public function testCorrectExportOfVcardFromRoundcube(string $vcfFile, string $_jsonFileNotExisting): void
    {
        $logger = TestInfrastructure::logger();
        $db = $this->db;
        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo(['typename', 'subtype']),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([
                ["typename" => "email", "subtype" => "SpecialLabel"],
            ]));

        $dc = new DataConversion("42");
        $vcardOrig = TestInfrastructure::readVCard($vcfFile);
        $saveData = $dc->toRoundcube($vcardOrig, $this->abook);

        $this->assertIsString($saveData["vcard"] ?? null);
        $vcardOrig = $saveData["_carddav_vcard"] ?? null;
        $this->assertInstanceOf(VCard::class, $vcardOrig);

        $vcfExported = DataConversion::exportVCard($vcardOrig, $saveData);
        $this->assertPhotoDownloadWarning($logger, $vcfFile);
        $vcardExported = VObject\Reader::read($vcfExported);
        $this->assertInstanceOf(VCard::class, $vcardExported);

        $vcardExpected = TestInfrastructure::readVCard("$vcfFile.exported");
        $this->compareVCards($vcardExpected, $vcardExported, false);
    }

    private function compareVCards(VCard $vcardExpected, VCard $vcardRoundcube, bool $isNew): void
    {
        // These attributes are dynamically created / updated and therefore cannot be statically compared
        $noCompare = [ 'REV', 'PRODID' ];

        if ($isNew) {
            // new VCard will have UID assigned by carddavclient lib on store
            $noCompare[] = 'UID';
        }

        foreach ($noCompare as $property) {
            unset($vcardExpected->{$property});
            unset($vcardRoundcube->{$property});
        }

        /** @var VObject\Property[] */
        $propsExp = $vcardExpected->children();
        $propsExp = self::groupNodesByName($propsExp);
        /** @var VObject\Property[] */
        $propsRC = $vcardRoundcube->children();
        $propsRC = self::groupNodesByName($propsRC);

        // compare
        foreach ($propsExp as $name => $props) {
            TestCase::assertArrayHasKey($name, $propsRC, "Expected property $name missing from test vcard");
            self::compareNodeList("Property $name", $props, $propsRC[$name]);

            for ($i = 0; $i < count($props); ++$i) {
                TestCase::assertEqualsIgnoringCase(
                    $props[$i]->group,
                    $propsRC[$name][$i]->group,
                    "Property group name differs"
                );
                /** @psalm-var VObject\Parameter[] */
                $paramExp = $props[$i]->parameters();
                $paramExp = self::groupNodesByName($paramExp);
                /** @psalm-var VObject\Parameter[] */
                $paramRC = $propsRC[$name][$i]->parameters();
                $paramRC = self::groupNodesByName($paramRC);
                foreach ($paramExp as $pname => $params) {
                    self::compareNodeList("Parameter $name/$pname", $params, $paramRC[$pname]);
                    unset($paramRC[$pname]);
                }
                TestCase::assertEmpty($paramRC, "Prop $name has extra params: " . implode(", ", array_keys($paramRC)));
            }
            unset($propsRC[$name]);
        }

        TestCase::assertEmpty($propsRC, "VCard has extra properties: " . implode(", ", array_keys($propsRC)));
    }

    /**
     * Groups a list of VObject\Node by node name.
     *
     * @template T of VObject\Property|VObject\Parameter
     *
     * @param T[] $nodes
     * @return array<string, list<T>> Array with node names as keys, and arrays of nodes by that name as values.
     */
    private static function groupNodesByName(array $nodes): array
    {
        $res = [];
        foreach ($nodes as $n) {
            $res[$n->name][] = $n;
        }

        return $res;
    }

    /**
     * Compares to lists of VObject nodes with the same name.
     *
     * This can be two lists of property instances (e.g. EMAIL, TEL) or two lists of parameters (e.g. TYPE).
     *
     * @param string $dbgid Some string to identify property/parameter for error messages
     * @param VObject\Property[]|VObject\Parameter[] $exp Expected list of nodes
     * @param VObject\Property[]|VObject\Parameter[] $rc  List of nodes in the VCard produces by rcmcarddav
     */
    private static function compareNodeList(string $dbgid, array $exp, array $rc): void
    {
        TestCase::assertCount(count($exp), $rc, "Different amount of $dbgid");

        for ($i = 0; $i < count($exp); ++$i) {
            if ($dbgid == "Property PHOTO") {
                Utils::comparePhoto((string) $exp[$i]->getValue(), (string) $rc[$i]->getValue());
            } else {
                TestCase::assertEquals($exp[$i]->getValue(), $rc[$i]->getValue(), "Nodes $dbgid differ");
            }
        }
    }

    /**
     * Asserts that a warning message concerning failure to download the photo has been issued for test cases that use
     * the InvalidUriPhoto.vcf data set.
     *
     * @param string Name of the vcffile used by the test. Assertion is only done if it contains InvalidUriPhoto.
     */
    private function assertPhotoDownloadWarning(TestLogger $logger, string $vcffile): void
    {
        if (str_contains($vcffile, 'InvalidUriPhoto')) {
            $logger->expectMessage(
                'warning',
                'downloadPhoto: Attempt to download photo from http://localhost/doesNotExist.jpg failed'
            );
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
