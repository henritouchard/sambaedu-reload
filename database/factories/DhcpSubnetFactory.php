<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DhcpSubnet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 8.3 — Factory des sous-réseaux DHCP (VLAN).
 *
 * @extends Factory<DhcpSubnet>
 */
class DhcpSubnetFactory extends Factory
{
    protected $model = DhcpSubnet::class;

    public function definition(): array
    {
        $octet = $this->faker->numberBetween(1, 254);

        return [
            'vlan_id' => $this->faker->unique()->numberBetween(1, 999),
            'network' => "192.168.{$octet}.0/24",
            'gateway' => "192.168.{$octet}.254",
            'ranges' => [
                ['begin' => "192.168.{$octet}.10", 'end' => "192.168.{$octet}.100"],
            ],
            'extra_option' => null,
            'description' => null,
        ];
    }
}
