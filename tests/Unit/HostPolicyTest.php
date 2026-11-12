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

namespace MStilkerich\Tests\RCMCardDAV\Unit;

use MStilkerich\RCMCardDAV\HostPolicy;
use PHPUnit\Framework\TestCase;

final class HostPolicyTest extends TestCase
{
    /**
     * @return array<string, array{list<string>, string, int, list<string>, bool}>
     */
    public static function hostProvider(): array
    {
        return [
            // no entries
            'public IP' => [ [], 'IP', 443, [ '93.184.216.34' ], true ],
            'public IPv6' => [ [], 'IP', 443, [ '2606:2800:220:1::1' ], true ],
            'public name' => [ [], 'public.example.com', 443, [ '93.184.216.34', '2606:2800:220:1::1' ], true ],
            'loopback IP' => [ [], 'IP', 80, [ '127.0.0.1' ], false ],
            'loopback IPv6' => [ [], 'IP', 8080, [ '::1' ], false ],
            'unspecified IP' => [ [], 'IP', 80, [ '0.0.0.0' ], false ],
            'private IP 10' => [ [], 'IP', 80, [ '10.1.2.3' ], false ],
            'private IP 172' => [ [], 'IP', 80, [ '172.31.255.255' ], false ],
            'private IP 192' => [ [], 'IP', 80, [ '192.168.178.1' ], false ],
            'link-local IP / metadata service' => [ [], 'IP', 80, [ '169.254.169.254' ], false ],
            'link-local IPv6' => [ [], 'IP', 80, [ 'fe80::1' ], false ],
            'unique local IPv6' => [ [], 'IP', 80, [ 'fd12:3456::1' ], false ],
            'reserved IP' => [ [], 'IP', 80, [ '240.0.0.1' ], false ],
            'invalid address' => [ [], 'IP', 80, [ 'foo' ], false ],
            'name resolving to internal' => [ [], 'intern.example.com', 443, [ '10.0.0.5' ], false ],
            'name resolving to public and internal' => [
                [], 'rebind.example.com', 443, [ '93.184.216.34', '127.0.0.1' ], false
            ],
            'name resolving to internal IPv6' => [ [], 'intern6.example.com', 443, [ 'fd00::5' ], false ],
            'unresolvable name' => [ [], 'nonexistent.example.com', 443, [], false ],

            // address entries
            'IP entry' => [ [ '127.0.0.1' ], 'IP', 8080, [ '127.0.0.1' ], true ],
            'IP entry other IP' => [ [ '127.0.0.1' ], 'IP', 80, [ '127.0.0.2' ], false ],
            'mapped IP entry' => [ [ '::ffff:127.0.0.1' ], 'IP', 80, [ '127.0.0.1' ], true ],
            'IP entry with port' => [ [ '127.0.0.1:8080' ], 'IP', 8080, [ '127.0.0.1' ], true ],
            'IP entry with other port' => [ [ '127.0.0.1:8080' ], 'IP', 8081, [ '127.0.0.1' ], false ],
            'IP entry permits resolved name' => [ [ '10.0.0.5' ], 'intern.example.com', 443, [ '10.0.0.5' ], true ],
            'CIDR entry' => [ [ '10.0.0.0/8' ], 'IP', 443, [ '10.200.1.1' ], true ],
            'CIDR entry no match' => [ [ '10.0.0.0/9' ], 'IP', 443, [ '10.200.1.1' ], false ],
            'CIDR entry permits resolved name' => [
                [ ' 10.0.0.0/24 ' ], 'intern.example.com', 443, [ '10.0.0.5' ], true
            ],
            'CIDR entry with port' => [ [ '10.0.0.0/24:8443' ], 'intern.example.com', 8443, [ '10.0.0.5' ], true ],
            'CIDR entry with other port' => [
                [ '10.0.0.0/24:8443' ], 'intern.example.com', 443, [ '10.0.0.5' ], false
            ],
            'CIDR entry covers only part' => [
                [ '10.0.0.0/8' ], 'multi.example.com', 443, [ '10.0.0.6', 'fd00::6' ], false
            ],
            'CIDR entries cover all' => [
                [ '10.0.0.0/8', 'FD00::/8' ], 'multi.example.com', 443, [ '10.0.0.6', 'fd00::6' ], true
            ],
            'CIDR entry and public address' => [
                [ '10.0.0.0/8' ], 'mixed.example.com', 443, [ '10.0.0.6', '93.184.216.34' ], true
            ],
            'IPv6 entry' => [ [ 'fd00:0:0::5' ], 'IP', 443, [ 'fd00::5' ], true ],
            'IPv6 entry with port' => [ [ '[fd00::5]:8443' ], 'IP', 8443, [ 'fd00::5' ], true ],
            'IPv6 entry with other port' => [ [ '[fd00::5]:8443' ], 'IP', 443, [ 'fd00::5' ], false ],
            'IPv6 CIDR entry with port' => [ [ '[fd00::/8]:8443' ], 'IP', 8443, [ 'fd00::5' ], true ],

            // hostname entries
            'host entry' => [ [ 'Intern.Example.com.' ], 'intern.example.com', 443, [ '10.0.0.5' ], true ],
            'host entry unresolvable' => [ [ 'nonexistent.example.com' ], 'nonexistent.example.com', 443, [], true ],
            'host entry other host' => [ [ 'intern.example.com' ], 'intern6.example.com', 443, [ 'fd00::5' ], false ],
            'host entry no subdomain' => [ [ 'example.com' ], 'intern.example.com', 443, [ '10.0.0.5' ], false ],
            'host entry does not permit IP' => [ [ 'intern.example.com' ], 'IP', 443, [ '10.0.0.5' ], false ],
            'host entry with port' => [
                [ 'intern.example.com:8443' ], 'intern.example.com', 8443, [ '10.0.0.5' ], true
            ],
            'host entry with other port' => [
                [ 'intern.example.com:8443' ], 'intern.example.com', 443, [ '10.0.0.5' ], false
            ],
            'suffix entry' => [ [ '.example.com' ], 'intern.example.com', 443, [ '10.0.0.5' ], true ],
            'suffix entry not domain itself' => [
                [ '.intern.example.com' ], 'intern.example.com', 443, [ '10.0.0.5' ], false
            ],
            'suffix entry not partial label' => [
                [ '.tern.example.com' ], 'intern.example.com', 443, [ '10.0.0.5' ], false
            ],

            // invalid entries are ignored
            'invalid entries' => [
                [ '10.0.0.0/33', 'fd00::/129', '10.0.0.5:0', '10.0.0.5:65536', 'in tern', '', '*' ],
                'intern.example.com', 443, [ '10.0.0.5' ], false
            ],
        ];
    }

