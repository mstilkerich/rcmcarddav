<?php

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube;

use Psr\Log\AbstractLogger;

class RoundcubeLogger extends AbstractLogger
{
    private $logfile;

    public function __construct(string $logfile)
    {
        $this->logfile = $logfile;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = array())
    {
        $ctx = empty($context) ? "" : json_encode($context);
        \rcmail::write_log($this->logfile, $message . $ctx);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
