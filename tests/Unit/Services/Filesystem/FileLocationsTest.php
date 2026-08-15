<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\FileLocationException;
use App\Services\Filesystem\FileLocations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Story 63.1 — AC3/AC4/AC5 : la matrice des gardes de `FileLocations::make()`.
 *
 * `TestCase` PUR, aucune application, aucune base — c'est ce qui prouve
 * l'AC5 : `FileLocations` ne fait aucune I/O.
 */
class FileLocationsTest extends TestCase
{
    // =========================================================================
    // Constructeur privé — la garde est structurelle
    // =========================================================================

    #[Test]
    public function le_constructeur_est_prive_make_est_l_unique_porte_d_entree(): void
    {
        $reflection = new ReflectionClass(FileLocations::class);

        self::assertTrue($reflection->getConstructor()?->isPrivate());
        self::assertFalse($reflection->hasMethod('withEspacePerso'));
        self::assertFalse($reflection->hasMethod('withEspacePartage'));
        self::assertFalse($reflection->hasMethod('withCloudActif'));
        self::assertFalse($reflection->hasMethod('fromArray'));
    }

    /**
     * Le vocabulaire annoncé par les refus est celui qui est RÉELLEMENT
     * acceptable : `FileBackendName::cases()` privé de l'aperçu. Un refus qui
     * proposerait `preview` orienterait vers une valeur refusée au tour
     * suivant — et cette liste ne doit pas dériver si le vocabulaire s'élargit.
     */
    #[Test]
    public function le_vocabulaire_acceptable_est_celui_des_backends_moins_l_apercu(): void
    {
        $attendu = array_values(array_filter(
            FileBackendName::cases(),
            static fn (FileBackendName $backend): bool => $backend !== FileBackendName::Preview,
        ));

        self::assertSame($attendu, FileLocations::ACCEPTABLE_AUTHORITIES);
        self::assertSame(['posix', 'nextcloud', 'opencloud'], FileLocations::acceptableAuthorityValues());
        self::assertNotContains('preview', FileLocations::acceptableAuthorityValues());
    }

    // =========================================================================
    // AC4 — combinaisons acceptées
    // =========================================================================

    #[Test]
    public function posix_et_posix_avec_aucun_cloud_est_accepte(): void
    {
        $locations = FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun);

