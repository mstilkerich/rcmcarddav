<?php

$prefs = [
    '_GLOBAL' => [
        'pwstore_scheme' => 'base64',
    ],

    'admpreset' => [
        'accountname'    =>  'Preset Account',

        'username'       =>  '%u',
        'password'       =>  '%p',
        'discovery_url'  =>  'https://contacts.example.com/',

        'hide'           => true, // must show up in AddressbookManager nevertheless!

        // these attributes are only found in the admin settings, not the DB - set to non-default values to verify usage
        'readonly'       =>  true,
        'require_always' => ['email'],
    ],

    // This preset only exists in the configuration, but has not been added to the DB yet.
    'admpreset_new' => [
        'accountname'         =>  'Preset Account - Not added for user yet',

        'username'      =>  'usr2',
        'password'      =>  'pass2',
        'discovery_url' =>  'https://contacts2.example.com/',
    ]
];

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
