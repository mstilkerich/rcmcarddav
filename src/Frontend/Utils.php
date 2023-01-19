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

namespace MStilkerich\RCMCardDAV\Frontend;

use Exception;
use rcube;
use MStilkerich\RCMCardDAV\Config;

/**
 * Various utility functions used in the Frontend.
 *
 * @psalm-import-type PasswordStoreScheme from AdminSettings
 */
class Utils
{
    public static function replacePlaceholdersUsername(string $username, bool $quoteRegExp = false): string
    {
        $rcusername = (string) $_SESSION['username'];
        $rcusernameParts = explode('@', $rcusername, 2);

        $transTable = [
            '%u' => $rcusername,
            '%l' => $rcusernameParts[0],
            '%d' => $rcusernameParts[1] ?? '',
            // %V parses username for macOS, replaces periods and @ by _, work around bugs in contacts.app
            '%V' => strtr($rcusername, "@.", "__")
        ];

        if ($quoteRegExp) {
            $transTable = array_map(
                function (string $s): string {
                    return preg_quote($s);
                },
                $transTable
            );
        }

        return strtr($username, $transTable);
    }

    public static function replacePlaceholdersUrl(string $url, bool $quoteRegExp = false): string
    {
        // currently same as for username
        return self::replacePlaceholdersUsername($url, $quoteRegExp);
    }

    public static function replacePlaceholdersPassword(string $password): string
    {
        if ($password == '%p') {
            $rcube = rcube::get_instance();
            $password = $rcube->decrypt((string) $_SESSION['password']);
            if ($password === false) {
                $password = "";
            }
        }

        return $password;
    }

    /**
     * Parses a time string to seconds.
     *
     * The time string must have the format HH[:MM[:SS]]. If the format does not match, an exception is thrown.
     *
     * @param string $timeStr The time string to parse
     * @return int The time in seconds
     */
    public static function parseTimeParameter(string $timeStr): int
    {
        if (preg_match('/^(\d+)(:([0-5]?\d))?(:([0-5]?\d))?$/', $timeStr, $match)) {
            $ret = 0;

            $ret += intval($match[1] ?? 0) * 3600;
            $ret += intval($match[3] ?? 0) * 60;
            $ret += intval($match[5] ?? 0);
        } else {
            throw new Exception("Time string $timeStr could not be parsed");
        }

        return $ret;
    }

    /**
     * Converts a password to storage format according to the password storage scheme setting.
     *
     * @param string $clear The password in clear text.
     * @return string The password in storage format (e.g., encrypted with user password as key)
     */
    public static function encryptPassword(string $clear): string
    {
        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $scheme = $admPrefs->pwStoreScheme;

        if (strcasecmp($scheme, 'plain') === 0) {
            return $clear;
        }

        if (strcasecmp($scheme, 'encrypted') === 0) {
            try {
                // encrypted with IMAP password
                $rcube = rcube::get_instance();

                $imap_password = self::getDesKey();
                $rcube->config->set('carddav_des_key', $imap_password);

                $crypted = $rcube->encrypt($clear, 'carddav_des_key');

                // there seems to be no way to unset a preference
                $rcube->config->set('carddav_des_key', null);

                if ($crypted === false) {
                    throw new Exception("Password encryption with user password failed");
                }
                return '{ENCRYPTED}' . $crypted;
            } catch (Exception $e) {
                $logger = Config::inst()->logger();
                $logger->warning(
                    "Could not encrypt password with 'encrypted' method, falling back to 'des_key': " . $e->getMessage()
                );
                $scheme = 'des_key';
            }
        }

        if (strcasecmp($scheme, 'des_key') === 0) {
            // encrypted with global des_key
            $rcube = rcube::get_instance();
            $crypted = $rcube->encrypt($clear);

            if ($crypted === false) {
                throw new Exception("Could not encrypt password with 'des_key' method");
            }
            return '{DES_KEY}' . $crypted;
        }

        // otherwise, it's BASE64
        return '{BASE64}' . base64_encode($clear);
    }

    /**
     * Decrypts a password in the database according to the scheme used to encrypt or encode the password.
     *
     * In case of error, an error is logged and an empty string is returned.
     */
    public static function decryptPassword(string $crypt): string
    {
        $logger = Config::inst()->logger();
        $rcube = rcube::get_instance();
        $key = null;
        $clear = '';

        try {
            if (strpos($crypt, '{ENCRYPTED}') === 0) {
                $crypt = substr($crypt, strlen('{ENCRYPTED}'));
                $imap_password = self::getDesKey();

                $key = 'carddav_des_key';
                $rcube->config->set($key, $imap_password);
            } elseif (strpos($crypt, '{DES_KEY}') === 0) {
                $crypt = substr($crypt, strlen('{DES_KEY}'));
                $key = 'des_key';
            } elseif (strpos($crypt, '{BASE64}') === 0) {
                $crypt = substr($crypt, strlen('{BASE64}'));
                $clear = base64_decode($crypt, true);

                if ($clear === false) {
                    throw new Exception('not a valid base64 string');
                }
            } else {
                // unknown scheme, assume cleartext
                $clear = $crypt;
            }

            if (isset($key)) {
                $clear = $rcube->decrypt($crypt, $key);
                if ($clear === false) {
                    throw new Exception("decryption with $key failed");
                }
            }
        } catch (Exception $e) {
            $logger->warning("Cannot decrypt password: " . $e->getMessage());
            $clear = '';
        }

        // not strictly needed, but lets not keep the cleartext IMAP password in rcube_config
        if ($key === 'carddav_des_key') {
            $rcube->config->set($key, null);
        }

        return $clear;
    }

    /**
     * Returns the 24-byte decryption key derived from the user's password.
     *
     * The returned string is made exactly 24 bytes by repeating the IMAP password or, if it is longer, truncating it.
     * This should not be required anymore, because openssl_encrypt() (and assumedly also openssl_decrypt()) pad the
     * password themselves if needed, but this is kept for backwards compatibility with existing encrypted passwords.
     *
     * In case of error, this function throws an exception.
     */
    private static function getDesKey(): string
    {
        $rcube = rcube::get_instance();

        // if the user logged in via OAuth, we do not have a password to use for encryption / decryption of carddav
        // passwords; roundcube sets SESSION[password] to the encrypted 'Bearer <accesstoken>', so we need to
        // specifically check if oauth is used for login
        if (isset($_SESSION['oauth_token'])) {
            throw new Exception("No password available to use for encryption because user logged in via OAuth2");
        }

        $imapPw = $rcube->decrypt((string) $_SESSION['password']);

        if ($imapPw === false || strlen($imapPw) == 0) {
            throw new Exception("No password available to use for encryption");
        }

        $imapPw = str_pad($imapPw, 24, $imapPw);
        return substr($imapPw, 0, 24);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
