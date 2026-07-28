<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\ExtensionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 54.1 — Fabrique d'extensions du registre.
 *
 * Le `manifest` généré est COHÉRENT avec les colonnes dénormalisées : c'est
 * l'invariant que produit la synchro réelle
 * ({@see \App\Services\Extensions\ExtensionCatalogService::syncBundled()}), et
 * les fiches le supposent.
 *
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
{
    protected $model = Extension::class;

    public function definition(): array
    {
        $key = 'ext_'.fake()->unique()->numerify('####');

        return [
            'extension_source_id' => ExtensionSource::factory(),
            'key' => $key,
            'name' => ucfirst(fake()->words(2, true)),
            'version' => '1.0.0',
            'publisher' => 'SambaEdu',
            'icon' => 'fa-solid fa-puzzle-piece',
            'description' => 'Extension de test.',
            'type' => ExtensionType::Link,
            'status' => ExtensionStatus::Available,
            'manifest' => [
                'manifest_version' => 1,
                'id' => $key,
                'type' => ExtensionType::Link->value,
                'name' => 'Extension de test',
                'version' => '1.0.0',
                'entry_url' => '/test',
                'icon' => 'fa-solid fa-puzzle-piece',
                'publisher' => 'SambaEdu',
                'description' => 'Extension de test.',
                'scopes' => [],
                'dependencies' => [],
                'visibility' => ['roles' => ['admin']],
            ],
        ];
    }

    /** Extension de type `link` (tuile pointant une URL déjà servie). */
    public function link(string $entryUrl = '/test'): static
    {
        return $this->state(function (array $attributes) use ($entryUrl): array {
            $manifest = $attributes['manifest'];
            $manifest['type'] = ExtensionType::Link->value;
            $manifest['entry_url'] = $entryUrl;

            return ['type' => ExtensionType::Link, 'manifest' => $manifest];
        });
    }

    /** Extension déjà INTÉGRÉE (état muté en Story 54.2 seulement). */
    public function integrated(): static
    {
        return $this->state(fn (): array => ['status' => ExtensionStatus::Integrated]);
    }

    /**
     * Extension rattachée à LA source embarquée du dépôt.
     *
     * `firstOrCreate` et non `ExtensionSource::factory()->bundled()` : la clé
     * `bundled` est UNIQUE — deux extensions embarquées dans le même test
     * doivent partager la MÊME source (comme en production), pas en créer deux.
     */
    public function fromBundled(): static
    {
        return $this->state(fn (): array => [
            'extension_source_id' => ExtensionSource::firstOrCreate(
                ['key' => ExtensionSource::KEY_BUNDLED],
                [
                    'name' => ExtensionSource::NAME_BUNDLED,
                    'kind' => ExtensionSourceKind::Bundled,
                    'url' => '',
                    'is_official' => true,
                    'enabled' => true,
                ],
            )->id,
        ]);
    }

    /**
     * Manifest enrichi : scopes demandés + dépendances (fiche non vide).
     *
     * @param  list<string>  $scopes
     * @param  list<string>  $dependencies
     */
    public function withManifestExtras(array $scopes, array $dependencies): static
    {
        return $this->state(function (array $attributes) use ($scopes, $dependencies): array {
            $manifest = $attributes['manifest'];
            $manifest['scopes'] = $scopes;
            $manifest['dependencies'] = $dependencies;

            return ['manifest' => $manifest];
        });
    }
}
