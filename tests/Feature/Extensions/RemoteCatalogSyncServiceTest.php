<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionSourceSyncStatus;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\ExtensionSourceException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use App\Services\Extensions\RemoteCatalogSyncService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.1 (AC1/AC4/AC5/AC6/AC7) — Synchronisation d'une source distante.
 *
 * Tout le réseau est simulé (`Http::fake()`), toutes les paires de clés sont
 * fabriquées en test (`sodium_crypto_sign_keypair()`) : aucune fixture binaire,
 * aucun accès réseau réel.
 *
 * Ce que cette suite verrouille, dans l'ordre d'importance :
 *
 *  1. **L'ordre des opérations** : la signature est vérifiée AVANT tout
 *     décodage. Un index à la fois mal signé ET malformé est refusé POUR SA
 *     SIGNATURE — la preuve que le parseur JSON n'a jamais été atteint.
 *  2. **Aucun chemin d'échec n'écrit ni ne prune.** C'est le sinistre que ce
 *     projet a déjà vécu (catalogue effacé par une synchro ratée) ; la règle
 *     est testée sur chacun des chemins d'échec.
 *  3. La clé pinnée n'est jamais renégociée, et `last_error` ne porte jamais
 *     l'URL du dépôt.
 *
 * Tests HÔTE (php 8.4 + pdo_sqlite + sodium natif), `RefreshDatabase`.
 */
class RemoteCatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    /** @var array{public: string, secret: string} */
    private array $keys;

    /**
     * Contenu servi par le faux dépôt, indexé par URL exacte.
     *
     * ⚠️ Un SEUL `Http::fake()` est enregistré, qui lit cette table à chaque
     * requête. `Http::fake()` FUSIONNE ses stubs et le premier motif qui
     * correspond gagne : re-faker en cours de test laisserait servir l'ancien
     * contenu, et un test « le dépôt a changé » vérifierait en réalité que rien
     * n'a changé — faux positif silencieux.
     *
     * @var array<string, array{body: string, status: int, headers: array<string, string>}|Closure>
     */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->keys = self::keypair();
        $this->files = [];

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->serve($request));
    }

    // ── Faux dépôt ────────────────────────────────────────────────────────

    private function serve(Request $request): mixed
    {
        $url = $request->url();

        if (! array_key_exists($url, $this->files)) {
            return Http::response('not found', 404);
        }

        $file = $this->files[$url];

        if ($file instanceof Closure) {
            return $file();
        }

        return Http::response($file['body'], $file['status'], $file['headers']);
    }

    private function serveFile(string $url, string $body, int $status = 200, array $headers = []): void
    {
        $this->files[$url] = ['body' => $body, 'status' => $status, 'headers' => $headers];
    }

    /** Le dépôt entier tombe (DNS, TCP, TLS, timeout). */
    private function breakRepository(string $base, string $message): void
    {
        foreach (['index.json', 'index.json.sig', 'source.pub'] as $file) {
            $this->files[$base.'/'.$file] = static fn () => throw new ConnectionException($message);
        }
    }

    /** Le dépôt répond, mais mal (5xx, 3xx…). */
    private function repositoryReturns(string $base, int $status, array $headers = []): void
    {
        foreach (['index.json', 'index.json.sig', 'source.pub'] as $file) {
            $this->serveFile($base.'/'.$file, '', $status, $headers);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{public: string, secret: string} */
    private static function keypair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    private function sign(string $bytes, ?string $secretB64 = null): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            $bytes,
            (string) base64_decode($secretB64 ?? $this->keys['secret'], true),
        ));
    }

    private function service(): RemoteCatalogSyncService
    {
        return app(RemoteCatalogSyncService::class);
    }

    private function source(array $overrides = []): ExtensionSource
    {
        return ExtensionSource::factory()
            ->remote(self::BASE, $this->keys['public'])
            ->create($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function manifest(string $id, array $overrides = []): array
    {
        // Story 56.2 (AR3) : une `app` DOIT déclarer `/ext/<id>` (chemin
        // provisionné par SE5) ; une `link` pointe où elle veut.
        $type = (string) ($overrides['type'] ?? 'link');

        return array_merge([
            'manifest_version' => 1,
            'id' => $id,
            'type' => 'link',
            'name' => 'Extension '.$id,
            'version' => '1.0.0',
            'entry_url' => $type === 'app' ? '/ext/'.$id : '/'.$id,
            'icon' => 'fa-solid fa-puzzle-piece',
            'publisher' => 'Éditeur tiers',
            'description' => 'Extension '.$id.'.',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => ['admin']],
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $extensions */
    private function index(array $extensions, array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'index_version' => 1,
            'name' => 'Dépôt de test',
            'publisher' => 'Académie de test',
            'extensions' => $extensions,
        ], $overrides), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Publie (ou re-publie) un dépôt : index + signature. */
    private function publish(string $indexJson, ?string $signature = null, string $base = self::BASE): void
    {
        $this->serveFile($base.'/index.json', $indexJson);
        $this->serveFile($base.'/index.json.sig', $signature ?? $this->sign($indexJson));
    }

    // =====================================================================
    // AC1 — chemin nominal
    // =====================================================================

    #[Test]
    public function a_verified_catalog_is_loaded_and_the_source_becomes_ok(): void
    {
        $source = $this->source();
        $this->publish($this->index([
            $this->manifest('agenda', ['name' => 'Agenda']),
            $this->manifest('reservation', ['type' => 'app']),
        ]));

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Ok->value, $result['status']);
        self::assertSame(2, $result['loaded']);
        self::assertSame(2, $result['created']);

        $fresh = $source->fresh();
        self::assertSame(ExtensionSourceSyncStatus::Ok, $fresh->sync_status);
        self::assertSame('', $fresh->last_error);
        self::assertNotNull($fresh->last_synced_at);

        $agenda = Extension::where('key', 'agenda')->first();
        self::assertNotNull($agenda);
        self::assertSame('Agenda', $agenda->name);
        self::assertSame($source->id, $agenda->extension_source_id);
        self::assertSame(ExtensionStatus::Available, $agenda->status);
        self::assertSame('/agenda', $agenda->entryUrl());

        // Une extension `app` d'une source tierce est bien AU CATALOGUE : son
        // installation relève de la 56.2, son affichage non.
        self::assertSame(ExtensionType::App, Extension::where('key', 'reservation')->firstOrFail()->type);
    }

    #[Test]
    public function replaying_the_sync_writes_nothing(): void
    {
        $source = $this->source();
        $this->publish($this->index([$this->manifest('agenda')]));
        $this->service()->sync($source);

        $before = Extension::where('key', 'agenda')->firstOrFail();
        $updatedAtBefore = (string) $before->updated_at;

        $result = $this->service()->sync($source->fresh());

        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['updated'], 'rien de sale ⇒ aucune écriture (invariant 54.1 #3)');
        self::assertSame(1, Extension::query()->count());
        self::assertSame($updatedAtBefore, (string) $before->fresh()->updated_at);
    }

    #[Test]
    public function a_faulty_manifest_is_skipped_without_breaking_the_others(): void
    {
        $source = $this->source();
        $this->publish($this->index([
            $this->manifest('agenda'),
            $this->manifest('broken', ['name' => '']),                 // champ requis vide
            $this->manifest('weird', ['type' => 'widget']),            // type inconnu
            $this->manifest('future', ['manifest_version' => 99]),     // version non supportée
            $this->manifest('evil', ['entry_url' => 'javascript:alert(1)']), // schéma refusé
            'pas un objet',                                            // entrée non exploitable
            $this->manifest('cdi'),
        ]));

        $result = $this->service()->sync($source);

        self::assertSame(2, $result['loaded']);
        self::assertSame(5, $result['skipped']);
        self::assertEqualsCanonicalizing(['agenda', 'cdi'], Extension::query()->pluck('key')->all());
        self::assertSame(ExtensionSourceSyncStatus::Ok, $source->fresh()->sync_status);
    }

    #[Test]
    public function the_prune_is_bounded_to_the_synced_source(): void
    {
        $source = $this->source();
        $other = ExtensionSource::factory()->remote('https://autre.example.test/depot')->create();
        $foreign = Extension::factory()->create(['extension_source_id' => $other->id, 'key' => 'agenda']);

        $this->publish($this->index([$this->manifest('agenda'), $this->manifest('cdi')]));
        $this->service()->sync($source);
        self::assertSame(3, Extension::query()->count());

        // « cdi » disparaît du catalogue publié.
        $this->publish($this->index([$this->manifest('agenda')]));
        $result = $this->service()->sync($source->fresh());

        self::assertSame(1, $result['pruned']);
        self::assertNull(Extension::where('extension_source_id', $source->id)->where('key', 'cdi')->first());
        self::assertNotNull($foreign->fresh(), 'le catalogue des autres sources est intouché');
    }

    #[Test]
    public function an_integrated_extension_survives_the_disappearance_of_its_manifest(): void
    {
        $source = $this->source();
        $this->publish($this->index([$this->manifest('agenda')]));
        $this->service()->sync($source);

        $agenda = Extension::where('key', 'agenda')->firstOrFail();
        $agenda->status = ExtensionStatus::Integrated;
        $agenda->save();

        $this->publish($this->index([]));
        $result = $this->service()->sync($source->fresh());

        self::assertSame(0, $result['pruned']);
        self::assertNotNull($agenda->fresh(), 'une intégrée n\'est jamais dé-intégrée silencieusement (invariant 54.1 #4)');
    }

    #[Test]
    public function two_sources_may_publish_the_same_extension_key(): void
    {
        // Collision TOLÉRÉE au catalogue : la clé naturelle est
        // `(source, key)`, chaque carte affiche sa provenance. L'unicité
        // GLOBALE ne devient une contrainte qu'à l'installation (Story 56.2).
        $first = $this->source();
        $secondKeys = self::keypair();
        $second = ExtensionSource::factory()
            ->remote('https://autre.example.test/depot', $secondKeys['public'])
            ->create();

        $index = $this->index([$this->manifest('agenda')]);
        $this->publish($index);
        $this->service()->sync($first);

        $this->publish($index, $this->sign($index, $secondKeys['secret']), 'https://autre.example.test/depot');
        $this->service()->sync($second);

        self::assertSame(2, Extension::where('key', 'agenda')->count());
    }

    // =====================================================================
    // AC4 — signature invalide ⇒ fail-closed
    // =====================================================================

    #[Test]
    public function an_invalid_signature_refuses_the_catalog_without_writing_anything(): void
    {
        $source = $this->source();
        $existing = Extension::factory()->create(['extension_source_id' => $source->id, 'key' => 'deja-la']);

        $index = $this->index([$this->manifest('agenda')]);
        $this->publish($index, $this->sign($index, self::keypair()['secret']));   // signé par une AUTRE clé

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertSame(0, $result['loaded']);
        self::assertSame(0, $result['created']);
        self::assertSame(0, $result['pruned']);

        self::assertNull(Extension::where('key', 'agenda')->first(), 'aucune extension écrite depuis un catalogue non vérifié');
        self::assertNotNull($existing->fresh(), 'les lignes existantes sont PRÉSERVÉES (aucun prune sur un chemin d\'échec)');
        self::assertSame(ExtensionSourceSyncStatus::Error, $source->fresh()->sync_status);
    }

    #[Test]
    public function the_signature_is_verified_before_any_decoding(): void
    {
        // L'index est À LA FOIS mal signé ET syntaxiquement invalide. Si le
        // décodage précédait la vérification, le refus serait motivé par le
        // JSON. Il est motivé par la SIGNATURE : le parseur n'a jamais été
        // atteint, et rien du contenu non vérifié n'a été interprété.
        $source = $this->source();
        $this->publish('{ ceci n\'est pas du JSON', 'c2lnbmF0dXJlLWJpZG9u');

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertStringContainsString('signature', $result['error']);
        self::assertStringNotContainsString('JSON', $result['error']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function a_repeated_signature_failure_logs_a_single_audit_transition(): void
    {
        $source = $this->source();
        $index = $this->index([$this->manifest('agenda')]);
        $this->publish($index, $this->sign($index, self::keypair()['secret']));

        $this->service()->sync($source);
        $this->service()->sync($source->fresh());
        $this->service()->sync($source->fresh());

        self::assertSame(
            1,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED)->count(),
            'le journal trace des TRANSITIONS, pas des re-échecs quotidiens',
        );

        $entry = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED)->firstOrFail();
        self::assertSame($source->id, $entry->extension_source_id);
        self::assertSame($source->key, $entry->source_key);
        self::assertNull($entry->actor_user_id);
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $entry->actor_login);
        self::assertSame('', $entry->extension_key);
    }

    #[Test]
    public function recovering_then_failing_again_logs_a_second_transition(): void
    {
        $source = $this->source();
        $index = $this->index([$this->manifest('agenda')]);

        $this->publish($index, $this->sign($index, self::keypair()['secret']));
        $this->service()->sync($source);

        $this->publish($index);                       // le dépôt se corrige
        $this->service()->sync($source->fresh());
        self::assertSame(ExtensionSourceSyncStatus::Ok, $source->fresh()->sync_status);

        $this->publish($index, $this->sign($index, self::keypair()['secret']));
        $this->service()->sync($source->fresh());

        self::assertSame(
            2,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED)->count(),
        );
    }

    #[Test]
    public function a_failed_sync_records_the_acting_admin(): void
    {
        $admin = User::query()->create(['login' => 'sync-admin', 'role' => 'autre', 'is_active' => true]);
        $source = $this->source();
        $index = $this->index([]);
        $this->publish($index, $this->sign($index, self::keypair()['secret']));

        $this->service()->sync($source, $admin);

        $entry = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED)->firstOrFail();
        self::assertSame($admin->id, $entry->actor_user_id);
        self::assertSame('sync-admin', $entry->actor_login);
    }

    #[Test]
    public function an_index_beyond_the_size_limit_is_refused(): void
    {
        config(['extensions.remote.index_max_bytes' => 256]);
        $source = $this->source();
        $index = $this->index([$this->manifest('agenda', ['description' => str_repeat('A', 4096)])]);
        $this->publish($index);           // signature PARFAITEMENT valide

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertStringContainsString('taille', $result['error']);
        self::assertSame(0, Extension::query()->count(), 'la borne est vérifiée AVANT la signature — rien n\'est chargé');
    }

    #[Test]
    public function an_index_announcing_a_huge_content_length_is_refused_without_reading_it(): void
    {
        // Review 56.1 #2 — la borne doit mordre AVANT que le corps ne soit en
        // mémoire. Ici le corps servi est minuscule et parfaitement signé :
        // seul l'en-tête `Content-Length` annonce l'énormité. Le refus prouve
        // que l'en-tête est consulté en premier, sans quoi ce catalogue
        // passerait `ok`.
        $source = $this->source();
        $index = $this->index([$this->manifest('agenda')]);
        $this->serveFile(self::BASE.'/index.json', $index, 200, ['Content-Length' => '2147483648']);
        $this->serveFile(self::BASE.'/index.json.sig', $this->sign($index));

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertStringContainsString('taille', $result['error']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function an_oversized_signature_file_is_refused(): void
    {
        $source = $this->source();
        $this->publish($this->index([]), str_repeat('A', 5000));

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertStringContainsString('signature', $result['error']);
    }

    #[Test]
    public function an_unknown_index_version_is_refused_after_the_signature(): void
    {
        $source = $this->source();
        $this->publish($this->index([$this->manifest('agenda')], ['index_version' => 2]));

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertStringContainsString('version', $result['error']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function a_loosely_typed_index_version_is_not_version_one(): void
    {
        // Mêmes règles de normalisation que `manifest_version` : « 1.0 » ou
        // « v1 » ne sont PAS la version 1 (aucun repli tolérant).
        foreach (['1.0', 'v1', '', null, ['1'], true] as $declared) {
            $source = $this->source(['key' => 'src-'.md5(serialize($declared))]);
            $this->publish($this->index([$this->manifest('agenda')], ['index_version' => $declared]));

            $result = $this->service()->sync($source);

            self::assertSame(
                ExtensionSourceSyncStatus::Error->value,
                $result['status'],
                'index_version = '.var_export($declared, true).' doit être refusée',
            );
        }

        // Un littéral JSON flottant (`1.0`) décode en float : ce n'est pas non
        // plus la version 1.
        $source = $this->source(['key' => 'src-float']);
        $this->publish('{"index_version":1.0,"extensions":[]}');
        self::assertSame(ExtensionSourceSyncStatus::Error->value, $this->service()->sync($source)['status']);

        // Contre-épreuve : la chaîne « 1 » EST la version 1.
        $source = $this->source(['key' => 'src-string-one']);
        $this->publish($this->index([$this->manifest('agenda')], ['index_version' => '1']));
        self::assertSame(ExtensionSourceSyncStatus::Ok->value, $this->service()->sync($source)['status']);
    }

    #[Test]
    public function a_malformed_extensions_list_is_refused(): void
    {
        $source = $this->source();
        $this->publish($this->index([], ['extensions' => ['agenda' => $this->manifest('agenda')]]));

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertStringContainsString('extensions', $result['error']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function an_index_that_is_not_a_json_object_is_refused(): void
    {
        $source = $this->source();
        $this->publish('[1, 2, 3]');            // JSON valide, mais pas un objet

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function an_empty_index_is_refused(): void
    {
        $source = $this->source();
        $this->publish('');

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
    }

    // =====================================================================
    // AC5 — dépôt injoignable ⇒ dégradation propre (NFR7)
    // =====================================================================

    #[Test]
    public function a_connection_failure_marks_the_source_unreachable_without_touching_the_catalog(): void
    {
        $source = $this->source();
        $kept = Extension::factory()->create(['extension_source_id' => $source->id, 'key' => 'agenda']);
        $integrated = Extension::factory()->integrated()->create([
            'extension_source_id' => $source->id,
            'key' => 'cdi',
        ]);

        $this->breakRepository(self::BASE, 'cURL error 28: timeout for '.self::BASE.'/index.json?token=SECRET');

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Unreachable->value, $result['status']);
        self::assertSame(0, $result['pruned']);
        self::assertNotNull($kept->fresh(), 'le dernier catalogue VÉRIFIÉ reste en place (le registre EST le cache)');
        self::assertNotNull($integrated->fresh());
        self::assertSame(ExtensionSourceSyncStatus::Unreachable, $source->fresh()->sync_status);
    }

    #[Test]
    public function a_server_error_marks_the_source_unreachable(): void
    {
        $source = $this->source();
        $this->repositoryReturns(self::BASE, 503);

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Unreachable->value, $result['status']);
        self::assertStringContainsString('503', $result['error']);
    }

    #[Test]
    public function a_redirect_is_never_followed_and_counts_as_unreachable(): void
    {
        // `allow_redirects => false` : un dépôt ne peut pas emmener SE5 vers un
        // autre hôte. Une 3xx EST une indisponibilité de ce point d'accès.
        $source = $this->source();
        $this->repositoryReturns(self::BASE, 302, ['Location' => 'https://attaquant.example.test/index.json']);

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Unreachable->value, $result['status']);
        self::assertStringContainsString('302', $result['error']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'attaquant.example.test'));
    }

    #[Test]
    public function a_missing_signature_file_marks_the_source_unreachable(): void
    {
        $source = $this->source();
        $this->serveFile(self::BASE.'/index.json', $this->index([]));
        $this->serveFile(self::BASE.'/index.json.sig', '', 404);

        $result = $this->service()->sync($source);

        self::assertSame(ExtensionSourceSyncStatus::Unreachable->value, $result['status']);
        self::assertSame(0, Extension::query()->count());
    }

    #[Test]
    public function the_last_synced_at_of_a_previously_verified_source_survives_an_outage(): void
    {
        $source = $this->source();
        $this->publish($this->index([$this->manifest('agenda')]));
        $this->service()->sync($source);
        $syncedAt = (string) $source->fresh()->last_synced_at;

        $this->breakRepository(self::BASE, 'down');
        $this->service()->sync($source->fresh());

        self::assertSame(
            $syncedAt,
            (string) $source->fresh()->last_synced_at,
            'last_synced_at date la dernière synchro RÉUSSIE',
        );
    }

    // =====================================================================
    // Secrets : ce qui est persisté ne doit jamais porter d'URL
    // =====================================================================

    #[Test]
    public function the_persisted_error_never_contains_the_repository_url(): void
    {
        // Une URL de dépôt peut porter un jeton (`?private_token=…`) : le
        // message d'exception Guzzle, lui, suffixe toujours l'URI complète.
        $base = 'https://gitlab.example.test/depot';
        $source = ExtensionSource::factory()->remote($base, $this->keys['public'])->create();

        $this->breakRepository($base, 'cURL error 7 for '.$base.'/index.json?private_token=GLPAT-supersecret');

        $this->service()->sync($source);

        $lastError = (string) $source->fresh()->last_error;
        self::assertNotSame('', $lastError);
        self::assertStringNotContainsString('gitlab.example.test', $lastError);
        self::assertStringNotContainsString('private_token', $lastError);
        self::assertStringNotContainsString('GLPAT', $lastError);
        self::assertStringNotContainsString('http', $lastError);
    }

    // =====================================================================
    // AC6 — la clé pinnée n'est JAMAIS renégociée
    // =====================================================================

    #[Test]
    public function a_sync_never_downloads_the_public_key_again(): void
    {
        $source = $this->source();
        $this->publish($this->index([$this->manifest('agenda')]));

        $this->service()->sync($source);
        $this->service()->sync($source->fresh());

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'source.pub'));
        self::assertSame($this->keys['public'], $source->fresh()->public_key, 'la clé pinnée n\'est jamais réécrite');
    }

    #[Test]
    public function a_repository_that_rotates_its_key_falls_into_error(): void
    {
        // La rotation légitime est un retrait + un ré-ajout explicites. Un
        // dépôt ne peut pas imposer sa nouvelle clé.
        $source = $this->source();
        $index = $this->index([$this->manifest('agenda')]);
        $this->publish($index);
        $this->service()->sync($source);
        self::assertSame(1, Extension::query()->count());

        $rotated = self::keypair();
        $this->publish($index, $this->sign($index, $rotated['secret']));
        $this->serveFile(self::BASE.'/source.pub', $rotated['public']);

        $result = $this->service()->sync($source->fresh());

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        self::assertSame($this->keys['public'], $source->fresh()->public_key);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'source.pub'));
        self::assertSame(1, Extension::query()->count(), 'le catalogue déjà vérifié est conservé');
    }

    // =====================================================================
    // AC7 — syncAll
    // =====================================================================

    #[Test]
    public function sync_all_covers_active_remote_sources_only(): void
    {
        ExtensionSource::factory()->bundled()->create();
        $active = $this->source(['key' => 'actif']);
        ExtensionSource::factory()->remote('https://gele.example.test/depot')->disabled()->create(['key' => 'gele']);

        $this->publish($this->index([$this->manifest('agenda')]));

        $results = $this->service()->syncAll();

        self::assertCount(1, $results);
        self::assertSame($active->key, $results[0]['source']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'gele.example.test'));
    }

    #[Test]
    public function one_failing_source_never_stops_the_others(): void
    {
        $goodKeys = self::keypair();
        $good = ExtensionSource::factory()->remote('https://bon.example.test/depot', $goodKeys['public'])->create(['key' => 'aaa-bon']);
        $bad = ExtensionSource::factory()->remote('https://casse.example.test/depot', $this->keys['public'])->create(['key' => 'zzz-casse']);

        $index = $this->index([$this->manifest('agenda')]);
        $this->publish($index, $this->sign($index, $goodKeys['secret']), 'https://bon.example.test/depot');
        $this->repositoryReturns('https://casse.example.test/depot', 500);

        $results = $this->service()->syncAll();

        self::assertCount(2, $results);
        self::assertSame(ExtensionSourceSyncStatus::Ok->value, $results[0]['status']);
        self::assertSame(ExtensionSourceSyncStatus::Unreachable->value, $results[1]['status']);
        self::assertSame(1, Extension::where('extension_source_id', $good->id)->count());
        self::assertSame(0, Extension::where('extension_source_id', $bad->id)->count());
    }

    #[Test]
    public function the_bundled_source_is_not_synchronizable_over_the_network(): void
    {
        $bundled = ExtensionSource::factory()->bundled()->create();

        $this->expectException(ExtensionSourceException::class);

        $this->service()->sync($bundled);
    }

    // =====================================================================
    // TOFU — lecture unique de source.pub
    // =====================================================================

    #[Test]
    public function fetching_a_public_key_validates_it(): void
    {
        $this->serveFile(self::BASE.'/source.pub', $this->keys['public']."\n");

        self::assertSame($this->keys['public'], $this->service()->fetchPublicKey(self::BASE));
    }

    #[Test]
    public function an_unreadable_or_invalid_public_key_is_refused(): void
    {
        $this->serveFile(self::BASE.'/source.pub', '', 404);
        try {
            $this->service()->fetchPublicKey(self::BASE);
            self::fail('une clé absente doit être refusée');
        } catch (ExtensionSourceException) {
            self::assertTrue(true);
        }

        $this->serveFile(self::BASE.'/source.pub', 'pas une clé');
        $this->expectException(ExtensionSourceException::class);
        $this->service()->fetchPublicKey(self::BASE);
    }

    #[Test]
    public function an_oversized_public_key_file_is_refused(): void
    {
        $this->serveFile(self::BASE.'/source.pub', str_repeat('A', 2048));

        $this->expectException(ExtensionSourceException::class);

        $this->service()->fetchPublicKey(self::BASE);
    }
}
