<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use Psr\Log\LoggerInterface;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\CardDavClient\{Account,AddressbookCollection};
use MStilkerich\CardDavAddressbook4Roundcube\{Database,DataConversion};

final class DataConversionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        TestInfrastructure::init();
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
     * Tests that our UNIQUE constraints in the database use case-insensitive semantics on the included key components.
     *
     * @dataProvider vcardImportSamplesProvider
     */
    public function testCorrectConversionOfVcardToRoundcube(string $vcfFile, string $jsonFile): void
    {
        [ $logger, $db, $abook ] = $this->initStubs();

        $dc = new DataConversion("42", $db, $logger);
        $vcard = $this->readVCard($vcfFile);
        $saveDataExpected = $this->readJsonArray($jsonFile);
        $result = $dc->toRoundcube($vcard, $abook);
        $saveData = $result["save_data"];
        $this->assertEquals($saveDataExpected, $saveData, "Converted VCard does not result in expected roundcube data");
    }

    /**
     * Tests that a new custom label is inserted into the database.
     */
    public function testNewCustomLabelIsInsertedToDatabase(): void
    {
        [ $logger, $db, $abook ] = $this->initStubs();

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo("42"),
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes'),
                $this->equalTo(false),
                $this->equalTo('abook_id')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "Speciallabel"] ]));
        $db->expects($this->once())
            ->method("insert")
            ->with(
                $this->equalTo("xsubtypes"),
                $this->equalTo(["typename", "subtype", "abook_id"]),
                $this->equalTo(["email", "SpecialLabel", "42"])
            )
            ->will($this->returnValue("49"));

        $dc = new DataConversion("42", $db, $logger);
        $vcard = $this->readVCard("tests/unit/data/vcardImport/XAbLabel.vcf");
        $dc->toRoundcube($vcard, $abook);
    }

    /**
     * Tests that known custom labels are offered in coltypes.
     */
    public function testKnownCustomLabelPresentedToRoundcube(): void
    {
        [ $logger, $db ] = $this->initStubs();

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo("42"),
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes'),
                $this->equalTo(false),
                $this->equalTo('abook_id')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "SpecialLabel"] ]));

        $dc = new DataConversion("42", $db, $logger);
        $coltypes = $dc->getColtypes();
        $this->assertContains("SpecialLabel", $coltypes["email"]["subtypes"], "SpecialLabel not contained in coltypes");
    }

    /**
     * Tests that a known custom label is not inserted into the database again.
     */
    public function testKnownCustomLabelIsNotInsertedToDatabase(): void
    {
        [ $logger, $db, $abook ] = $this->initStubs();

        $db->expects($this->once())
            ->method("get")
            ->with(
                $this->equalTo("42"),
                $this->equalTo('typename,subtype'),
                $this->equalTo('xsubtypes'),
                $this->equalTo(false),
                $this->equalTo('abook_id')
            )
            ->will($this->returnValue([ ["typename" => "email", "subtype" => "SpecialLabel"] ]));
        $db->expects($this->never())
            ->method("insert");

        $dc = new DataConversion("42", $db, $logger);
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
        [ $logger, $db ] = $this->initStubs();

        $dc = new DataConversion("42", $db, $logger);
        $vcardExpected = $this->readVCard($vcfFile);
        $saveData = $this->readJsonArray($jsonFile);
        $result = $dc->fromRoundcube($saveData);

        $serializedResult = $result->serialize();
        $result = VObject\Reader::read($serializedResult);

        $noCompare = [ 'REV', 'UID', 'PRODID', 'X-ABSHOWAS', 'X-ADDRESSBOOKSERVER-KIND' ];
        foreach ($noCompare as $property) {
            unset($vcardExpected->{$property});
            unset($result->{$property});
        }

        $this->assertEquals($vcardExpected, $result, "Created VCard does not match roundcube data");
    }

    private function initStubs(): array
    {
        $logger = TestInfrastructure::$logger;
        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $abook = $this->createStub(AddressbookCollection::class);
        $db = $this->createMock(Database::class);

        return [ $logger, $db, $abook ];
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
        return $phpArray;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
