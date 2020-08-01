<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube;

use Psr\Log\LoggerInterface;

final class TestInfrastructure
{
    /** @var ?LoggerInterface Logger object used to store log messages produced during the tests */
    public static $logger;

    public static function init(): void
    {
        if (!isset(self::$logger)) {
            if (file_exists('testreports/tests.log')) {
                unlink('testreports/tests.log');
            }
            self::$logger = new \Wa72\SimpleLogger\FileLogger('testreports/tests.log', \Psr\Log\LogLevel::DEBUG);
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120:ft=php
