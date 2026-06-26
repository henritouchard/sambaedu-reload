<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Workstation;
use App\Services\Network\DhcpService;
use App\Services\Parc\WorkstationAddressResolver;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class WorkstationAddressResolverTest extends TestCase
{
    private DhcpService $dhcp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dhcp = Mockery::mock(DhcpService::class);
    }

    private function workstation(?string $name, ?string $mac, ?string $ip): Workstation
    {
        $w = new Workstation;
        $w->name = $name;
        $w->mac = $mac;
        $w->ip = $ip;

        return $w;
    }

    /**
     * @param  array<int,array<string,mixed>>  $leases
     */
    private function resolver(array $leases = [], ?string $dns = null): WorkstationAddressResolver
    {
        $this->dhcp->shouldReceive('listActiveLeases')->andReturn(new Collection($leases))->byDefault();

        // On stube le DNS via un partial mock pour ne pas dépendre du réseau.
        $resolver = Mockery::mock(WorkstationAddressResolver::class, [$this->dhcp])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        // gethostbyname renvoie l'argument inchangé en cas d'échec → on simule ça
        // quand aucune réponse DNS n'est fournie.
        $resolver->shouldReceive('lookupHostname')->andReturnUsing(
            fn (string $n) => $dns ?? $n
        );

        return $resolver;
    }

    public function test_resolves_from_active_lease_by_mac(): void
    {
        $resolver = $this->resolver([
            ['ip' => '10.0.1.77', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => 'post-neofut', 'state' => 'active', 'ends_at' => '2026/06/26 14:00:00'],
        ]);

        // MAC stockée en majuscule + tirets : doit matcher malgré le format.
        $w = $this->workstation('post-neofut', 'AA-BB-CC-DD-EE-FF', null);

        $this->assertSame('10.0.1.77', $resolver->resolve($w));
    }

    public function test_lease_match_prefers_most_recent_ends_at(): void
    {
        $resolver = $this->resolver([
            ['ip' => '10.0.1.10', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => null, 'state' => 'active', 'ends_at' => '2026/06/20 10:00:00'],
            ['ip' => '10.0.1.99', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => null, 'state' => 'active', 'ends_at' => '2026/06/26 18:00:00'],
        ]);

        $w = $this->workstation('post-neofut', 'aa:bb:cc:dd:ee:ff', null);

        $this->assertSame('10.0.1.99', $resolver->resolve($w));
    }

    public function test_falls_back_to_dns_when_no_lease(): void
    {
        $resolver = $this->resolver([], dns: '192.168.5.20');

        $w = $this->workstation('post-neofut', 'aa:bb:cc:dd:ee:ff', null);

        $this->assertSame('192.168.5.20', $resolver->resolve($w));
    }

    public function test_falls_back_to_stored_reservation_when_no_lease_no_dns(): void
    {
        // Pas de bail, DNS échoue (renvoie le nom) → on prend l'IP stockée.
        $resolver = $this->resolver([]);

        $w = $this->workstation('pc-reserved', 'aa:bb:cc:dd:ee:ff', '192.168.1.30');

        $this->assertSame('192.168.1.30', $resolver->resolve($w));
    }

    public function test_returns_null_when_nothing_resolves(): void
    {
        // post-neofut : pas de bail, pas de DNS, pas de réservation → null.
        $resolver = $this->resolver([]);

        $w = $this->workstation('post-neofut', 'aa:bb:cc:dd:ee:ff', null);

        $this->assertNull($resolver->resolve($w));
    }

    public function test_ignores_non_active_lease(): void
    {
        $resolver = $this->resolver([
            ['ip' => '10.0.1.77', 'mac' => 'aa:bb:cc:dd:ee:ff', 'hostname' => null, 'state' => 'free', 'ends_at' => '2026/06/26 14:00:00'],
        ]);

        $w = $this->workstation('post-neofut', 'aa:bb:cc:dd:ee:ff', null);

        $this->assertNull($resolver->resolve($w));
    }

    public function test_degrades_gracefully_when_leases_unreadable(): void
    {
        // listActiveLeases lève (fichier illisible) → on n'explose pas, on tente
        // le DNS puis la réservation stockée.
        $this->dhcp->shouldReceive('listActiveLeases')->andThrow(new \RuntimeException('leases unreadable'));

        $resolver = Mockery::mock(WorkstationAddressResolver::class, [$this->dhcp])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $resolver->shouldReceive('lookupHostname')->andReturnUsing(fn (string $n) => $n);

        $w = $this->workstation('pc-reserved', 'aa:bb:cc:dd:ee:ff', '192.168.1.40');

        $this->assertSame('192.168.1.40', $resolver->resolve($w));
    }
}
