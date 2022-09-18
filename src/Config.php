<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2022 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\{LoggerInterface,LogLevel};
use MStilkerich\CardDavClient\{Account,WebDavResource};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\CardDavAddressbook4Roundcube\Db\{Database, AbstractDatabase};
use rcube;
use rcube_cache;

/**
 * This class is intended as a central point to inject dependencies to infrastructure classes.
 *
 * This allows replacing dependencies in unit tests with mock objects.
 */
class Config
{
    /** @var ?Config The single instance of this class - can be exchanged by tests */
    public static $inst;

    /** @var ?Discovery Instance of the discovery service to be returned - normally null, but can be set by tests */
    public $discovery;

    /** @var ?WebDavResource|\Exception
     *    WebDavResource to be returned by makeWebDavResource() - normally null, but can be set by tests.
     *    If set to an instance of Exception, this exception will be thrown by makeWebDavResource() instead.
     */
    public $webDavResource;

    /** @var LoggerInterface */
    protected $logger;

    /** @var LoggerInterface */
    protected $httpLogger;

    /** @var AbstractDatabase */
    protected $db;

    /** @var ?rcube_cache */
    protected $cache;

    public static function inst(): Config
    {
        if (!isset(self::$inst)) {
            self::$inst = new Config();
        }

        return self::$inst;
    }

    public function __construct()
    {
        $this->logger = new RoundcubeLogger("carddav", LogLevel::ERROR);
        $this->httpLogger = new RoundcubeLogger("carddav_http", LogLevel::ERROR);

        $rcube = rcube::get_instance();
        $this->db = new Database($this->logger, $rcube->db);
    }

    public function db(): AbstractDatabase
    {
        return $this->db;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function httpLogger(): LoggerInterface
    {
        return $this->httpLogger;
    }

    /**
     * Returns a handle to the roundcube cache for the user.
     *
     * Note: this must be called at a time where the user is already logged on, specifically it must not be called
     * during the constructor of the plugin.
     */
    public function cache(): rcube_cache
    {
        if (!isset($this->cache)) {
            // TODO make TTL and cache type configurable
            $rcube = rcube::get_instance();
            $this->cache = $rcube->get_cache("carddav", "db", "1w");
        }

        if (!isset($this->cache)) {
            throw new \Exception("Attempt to request cache where not available yet");
        }

        return $this->cache;
    }

    public function makeDiscoveryService(): Discovery
    {
        return $this->discovery ?? new Discovery();
    }

    public function makeWebDavResource(string $uri, Account $account): WebDavResource
    {
        if (isset($this->webDavResource)) {
            if ($this->webDavResource instanceof \Exception) {
                throw $this->webDavResource;
            }

            $res = $this->webDavResource;
        } else {
            $res = WebDavResource::createInstance($uri, $account);
        }

        return $res;
    }

    /**
     * Creates an Account object from the credentials in rcmcarddav.
     *
     * Particularly, this takes care of setting up the credentials information properly.
     */
    public static function makeAccount(string $discUrl, string $username, string $password, ?string $baseUrl): Account
    {
        if ($password == "%b") {
            if (
                isset($_SESSION['oauth_token']['access_token'])
                && is_string($_SESSION['oauth_token']['access_token'])
            ) {
                $credentials = [ 'bearertoken' => $_SESSION['oauth_token']['access_token'] ];
            } else {
                throw new \Exception("OAUTH2 bearer authentication requested, but no token available in roundcube");
            }
        } else {
            $credentials = [ 'username' => $username, 'password' => $password ];
        }

        return new Account($discUrl, $credentials, "", $baseUrl);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
