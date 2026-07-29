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
     * Story 56.2 — Extension de type `app`.
     *
     * `entry_url` vaut EXACTEMENT `/ext/<key>` : c'est la règle AR3 du
     * validateur, et la fabrique doit produire ce que la synchro réelle
     * produirait (sinon un test passerait sur une donnée impossible).
     */
    public function app(): static
    {
        return $this->state(function (array $attributes): array {
            $manifest = $attributes['manifest'];
            $manifest['type'] = ExtensionType::App->value;
            $manifest['entry_url'] = '/ext/'.$attributes['key'];

            return ['type' => ExtensionType::App, 'manifest' => $manifest];
        });
    }

    /**
     * Story 56.2 — Bloc `install` du manifest (paquet `deb` + sha256).
     *
     * Le sha256 par défaut est un hexadécimal arbitraire : les tests qui
     * vérifient réellement le hash passent le leur (`hash('sha256', $octets)`).
     *
     * @param  list<string>|null  $redirectPaths
     */
    public function withInstallBlock(?string $sha256 = null, ?array $redirectPaths = null): static
    {
        return $this->state(function (array $attributes) use ($sha256, $redirectPaths): array {
            $manifest = $attributes['manifest'];
            $manifest['install'] = [
                'channel' => 'deb',
                'package' => 'packages/sambaedu-ext-'.$attributes['key'].'_1.0.0_all.deb',
                'sha256' => $sha256 ?? str_repeat('ab', 32),
                'redirect_paths' => $redirectPaths ?? [],
            ];

            return ['manifest' => $manifest];
        });
    }

    /**
     * Story 56.2 — `app` déjà INSTALLÉE (état posé par
     * {@see \App\Services\Extensions\ExtensionLifecycleService::markAppInstalled()}).
     */
    public function installed(int $port, string $version = '1.0.0'): static
    {
        return $this->state(fn (): array => [
            'status' => ExtensionStatus::Integrated,
            'installed_version' => $version,
            'installed_port' => $port,
            'installed_at' => now(),
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
