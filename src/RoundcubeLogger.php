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

use Psr\Log\{AbstractLogger,LogLevel,InvalidArgumentException};

class RoundcubeLogger extends AbstractLogger
{
    /**
     * @var int[] Assigns each log level a numerical severity value.
     */
    private const LOGLEVELS = [
        LogLevel::DEBUG     => 1,
        LogLevel::INFO      => 2,
        LogLevel::NOTICE    => 3,
        LogLevel::WARNING   => 4,
        LogLevel::ERROR     => 5,
        LogLevel::CRITICAL  => 6,
        LogLevel::ALERT     => 7,
        LogLevel::EMERGENCY => 8
    ];

    /**
     * @var string[] Assigns each short name to each log level.
     */
    private const LOGLEVELS_SHORT = [
        LogLevel::DEBUG     => "DBG",
        LogLevel::INFO      => "NFO",
        LogLevel::NOTICE    => "NTC",
        LogLevel::WARNING   => "WRN",
        LogLevel::ERROR     => "ERR",
        LogLevel::CRITICAL  => "CRT",
        LogLevel::ALERT     => "ALT",
        LogLevel::EMERGENCY => "EMG"
    ];

    /** @var string $logfile Name of the roundcube logfile that this logger logs to */
    private $logfile;

    /** @var int $loglevel The minimum log level for that messages are reported */
    private $loglevel;

    /** @var bool $redact If true, attempt to redact confidential information from HTTP logs */
    private $redact;

    public function __construct(string $logfile, string $loglevel = LogLevel::ERROR, bool $redact = true)
    {
        $this->logfile = $logfile;
        $this->setLogLevel($loglevel);
        $this->redact = $redact;
    }

    /**
     * Sets the minimum log level for that messages are logged.
     *
     * @param string $loglevel One of the Psr\Log\LogLevel constants for log levels.
     */
    final public function setLogLevel(string $loglevel): void
    {
        if (isset(self::LOGLEVELS[$loglevel])) {
            $this->loglevel = self::LOGLEVELS[$loglevel];
        } else {
            throw new \Exception("Logger instantiated with unknown loglevel");
        }
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return void
     */
    public function log($level, $message, array $context = array()): void
    {
        if (is_string($level) && isset(self::LOGLEVELS[$level])) {
            if (self::LOGLEVELS[$level] >= $this->loglevel) {
                $ctx = empty($context) ? "" : json_encode($context);
                $message = "$message $ctx";
                if ($this->redact) {
                    $message = $this->redactMessage($message);
                }

                $levelNumeric = self::LOGLEVELS[$level];
                $levelShort = self::LOGLEVELS_SHORT[$level];
                $message = "[$levelNumeric $levelShort] $message";
                \rcube::write_log($this->logfile, $message);
            }
        } else {
            throw new InvalidArgumentException("Unknown loglevel " . print_r($level, true));
        }
    }

    private function redactMessage(string $message): string
    {
        // Authorization header
        $message = preg_replace(
            "/^Authorization: .*$/m",
            "Authorization: --- REDACTED BY " . self::class . " ---",
            $message
        );
        return $message;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
