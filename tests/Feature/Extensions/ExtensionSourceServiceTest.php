<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionSourceSyncStatus;
use App\Enums\ExtensionStatus;
use App\Exceptions\ExtensionSourceException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use App\Services\Extensions\ExtensionSourceService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.1 (AC1/AC3/AC6) — Actes d'administration d'une source.
 *
 * Le cœur testé ici est le **pin de clé** : par où elle peut entrer (collée, ou
 * lue une seule fois sous https), par où elle ne peut PAS entrer (http sans
 * clé, dépôt qui rote sa clé), et le fait qu'elle ne soit jamais renégociée.
 * Puis les gardes de cycle de vie (bundled intouchable, retrait bloqué par une
 * intégrée) et la discipline d'audit (no-op = zéro ligne).
 *
 * Tests HÔTE (php 8.4 + pdo_sqlite + sodium natif), `RefreshDatabase`.
 */
class ExtensionSourceServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    private User $admin;

    /** @var array{public: string, secret: string} */
    private array $keys;

    /** @var array<string, array{body: string, status: int}|Closure> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create(['login' => 'source-admin', 'role' => 'autre', 'is_active' => true]);
        $this->keys = self::keypair();
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

    private function service(): ExtensionSourceService
    {
        return app(ExtensionSourceService::class);
    }

    private function serveFile(string $url, string $body, int $status = 200): void
    {
        $this->files[$url] = ['body' => $body, 'status' => $status];
    }

    /** @param list<array<string, mixed>> $extensions */
    private function publish(string $base = self::BASE, array $extensions = [], ?string $secret = null): void
    {
        $index = (string) json_encode([
            'index_version' => 1,
            'name' => 'Dépôt de test',
            'extensions' => $extensions,
        ], JSON_UNESCAPED_SLASHES);

        $this->serveFile($base.'/index.json', $index);
        $this->serveFile(
            $base.'/index.json.sig',
            base64_encode(sodium_crypto_sign_detached($index, (string) base64_decode($secret ?? $this->keys['secret'], true))),
        );
    }

    private function publishPublicKey(string $base = self::BASE, ?string $key = null): void
    {
        $this->serveFile($base.'/source.pub', ($key ?? $this->keys['public'])."\n");
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

    // =====================================================================
    // AC1 — ajout
    // =====================================================================

    #[Test]
    public function a_source_is_added_with_a_pasted_key_and_synchronized_at_once(): void
    {
        $this->publish(extensions: [$this->manifest('agenda')]);

        $result = $this->service()->add('Extensions Académie', self::BASE, $this->keys['public'], $this->admin);

        self::assertSame(ExtensionSourceSyncStatus::Ok->value, $result['status']);
        self::assertSame(1, $result['loaded']);

        $source = ExtensionSource::query()->where('url', self::BASE)->firstOrFail();
        self::assertSame(ExtensionSourceKind::Remote, $source->kind);
        self::assertFalse($source->is_official, 'une source ajoutée par un admin n\'est jamais « officielle »');
        self::assertTrue($source->enabled);
        self::assertSame($this->keys['public'], $source->public_key);
        self::assertSame('extensions-academie', $source->key);

        self::assertSame(1, Extension::where('extension_source_id', $source->id)->count());

        // Aucun appel à source.pub : la clé était fournie.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'source.pub'));
    }

    #[Test]
    public function an_https_source_without_a_pasted_key_reads_source_pub_exactly_once(): void
    {
        $this->publishPublicKey();
        $this->publish(extensions: [$this->manifest('agenda')]);

        $result = $this->service()->add('Dépôt TOFU', self::BASE, null, $this->admin);

        $source = ExtensionSource::query()->findOrFail($result['id']);
        self::assertSame($this->keys['public'], $source->public_key);

        Http::assertSentCount(3);   // source.pub + index.json + index.json.sig
        self::assertSame(
            1,
            collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), 'source.pub'))->count(),
            'la clé n\'est lue QU\'UNE fois, à l\'ajout',
        );
    }

    #[Test]
    public function an_http_source_without_a_pasted_key_is_refused(): void
    {
        // Sur un canal en clair, un intermédiaire fournirait SA clé et
        // signerait SON catalogue : la signature ne prouverait plus rien.
        $this->expectException(ExtensionSourceException::class);
        $this->expectExceptionMessageMatches('/clé publique vous-même/');

        try {
            $this->service()->add('Miroir LAN', 'http://miroir.lan/extensions', null, $this->admin);
        } finally {
            self::assertSame(0, ExtensionSource::query()->count(), 'aucune source créée sur un refus');
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function an_http_source_with_a_pasted_key_is_accepted(): void
    {
        // Le miroir LAN hors-ligne (AR9) reste possible : c'est la RÉCUPÉRATION
        // de la clé qui exige TLS, pas la source elle-même.
        $this->publish('http://miroir.lan/extensions', [$this->manifest('agenda')]);

        $result = $this->service()->add('Miroir LAN', 'http://miroir.lan/extensions', $this->keys['public'], $this->admin);

        self::assertSame(ExtensionSourceSyncStatus::Ok->value, $result['status']);
        self::assertSame(1, ExtensionSource::query()->count());
    }

    #[Test]
    public function an_invalid_pasted_key_is_refused_before_any_network_call(): void
    {
        $this->expectException(ExtensionSourceException::class);

        try {
            $this->service()->add('Dépôt', self::BASE, 'ceci-n-est-pas-une-cle', $this->admin);
        } finally {
            self::assertSame(0, ExtensionSource::query()->count());
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function an_unreachable_source_pub_refuses_the_creation(): void
    {
        // On ne crée pas une source sans clé : elle serait condamnée à l'erreur
        // ET donnerait l'illusion d'un pin.
        $this->expectException(ExtensionSourceException::class);

        try {
            $this->service()->add('Dépôt', self::BASE, null, $this->admin);
        } finally {
            self::assertSame(0, ExtensionSource::query()->count());
        }
    }

    #[Test]
    public function malformed_urls_are_refused(): void
    {
        $refused = [
            'ftp://depot.example.test/ext',                       // schéma
            'depot.example.test/ext',                             // pas de schéma
            'https://',                                           // pas d'hôte
            'https://user:pass@depot.example.test/ext',           // identifiants
            'https://depot.example.test/ext?private_token=abc',   // query
            'https://depot.example.test/ext#ancre',               // fragment
            'javascript:alert(1)',
            '',
        ];

        foreach ($refused as $url) {
            try {
                $this->service()->add('Dépôt', $url, $this->keys['public'], $this->admin);
                self::fail("l'URL « {$url} » aurait dû être refusée");
            } catch (ExtensionSourceException) {
                self::assertTrue(true);
            }
        }

        self::assertSame(0, ExtensionSource::query()->count());
    }

    #[Test]
    public function an_empty_name_is_refused(): void
    {
        $this->expectException(ExtensionSourceException::class);

        $this->service()->add('   ', self::BASE, $this->keys['public'], $this->admin);
    }

    #[Test]
    public function the_same_repository_cannot_be_registered_twice(): void
    {
        $this->publish();
        $this->service()->add('Premier', self::BASE, $this->keys['public'], $this->admin);

        try {
            // Le `/` final ne fait pas un dépôt différent.
            $this->service()->add('Doublon', self::BASE.'/', $this->keys['public'], $this->admin);
            self::fail('le doublon d\'URL doit être refusé');
        } catch (ExtensionSourceException $e) {
            self::assertStringContainsString('Premier', $e->getMessage());
        }

        self::assertSame(1, ExtensionSource::query()->count());
    }

    #[Test]
    public function two_sources_with_the_same_name_get_distinct_keys(): void
    {
        $this->publish();
        $this->publish('https://autre.example.test/depot');

        $first = $this->service()->add('Extensions', self::BASE, $this->keys['public'], $this->admin);
        $second = $this->service()->add('Extensions', 'https://autre.example.test/depot', $this->keys['public'], $this->admin);

        self::assertSame('extensions', $first['key']);
        self::assertSame('extensions-2', $second['key']);
    }

    #[Test]
    public function a_source_whose_catalog_is_refused_still_exists_and_is_marked_in_error(): void
    {
        // Elle DOIT exister : sinon l'admin n'a rien à inspecter ni à retirer.
        $this->publish(secret: self::keypair()['secret']);

        $result = $this->service()->add('Dépôt douteux', self::BASE, $this->keys['public'], $this->admin);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);
        $source = ExtensionSource::query()->findOrFail($result['id']);
        self::assertSame(ExtensionSourceSyncStatus::Error, $source->sync_status);
        self::assertStringContainsString('signature', $source->last_error);
    }

    #[Test]
    public function adding_a_source_is_audited(): void
    {
        $this->publish();
        $result = $this->service()->add('Dépôt', self::BASE, $this->keys['public'], $this->admin);

        $entry = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_ADD)->firstOrFail();
        self::assertSame($result['id'], $entry->extension_source_id);
        self::assertSame($result['key'], $entry->source_key);
        self::assertSame($this->admin->id, $entry->actor_user_id);
        self::assertSame('source-admin', $entry->actor_login);
        self::assertSame('', $entry->extension_key, 'un événement de source ne nomme aucune extension');
    }

    // =====================================================================
    // AC6 — la clé pinnée n'est jamais renégociée
    // =====================================================================

    #[Test]
    public function refreshing_never_downloads_source_pub_again(): void
    {
        $this->publishPublicKey();
        $this->publish(extensions: [$this->manifest('agenda')]);
        $added = $this->service()->add('Dépôt TOFU', self::BASE, null, $this->admin);

        // Le dépôt tourne sa clé et republie tout, y compris son source.pub.
        $rotated = self::keypair();
        $this->publishPublicKey(key: $rotated['public']);
        $this->publish(extensions: [$this->manifest('agenda')], secret: $rotated['secret']);

        $result = $this->service()->refresh($added['id'], $this->admin);

        self::assertSame(ExtensionSourceSyncStatus::Error->value, $result['status']);

        $source = ExtensionSource::query()->findOrFail($added['id']);
        self::assertSame($this->keys['public'], $source->public_key, 'la clé pinnée est immuable');

        $pubCalls = collect(Http::recorded())->filter(fn ($pair): bool => str_contains($pair[0]->url(), 'source.pub'))->count();
        self::assertSame(1, $pubCalls, 'source.pub n\'est lu qu\'à l\'ajout, jamais au rafraîchissement');
    }

    // =====================================================================
    // AC3 — activer / désactiver / retirer
    // =====================================================================

    #[Test]
    public function disabling_and_enabling_a_source_is_audited_once_each(): void
    {
        $this->publish();
        $added = $this->service()->add('Dépôt', self::BASE, $this->keys['public'], $this->admin);

        $disabled = $this->service()->disable($added['id'], $this->admin);
        self::assertTrue($disabled['changed']);
        self::assertFalse(ExtensionSource::query()->findOrFail($added['id'])->enabled);

        $enabled = $this->service()->enable($added['id'], $this->admin);
        self::assertTrue($enabled['changed']);
        self::assertTrue(ExtensionSource::query()->findOrFail($added['id'])->enabled);

        self::assertSame(1, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_DISABLE)->count());
        self::assertSame(1, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_ENABLE)->count());
    }

    #[Test]
    public function a_no_op_toggle_writes_neither_a_row_nor_an_audit_entry(): void
    {
        $this->publish();
        $added = $this->service()->add('Dépôt', self::BASE, $this->keys['public'], $this->admin);
        $source = ExtensionSource::query()->findOrFail($added['id']);
        $updatedAt = (string) $source->updated_at;

        $result = $this->service()->enable($added['id'], $this->admin);   // déjà active

        self::assertFalse($result['changed']);
        self::assertSame(0, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_ENABLE)->count());
        self::assertSame($updatedAt, (string) $source->fresh()->updated_at, 'pas même un updated_at');
    }

    #[Test]
    public function removing_a_source_drops_its_extensions_and_keeps_the_audit_trail(): void
    {
        $this->publish(extensions: [$this->manifest('agenda')]);
        $added = $this->service()->add('Dépôt', self::BASE, $this->keys['public'], $this->admin);
        self::assertSame(1, Extension::query()->count());

        $result = $this->service()->remove($added['id'], $this->admin);

        self::assertTrue($result['removed']);
        self::assertSame(0, ExtensionSource::query()->count());
        self::assertSame(0, Extension::query()->count(), 'cascade FK');

        $entry = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_REMOVE)->firstOrFail();
        self::assertSame($added['key'], $entry->source_key, 'la trace reste lisible après le retrait');
    }

    #[Test]
    public function removing_a_source_is_refused_while_one_of_its_extensions_is_integrated(): void
    {
        $this->publish(extensions: [$this->manifest('agenda'), $this->manifest('cdi')]);
        $added = $this->service()->add('Dépôt', self::BASE, $this->keys['public'], $this->admin);

        $agenda = Extension::where('key', 'agenda')->firstOrFail();
        $agenda->status = ExtensionStatus::Integrated;
        $agenda->save();

        try {
            $this->service()->remove($added['id'], $this->admin);
            self::fail('le retrait doit être refusé');
        } catch (ExtensionSourceException $e) {
            self::assertStringContainsString('Extension agenda', $e->getMessage(), 'le refus NOMME l\'extension bloquante');
        }

        self::assertSame(1, ExtensionSource::query()->count());
        self::assertSame(2, Extension::query()->count());
        self::assertSame(0, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_SOURCE_REMOVE)->count());
    }

    #[Test]
    public function the_bundled_source_can_be_neither_disabled_nor_removed(): void
    {
        $bundled = ExtensionSource::factory()->bundled()->create();

        foreach (['disable', 'enable', 'remove'] as $act) {
            try {
                $this->service()->{$act}($bundled->id, $this->admin);
                self::fail("« {$act} » doit être refusé sur la source embarquée");
            } catch (ExtensionSourceException $e) {
                self::assertStringContainsString('embarquée', $e->getMessage());
            }
        }

        self::assertTrue($bundled->fresh()->enabled);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function acting_on_an_unknown_source_is_refused_cleanly(): void
    {
        foreach (['disable', 'enable', 'remove'] as $act) {
            try {
                $this->service()->{$act}(999_999, $this->admin);
                self::fail("« {$act} » sur un id inconnu doit être refusé");
            } catch (ExtensionSourceException $e) {
                self::assertStringContainsString('introuvable', $e->getMessage());
            }
        }
    }

    // =====================================================================
    // Rafraîchissement
    // =====================================================================

    #[Test]
    public function refreshing_a_disabled_source_is_refused(): void
    {
        // Désactiver, c'est GELER : on n'interroge pas un dépôt mis hors circuit.
        $this->publish();
        $added = $this->service()->add('Dépôt', self::BASE, $this->keys['public'], $this->admin);
        $this->service()->disable($added['id'], $this->admin);

        $this->expectException(ExtensionSourceException::class);
        $this->expectExceptionMessageMatches('/désactivée/');

        $this->service()->refresh($added['id'], $this->admin);
    }

    #[Test]
    public function refreshing_the_bundled_source_is_refused(): void
    {
        $bundled = ExtensionSource::factory()->bundled()->create();

        $this->expectException(ExtensionSourceException::class);

        $this->service()->refresh($bundled->id, $this->admin);
    }

    // =====================================================================
    // Lecture
    // =====================================================================

    #[Test]
    public function the_listing_exposes_flat_rows_with_counts_and_state(): void
    {
        ExtensionSource::factory()->bundled()->create();
        $this->publish(extensions: [$this->manifest('agenda'), $this->manifest('cdi')]);
        $added = $this->service()->add('Dépôt tiers', self::BASE, $this->keys['public'], $this->admin);

        $agenda = Extension::where('key', 'agenda')->firstOrFail();
        $agenda->status = ExtensionStatus::Integrated;
        $agenda->save();

        $rows = $this->service()->list();
        self::assertCount(2, $rows);

        $remote = collect($rows)->firstWhere('id', $added['id']);
        self::assertNotNull($remote);
        self::assertSame('Dépôt tiers', $remote['name']);
        self::assertSame('depot.example.test', $remote['host']);
        self::assertFalse($remote['is_official']);
        self::assertTrue($remote['is_remote']);
        self::assertSame(2, $remote['extensions_count']);
        self::assertSame(1, $remote['integrated_count']);
        self::assertSame(ExtensionSourceSyncStatus::Ok->value, $remote['sync_status']);
        self::assertNotSame('', $remote['last_synced_at']);
        self::assertStringContainsString('…', $remote['public_key_preview'], 'la clé est abrégée, jamais étalée');

        $bundled = collect($rows)->firstWhere('key', ExtensionSource::KEY_BUNDLED);
        self::assertNotNull($bundled);
        self::assertTrue($bundled['is_official']);
        self::assertFalse($bundled['is_remote'], 'la source embarquée n\'expose aucune action');
    }
}
