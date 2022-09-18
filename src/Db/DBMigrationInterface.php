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

namespace MStilkerich\CardDavAddressbook4Roundcube\Db;

use rcube_db;
use Psr\Log\LoggerInterface;

/**
 * Interface for PHP-based database migration scripts.
 */
interface DBMigrationInterface
{
    /**
     * Performs the migration.
     *
     * @param rcube_db $dbh The database handle
     * @param LoggerInterface $logger The logger object
     * @return bool true if the migration was successful, false otherwise
     */
    public function migrate(rcube_db $dbh, LoggerInterface $logger): bool;
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
