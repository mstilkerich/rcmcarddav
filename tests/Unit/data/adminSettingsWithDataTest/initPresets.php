<?php

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::DEBUG;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::INFO;

$prefs['_GLOBAL']['pwstore_scheme'] = 'plain';

$prefs['UpdatedPreset'] = [
    'accountname'         => 'Updated Account',
    'discovery_url'       => 'https://carddav.example.com/',

    'fixed'               => [
        'username', 'refresh_time', 'require_always_email', 'name', 'preemptive_basic_auth', 'ssl_noverify'
    ],

    'username'            => 'foodoo',
    'password'            => 'bar',
    'rediscover_time'     => '1:00',

    'refresh_time'        => '0:30',
    'require_always_email' => true,
    'name'                => '%N (%D)',
    'use_categories'      => false,

    'preemptive_basic_auth' => true,
    'ssl_noverify' => false,

    'extra_addressbooks' => [
        [
            'url'          =>  'https://carddav.example.com/global/UpdatedXBook/',
            'name'         => 'Public readonly contacts',
            'readonly'     =>  true,
            'require_always_email' => false,
        ],
        [
            'url'          =>  'https://carddav.example.com/global/NewXBook/',
            'name'         => '%D',
            'readonly'     =>  true,
            'require_always_email' => false,
            'active'       => false
        ],
        [
            'url'          =>  'https://carddav.example.com/global/InvalidXBook/',
        ],
    ],
];

$prefs['NewPreset'] = [
    'accountname'         => 'New Preset Account',
    'discovery_url'       => 'https://newcard.example.com/',

    'username'            => '%u',
    'password'            => 'foo',
    'rediscover_time'     => '10:00',

    'refresh_time'        => '5:00',
    'name'                => '%N (%D)',

    'preemptive_basic_auth' => true,
    'ssl_noverify' => true,

    'extra_addressbooks' => [
        [
            'url'          =>  'https://newcard.example.com/global/PublicAddrs',
            'name'         => '%c',
        ],
    ],
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
