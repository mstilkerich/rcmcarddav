<?php

$prefs['_GLOBAL']['loglevel'] = \Psr\Log\LogLevel::DEBUG;
$prefs['_GLOBAL']['loglevel_http'] = \Psr\Log\LogLevel::INFO;

$prefs['UpdatedPreset'] = [
    'accountname'         => 'Updated Account',
    'discovery_url'       => 'https://carddav.example.com/',

    'fixed'               => [ 'username', 'refresh_time', 'require_always_email', 'name' ],

    'username'            => 'foodoo',
    'password'            => 'bar',
    'rediscover_time'     => '1:00',

    'refresh_time'        => '0:30',
    'require_always_email' => true,
    'name'                => '%N (%D)',
    'use_categories'      => false,

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

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
