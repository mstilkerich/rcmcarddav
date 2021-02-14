<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use rcube_cache;

class Config extends \MStilkerich\CardDavAddressbook4Roundcube\Config
{
    public function __construct(AbstractDatabase $db, TestLogger $logger)
    {
        $this->logger = $logger;
        $this->httpLogger = $logger;
        $this->db = $db;
    }

    public function setCache(rcube_cache $cache): void
    {
        $this->cache = $cache;
    }

    public function setDb(AbstractDatabase $db): void
    {
        $this->db = $db;
    }

    public function cache(): rcube_cache
    {
        TestCase::assertNotNull($this->cache);
        return $this->cache;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
