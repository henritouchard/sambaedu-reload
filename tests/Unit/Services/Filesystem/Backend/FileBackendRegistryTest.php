<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\UnknownFileBackendException;
use App\Models\NetworkShare;
use App\Services\Filesystem\Backend\FileBackendRegistry;
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
     * **CE TEST EST À RETOURNER PAR LA STORY 60.4.** Tant que l'exécution n'est
     * pas descendue sous la ligne de contrat, demander le serveur de fichiers
     * historique échoue en NOMMANT ce qui est disponible et la story qui livrera
     * l'implémentation. Fail-closed : jamais un repli sur le backend d'aperçu, qui
     * ferait croire à une application alors que rien ne serait écrit.
     */
    #[Test]
    public function posix_is_a_legitimate_column_value_without_an_implementation_until_60_4(): void
    {
        $this->assertFalse($this->registry()->has(FileBackendName::Posix));

        try {
            $this->registry()->get(FileBackendName::Posix);
            $this->fail('le backend posix ne devrait pas se résoudre avant la story 60.4');
        } catch (UnknownFileBackendException $e) {
            $this->assertStringContainsString('posix', $e->getMessage());
            $this->assertStringContainsString('preview', $e->getMessage());
            $this->assertStringContainsString('60.4', $e->getMessage());
        }
    }

    #[Test]
    public function only_the_implemented_names_are_advertised_as_available(): void
    {
        $this->assertSame([FileBackendName::Preview], $this->registry()->availableNames());
    }

    #[Test]
    public function a_share_resolves_through_its_column(): void
    {
        $share = NetworkShare::factory()->create();

        // Le défaut de la colonne dit vrai : les partages existants SONT du POSIX.
        $this->assertSame(FileBackendName::Posix, $share->fresh()->backendName());

        $this->expectException(UnknownFileBackendException::class);
        $this->registry()->forShare($share->fresh());
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
