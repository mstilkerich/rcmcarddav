<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use rcmail;
use rcube_db;
use Psr\Log\LoggerInterface;

/**
 * Exception subclass for errors in database operations reported by the database.
 */
class DatabaseException extends \Exception
{

}
