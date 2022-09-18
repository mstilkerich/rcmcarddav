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

include_once __DIR__ . '/../autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();
$classLoader->addPsr4(
    "MStilkerich\\Tests\\CardDavAddressbook4Roundcube\\Unit\\",
    __DIR__,
    true
);
//$classLoader->setPsr4(
//    "MStilkerich\\CardDavClient\\",
//    __DIR__ . "/../stubs/carddavclient"
//);
$classLoader->register(true); // true -> Prepend classloader to other ones

// setup environment for roundcube - this is taken from the roundcube unit tests
/** @psalm-suppress UnresolvableInclude */
require_once(INSTALL_PATH . 'program/include/iniset.php');

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
