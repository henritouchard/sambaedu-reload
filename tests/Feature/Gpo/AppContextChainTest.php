<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Services\AppCustomization\CacheAppContextRepository;
use App\Services\AppCustomization\CacheAppContextWriter;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC7.5 (origine). Story 16.15 — AC7.6 (migration Cache + test bi-compat).
 *
 * Test bout-en-bout : l'écriture via `CacheAppContextWriter` doit être
 * **lisible sans modification** par le lecteur `CacheAppContextRepository`.
 *
 * Nouveau test bi-compat AC7.6 : vérifie l'interop APCu legacy (D3/D4) —
 * un payload écrit par CacheAppContextWriter doit être lisible par
 * `apcu_fetch('apps.'.$id)` direct (garantit que le store `app_context`
 * avec `prefix => ''` est bien physiquement équivalent à apcu_store direct).
 */
class AppContextChainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Story 16.15 — AC7.6 : Cache::store('app_context')->flush() au lieu de apcu_clear_cache().
        Cache::store('app_context')->flush();
        // Isolation cross-driver (review #4) : nécessaire pour le test bi-compat
        // qui mixe store Laravel et apcu_* directs.
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    #[Test]
    public function writer_writes_structure_readable_by_cache_repository(): void
    {
        $id = md5('chain-test-' . microtime(true));
        $writer = new CacheAppContextWriter();
        $reader = new CacheAppContextRepository();

        $writer->write($id, [
            'id' => $id,
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'PC01'],
            'salle' => 'salle1',
            'list_u' => ['Profs'],
            'list' => ['PC01', 'salle1', 'Profs'],
            'os' => 'windows',
            'action' => 'logon',
            'time' => time(),
        ]);

        $ctx = $reader->findById($id);
        self::assertNotNull($ctx, 'Le contexte écrit par CacheAppContextWriter doit être lisible par CacheAppContextRepository');
        self::assertSame('jdoe', $ctx->userLogin);
        self::assertSame('PC01', $ctx->machineName);
        self::assertSame('salle1', $ctx->salleName);
        self::assertSame('windows', $ctx->os);
        self::assertSame(['Profs'], $ctx->groupsUser);
        self::assertSame('Profs', $ctx->mainUserType);
    }

    #[Test]
    public function writer_forget_clears_chain_readable_context(): void
    {
        $id = md5('forget-test-' . microtime(true));
        $writer = new CacheAppContextWriter();
        $reader = new CacheAppContextRepository();

        $writer->write($id, ['user' => ['cn' => 'x'], 'machine' => ['cn' => 'PC'], 'os' => 'linux']);
        self::assertNotNull($reader->findById($id));

        $writer->forget($id);
        self::assertNull($reader->findById($id));
    }

    /**
     * AC7.6 — Test bi-compat interop APCu legacy (D3/D4 Story 16.15).
     *
     * Vérifie que le payload écrit par `CacheAppContextWriter` via
     * `Cache::store('app_context')` est lisible par `apcu_fetch('apps.'.$id)`
     * direct (garantit l'interop avec `LegacyBootstrapTokenValidator` hors-scope).
     *
     * Skippé si APCu non disponible en CLI (CI sans extension).
     */
    #[Test]
    public function it_preserves_legacy_apcu_interop_when_writing_via_cache_writer(): void
    {
        if (! function_exists('apcu_fetch') || ! function_exists('apcu_enabled') || ! apcu_enabled()) {
            self::markTestSkipped('APCu non disponible en CLI — test bi-compat à exécuter en VM (AC10.2)');
        }
        // Review #1 : si le driver effectif du store app_context n'est pas `apc`
        // (typique en testing où phpunit.xml force APP_CONTEXT_CACHE_DRIVER=array),
        // le writer pose dans un autre backend et apcu_fetch direct ne trouve rien.
        // Le test bi-compat n'a de sens que quand les deux côtés (Cache + apcu_*)
        // tapent réellement APCu.
        if (config('cache.stores.app_context.driver') !== 'apc') {
            self::markTestSkipped(
                'Driver effectif app_context = ' . (string) config('cache.stores.app_context.driver')
                . ' (≠ apc) — test bi-compat à exécuter sur VM avec APP_CONTEXT_CACHE_DRIVER=apc'
            );
        }

        $id = md5('bicompat-test-' . microtime(true));
        $writer = new CacheAppContextWriter();

        $ctx = [
            'id' => $id,
            'user' => ['cn' => 'legacy_user'],
            'machine' => ['cn' => 'LEGACY-PC'],
            'salle' => 'salle_legacy',
            'list_u' => ['Profs'],
            'os' => 'windows',
            'time' => time(),
        ];

        $writer->write($id, $ctx, 1800);

        // Lecture directe APCu — doit retourner le même payload (interop legacy shim D3/D4).
        $success = false;
        $fetched = apcu_fetch('apps.' . $id, $success);

        self::assertTrue($success, 'apcu_fetch direct doit trouver la clé écrite par CacheAppContextWriter (store app_context prefix vide)');
        self::assertIsArray($fetched);
        self::assertSame('legacy_user', $fetched['user']['cn'] ?? null);
        self::assertSame('LEGACY-PC', $fetched['machine']['cn'] ?? null);
    }
}
