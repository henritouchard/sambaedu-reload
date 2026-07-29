<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionSourceSyncStatus;
use App\Models\Extension;
use App\Models\ExtensionSource;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.1 (AC7, AR1) — `php artisan ext:sources:sync {key?}`.
 *
 * La commande n'a AUCUNE logique de synchro propre : elle appelle le même
 * service que le bouton « Actualiser » de l'UI. Ce qui est testé ici, c'est
 * donc son contrat d'OUTIL : sélection de la cible, code de sortie, refus
 * explicites.
 */
class ExtensionSourcesSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    /** @var array{public: string, secret: string} */
    private array $keys;

    /** @var array<string, array{body: string, status: int}|Closure> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->keys = [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
        $this->files = [];

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (! array_key_exists($url, $this->files)) {
                return Http::response('not found', 404);
            }
            $file = $this->files[$url];

            return $file instanceof Closure ? $file() : Http::response($file['body'], $file['status']);
        });
    }

    /** @param list<array<string, mixed>> $extensions */
    private function publish(string $base = self::BASE, array $extensions = [], ?string $secret = null): void
    {
        $index = (string) json_encode([
            'index_version' => 1,
            'extensions' => $extensions,
        ], JSON_UNESCAPED_SLASHES);

        $this->files[$base.'/index.json'] = ['body' => $index, 'status' => 200];
        $this->files[$base.'/index.json.sig'] = [
            'body' => base64_encode(sodium_crypto_sign_detached($index, (string) base64_decode($secret ?? $this->keys['secret'], true))),
            'status' => 200,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function manifest(string $id, array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'id' => $id,
            'type' => 'link',
            'name' => 'Extension '.$id,
            'version' => '1.0.0',
            'entry_url' => '/'.$id,
            'visibility' => ['roles' => ['admin']],
        ], $overrides);
    }

    private function source(string $key, string $base = self::BASE, array $overrides = []): ExtensionSource
    {
        return ExtensionSource::factory()
            ->remote($base, $this->keys['public'])
            ->create(array_merge(['key' => $key], $overrides));
    }

    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function it_synchronizes_a_single_source_by_key(): void
    {
        $this->source('academie');
        ExtensionSource::factory()->remote('https://autre.example.test/depot')->create(['key' => 'autre']);
        $this->publish(extensions: [$this->manifest('agenda')]);

        $this->artisan('ext:sources:sync', ['key' => 'academie'])
            ->assertExitCode(0);

        self::assertSame(1, Extension::query()->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'autre.example.test'));
    }

    #[Test]
    public function it_synchronizes_every_active_remote_source_by_default(): void
    {
        ExtensionSource::factory()->bundled()->create();
        $this->source('academie');
        $this->publish(extensions: [$this->manifest('agenda')]);

        $this->artisan('ext:sources:sync')->assertExitCode(0);

        self::assertSame(1, Extension::query()->count());
    }

    #[Test]
    public function it_reports_no_work_when_no_remote_source_exists(): void
    {
        ExtensionSource::factory()->bundled()->create();

        $this->artisan('ext:sources:sync')
            ->expectsOutputToContain('Aucune source distante active')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_exits_non_zero_when_a_catalog_is_refused(): void
    {
        $source = $this->source('academie');
        // Signé par une autre clé que celle pinnée.
        $other = sodium_crypto_sign_keypair();
        $this->publish(extensions: [$this->manifest('agenda')], secret: base64_encode(sodium_crypto_sign_secretkey($other)));

        $this->artisan('ext:sources:sync')->assertExitCode(1);

        self::assertSame(ExtensionSourceSyncStatus::Error, $source->fresh()->sync_status);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function it_exits_non_zero_when_a_repository_is_unreachable(): void
    {
        $this->source('academie');
        // Aucun fichier publié ⇒ 404 sur index.json.

        $this->artisan('ext:sources:sync')->assertExitCode(1);
    }

    #[Test]
    public function an_unknown_key_is_refused_without_touching_anything(): void
    {
        $this->artisan('ext:sources:sync', ['key' => 'inconnue'])
            ->expectsOutputToContain('introuvable')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_disabled_source_is_never_synchronized_even_when_named(): void
    {
        // Désactiver, c'est GELER : la commande ne contourne pas la décision
        // de l'admin.
        $this->source('academie', overrides: ['enabled' => false]);
        $this->publish(extensions: [$this->manifest('agenda')]);

        $this->artisan('ext:sources:sync', ['key' => 'academie'])
            ->expectsOutputToContain('désactivée')
            ->assertExitCode(1);

        self::assertSame(0, Extension::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function the_bundled_source_named_explicitly_is_refused(): void
    {
        $bundled = ExtensionSource::factory()->bundled()->create();

        $this->artisan('ext:sources:sync', ['key' => $bundled->key])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
