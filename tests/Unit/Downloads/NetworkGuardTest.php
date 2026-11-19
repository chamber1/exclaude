<?php

namespace Tests\Unit\Downloads;

use App\Downloads\Exceptions\DownloadRejected;
use App\Downloads\NetworkGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NetworkGuardTest extends TestCase
{
    public static function privateAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], [
            '0.0.0.0', '10.1.2.3', '100.64.0.1', '127.0.0.1', '127.255.255.254', '169.254.169.254',
            '172.16.0.1', '172.31.255.255', '192.168.1.1', '192.0.2.1', '198.18.0.1', '224.0.0.1', '255.255.255.255',
            '::', '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'ff02::1', '2001:db8::1',
            // IPv4 hidden inside IPv6.
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a9fe:a9fe', '2002:7f00:0001::1',
            'not an ip', '',
        ]);
    }

    #[DataProvider('privateAddresses')]
    public function test_private_addresses_are_not_public(string $ip): void
    {
        $this->assertFalse(NetworkGuard::isPublicIp($ip));
    }

    public static function publicAddresses(): array
    {
        return array_map(fn ($ip) => [$ip], ['142.250.74.78', '8.8.8.8', '172.32.0.1', '100.128.0.1', '2a00:1450:4001:82b::200e', '::ffff:8.8.8.8']);
    }

    #[DataProvider('publicAddresses')]
    public function test_public_addresses_are_public(string $ip): void
    {
        $this->assertTrue(NetworkGuard::isPublicIp($ip));
    }

    public static function ipLiterals(): array
    {
        return array_map(fn ($h) => [$h], ['127.0.0.1', '[::1]', '::1', '2130706433', '0x7f000001', '0177.0.0.1', '127.1', '0x7f.1']);
    }

    #[DataProvider('ipLiterals')]
    public function test_ip_literals_are_detected(string $host): void
    {
        $this->assertTrue(NetworkGuard::isIpLiteral($host));
    }

    public function test_host_names_are_not_ip_literals(): void
    {
        $this->assertFalse(NetworkGuard::isIpLiteral('www.youtube.com'));
        $this->assertFalse(NetworkGuard::isIpLiteral('youtu.be'));
    }

    public function test_host_resolving_to_public_addresses_passes(): void
    {
        (new NetworkGuard(fn () => ['142.250.74.78', '2a00:1450:4001:82b::200e']))->assertPublicHost('www.youtube.com');

        $this->addToAssertionCount(1);
    }

    public function test_host_with_one_private_address_is_rejected(): void
    {
        $this->expectExceptionObject(DownloadRejected::privateAddress());

        (new NetworkGuard(fn () => ['142.250.74.78', '10.0.0.5']))->assertPublicHost('www.youtube.com');
    }

    public function test_unresolvable_host_is_rejected_as_temporary_error(): void
    {
        try {
            (new NetworkGuard(fn () => []))->assertPublicHost('www.youtube.com');
            $this->fail('No exception');
        } catch (DownloadRejected $e) {
            $this->assertSame('unresolvable_host', $e->reason);
            $this->assertSame(503, $e->status);
        }
    }
}
