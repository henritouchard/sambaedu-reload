<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Capability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 27.12 — factory de capacités (toggle windows par défaut). Les états
 * `enum()`/`scalar()` règlent `value_type`/`options` pour les tests UI/validation.
 *
 * @extends Factory<Capability>
 */
class CapabilityFactory extends Factory
{
    protected $model = Capability::class;

    public function definition(): array
    {
        return [
            'key' => 'cap_'.$this->faker->unique()->slug(2),
            'label' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'category' => 'Bureau',
            'value_type' => Capability::VALUE_TYPE_TOGGLE,
            'options' => [
                ['value' => 'on', 'label' => 'Activé'],
                ['value' => 'off', 'label' => 'Désactivé'],
            ],
            'default_value' => Capability::TOGGLE_ON,
            'warning' => null,
            'applies_to_os' => [Capability::OS_WINDOWS],
            'is_active' => true,
            'overrides_locked' => false,
        ];
    }

    /** Capacité à choix fermé (enum) — sélecteur en UI. */
    public function enum(array $options, string $default): self
    {
        return $this->state(fn (): array => [
            'value_type' => Capability::VALUE_TYPE_ENUM,
            'options' => $options,
            'default_value' => $default,
        ]);
    }

    /** Capacité scalaire (saisie libre). */
    public function scalar(string $default = ''): self
    {
        return $this->state(fn (): array => [
            'value_type' => Capability::VALUE_TYPE_SCALAR,
            'options' => null,
            'default_value' => $default,
        ]);
    }

    /** Capacité inactive (ni diffusée ni surchargeable). */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** Gèle l'ajout de nouveaux overrides (diffusion inchangée). */
    public function locked(): self
    {
        return $this->state(fn (): array => ['overrides_locked' => true]);
    }

    /** Porte un message d'implications à confirmer (capacité sensible). */
    public function withWarning(string $warning = 'Réglage sensible.'): self
    {
        return $this->state(fn (): array => ['warning' => $warning]);
    }
}
