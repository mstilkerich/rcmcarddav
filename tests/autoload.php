<?php

include_once __DIR__ . '/../vendor/autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();
$classLoader->addPsr4("MStilkerich\\Tests\\CardDavAddressbook4Roundcube\\", __DIR__, true);
$classLoader->register();

require_once("autoload_defs.php");

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
