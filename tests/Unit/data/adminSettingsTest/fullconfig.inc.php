<?php

// use non-default values for all of these
$prefs['_GLOBAL']['fixed'] = 1;
$prefs['_GLOBAL']['hide_preferences'] = "enable";
$prefs['_GLOBAL']['pwstore_scheme'] = 'plain';

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::DEBUG;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::INFO;

$prefs['_GLOBAL']['collected_recipients'] = [
    'preset'  => 'Preset1',
    'matchurl' => '#.*\.example\.com/%d/%l/%u#',
];
$prefs['_GLOBAL']['collected_senders'] = [
    'preset'  => 'OnlyShared',
    'matchname' => '/shared %d addressbook/i',
];

$prefs['Preset1'] = [
    'accountname'         =>  'First Preset',
    'username'     =>  '%u',
    'password'     =>  '%p',
    //// optional attributes
    'discovery_url'          =>  'cdav.example.com/%u',
    'rediscover_time' => '01:02:34',
    'hide' => true,
    'preemptive_basic_auth' => true,
    'ssl_noverify' => true,

    // Auto-discovered addressbook attributes
    //// optional attributes
    'name'         =>  "%a (%N)",
    'active'       =>  "0",
    'readonly'     =>  "1",
    'refresh_time' => '2',
    'use_categories' => false,

    'fixed'        =>  [ 'accountname' ],
    'require_always_email' => true,

    'extra_addressbooks' =>  [
        [
            // required attributes
            'url'          =>  'https://cdav.example.com/shared/book',
            // optional attributes - if not specified, values from account are applied
            'name'         =>  '%N',
            'active'       =>  true,
            'readonly'     =>  false,
            'refresh_time' => '2:3',
            'use_categories' => true,

            'fixed'        =>  [ 'refresh_time' ],
            'require_always_email' => false
        ],
        [
            'url'          =>  'https://cdav.example.com/shared/book2',
            // all the optional attributes should default to the settings in the account
        ],
        [
            'url'          =>  'https://cdav.example.com/%d/%l/%u',
            // all the optional attributes should default to the settings in the account
            'readonly'     =>  false,
            'fixed'        =>  [ 'readonly' ],
        ],
    ],
];

$prefs['Minimal'] = [
    // this tests that the preset key is used as default for accountname
];

$prefs['OnlyShared'] = [
    'accountname'         =>  'Preset that contains a shared example.com addressbook only',
    'username'     =>  'uonly',
    'password'     =>  'ponly',
    'extra_addressbooks' =>  [
        [
            'url'          =>  'https://cdavshared.example.com/shared/book',
        ],
    ],
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
