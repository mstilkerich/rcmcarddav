<?php

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::DEBUG;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::INFO;

$prefs['_GLOBAL']['pwstore_scheme'] = 'plain';

$prefs['AdmPreset'] = [
    'accountname'         => 'Preset Contacts',
    'discovery_url'       => 'https://carddav.example.com/',

    'fixed'               => [ 'username', 'password', 'refresh_time', 'name', 'require_always_email', 'ssl_noverify' ],

    'username'            => 'foodoo',
    'password'            => 'bar',
    'rediscover_time'     => '1:00',

    'refresh_time'        => '0:30',
    'name'                => '%N (%D)',
    'use_categories'      => false,

    'require_always_email' => false,
    'ssl_noverify        ' => false,

    'extra_addressbooks' => [
        [
            'url'          =>  'https://carddav.example.com/shared/Public/',
            'name'         => 'Public readonly contacts',
            'readonly'     =>  true,
            'fixed'        => [ 'username', 'active', 'name' ],
            'active'       => true, // this cannot be deactivated by the user
        ],
    ],
];

$prefs['HiddenPreset'] = [
    'accountname'         => 'Hidden Preset',
    'discovery_url'       => 'https://admonly.example.com/',

    'username'            => 'foodoo',
    'password'            => 'bar',

    'hide'                => true,
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
