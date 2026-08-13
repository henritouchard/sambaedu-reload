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

    /**
     * Story 61.3 — la liste s'allonge d'un nom, et la propriété se RENFORCE : depuis
     * qu'un second backend réel existe, TOUTE case du vocabulaire résout. Un nom
     * annoncé sans implémentation était l'état daté de la story 60.3 ; ce n'est plus
     * un état acceptable.
     *
     * **LA LISTE S'ALLONGE D'UN NOM DE PLUS — `opencloud` — ET C'EST TOUT CE QUI
     * CHANGE.** La retouche est ÉNUMÉRATIVE : elle nomme le troisième backend réel,
     * elle ne touche à aucune propriété. La seconde assertion, elle, ne bouge pas
     * d'un caractère et c'est celle qui compte : *les noms disponibles sont
     * EXACTEMENT les cases du vocabulaire*. C'est cette égalité, et non la longueur
     * de la liste, qui interdit une case déclarée sans implémentation.
     */
    #[Test]
    public function only_the_implemented_names_are_advertised_as_available(): void
    {
        $this->assertSame(
            [
                FileBackendName::Posix,
                FileBackendName::Preview,
                FileBackendName::Nextcloud,
                FileBackendName::OpenCloud,
            ],
            $this->registry()->availableNames(),
        );

        $this->assertSame(FileBackendName::cases(), $this->registry()->availableNames());
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
     *
     * **LA VALEUR TÉMOIN A CHANGÉ, ET LA PROPRIÉTÉ N'A PAS BOUGÉ.** Ce test
     * employait `opencloud` comme exemple d'inconnu — un choix qui n'était pas
     * gratuit : c'était le nom que le cadrage annonçait pour un backend à venir. Ce
     * backend existe désormais, la valeur est devenue LÉGITIME, et la garder ici
     * ferait passer le test au vert pour la mauvaise raison (il vérifierait qu'un
     * nom connu est refusé). Le témoin devient donc une valeur qui n'a jamais été
     * annoncée nulle part et n'a aucune raison de le devenir.
     */
    #[Test]
    public function an_unknown_column_value_fails_explicitly_and_never_falls_back(): void
    {
        $share = NetworkShare::factory()->create();
        DB::table('network_shares')->where('id', $share->id)->update(['backend' => 'dropbox']);

        try {
            $this->registry()->forShare($share->fresh());
            $this->fail('une valeur hors vocabulaire aurait dû échouer');
        } catch (UnknownFileBackendException $e) {
            $this->assertStringContainsString('dropbox', $e->getMessage());
            $this->assertStringContainsString('posix|preview', $e->getMessage());
        }
    }
}
