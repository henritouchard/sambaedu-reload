<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization;

use App\Services\AppCustomization\ApcuAppContextRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — ApcuAppContextRepository (AC 9).
 *
 * Vérifie le comportement de dégradation gracieuse quand APCu est absent +
 * la validation du format `id`.
 */
class AppContextRepositoryTest extends TestCase
{
    #[Test]
    public function invalid_id_returns_null(): void
    {
        $repo = new ApcuAppContextRepository();
        $this->assertNull($repo->findById(''));
        $this->assertNull($repo->findById('not-a-md5'));
        $this->assertNull($repo->findById(str_repeat('z', 32)));
    }

    #[Test]
    public function missing_apcu_payload_returns_null(): void
    {
        $repo = new ApcuAppContextRepository();
        // id valide mais APCu vide → null
        $this->assertNull($repo->findById(str_repeat('a', 32)));
    }

    #[Test]
    public function valid_apcu_payload_hydrates_context(): void
    {
        if (! function_exists('apcu_store') || ! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $this->markTestSkipped('APCu non disponible');
        }

        $id = str_repeat('b', 32);
        apcu_store('apps.' . $id, [
            'user' => ['cn' => 'alice'],
            'machine' => ['cn' => 'post01'],
            'salle' => 'Salle-A',
            'list_u' => ['Profs', 'Direction'],
            'os' => 'linux',
            'time' => 1_700_000_000,
        ]);

        $repo = new ApcuAppContextRepository();
        $ctx = $repo->findById($id);

        $this->assertNotNull($ctx);
        $this->assertSame('alice', $ctx->userLogin);
        $this->assertSame('post01', $ctx->machineName);
        $this->assertSame('Salle-A', $ctx->salleName);
        $this->assertSame('Profs', $ctx->mainUserType);
        $this->assertSame('linux', $ctx->os);

        apcu_delete('apps.' . $id);
    }
}
