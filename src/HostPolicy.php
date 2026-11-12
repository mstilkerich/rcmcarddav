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

namespace MStilkerich\RCMCardDAV;

/**
 * Decides on the hosts that connections may be made to.
 *
 * Connections to internal addresses are only permitted if the host is allowed by the admin. Whether an address is
 * internal is determined using the IP address filters of PHP. This prevents users from using the plugin to access
 * services that are only reachable from the roundcube server, e.g. by adding an addressbook for an internal address.
 *
 * Additionally, hosts can be denied by the admin. These are treated like internal addresses: A denied address must be
 * permitted by an address or network entry, a denied hostname by a hostname or domain suffix entry.
 *
 * Each entry of a list of hosts has one of the following forms, each optionally followed by a port (`:port`) to
 * restrict the entry to that port. IPv6 addresses and networks must be enclosed in brackets if a port is given.
 *   - A hostname (`carddav.example.internal`): Matches that hostname, independent of the addresses it resolves to.
 *   - A domain suffix with leading dot (`.example.internal`): Matches all hostnames within that domain (not including
 *     the domain itself).
 *   - An IP address (`192.168.1.5`, `fd00::5`, `[fd00::5]:8443`): Matches that address, including when used by
 *     a hostname that resolves to it.
 *   - A network in CIDR notation (`10.1.0.0/16`, `fd00::/8`): As before, for all addresses of the network.
 *
 * The enforcement is done by the carddavclient library, see {@see HostPolicy::isPermitted()}.
 *
 * @psalm-type HostEntry = array{host?: string, suffix?: string, net?: string, bits?: int, port: ?int}
 */
class HostPolicy
{
    /**
     * @var int Flags for filter_var() with FILTER_VALIDATE_IP that make the validation fail for internal addresses.
     *
     * These flags cover the private (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, fc00::/7) and the reserved ranges
     * (0.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 240.0.0.0/4, ::, ::1, fe80::/10) known to the PHP version. The more
     * comprehensive FILTER_FLAG_GLOBAL_RANGE requires PHP 8.2.
     */
    private const INTERNAL_FILTER_FLAGS = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

    /**
     * @var list<HostEntry> The parsed entries of internal hosts that are permitted
     */
    private $allowed = [];

    /**
     * @var list<HostEntry> The parsed entries of additional hosts that are treated like internal addresses
     */
    private $denied = [];

    /**
     * Constructs a HostPolicy.
     *
     * Invalid entries are ignored, use {@see HostPolicy::parseEntry()} to check entries beforehand.
     *
     * @param list<string> $allowedHosts Internal hosts that connections are permitted to
     * @param list<string> $deniedHosts Additional hosts that are treated like internal addresses
     */
    public function __construct(array $allowedHosts, array $deniedHosts = [])
    {
        $this->allowed = self::parseEntries($allowedHosts);
        $this->denied = self::parseEntries($deniedHosts);
    }

    /**
     * Checks whether a connection to a host is permitted.
     *
     * This function is suited as allow_host_callback of the carddavclient library, which calls it for every request it
     * sends, including requests to redirect targets, and makes sure that the connection is made to one of the checked
     * addresses.
     *
     * @param string $host The host of the request, a hostname in lowercase or an IP address
     * @param int $port The port of the request
     * @param list<string> $addresses
     *  The IP addresses of the host in canonical notation, empty if the hostname could not be resolved. IPv4-mapped
     *  IPv6 addresses must be given as the IPv4 address.
     * @return bool True if the connection is permitted.
     */
    public function isPermitted(string $host, int $port, array $addresses): bool
    {
        // The admin trusts an allowed hostname independent of the addresses it resolves to
        if (self::matchesHostname($this->allowed, $host, $port)) {
            return true;
        }

        // A denied hostname is refused independent of its addresses
        if (self::matchesHostname($this->denied, $host, $port)) {
            return false;
        }

        foreach ($addresses as $address) {
            $internal = self::isInternalAddress($address) || self::matchesAddress($this->denied, $address, $port);
            if ($internal && !self::matchesAddress($this->allowed, $address, $port)) {
                return false;
            }
        }

        // A host that cannot be resolved must not be used: the HTTP client might get a different result
        return !empty($addresses);
    }

