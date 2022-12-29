<?php

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::DEBUG;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::INFO;

$prefs['AdmPreset'] = [
    'accountname'         =>  'Admin Preset',
    'discovery_url'       =>  'https://carddav.example.com/',

    'fixed' => [ 'refresh_time', 'active', 'readonly' ],
    'refresh_time' => '0:30',
    'active' => false,              // 0x1
    'use_categories' => false,      // 0x2
    'readonly' => true,             // 0x8
    'require_always_email' => true, // 0x10
    'name' => '%D', // not fixed, assume change by user
];

$prefs['AdmPreset2'] = [
    'accountname'         =>  'Admin Preset with NO template abook in DB',
    'discovery_url'       =>  'https://card.example.com/',

    'fixed' => [ 'refresh_time', 'active', 'readonly' ],
    'refresh_time' => '0:10',
    'use_categories' => false,      // 0x2
    'name' => '%N - %D', // not fixed, assume change by user
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
