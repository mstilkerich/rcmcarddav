<?php
/*
    RCM CardDAV Plugin
    Copyright (C) 2011-2016 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
                            Michael Stilkerich <ms@mike2k.de>

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along
    with this program; if not, write to the Free Software Foundation, Inc.,
    51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

use MStilkerich\CardDavAddressbook4Roundcube\RoundcubeCarddavAddressbook;

class carddav_common
{
    // admin settings from config.inc.php
    private static $admin_settings;
    // encryption scheme
    public static $pwstore_scheme = 'encrypted';

    // password helpers
    private static function carddav_des_key()
    {
        $rcmail = rcmail::get_instance();
        $imap_password = $rcmail->decrypt($_SESSION['password']);
        while(strlen($imap_password)<24) {
            $imap_password .= $imap_password;
        }
        return substr($imap_password, 0, 24);
    }

    public static function encrypt_password($clear)
    {
        if(strcasecmp(self::$pwstore_scheme, 'plain')===0)
            return $clear;

        if(strcasecmp(self::$pwstore_scheme, 'encrypted')===0) {

            // return {IGNORE} scheme if session password is empty (krb_authentication plugin)
            if(empty($_SESSION['password'])) return '{IGNORE}';

            // encrypted with IMAP password
            $rcmail = rcmail::get_instance();

            $imap_password = self::carddav_des_key();
            $deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

            $crypted = $rcmail->encrypt($clear, 'carddav_des_key');

            // there seems to be no way to unset a preference
            $deskey_backup = $rcmail->config->set('carddav_des_key', '');

            return '{ENCRYPTED}'.$crypted;
        }

        if(strcasecmp(self::$pwstore_scheme, 'des_key')===0) {

            // encrypted with global des_key
            $rcmail = rcmail::get_instance();
            $crypted = $rcmail->encrypt($clear);

            return '{DES_KEY}'.$crypted;
        }

        // default: base64-coded password
        return '{BASE64}'.base64_encode($clear);
    }

    public static function decrypt_password($crypt)
    {
        if(strpos($crypt, '{ENCRYPTED}') === 0) {
            // return {IGNORE} scheme if session password is empty (krb_authentication plugin)
            if (empty($_SESSION['password'])) return '{IGNORE}';

            $crypt = substr($crypt, strlen('{ENCRYPTED}'));
            $rcmail = rcmail::get_instance();

            $imap_password = self::carddav_des_key();
            $deskey_backup = $rcmail->config->set('carddav_des_key', $imap_password);

            $clear = $rcmail->decrypt($crypt, 'carddav_des_key');

            // there seems to be no way to unset a preference
            $deskey_backup = $rcmail->config->set('carddav_des_key', '');

            return $clear;
        }

        if(strpos($crypt, '{DES_KEY}') === 0) {
            $crypt = substr($crypt, strlen('{DES_KEY}'));
            $rcmail = rcmail::get_instance();

            return $rcmail->decrypt($crypt);
        }

        if(strpos($crypt, '{BASE64}') === 0) {
            $crypt = substr($crypt, strlen('{BASE64}'));
            return base64_decode($crypt);
        }

        // unknown scheme, assume cleartext
        return $crypt;
    }

    // admin settings from config.inc.php
    public static function get_adminsettings()
    {
        if (is_array(self::$admin_settings)) {
            return self::$admin_settings;
        }

        $rcmail = rcmail::get_instance();
        $prefs = array();
        $configfile = dirname(__FILE__)."/config.inc.php";
        if (file_exists($configfile)) {
            include($configfile);
        }
        self::$admin_settings = $prefs;

        if(isset($prefs['_GLOBAL']['pwstore_scheme'])) {
            $scheme = $prefs['_GLOBAL']['pwstore_scheme'];
            if(preg_match("/^(plain|base64|encrypted|des_key)$/", $scheme))
                self::$pwstore_scheme = $scheme;
        }
        return $prefs;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