    /**
     * Checks whether an IP address is an internal address.
     *
     * @param string $address The IP address in canonical notation
     * @return bool True if the address is internal or not a valid IP address.
     */
    public static function isInternalAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, self::INTERNAL_FILTER_FLAGS) === false;
    }

    /**
     * Parses an entry of a list of hosts.
     *
     * @return ?HostEntry The parsed entry, null if the entry is not valid.
     */
    public static function parseEntry(string $entry): ?array
    {
        $entry = strtolower(trim($entry));
        $port = null;

        // IPv6 in brackets with optional port, example: [::1]:443
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $entry, $matches)) {
            $entry = $matches[1];
            $port = $matches[2] ?? null;
        // IPv4 or hostname with explicit port, example: 192.168.0.1:443 or example.com:80
        } elseif (preg_match('/^([^:]+):(\d+)$/', $entry, $matches)) {
            $entry = $matches[1];
            $port = $matches[2];
        }

        if (isset($port)) {
            $port = (int) $port;
            if ($port < 1 || $port > 65535) {
                return null;
            }
        }

        // IP address or network, examples 192.168.0.0/16 or fc00::/7
        $net = preg_match('/^([0-9a-f:.]+)(?:\/(\d+))?$/', $entry, $matches) ? @inet_pton($matches[1]) : false;
        if ($net !== false) {
            $bits = isset($matches[2]) ? (int) $matches[2] : 8 * strlen($net);

            if ($bits > 8 * strlen($net)) {
                return null;
            }

            // IPv4-mapped IPv6 addresses are matched as IPv4 addresses
            if (strlen($net) === 16 && $bits >= 96 && strncmp($net, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
                $net = substr($net, 12);
                $bits -= 96;
            }

            return [ 'net' => $net, 'bits' => $bits, 'port' => $port ];
        }

        // hostname or domain suffix
        if (preg_match('/^(\.?)([a-z0-9_]([a-z0-9_-]*[a-z0-9_])?\.)*[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?\.?$/', $entry)) {
            $entry = rtrim($entry, '.');
            return ($entry[0] === '.')
                ? [ 'suffix' => $entry, 'port' => $port ]
                : [ 'host' => $entry, 'port' => $port ];
        }

        return null;
    }

    /**
     * Parses a list of host entries, skipping invalid entries.
     *
     * @param list<string> $entries
     * @return list<HostEntry>
     */
    private static function parseEntries(array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            $parsed = self::parseEntry($entry);
            if (isset($parsed)) {
                $result[] = $parsed;
            }
        }

        return $result;
    }

    /**
     * Checks whether a hostname and port are matched by a hostname or domain suffix entry.
     *
     * @param list<HostEntry> $entries The entries to check
     * @param string $host The hostname in lowercase without trailing dot
     * @param int $port The port of the connection
     */
    private static function matchesHostname(array $entries, string $host, int $port): bool
    {
        foreach ($entries as $entry) {
            if (($entry['port'] ?? $port) !== $port) {
                continue;
            }

            if (isset($entry['host']) && $entry['host'] === $host) {
                return true;
            }

            if (isset($entry['suffix']) && substr($host, -strlen($entry['suffix'])) === $entry['suffix']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether an IP address and port are matched by an address or network entry.
     *
     * @param list<HostEntry> $entries The entries to check
     * @param string $address The IP address
     * @param int $port The port of the connection
     */
    private static function matchesAddress(array $entries, string $address, int $port): bool
    {
        $ip = @inet_pton($address);
        if ($ip === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if (
                isset($entry['net'])
                && isset($entry['bits'])
                && ($entry['port'] ?? $port) === $port
                && self::inNet($ip, $entry['net'], $entry['bits'])
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether an IP address belongs to a network.
     *
     * @param string $ip The IP address in packed form
     * @param string $net The network address in packed form
     * @param int $bits The prefix length of the network
     */
    private static function inNet(string $ip, string $net, int $bits): bool
    {
        if (strlen($ip) !== strlen($net)) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        $remBits = $bits % 8;
        if ($remBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remBits)) & 0xff;
        return (ord($ip[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
