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

namespace MStilkerich\Tests\RCMCardDAV\Unit;

use Exception;
use MStilkerich\RCMCardDAV\Db\Database;
use MStilkerich\Tests\RCMCardDAV\TestInfrastructure;
use PHPUnit\Framework\TestCase;
use MStilkerich\RCMCardDAV\Frontend\{AdminSettings,Utils};

/**
 * @psalm-import-type PasswordStoreScheme from AdminSettings
 */
final class FrontendUtilsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
    }

    public function setUp(): void
    {
        $db = $this->createMock(Database::class);
        TestInfrastructure::init($db);
    }

    public function tearDown(): void
    {
        TestInfrastructure::logger()->reset();
    }

    /**
     * @return list<array{string,?int}>
     */
    public static function timeStringProvider(): array
    {
        return [
            [ "01:00:00", 3600 ],
            [ "1:0:0", 3600 ],
            [ "1", 3600 ],
            [ "00:01", 60 ],
            [ "00:00:01", 1 ],
            [ "01:02:03", 3723 ],
            [ "", null ],
            [ "blah", null ],
            [ "1:2:3:4", null ],
            [ "1:60:01", null ],
            [ "1:60:010", null ],
            [ "1:00:60", null ],
            [ "1:00:500", null ],
            [ "100", 360000 ],
        ];
    }

    /**
     * Tests that parseTimeParameter() converts time strings properly.
     *
     * @dataProvider timeStringProvider
     */
    public function testTimeParameterParsedCorrectly(string $timestr, ?int $seconds): void
    {
        if (isset($seconds)) {
            $this->assertSame($seconds, Utils::parseTimeParameter($timestr));
        } else {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("Time string $timestr could not be parsed");
            Utils::parseTimeParameter($timestr);
        }
    }

    /**
     * @return array<string, list{string,string,string}>
     */
    public static function encryptedPasswordsProvider(): array
    {
        return [
            'Cleartext password' => ['', '(l3art3x7!', '(l3art3x7!'],
            'Base64-encoded password' => ['', '{BASE64}YjRzZTY0IGVuYzBkZWQgUDQ1NXcwcmQh', 'b4se64 enc0ded P455w0rd!'],
            'DES-EDE3-CBC encrypted password (roundcube des_key)' => [
                'DES-EDE3-CBC',
                '{DES_KEY}iV+mfXdzDqpn/rjyah/i0u6xOOC7jBpf5DS39hVixAA=',
                '3n(2ypted p4ssw0rd'
            ],
            'DES-EDE3-CBC encrypted password (IMAP password)' => [
                'DES-EDE3-CBC',
                '{ENCRYPTED}Xfid9p4nu69npROnbKS4PrEUTkTPkKcG+UXecLAXjuA=',
                'imap 3n(2ypted p4ssw0rd',
            ],
            'AES-256-CBC encrypted password (roundcube des_key)' => [
                'AES-256-CBC',
                '{DES_KEY}4hi+5PcK+EFetVqJOsfHLB0hic+hJ2lWTqbMaM1c6KZsKLfGNkYctcuCJQZaikXd',
                '3n(Rypted P4s5w0rd'
            ],
            'Decryption failure (wrong cipher method)' => [
                'DES-EDE3-CBC',
                '{DES_KEY}4hi+5PcK+EFetVqJOsfHLB0hic+hJ2lWTqbMaM1c6KZsKLfGNkYctcuCJQZaikXd',
                '' // empty string expected on error
            ],
            'Decryption failure (wrong password)' => [
                'AES-256-CBC',
                '{ENCRYPTED}4hi+5PcK+EFetVqJOsfHLB0hic+hJ2lWTqbMaM1c6KZsKLfGNkYctcuCJQZaikXd',
                '' // empty string expected on error
            ],
            'Decryption failure (invalid base64)' => [
                '',
                '{BASE64}Not valid base64!',
                '' // empty string expected on error
            ],
        ];
    }

    /**
     * @dataProvider encryptedPasswordsProvider
     */
    public function testPasswordCorrectlyDecrypted(string $cipherMethod, string $enc, string $expClear): void
    {
        $rcconfig = \rcube::get_instance()->config;
        $rcconfig->set("cipher_method", $cipherMethod, false);
        $rcconfig->set("des_key", 'ooceheiFeesah6PheeWaarae', false);
        $_SESSION["password"] = \rcube::get_instance()->encrypt('iM4p P455w0rd');
        $clear = Utils::decryptPassword($enc);
        $this->assertSame($expClear, $clear);

        // carddav_des_key must be cleared if set
        $this->assertNull($rcconfig->get("carddav_des_key"));

        if ($expClear === '') {
            // empty password means expected error in our tests
            TestInfrastructure::logger()->expectMessage('warning', 'Cannot decrypt password');
        }
    }

    /**
     * @return array<string, list{string,PasswordStoreScheme,string,?string}>
     */
    public static function passwordsProvider(): array
    {
        return [
            'Cleartext password' => [
                '',
                'plain',
                '(l3art3x7!',
                '(l3art3x7!'
            ],
            'Cleartext password - %p shall not be replaced' => [
                '',
                'plain',
                '%p',
                '%p'
            ],
            'Cleartext password - %b shall not be replaced' => [
                '',
                'plain',
                '%b',
                '%b'
            ],
            'Base64-encoded password' => [
                '',
                'base64',
                'b4se64 enc0ded P455w0rd!',
                '{BASE64}YjRzZTY0IGVuYzBkZWQgUDQ1NXcwcmQh'
            ],
            'DES-EDE3-CBC encrypted password (roundcube des_key)' => [
                'DES-EDE3-CBC',
                'des_key',
                '3n(2ypted p4ssw0rd',
                null
            ],
            'DES-EDE3-CBC encrypted password (IMAP password)' => [
                'DES-EDE3-CBC',
                'encrypted',
                'imap 3n(2ypted p4ssw0rd',
                null
            ],
            'AES-256-CBC encrypted password (roundcube des_key)' => [
                'AES-256-CBC',
                'des_key',
                '3n(Rypted P4s5w0rd',
                null
            ],
        ];
    }

    /**
     * @param PasswordStoreScheme $scheme
     * @dataProvider passwordsProvider
     */
    public function testPasswordCorrectlyEncrypted(
        string $cipherMethod,
        string $scheme,
        string $clear,
        ?string $expCipher
    ): void {
        if ($clear === '%b' || $clear === '%p' || $scheme === 'plain') {
            // placeholders always expected to be stored as plaintext, independent of configured pwStoreScheme
            $prefix = '';
        } else {
            $prefix = "{" . strtoupper($scheme) . "}";
        }

        $admPrefs = TestInfrastructure::$infra->admPrefs();

        /** @psalm-suppress InaccessibleProperty For test purposes, we override the scheme */
        $admPrefs->pwStoreScheme = $scheme;

        $rcube = \rcube::get_instance();
        $rcconfig = $rcube->config;
        $rcconfig->set("cipher_method", $cipherMethod, false);
        $rcconfig->set("des_key", 'ooceheiFeesah6PheeWaarae', false);
        $rcconfig->set("rcmcarddav_test_des_key", 'iM4p P455w0rdiM4p P455w0', false);
        $_SESSION["password"] = $rcube->encrypt('iM4p P455w0rd');

        $cipher = Utils::encryptPassword($clear);

        // carddav_des_key must be cleared if set
        $this->assertNull($rcconfig->get("carddav_des_key"));

        if (strlen($prefix) > 0) {
            $this->assertStringStartsWith($prefix, $cipher);
        }

        if (is_null($expCipher)) {
            $cipher = substr($cipher, strlen($prefix));
            $key = (($scheme === 'des_key') ? 'des_key' : 'rcmcarddav_test_des_key');
            $clearDec = $rcube->decrypt($cipher, $key);
            $this->assertSame($clear, $clearDec);
        } else {
            $this->assertSame($expCipher, $cipher);
        }
    }

    /**
     * When encryption of a password with the encrypted method fails, RCMCardDAV shall fall back to DES_KEY encryption.
     * This is tested here.
     */
    public function testPasswordEncryptionFallsBackToDesKeyOnError(): void
    {
        $admPrefs = TestInfrastructure::$infra->admPrefs();

        /** @psalm-suppress InaccessibleProperty For test purposes, we override the scheme */
        $admPrefs->pwStoreScheme = 'encrypted';

        $rcube = \rcube::get_instance();
        $rcconfig = $rcube->config;
        $rcconfig->set("des_key", 'ooceheiFeesah6PheeWaarae', false);
        $_SESSION["password"] = 'not an encrypted imap password';

        $clear = 't3st p4ssw0rd';
        $cipher = Utils::encryptPassword($clear);
        $this->assertStringStartsWith('{DES_KEY}', $cipher);

        $cipher = substr($cipher, strlen('{DES_KEY}'));
        $clearDec = $rcube->decrypt($cipher);
        $this->assertSame($clear, $clearDec);
        TestInfrastructure::logger()->expectMessage('warning', "Could not encrypt password with 'encrypted' method");
    }

    /**
     * When OAuth authentication is used, the SESSION password contains a volatile token that must not be used for
     * encryption, as at a later point in time, the token will change and we would not be able to decrypt the password
     * anymore. Fallback to DES_KEY expected in this case on encryption.
     */
    public function testPasswordEncryptionFallsBackToDesKeyOnOauth(): void
    {
        $admPrefs = TestInfrastructure::$infra->admPrefs();

        /** @psalm-suppress InaccessibleProperty For test purposes, we override the scheme */
        $admPrefs->pwStoreScheme = 'encrypted';

        $rcube = \rcube::get_instance();
        $rcconfig = $rcube->config;
        $rcconfig->set("des_key", 'ooceheiFeesah6PheeWaarae', false);
        $_SESSION["password"] = $rcube->encrypt('iM4p P455w0rd');
        $_SESSION["oauth_token"] = 'I am a token!';

        $clear = 'oauth t3st p4ssw0rd';
        $cipher = Utils::encryptPassword($clear);
        $this->assertStringStartsWith('{DES_KEY}', $cipher);

        $cipher = substr($cipher, strlen('{DES_KEY}'));
        $clearDec = $rcube->decrypt($cipher);
        $this->assertSame($clear, $clearDec);
        TestInfrastructure::logger()->expectMessage(
            'warning',
            'No password available to use for encryption because user logged in via OAuth2'
        );
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
