<?php

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
