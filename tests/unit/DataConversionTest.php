<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{Database,DataConversion,DelayedPhotoLoader};

final class DataConversionTest extends TestCase
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
        [ $logger, $db, $cache, $abook ] = $this->initStubs();

        $dc = new DataConversion("42", $db, $cache, $logger);
        $vcard = $this->readVCard($vcfFile);
        $saveDataExpected = $this->readJsonArray($jsonFile);
        $saveData = $dc->toRoundcube($vcard, $abook);
        $this->assertEquals($saveDataExpected, $saveData, "Converted VCard does not result in expected roundcube data");
    }

    /**
     * Tests that a new custom label is inserted into the database.
     */
    public function testNewCustomLabelIsInsertedToDatabase(): void
    {
        [ $logger, $db, $cache, $abook ] = $this->initStubs();

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

        $dc = new DataConversion("42", $db, $cache, $logger);
        $vcard = $this->readVCard("tests/unit/data/vcardImport/XAbLabel.vcf");
        $dc->toRoundcube($vcard, $abook);
    }

    /**
     * Tests that known custom labels are offered in coltypes.
     */
    public function testKnownCustomLabelPresentedToRoundcube(): void
    {
        [ $logger, $db, $cache ] = $this->initStubs();

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "SpecialLabel"] ]));

        $dc = new DataConversion("42", $db, $cache, $logger);
        $coltypes = $dc->getColtypes();
        $this->assertContains("SpecialLabel", $coltypes["email"]["subtypes"], "SpecialLabel not contained in coltypes");
    }

    /**
     * Tests that a known custom label is not inserted into the database again.
     */
    public function testKnownCustomLabelIsNotInsertedToDatabase(): void
    {
        [ $logger, $db, $cache, $abook ] = $this->initStubs();

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

        $dc = new DataConversion("42", $db, $cache, $logger);
        $vcard = $this->readVCard("tests/unit/data/vcardImport/XAbLabel.vcf");
        $dc->toRoundcube($vcard, $abook);
    }

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
        [ $logger, $db, $cache ] = $this->initStubs();

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo(["abook_id" => "42"]),
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "SpecialLabel"] ]));
        $dc = new DataConversion("42", $db, $cache, $logger);
        $vcardExpected = $this->readVCard($vcfFile);
        $saveData = $this->readJsonArray($jsonFile);
        $result = $dc->fromRoundcube($saveData);

        $this->compareVCards($vcardExpected, $result);
    }

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
        [ $logger, $db, $cache ] = $this->initStubs();

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

        $dc = new DataConversion("42", $db, $cache, $logger);
        $vcardOriginal = $this->readVCard($vcfFile);
        $vcardExpected = $this->readVCard("$vcfFile.new");
        $saveData = $this->readJsonArray($jsonFile);

        $result = $dc->fromRoundcube($saveData, $vcardOriginal);
        $this->compareVCards($vcardExpected, $result);
    }

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
        [ $logger, $db, $cache, $abook ] = $this->initStubs();

        $vcard = $this->readVCard("$basename.vcf");
        $this->assertInstanceOf(VObject\Property::class, $vcard->PHOTO);

        $key = "photo_105_" . md5((string) $vcard->UID);
        $saveDataExpected = $this->readJsonArray("$basename.json");


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
            $cache->expects($this->once())
               ->method("set")
               ->with(
                   $this->equalTo($key),
                   $this->equalTo([
                       'photoPropMd5' => md5($vcard->PHOTO->serialize()),
                       'photo' => $saveDataExpected["photo"]
                   ])
               )
               ->will($this->returnValue(true));
        } else {
            $cache->expects($this->never())->method("set");
        }

        $dc = new DataConversion("42", $db, $cache, $logger);
        $saveData = $dc->toRoundcube($vcard, $abook);
        $this->assertEquals($saveDataExpected["photo"], $saveData["photo"]);
    }

    /**
     * Tests that a photo is retrieved from the roundcube cache if available, skipping processing.
     *
     * @dataProvider cachePhotosSamplesProvider
     */
    public function testPhotoIsUsedFromCacheIfAvailable(string $basename, bool $getExp, bool $storeExp): void
    {
        [ $logger, $db, $cache, $abook ] = $this->initStubs();

        // we use this file as some placeholder for cached data that is not used in any of the vcards photos
        $cachedPhotoData = file_get_contents("tests/unit/data/srv/pixel.jpg");
        $this->assertNotFalse($cachedPhotoData);

        $vcard = $this->readVCard("$basename.vcf");
        $this->assertInstanceOf(VObject\Property::class, $vcard->PHOTO);

        $key = "photo_105_" . md5((string) $vcard->UID);
        $saveDataExpected = $this->readJsonArray("$basename.json");

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

        $dc = new DataConversion("42", $db, $cache, $logger);
        $saveData = $dc->toRoundcube($vcard, $abook);

        if ($getExp) {
            $this->assertEquals($cachedPhotoData, $saveData["photo"]);
        } else {
            $this->assertEquals($saveDataExpected["photo"], $saveData["photo"]);
        }
    }

    /**
     * Tests that an outdated photo in the cache is replaced by a newly processed one.
     *
     * @dataProvider cachePhotosSamplesProvider
     */
    public function testOutdatedPhotoIsReplacedInCache(string $basename, bool $getExp, bool $storeExp): void
    {
        [ $logger, $db, $cache, $abook ] = $this->initStubs();

        // we use this file as some placeholder for cached data that is not used in any of the vcards photos
        $cachedPhotoData = file_get_contents("tests/unit/data/srv/pixel.jpg");
        $this->assertNotFalse($cachedPhotoData);

        $vcard = $this->readVCard("$basename.vcf");
        $this->assertInstanceOf(VObject\Property::class, $vcard->PHOTO);

        $key = "photo_105_" . md5((string) $vcard->UID);
        $saveDataExpected = $this->readJsonArray("$basename.json");

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
            $cache->expects($this->once())
               ->method("set")
               ->with(
                   $this->equalTo($key),
                   $this->equalTo([
                       'photoPropMd5' => md5($vcard->PHOTO->serialize()),
                       'photo' => $saveDataExpected["photo"]
                   ])
               )
               ->will($this->returnValue(true));
        } else {
            $cache->expects($this->never())->method("set");
        }

        $dc = new DataConversion("42", $db, $cache, $logger);
        $saveData = $dc->toRoundcube($vcard, $abook);
        $this->assertEquals($saveDataExpected["photo"], $saveData["photo"]);
    }

    /**
     * Tests that a delayed photo loader handles vcards lacking a PHOTO property.
     */
    public function testPhotoloaderHandlesVcardWithoutPhotoProperty(): void
    {
        [ $logger, $_db, $cache, $abook ] = $this->initStubs();

        $vcard = $this->readVCard("tests/unit/data/vcardImport/AllAttr.vcf");
        $this->assertNull($vcard->PHOTO);

        $proxy = new DelayedPhotoLoader($vcard, $abook, $cache, $logger);
        $this->assertEquals("", $proxy);
    }

    private function initStubs(): array
    {
        $logger = TestInfrastructure::$logger;
        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $abook = $this->createStub(AddressbookCollection::class);
        $abook->method('downloadResource')->will($this->returnCallback(function (string $uri): array {
            if (preg_match(',^http://localhost/(.+),', $uri, $matches) && isset($matches[1])) {
                $filename = "tests/unit/data/srv/$matches[1]";
                $this->assertFileIsReadable($filename);
                return [ 'body' => file_get_contents($filename) ];
            }
            throw new \Exception("URI $uri not known to stub");
        }));
        $db = $this->createMock(Database::class);
        $cache = $this->createMock(\rcube_cache::class);

        return [ $logger, $db, $cache, $abook ];
    }

    private function readVCard(string $vcfFile): VCard
    {
        $this->assertFileIsReadable($vcfFile);
        $vcard = VObject\Reader::read(fopen($vcfFile, 'r'));
        $this->assertInstanceOf(VCard::class, $vcard);
        return $vcard;
    }

    private function readJsonArray(string $jsonFile): array
    {
        $this->assertFileIsReadable($jsonFile);
        $json = file_get_contents($jsonFile);
        $this->assertNotFalse($json);
        $phpArray = json_decode($json, true);
        $this->assertTrue(is_array($phpArray));

        // special handling for photo as we cannot encode binary data in json
        if (isset($phpArray['photo'])) {
            $photoFile = $phpArray['photo'];
            if ($photoFile[0] == '@') {
                $photoFile = substr($photoFile, 1);
                $comp = pathinfo($jsonFile);
                $photoFile = "{$comp["dirname"]}/$photoFile";
                $this->assertFileIsReadable($photoFile);
                $phpArray['photo'] = file_get_contents($photoFile);
            }
        }
        return $phpArray;
    }

    private function compareVCardsOld(VCard $vcardExpected, VCard $vcardRoundcube): void
    {
        // this is needed to get a VCard object that is identical in structure to the one parsed
        // from file. There is differences with respect to the parent attributes and concerning whether single value
        // properties have an array setting or a plain value. We want to use phpunits equal assertion, so the data
        // structures need to be identical
        $vcardRoundcube = VObject\Reader::read($vcardRoundcube->serialize());

        // These attributes are dynamically created / updated and therefore must not be compared with the static
        // expected card
        $noCompare = [ 'REV', 'UID', 'PRODID' ];
        foreach ($noCompare as $property) {
            unset($vcardExpected->{$property});
            unset($vcardRoundcube->{$property});
        }

        $this->assertEquals($vcardExpected, $vcardRoundcube, "Created VCard does not match roundcube data");
    }

    private function compareVCards(VCard $vcardExpected, VCard $vcardRoundcube): void
    {
        // FIXME for now we call the old comparision function in addition. It is known not to work with sabre/vobject 3,
        //       but we'll keep it for a while to detect whether the new comparison code misses any relevant property.
        $this->compareVCardsOld($vcardExpected, $vcardRoundcube);

        // These attributes are dynamically created / updated and therefore must not be compared with the static
        // expected card
        $noCompare = [ 'REV', 'UID', 'PRODID' ];
        foreach ($noCompare as $property) {
            unset($vcardExpected->{$property});
            unset($vcardRoundcube->{$property});
        }

        /** @var VObject\Property[] */
        $propsExp = $vcardExpected->children();
        $propsExp = $this->groupNodesByName($propsExp);
        /** @var VObject\Property[] */
        $propsRC = $vcardRoundcube->children();
        $propsRC = $this->groupNodesByName($propsRC);

        // compare
        foreach ($propsExp as $name => $props) {
            $this->assertNotNull($propsRC[$name], "Property $name not available in created data");
            $this->compareNodeList("Property $name", $props, $propsRC[$name]);

            for ($i = 0; $i < count($props); ++$i) {
                $this->assertSame($props[$i]->group, $propsRC[$name][$i]->group, "Property group name differs");
                $paramExp = $this->groupNodesByName($props[$i]->parameters());
                $paramRC = $this->groupNodesByName($propsRC[$name][$i]->parameters());
                foreach ($paramExp as $pname => $params) {
                    $this->assertNotNull($paramRC[$pname], "Parameter $name/$pname not available in created data");
                    $this->compareNodeList("Parameter $name/$pname", $params, $paramRC[$pname]);
                    unset($paramRC[$pname]);
                }
                $this->assertEmpty($paramRC, "Prop $name has extra parameters: " . implode(", ", array_keys($paramRC)));
            }
            unset($propsRC[$name]);
        }

        $this->assertEmpty($propsRC, "VCard has extra properties: " . implode(", ", array_keys($propsRC)));
    }

    /**
     * Groups a list of VObject\Node by node name.
     *
     * @template T of VObject\Property|VObject\Parameter
     *
     * @param T[] $nodes
     * @return array<string, T[]> Array with node names as keys, and arrays of nodes by that name as values.
     */
    private function groupNodesByName(array $nodes): array
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
    private function compareNodeList(string $dbgid, array $exp, array $rc): void
    {
        $this->assertCount(count($exp), $rc, "Different amount of $dbgid");

        for ($i = 0; $i < count($exp); ++$i) {
            $this->assertEquals($exp[$i]->getValue(), $rc[$i]->getValue(), "Nodes $dbgid differ");
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
