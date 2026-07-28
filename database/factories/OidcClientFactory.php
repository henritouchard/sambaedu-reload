<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Extension;
use App\Models\OidcClient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 55.1 — Fabrique de clients OIDC.
 *
 * ⚠️ Le secret **clair** produit par cette fabrique est
 * {@see self::DEFAULT_SECRET} : il est connu des tests pour pouvoir authentifier
 * le client au token endpoint. Seul son sha256 est stocké — exactement comme en
 * production, où le clair n'est affiché qu'une fois par
 * `php artisan oidc:client:register`.
 *
 * @extends Factory<OidcClient>
 */
class OidcClientFactory extends Factory
{
    protected $model = OidcClient::class;

    /** Secret clair par défaut des clients de test (jamais utilisé en prod). */
    public const DEFAULT_SECRET = 'test-secret-0123456789abcdef0123456789abcdef';

    public function definition(): array
    {
        return [
            'extension_id' => null,
            'extension_key' => '',
            'name' => 'Client de test '.fake()->unique()->numerify('####'),
            'client_id' => bin2hex(random_bytes(16)),
            'client_secret_hash' => hash('sha256', self::DEFAULT_SECRET),
            'redirect_uris' => ['https://ext.example.test/callback'],
            'enabled' => true,
        ];
    }

    /** Client révoqué (`enabled = false`) — n'obtient plus ni code ni token. */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    /** Client adossé à une extension du registre (patron Epic 56). */
    public function forExtension(?Extension $extension = null): static
    {
        return $this->state(function () use ($extension): array {
            $extension ??= Extension::factory()->create();

            return [
                'extension_id' => $extension->id,
                'extension_key' => $extension->key,
            ];
        });
    }

    /** Surcharge des URI de redirection déclarées (liste stricte). */
    public function withRedirectUris(string ...$uris): static
    {
        return $this->state(fn (): array => ['redirect_uris' => array_values($uris)]);
    }
}
