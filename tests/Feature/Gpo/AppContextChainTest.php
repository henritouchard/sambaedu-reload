<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Services\AppCustomization\ApcuAppContextRepository;
use App\Services\AppCustomization\ApcuAppContextWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC7.5.
 *
 * Test bout-en-bout : l'écriture APCu par Story 16.7 (`ApcuAppContextWriter`)
 * doit être **lisible sans modification** par le lecteur Story 4.8
 * (`ApcuAppContextRepository`).
 *
 * Permet de garantir qu'aucune régression côté chaîne native (4.7/4.8/16.3b/
 * 16.3c) n'est introduite.
 */
class AppContextChainTest extends TestCase
{
    private function apcuAvailable(): bool
    {
        return function_exists('apcu_store')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->apcuAvailable()) {
            @apcu_clear_cache();
        }
    }

    #[Test]
    public function writer_writes_structure_readable_by_4_8_repository(): void
    {
        if (! $this->apcuAvailable()) {
            self::markTestSkipped('APCu non disponible (CLI sans extension)');
        }

        $id = md5('chain-test-' . microtime(true));
        $writer = new ApcuAppContextWriter();
        $reader = new ApcuAppContextRepository();

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
        self::assertNotNull($ctx, 'Le contexte écrit par 16.7 doit être lisible par le repository 4.8');
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
        if (! $this->apcuAvailable()) {
            self::markTestSkipped('APCu non disponible');
        }
        $id = md5('forget-test-' . microtime(true));
        $writer = new ApcuAppContextWriter();
        $reader = new ApcuAppContextRepository();

        $writer->write($id, ['user' => ['cn' => 'x'], 'machine' => ['cn' => 'PC'], 'os' => 'linux']);
        self::assertNotNull($reader->findById($id));

        $writer->forget($id);
        self::assertNull($reader->findById($id));
    }
}
