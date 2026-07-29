<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionSourceSyncStatus;
use App\Models\ExtensionSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 54.1 — Fabrique de sources d'extensions.
 *
 * Défaut = source EMBARQUÉE anonyme (clé unique) ; l'état {@see self::bundled()}
 * produit LA source canonique `bundled` du dépôt.
 *
 * Story 56.1 : les états {@see self::remote()}, {@see self::syncError()} et
 * {@see self::unreachable()} couvrent les sources DISTANTES et leurs trois
 * états de synchro. L'URL d'une source distante est une URL de **BASE** (le
 * service compose `/index.json`, `/index.json.sig`, `/source.pub`).
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
     * Source DISTANTE (Story 56.1) : `$url` est l'URL de **BASE** du dépôt —
     * jamais `…/index.json`, que le service compose lui-même.
     *
     * `$publicKey` est la clé Ed25519 base64 PINNÉE. Vide par défaut : les
     * tests qui vérifient réellement une signature fabriquent leur paire
     * (`sodium_crypto_sign_keypair()`) et la passent ici — aucune fixture
     * binaire n'est commitée.
     */
    public function remote(string $url = 'https://extensions.example.test/depot', string $publicKey = ''): static
    {
        return $this->state(fn (): array => [
            'kind' => ExtensionSourceKind::Remote,
            'url' => $url,
            'is_official' => false,
            'public_key' => $publicKey,
        ]);
    }

    /** Source désactivée (Epic 56). */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    /** Source dont le dernier catalogue a été REFUSÉ (signature/contenu — fail-closed). */
    public function syncError(string $lastError = 'signature Ed25519 invalide pour la clé pinnée de la source'): static
    {
        return $this->state(fn (): array => [
            'sync_status' => ExtensionSourceSyncStatus::Error,
            'last_error' => $lastError,
        ]);
    }

    /** Source dont le dépôt est INJOIGNABLE (le dernier catalogue vérifié reste bon — NFR7). */
    public function unreachable(string $lastError = 'dépôt injoignable (connexion impossible)'): static
    {
        return $this->state(fn (): array => [
            'sync_status' => ExtensionSourceSyncStatus::Unreachable,
            'last_error' => $lastError,
        ]);
    }
}
