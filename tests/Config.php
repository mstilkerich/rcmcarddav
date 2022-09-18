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
