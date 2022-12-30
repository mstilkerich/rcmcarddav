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

namespace MStilkerich\Tests\RCMCardDAV;

use Exception;
use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Sabre\VObject\Component\VCard;
use MStilkerich\CardDavClient\{Account,WebDavResource};
use MStilkerich\CardDavClient\Services\Discovery;
use MStilkerich\RCMCardDAV\Db\AbstractDatabase;
use MStilkerich\RCMCardDAV\Frontend\AdminSettings;
use rcube_cache;

class Config extends \MStilkerich\RCMCardDAV\Config
{
    /** @var ?Discovery Instance of the discovery service to be returned - normally null, but can be set by tests */
    public $discovery;

    /** @var null|array<string, WebDavResource|Exception>
     *    WebDavResource to be returned by makeWebDavResource() - normally null, but can be set by tests. The array maps
     *    the resource URI to the object to be returned for it. If the set object is an instance of Exception, this
     *    exception will be thrown by makeWebDavResource() instead.
     */
    public $webDavResources;

    public function __construct(AbstractDatabase $db, TestLogger $logger, AdminSettings $admPrefs)
    {
        $this->logger = $logger;
        $this->httpLogger = $logger;
        $this->admPrefs = $admPrefs;
        $this->db = $db;
        $this->rc = new RcmAdapterStub();
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

    public function rcTestAdapter(): RcmAdapterStub
    {
        TestCase::assertInstanceOf(RcmAdapterStub::class, $this->rc);
        // always return the stub
        return $this->rc;
    }

    public function makeDiscoveryService(): Discovery
    {
        return $this->discovery ?? parent::makeDiscoveryService();
    }

    public function makeWebDavResource(string $uri, Account $account): WebDavResource
    {
        if (isset($this->webDavResources[$uri])) {
            if ($this->webDavResources[$uri] instanceof Exception) {
                throw $this->webDavResources[$uri];
            }

            $res = $this->webDavResources[$uri];
        } else {
            $res = parent::makeWebDavResource($uri, $account);
        }

        return $res;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
