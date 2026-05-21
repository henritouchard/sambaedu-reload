<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization;

use App\Services\AppCustomization\CacheAppContextRepository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — CacheAppContextRepository (AC 9 Story 4.8 / AC7.3 Story 16.15).
 *
 * Vérifie le comportement de dégradation gracieuse quand le cache est vide +
 * la validation du format `id`.
 *
 * Story 16.15 — AC7.3 : méthodes renommées (missing_apcu → missing_cache,
 * valid_apcu → valid_cache), setup utilise Cache::store('app_context')->put
 * au lieu de apcu_store.
 */
class AppContextRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::store('app_context')->flush();
        // Isolation cross-driver (Story 16.15 review #4).
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    #[Test]
    public function invalid_id_returns_null(): void
    {
        $repo = new CacheAppContextRepository();
        $this->assertNull($repo->findById(''));
        $this->assertNull($repo->findById('not-a-md5'));
        $this->assertNull($repo->findById(str_repeat('z', 32)));
    }

    #[Test]
    public function missing_cache_payload_returns_null(): void
    {
        $repo = new CacheAppContextRepository();
        // id valide mais cache vide → null
        $this->assertNull($repo->findById(str_repeat('a', 32)));
    }

    #[Test]
    public function valid_cache_payload_hydrates_context(): void
    {
        $id = str_repeat('b', 32);
        Cache::store('app_context')->put('apps.' . $id, [
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => 'post01'],
            'salle' => 'Salle-A',
            'list_u' => ['Profs', 'Direction'],
            'os' => 'linux',
            'time' => 1_700_000_000,
        ], 1800);

        $repo = new CacheAppContextRepository();
        $ctx = $repo->findById($id);

        $this->assertNotNull($ctx);
        $this->assertSame('alice', $ctx->userLogin);
        $this->assertSame('post01', $ctx->machineName);
        $this->assertSame('Salle-A', $ctx->salleName);
        $this->assertSame('Profs', $ctx->mainUserType);
        $this->assertSame('linux', $ctx->os);

        Cache::store('app_context')->forget('apps.' . $id);
    }
}
