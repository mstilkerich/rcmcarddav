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

    public function vcardSamplesProvider(): array
    {
        $vcfFiles = glob('tests/unit/data/*.vcf');

        $result = [];
        foreach ($vcfFiles as $vcfFile) {
            $comp = pathinfo($vcfFile);
            $jsonFile = "{$comp["dirname"]}/{$comp["filename"]}.json";
            $result[$comp["basename"]] = [ $vcfFile, $jsonFile ];
        }

        return $result;
    }

    /**
     * Tests that our UNIQUE constraints in the database use case-insensitive semantics on the included key components.
     *
     * @dataProvider vcardSamplesProvider
     */
    public function testCorrectConversionOfVcardToRoundcube(string $vcfFile, string $jsonFile): void
    {
        $this->assertFileIsReadable($vcfFile);
        $this->assertFileIsReadable($jsonFile);

        $json = file_get_contents($jsonFile);
        $this->assertNotFalse($json);
        $saveDataExpected = json_decode($json, true);
        $this->assertTrue(is_array($saveDataExpected));

        $vcard = VObject\Reader::read(fopen($vcfFile, 'r'));
        $this->assertInstanceOf(VCard::class, $vcard);

        $abook = $this->createStub(AddressbookCollection::class);
        $db = $this->createMock(Database::class);

        $logger = TestInfrastructure::$logger;
        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $dc = new DataConversion("abookId", $db, $logger);

        $result = $dc->toRoundcube($vcard, $abook);
        $saveData = $result["save_data"];
        $this->assertEquals($saveDataExpected, $saveData, "Converted VCard does not result in expected roundcube data");
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
