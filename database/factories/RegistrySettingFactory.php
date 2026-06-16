<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RegistrySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrySetting>
 *
 * Story 27.3 — réglages de catalogue de test. Par défaut HKCU (portée session) ;
 * `machine()` bascule en HKLM (portée machine).
 */
class RegistrySettingFactory extends Factory
{
    protected $model = RegistrySetting::class;

    public function definition(): array
    {
        $name = 'Val' . fake()->unique()->numerify('####');

        return [
            'key' => 'setting_' . fake()->unique()->numerify('####'),
            'label' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'hive' => RegistrySetting::HIVE_USER,
            'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
            'name' => $name,
            'type' => 'REG_DWORD',
            'value' => '1',
            'is_active' => true,
        ];
    }

    public function machine(): static
    {
        return $this->state(fn () => [
            'hive' => RegistrySetting::HIVE_MACHINE,
            'path' => 'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Policies\\System',
        ]);
    }

    public function user(): static
    {
        return $this->state(fn () => [
            'hive' => RegistrySetting::HIVE_USER,
            'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
        ]);
    }
}
