<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

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
