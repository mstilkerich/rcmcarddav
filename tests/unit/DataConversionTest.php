<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use PHasher;
use rcube_cache;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\{TestInfrastructure,TestLogger};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{DataConversion,DelayedPhotoLoader};
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;

/**
 * @psalm-import-type SaveData from DataConversion
 */
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
        $_SESSION['user_id'] = 105;
    }

    public function setUp(): void
    {
        $abook = $this->createStub(AddressbookCollection::class);
        $abook->method('downloadResource')->will($this->returnCallback(function (string $uri): array {
            if (preg_match(',^http://localhost/(.+),', $uri, $matches) && isset($matches[1])) {
                $filename = "tests/unit/data/srv/$matches[1]";
                $this->assertFileIsReadable($filename);
                return [ 'body' => file_get_contents($filename) ];
            }
            throw new \Exception("URI $uri not known to stub");
        }));
        $this->abook = $abook;

        $this->db = $this->createMock(Database::class);
        $this->cache = $this->createMock(\rcube_cache::class);

        TestInfrastructure::init($this->db);
        TestInfrastructure::$infra->setCache($this->cache);
    }

    public function tearDown(): void
    {
        // delete temporary files from image comparison
        $tmpimgs = glob("testreports/imgcomp_*");
        if (!empty($tmpimgs)) {
            foreach ($tmpimgs as $tmpimg) {
                unlink($tmpimg);
            }
        }

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
        $saveDataExp = $this->readSaveDataFromJson($jsonFile);
        $saveData = $dc->toRoundcube($vcard, $this->abook);
        self::compareSaveData($saveDataExp, $saveData, "Converted VCard does not result in expected roundcube data");
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
                $this->equalTo('typename,subtype'),
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
                $this->equalTo('typename,subtype'),
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
                $this->equalTo('typename,subtype'),
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
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([
                ["typename" => "email", "subtype" => "SpecialLabel"],
                ["typename" => "phone", "subtype" => "0"],
                ["typename" => "website", "subtype" => "0"]
            ]));
        $dc = new DataConversion("42");
        $vcardExpected = TestInfrastructure::readVCard($vcfFile);
        $saveData = $this->readSaveDataFromJson($jsonFile);
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
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([]));
        $dc = new DataConversion("42");
        $vcardExpected = TestInfrastructure::readVCard('tests/unit/data/singleTest/Errors.vcf');
        $saveData = $this->readSaveDataFromJson('tests/unit/data/singleTest/Errors.json');
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
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([
                ["typename" => "email", "subtype" => "SpecialLabel"],
                ["typename" => "email", "subtype" => "SpecialLabel2"]
            ]));

        $dc = new DataConversion("42");
        $vcardOriginal = TestInfrastructure::readVCard($vcfFile);
        $vcardExpected = TestInfrastructure::readVCard("$vcfFile.new");
        $saveData = $this->readSaveDataFromJson($jsonFile);

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
        $saveDataExpected = $this->readSaveDataFromJson("$basename.json");

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
                self::comparePhoto($saveDataExpected["photo"], $cacheObj['photo']);
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
        self::comparePhoto($saveDataExpected["photo"], (string) $saveData["photo"]);

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
        $saveDataExpected = $this->readSaveDataFromJson("$basename.json");

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
            self::comparePhoto($cachedPhotoData, (string) $saveData["photo"]);
        } else {
            $this->assertTrue(isset($saveDataExpected["photo"]));
            $this->assertIsString($saveDataExpected["photo"]);
            self::comparePhoto($saveDataExpected["photo"], (string) $saveData["photo"]);
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
        $saveDataExpected = $this->readSaveDataFromJson("$basename.json");

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
                self::comparePhoto($saveDataExpected["photo"], $cacheObj['photo']);
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
        self::comparePhoto($saveDataExpected["photo"], (string) $saveData["photo"]);

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
     * @return SaveData
     */
    private function readSaveDataFromJson(string $jsonFile): array
    {
        /** @var SaveData Assume our test samples are valid */
        $phpArray = TestInfrastructure::readJsonArray($jsonFile);

        // special handling for photo as we cannot encode binary data in json
        if (isset($phpArray['photo'])) {
            $this->assertIsString($phpArray['photo']);
            $photoFile = $phpArray['photo'];
            if (!empty($photoFile) && $photoFile[0] == '@') {
                $photoFile = substr($photoFile, 1);
                $phpArray['photo'] = TestInfrastructure::readFileRelative($photoFile, $jsonFile);
            }
        }
        return $phpArray;
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
                self::comparePhoto((string) $exp[$i]->getValue(), (string) $rc[$i]->getValue());
            } else {
                TestCase::assertEquals($exp[$i]->getValue(), $rc[$i]->getValue(), "Nodes $dbgid differ");
            }
        }
    }

    /**
     * Compares two roundcube save data arrays with special handling of photo.
     */
    private static function compareSaveData(array $saveDataExp, array $saveDataRc, string $msg): void
    {
        TestCase::assertSame(isset($saveDataExp['photo']), isset($saveDataRc['photo']));
        if (isset($saveDataExp['photo'])) {
            TestCase::assertTrue(isset($saveDataExp["photo"]));
            TestCase::assertIsString($saveDataExp["photo"]);
            self::comparePhoto($saveDataExp['photo'], (string) $saveDataRc['photo']);
            unset($saveDataExp['photo']);
            unset($saveDataRc['photo']);
        }
        TestCase::assertEquals($saveDataExp, $saveDataRc, $msg);
    }

    /**
     * Compares two photos given as binary strings.
     *
     * @param string $pExpStr The expected photo data
     * @param string $pRcStr The photo data produced by the test object
     */
    private static function comparePhoto(string $pExpStr, string $pRcStr): void
    {
        TestCase::assertTrue(function_exists('gd_info'), "php-gd required");

        // shortcut that also covers URI - if identical strings, save the comparison
        if (empty($pExpStr) || empty($pRcStr) || str_contains($pExpStr, "http")) {
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
