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

namespace MStilkerich\Tests\RCMCardDAV\DBInteroperability;

use MStilkerich\CardDavClient\{Account,AddressbookCollection,WebDavResource,WebDavCollection};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\RCMCardDAV\DelayedPhotoLoader;
use MStilkerich\Tests\RCMCardDAV\{RcmAdapterStub,TestInfrastructure};
use PHPUnit\Framework\TestCase;
use carddav;

/**
 * @psalm-type AddressbookSettings = array{
 *   name?: string,
 *   username?: string,
 *   password?: string,
 *   url?: string,
 *   refresh_time?: string,
 *   active?: "1",
 *   use_categories?: "1",
 *   delete?: "1",
 *   resync?: "1"
 * }
 */
final class CarddavTest extends TestCase
{
    /** @var TestData */
    private static $testData;

    public static function setUpBeforeClass(): void
    {
        // Set a session password so that encryption/decryption of password with scheme "encrypted" works
        $rcube = \rcube::get_instance();
        $_SESSION['password'] = $rcube->encrypt('theRoundcubePassword');

        // Setup the roundcube database handle
        $dbsettings = TestInfrastructureDB::dbSettings();
        $db_dsnw = $dbsettings[0];
        $db = TestInfrastructureDB::initDatabase($db_dsnw);

        // Initializes the infrastructure (central Config class with test-specific implementation)
        TestInfrastructure::init($db);

        // Setup test data in the  DB
        self::$testData = new TestData(TestInfrastructureDB::getDbHandle());
        self::$testData->initDatabase();
    }

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    public function testProvidesCorrectListOfAddressbooks(): void
    {
        $plugin = new carddav(\rcube_plugin_api::get_instance());
        $plugin->init();

        $pseudoAbook = [ "id" => "foo", "name" => "foo", "groups" => true, "autocomplete" => true, "readonly" => true ];
        $res = $plugin->listAddressbooks(["sources" => ["foobar" => $pseudoAbook]]);

        $this->assertArrayHasKey("foobar", $res["sources"], "Other addressbooks not preserved in list");

        foreach (TestData::INITDATA["carddav_addressbooks"] as $idx => $abookrow) {
            $id = "carddav_" . self::$testData->getRowId("carddav_addressbooks", $idx);

            $this->assertArrayHasKey($id, $res["sources"], print_r($res, true));
            $this->assertSame($abookrow[0], $res["sources"][$id]["name"]);
            $this->assertFalse($res["sources"][$id]["readonly"]);
            $this->assertTrue($res["sources"][$id]["autocomplete"]);
            $this->assertTrue($res["sources"][$id]["groups"]);
        }
    }

    /**
     * @return array<string, list{?string, bool, ?string, bool}>
     */
    public static function contactPhotoProvider(): array
    {
        return [
            'URI photo, download disabled' => [ 'UriPhoto', false, 'placeholder.png', true ],
            'URI photo, download disabled, no placeholder' => [ 'UriPhoto', false, null, false ],
            'URI photo, download failed' => [ 'InvalidUriPhoto', true, 'placeholder.png', true ],
            'Inline photo' => [ 'InlinePhoto', false, 'placeholder.png', false ],
            'Photo of other addressbook' => [ null, false, 'placeholder.png', false ],
        ];
    }

    /**
     * Tests that the contact_photo hook provides the placeholder image if the photo of a contact is not available.
     *
     * @param ?string $vcf Base name of the VCard file in vcardImport; null to simulate a non-carddav contact
     * @param bool $allowDownload Whether photo download is allowed for the photo loader
     * @param ?string $placeholder The photo placeholder in the roundcube output environment
     * @param bool $expPlaceholder Whether the placeholder URL is expected to be set
     *
     * @dataProvider contactPhotoProvider
     */
    public function testPlaceholderProvidedForUnavailablePhoto(
        ?string $vcf,
        bool $allowDownload,
        ?string $placeholder,
        bool $expPlaceholder
    ): void {
        $_SESSION['user_id'] = 105;

        TestInfrastructure::init(TestInfrastructure::$infra->db());
        TestInfrastructure::$infra->setCache($this->createStub(\rcube_cache::class));
        $rcStub = TestInfrastructure::$infra->rc();
        $this->assertInstanceOf(RcmAdapterStub::class, $rcStub);
        if (isset($placeholder)) {
            $rcStub->outputEnv['photo_placeholder'] = $placeholder;
        }

        if (isset($vcf)) {
            $vcard = TestInfrastructure::readVCard("tests/Unit/data/vcardImport/$vcf.vcf");
            $davAbook = $this->createStub(AddressbookCollection::class);
            $davAbook->method('downloadResource')->willThrowException(new \Exception('download failed'));
            $photo = new DelayedPhotoLoader($vcard, $davAbook, $allowDownload);
        } else {
            $photo = '';
        }

        $plugin = new carddav(\rcube_plugin_api::get_instance());
        $p = $plugin->contactPhoto(['record' => ['photo' => $photo], 'data' => $photo]);

        if ($allowDownload) {
            TestInfrastructure::logger()->expectMessage(
                'warning',
                'downloadPhoto: Attempt to download photo from http://localhost/doesNotExist.jpg failed'
            );
        }

        if ($expPlaceholder) {
            $this->assertSame($placeholder, $p['url'] ?? null);
        } else {
            $this->assertArrayNotHasKey('url', $p);
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