    /**
     * Tests that connections to internal addresses are only permitted for allowed hosts.
     *
     * A host of 'IP' in the test data stands for a request to the (single) IP address.
     *
     * @param list<string> $allowed
     * @param list<string> $addresses
     * @dataProvider hostProvider
     */
    public function testInternalHostsMustBeAllowed(
        array $allowed,
        string $host,
        int $port,
        array $addresses,
        bool $permitted
    ): void {
        $host = ($host === 'IP') ? $addresses[0] : $host;
        $this->assertSame($permitted, (new HostPolicy($allowed))->isPermitted($host, $port, $addresses));
    }

    /**
     * @return array<string, array{list<string>, list<string>, string, int, list<string>, bool}>
     */
    public static function deniedHostProvider(): array
    {
        $ip = '93.184.216.34';
        $name = 'public.example.com';

        return [
            'denied IP' => [ [], [ $ip ], 'IP', 443, [ $ip ], false ],
            'denied IP other IP' => [ [], [ $ip ], 'IP', 443, [ '93.184.216.35' ], true ],
            'denied IP with port' => [ [], [ "$ip:8443" ], 'IP', 8443, [ $ip ], false ],
            'denied IP with other port' => [ [], [ "$ip:8443" ], 'IP', 443, [ $ip ], true ],
            'denied network' => [ [], [ '93.184.216.0/24' ], 'IP', 443, [ $ip ], false ],
            'denied IP by resolved name' => [ [], [ $ip ], $name, 443, [ $ip ], false ],
            'denied IPv6 by resolved name' => [ [], [ '2606:2800::/32' ], $name, 443, [ $ip, '2606:2800::1' ], false ],
            'denied hostname' => [ [], [ $name ], $name, 443, [ $ip ], false ],
            'denied hostname other name' => [ [], [ $name ], 'dual.example.com', 443, [ $ip ], true ],
            'denied suffix' => [ [], [ '.example.com' ], $name, 443, [ $ip ], false ],
            'denied hostname with port' => [ [], [ "$name:8443" ], $name, 443, [ $ip ], true ],

            // allowed entries take precedence
            'denied network, allowed IP' => [ [ $ip ], [ '93.184.216.0/24' ], 'IP', 443, [ $ip ], true ],
            'denied IP, allowed IP with port' => [ [ "$ip:443" ], [ $ip ], 'IP', 8443, [ $ip ], false ],
            'denied IP, allowed IP, by name' => [ [ $ip ], [ $ip ], $name, 443, [ $ip ], true ],
            'denied IP, allowed hostname' => [ [ $name ], [ $ip ], $name, 443, [ $ip ], true ],
            'denied suffix, allowed hostname' => [ [ $name ], [ '.example.com' ], $name, 443, [ $ip ], true ],
            'denied hostname, allowed IP' => [ [ $ip ], [ $name ], $name, 443, [ $ip ], false ],

            // a range not considered internal by the PHP filters available before PHP 8.2
            'not denied CGNAT range' => [ [], [], 'IP', 80, [ '100.64.0.1' ], true ],
            'denied CGNAT range' => [ [], [ '100.64.0.0/10' ], 'IP', 80, [ '100.64.0.1' ], false ],
        ];
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $denied
     * @param list<string> $addresses
     * @dataProvider deniedHostProvider
     */
    public function testDeniedHostsAreTreatedAsInternal(
        array $allowed,
        array $denied,
        string $host,
        int $port,
        array $addresses,
        bool $permitted
    ): void {
        $host = ($host === 'IP') ? $addresses[0] : $host;
        $this->assertSame($permitted, (new HostPolicy($allowed, $denied))->isPermitted($host, $port, $addresses));
    }

    /**
     * Tests that host entries are validated.
     */
    public function testHostEntriesAreValidated(): void
    {
        $valid = [ 'localhost', '.example.com', 'dav.example.com:8443', '10.0.0.5', '10.0.0.0/8:443', '[fd00::/8]:1' ];
        foreach ($valid as $entry) {
            $this->assertNotNull(HostPolicy::parseEntry($entry), "Valid entry not accepted: $entry");
        }

        foreach ([ '10.0.0.0/33', 'fd00::/129', '10.0.0.5:0', 'in tern', '', '*', 'http://localhost' ] as $entry) {
            $this->assertNull(HostPolicy::parseEntry($entry), "Invalid entry accepted: $entry");
        }
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
