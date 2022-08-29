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
    'name'         =>  'First Preset',
    'username'     =>  '%u',
    'password'     =>  '%p',
    //// optional attributes
    'url'          =>  'cdav.example.com/%u',
    'rediscover_time' => '01:02:34',
    'hide' => true,

    // Auto-discovered addressbook attributes
    //// optional attributes
    'active'       =>  "0",
    'readonly'     =>  "1",
    'refresh_time' => '2',
    'use_categories' => false,

    'fixed'        =>  [ 'name' ],
    'require_always' => ['email'],

    'extra_addressbooks' =>  [
        [
            // required attributes
            'url'          =>  'https://cdav.example.com/shared/book',
            // optional attributes - if not specified, values from account are applied
            'active'       =>  true,
            'readonly'     =>  false,
            'refresh_time' => '2:3',
            'use_categories' => true,

            'fixed'        =>  [ 'refresh_time' ],
            'require_always' => ['phone'],
        ],
        [
            'url'          =>  'https://cdav.example.com/shared/book2',
            // all the optional attributes should default to the settings in the account
        ],
        [
            'url'          =>  'https://cdav.example.com/%d/%l/%u',
            // all the optional attributes should default to the settings in the account
            'readonly'     =>  false,
        ],
    ],
];

$prefs['Minimal'] = [
    'name'         =>  'Minimal Preset with required values only',
];

$prefs['OnlyShared'] = [
    'name'         =>  'Preset that contains a shared example.com addressbook only',
    'username'     =>  'uonly',
    'password'     =>  'ponly',
    'extra_addressbooks' =>  [
        [
            'url'          =>  'https://cdavshared.example.com/shared/book',
        ],
    ],
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
