<?php

include_once __DIR__ . '/../vendor/autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();
$classLoader->addPsr4("MStilkerich\\Tests\\CardDavAddressbook4Roundcube\\", __DIR__, true);
$classLoader->addPsr4(
    "MStilkerich\\Tests\\CardDavAddressbook4Roundcube\\DBInteroperability\\",
    __DIR__ . "/dbinterop",
    true
);
$classLoader->register();

// setup environment for roundcube - this is taken from the roundcube unit tests
require_once("autoload_defs.php");

/** @psalm-suppress UnresolvableInclude */
require_once(INSTALL_PATH . 'program/include/iniset.php');

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
