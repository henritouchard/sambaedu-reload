<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\UnknownFileBackendException;
use App\Models\NetworkShare;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Backend\PreviewBackend;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.3 — la résolution d'un backend PAR SON NOM, et son refus explicite.
 */
class FileBackendRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function registry(): FileBackendRegistry
    {
        return app(FileBackendRegistry::class);
    }

    #[Test]
    public function the_registry_is_a_container_singleton(): void
    {
        $this->assertSame($this->registry(), $this->registry());
    }

    #[Test]
    public function the_preview_backend_resolves_by_name(): void
    {
        $backend = $this->registry()->get(FileBackendName::Preview);

        $this->assertInstanceOf(PreviewBackend::class, $backend);
        $this->assertSame(FileBackendName::Preview, $backend->name());
    }

    /**
     * **LE TEST RETOURNÉ.** Son prédécesseur s'appelait « `posix` est une valeur de
     * colonne légitime SANS implémentation jusqu'à 60.4 » et vérifiait que
     * demander le serveur de fichiers historique ÉCHOUAIT en nommant la story à
     * venir. Cette story est celle-là : l'exécution est descendue sous la ligne de
     * contrat, le nom répond, et le refus n'a plus lieu d'être.
     *
     * Ce qui n'a PAS changé : un nom sans implémentation reste un échec explicite,
     * jamais un repli — c'est ce que vérifient les deux tests suivants.
     */
    #[Test]
    public function posix_now_resolves_to_the_real_file_server_backend(): void
    {
        $this->assertTrue($this->registry()->has(FileBackendName::Posix));

        $backend = $this->registry()->get(FileBackendName::Posix);

        $this->assertInstanceOf(PosixFileBackend::class, $backend);
        $this->assertSame(FileBackendName::Posix, $backend->name());
    }

    #[Test]
    public function only_the_implemented_names_are_advertised_as_available(): void
    {
        $this->assertSame([FileBackendName::Posix, FileBackendName::Preview], $this->registry()->availableNames());
    }

    #[Test]
    public function a_share_resolves_through_its_column(): void
    {
        $share = NetworkShare::factory()->create();

        // Le défaut de la colonne dit vrai : les partages existants SONT du POSIX,
        // et ils sont désormais servis par une implémentation réelle.
        $this->assertSame(FileBackendName::Posix, $share->fresh()->backendName());
        $this->assertInstanceOf(PosixFileBackend::class, $this->registry()->forShare($share->fresh()));
    }

    #[Test]
    public function a_share_on_the_preview_backend_resolves_to_the_preview_implementation(): void
    {
        $share = NetworkShare::factory()->create();
        DB::table('network_shares')->where('id', $share->id)->update(['backend' => 'preview']);

        $this->assertInstanceOf(PreviewBackend::class, $this->registry()->forShare($share->fresh()));
    }

    /**
     * Une valeur hors vocabulaire est un échec EXPLICITE nommant l'attendu —
     * jamais un repli silencieux sur un défaut. Provisionner au hasard un partage
     * dont l'autorité d'écriture est illisible est exactement ce qu'il faut
     * empêcher.
     */
    #[Test]
    public function an_unknown_column_value_fails_explicitly_and_never_falls_back(): void
    {
        $share = NetworkShare::factory()->create();
        DB::table('network_shares')->where('id', $share->id)->update(['backend' => 'opencloud']);

        try {
            $this->registry()->forShare($share->fresh());
            $this->fail('une valeur hors vocabulaire aurait dû échouer');
        } catch (UnknownFileBackendException $e) {
            $this->assertStringContainsString('opencloud', $e->getMessage());
            $this->assertStringContainsString('posix|preview', $e->getMessage());
        }
    }
}
