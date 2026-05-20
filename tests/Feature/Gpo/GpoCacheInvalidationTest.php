<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\CachedGpoLookups;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature — Hooks d'invalidation du cache santé (Story 16.14 Q2).
 *
 * Vérifie qu'une mutation via `GpoService::setLink/removeLink/setInheritance`
 * invalide bien les entrées correspondantes dans `CachedGpoLookups`.
 */
class GpoCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    #[Test]
    public function set_link_invalidates_cache_for_gpo(): void
    {
        $guid = '{12345678-0001-0001-0001-000000000001}';
        $containerDn = 'OU=Test,DC=ex,DC=org';

        // Pré-peupler manuellement le cache avec une vraie valeur via clé.
        Cache::put('gpo:version:' . $guid, 99999, 3600);
        Cache::put('gpo:cache:index', [$guid], 3600);

        // Fake samba-tool setLink → succès (Process::fake() sans args = tout réussit).
        Process::fake();

        // Force le rebuild des singletons pour reflecter les bindings.
        $this->app->forgetInstance(CachedGpoLookups::class);
        $svc = new GpoService(new SambaToolRunner());

        // Exécute setLink.
        $svc->setLink($containerDn, $guid);

        // Vérif : la clé version a été invalidée.
        self::assertFalse(
            Cache::has('gpo:version:' . $guid),
            'setLink doit invalider le cache santé pour cette GPO.',
        );
    }

    #[Test]
    public function remove_link_invalidates_cache_for_gpo(): void
    {
        $guid = '{12345678-0002-0002-0002-000000000002}';
        $containerDn = 'OU=Test,DC=ex,DC=org';

        Cache::put('gpo:links:' . $guid, [], 3600);
        Cache::put('gpo:version:' . $guid, 12345, 3600);
        Cache::put('gpo:cache:index', [$guid], 3600);

        Process::fake();

        $this->app->forgetInstance(CachedGpoLookups::class);
        $svc = new GpoService(new SambaToolRunner());

        $svc->removeLink($containerDn, $guid);

        self::assertFalse(Cache::has('gpo:version:' . $guid));
        self::assertFalse(Cache::has('gpo:links:' . $guid));
    }
}
