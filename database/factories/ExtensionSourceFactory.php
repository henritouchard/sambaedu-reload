<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtensionSourceKind;
use App\Models\ExtensionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 54.1 — Fabrique de sources d'extensions.
 *
 * Défaut = source EMBARQUÉE anonyme (clé unique) ; l'état {@see self::bundled()}
 * produit LA source canonique `bundled` du dépôt.
 *
 * @extends Factory<ExtensionSource>
 */
class ExtensionSourceFactory extends Factory
{
    protected $model = ExtensionSource::class;

    public function definition(): array
    {
        return [
            'key' => 'source_'.fake()->unique()->numerify('####'),
            'name' => 'Source '.fake()->unique()->numerify('####'),
            'kind' => ExtensionSourceKind::Bundled,
            'url' => '',
            'is_official' => true,
            'enabled' => true,
        ];
    }

    /** LA source embarquée du dépôt SE5 (clé naturelle `bundled`). */
    public function bundled(): static
    {
        return $this->state(fn (): array => [
            'key' => ExtensionSource::KEY_BUNDLED,
            'name' => ExtensionSource::NAME_BUNDLED,
            'kind' => ExtensionSourceKind::Bundled,
            'url' => '',
            'is_official' => true,
        ]);
    }

    /**
     * Source DISTANTE — anticipation Epic 56 (aucune UI, aucun téléchargement
     * en 54.1 : sert à prouver que le modèle multi-sources tient).
     */
    public function remote(string $url = 'https://extensions.example.test/index.json'): static
    {
        return $this->state(fn (): array => [
            'kind' => ExtensionSourceKind::Remote,
            'url' => $url,
            'is_official' => false,
        ]);
    }

    /** Source désactivée (Epic 56). */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }
}
