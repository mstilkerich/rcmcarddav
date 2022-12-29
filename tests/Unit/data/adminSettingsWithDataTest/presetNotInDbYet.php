<?php

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::DEBUG;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::INFO;

$prefs['_GLOBAL']['collected_recipients'] = [
    'preset'  => 'NotInDb',
];

$prefs['AdmPreset'] = [
    'accountname'         =>  'Admin Preset',
    'discovery_url'       =>  'https://carddav.example.com/',

    'extra_addressbooks' => [
        [
            'url'          =>  'https://carddav.example.com/shared/Public/',
            'name'         => 'Public readonly contacts',
            'readonly'     =>  true,
        ],
    ],
];

$prefs['NotInDb'] = [
    'accountname'         =>  'Admin Preset not in DB yet',
    'discovery_url'       =>  'https://carddav2.example.com/',
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