        self::assertSame(FileBackendName::Posix, $locations->espacePerso);
        self::assertSame(FileBackendName::Posix, $locations->espacePartage);
        self::assertSame(ActiveCloud::Aucun, $locations->cloudActif);
    }

    /**
     * `espace_perso = posix` avec `cloud.actif = nextcloud` est ACCEPTÉ : le
     * cloud est configuré, l'espace perso reste sur le serveur — le cas que
     * l'ancien `FilePolicyMode` ne savait pas exprimer.
     */
    #[Test]
    public function posix_reste_accepte_meme_quand_un_cloud_est_actif(): void
    {
        $locations = FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud);

        self::assertSame(FileBackendName::Posix, $locations->espacePerso);
        self::assertSame(ActiveCloud::Nextcloud, $locations->cloudActif);
    }

    #[Test]
    public function une_autorite_cloud_qui_egale_le_cloud_actif_est_acceptee(): void
    {
        $locations = FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);
        self::assertSame(FileBackendName::Nextcloud, $locations->espacePerso);

        $locations = FileLocations::make(FileBackendName::Posix, FileBackendName::OpenCloud, ActiveCloud::OpenCloud);
        self::assertSame(FileBackendName::OpenCloud, $locations->espacePartage);

        $locations = FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Nextcloud, ActiveCloud::Nextcloud);
        self::assertSame(FileBackendName::Nextcloud, $locations->espacePerso);
        self::assertSame(FileBackendName::Nextcloud, $locations->espacePartage);
    }

    // =========================================================================
    // AC4 — les trois refus
    // =========================================================================

    #[Test]
    public function preview_n_est_jamais_un_emplacement_pour_l_espace_perso(): void
    {
        try {
            FileLocations::make(FileBackendName::Preview, FileBackendName::Posix, ActiveCloud::Aucun);
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace perso', $e->getMessage());
            self::assertStringContainsString('aperçu', $e->getMessage());
            self::assertStringContainsString('aucun droit', $e->getMessage());
        }
    }

    #[Test]
    public function preview_n_est_jamais_un_emplacement_pour_l_espace_partage(): void
    {
        try {
            FileLocations::make(FileBackendName::Posix, FileBackendName::Preview, ActiveCloud::Aucun);
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace partagé', $e->getMessage());
            self::assertStringContainsString('aperçu', $e->getMessage());
        }
    }

    #[Test]
    public function une_autorite_nextcloud_alors_que_le_cloud_actif_est_opencloud_est_refusee(): void
    {
        try {
            FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::OpenCloud);
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace perso', $e->getMessage());
            self::assertStringContainsString('nextcloud', $e->getMessage());
            self::assertStringContainsString('OpenCloud', $e->getMessage());
        }
    }

    #[Test]
    public function une_autorite_opencloud_alors_que_le_cloud_actif_est_aucun_est_refusee(): void
    {
        try {
            FileLocations::make(FileBackendName::Posix, FileBackendName::OpenCloud, ActiveCloud::Aucun);
            self::fail('devait lever FileLocationException');
        } catch (FileLocationException $e) {
            self::assertStringContainsString('espace partagé', $e->getMessage());
            self::assertStringContainsString('opencloud', $e->getMessage());
            self::assertStringContainsString('Aucun cloud', $e->getMessage());
        }
    }

    #[Test]
    public function une_autorite_nextcloud_alors_que_le_cloud_actif_est_aucun_est_refusee(): void
    {
        $this->expectException(FileLocationException::class);
        FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Aucun);
    }

    #[Test]
    public function une_autorite_opencloud_alors_que_le_cloud_actif_est_nextcloud_est_refusee(): void
    {
        $this->expectException(FileLocationException::class);
        FileLocations::make(FileBackendName::Posix, FileBackendName::OpenCloud, ActiveCloud::Nextcloud);
    }

    // =========================================================================
    // AC5 — lectures dérivées, et rien de plus
    // =========================================================================

    #[Test]
    public function espace_perso_sur_smb_vaut_vrai_uniquement_pour_posix(): void
    {
        self::assertTrue(
            FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun)->espacePersoSurSmb(),
        );
        self::assertFalse(
            FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud)->espacePersoSurSmb(),
        );
    }

    #[Test]
    public function espace_partage_sur_smb_vaut_vrai_uniquement_pour_posix(): void
    {
        self::assertTrue(
            FileLocations::make(FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun)->espacePartageSurSmb(),
        );
        self::assertFalse(
            FileLocations::make(FileBackendName::Posix, FileBackendName::OpenCloud, ActiveCloud::OpenCloud)->espacePartageSurSmb(),
        );
    }

    #[Test]
    public function to_array_rend_les_trois_cles_litterales_du_cadrage(): void
    {
        $locations = FileLocations::make(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);

        self::assertSame(
            [
                'espace_perso.autorite' => 'nextcloud',
                'espace_partage.autorite' => 'posix',
                'cloud.actif' => 'nextcloud',
            ],
            $locations->toArray(),
        );
    }

    /**
     * AUCUNE lettre de lecteur, AUCUN UNC, AUCUN chemin n'apparaît dans cet
     * objet — c'est l'AC5, épinglé sur la surface publique de la classe.
     */
    #[Test]
    public function la_surface_publique_ne_porte_ni_lettre_ni_unc_ni_chemin(): void
    {
        $reflection = new ReflectionClass(FileLocations::class);
        $methods = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $reflection->getMethods());

        foreach ($methods as $method) {
            self::assertStringNotContainsStringIgnoringCase('drive', $method);
            self::assertStringNotContainsStringIgnoringCase('letter', $method);
            self::assertStringNotContainsStringIgnoringCase('unc', $method);
            self::assertStringNotContainsStringIgnoringCase('path', $method);
        }
    }
}
