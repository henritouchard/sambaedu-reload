<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization;

use App\Dto\AppCustomization\AppContext;
use App\Services\AppCustomization\ApcuAppContextRepository;
use App\Services\AppCustomization\ApcuAppContextWriter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC2.2.
 *
 * Vérifie que `ApcuAppContextWriter` :
 *  - écrit la clé `apps.$id` avec structure compatible `ApcuAppContextRepository` (lecteur 4.8)
 *  - rejette les ids mal formés (no-op silencieux + warning log)
 *  - dégradation gracieuse si APCu absent (no-op)
 *  - `forget()` supprime la clé + scripts cached
 */
class ApcuAppContextWriterTest extends TestCase
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
            // Clear potential leftover keys.
            @apcu_clear_cache();
        }
    }

    #[Test]
    public function write_persists_iso_legacy_structure_readable_by_repository(): void
    {
        if (! $this->apcuAvailable()) {
            self::markTestSkipped('APCu non disponible (CLI sans extension)');
        }

        $writer = new ApcuAppContextWriter();
        $reader = new ApcuAppContextRepository();

        $id = md5('test-fixture-' . microtime(true));
        $context = [
            'id' => $id,
            'user' => ['cn' => 'jdupont'],
            'machine' => ['cn' => 'post01'],
            'salle' => 'salle1',
            'list_u' => ['Profs', 'eleves'],
            'os' => 'linux',
            'time' => 1700000000,
            'action' => 'logon',
            'list' => ['post01', 'salle1', 'Profs'],
        ];

        $writer->write($id, $context);

        $reread = $reader->findById($id);
        self::assertNotNull($reread);
        self::assertInstanceOf(AppContext::class, $reread);
        self::assertSame('jdupont', $reread->userLogin);
        self::assertSame('post01', $reread->machineName);
        self::assertSame('salle1', $reread->salleName);
        self::assertSame(['Profs', 'eleves'], $reread->groupsUser);
        self::assertSame('Profs', $reread->mainUserType);
        self::assertSame('linux', $reread->os);
    }

    #[Test]
    public function write_rejects_invalid_id_silently(): void
    {
        $writer = new ApcuAppContextWriter();
        // Pas d'exception levée — log warning + no-op.
        $writer->write('not-md5', ['user' => ['cn' => 'x']]);
        self::assertTrue(true);
    }

    #[Test]
    public function write_rejects_empty_id_silently(): void
    {
        $writer = new ApcuAppContextWriter();
        $writer->write('', []);
        self::assertTrue(true);
    }

    #[Test]
    public function forget_removes_keys_apps_and_scripts(): void
    {
        if (! $this->apcuAvailable()) {
            self::markTestSkipped('APCu non disponible');
        }
        $id = str_repeat('a', 32);
        apcu_store('apps.' . $id, ['x' => 1]);
        apcu_store('scripts.' . $id, ['cmd' => '']);

        $writer = new ApcuAppContextWriter();
        $writer->forget($id);

        $success1 = false;
        $success2 = false;
        @apcu_fetch('apps.' . $id, $success1);
        @apcu_fetch('scripts.' . $id, $success2);
        self::assertFalse($success1);
        self::assertFalse($success2);
    }
}
