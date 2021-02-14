<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\{LoggerInterface,LogLevel};
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
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
