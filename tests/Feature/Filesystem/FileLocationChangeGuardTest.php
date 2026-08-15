<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\FileLocationChangeGuard;
use App\Services\Filesystem\FileLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.3 AC7 — LA GARDE D9, SANS UI.
 *
 * Elle refuse de déplacer un espace qui porte des données, et elle nomme le
 * chantier qui lèvera le refus. Le constat se fait par des existences en base —
 * jamais par un parcours du stockage, jamais par un appel réseau — et il est
 * volontairement CONSERVATEUR : une instance qui porte des comptes est réputée
 * porter des fichiers.
 */
class FileLocationChangeGuardTest extends TestCase
{
    use RefreshDatabase;

    private FileLocationChangeGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        // Créer un groupe déclenche la projection d'annuaire et la
        // matérialisation de son arbre : ni l'une ni l'autre ne concerne cette
        // garde, qui ne fait que constater une existence.
        UserGroupObserver::disableSync();
        Queue::fake();

        $this->guard = app(FileLocationChangeGuard::class);
    }

    private static function locations(
        FileBackendName $perso = FileBackendName::Posix,
        FileBackendName $partage = FileBackendName::Posix,
        ActiveCloud $cloud = ActiveCloud::Aucun,
    ): FileLocations {
        return FileLocations::make($perso, $partage, $cloud);
    }

    private function seedDirectoryAccount(): void
    {
        // `source` vaut « ad » par défaut en base — la colonne n'est pas
        // `$fillable`, elle ne se passe donc pas à `create()`.
        User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => true]);
    }

    // =====================================================================
    // L'instance NEUVE : le choix est libre, parce qu'il ne coûte rien
    // =====================================================================

    #[Test]
    public function a_brand_new_instance_may_move_both_spaces_freely(): void
    {
        $current = self::locations();
        $submitted = self::locations(
            FileBackendName::Nextcloud,
            FileBackendName::Nextcloud,
            ActiveCloud::Nextcloud,
        );

        self::assertNull($this->guard->refusalFor($current, $submitted));

        // …et la garde rejouée côté service ne lève pas non plus.
        $this->guard->assertChangeIsAllowed($current, $submitted);
    }

    /**
     * Un compte INACTIF, ou d'une autre source que l'annuaire, ne suffit pas :
     * le constat porte sur les comptes d'annuaire actifs et sur les identités
     * cloud, pas sur toute ligne de la table.
     */
    #[Test]
    public function an_inactive_or_federated_account_alone_does_not_freeze_the_personal_space(): void
    {
        // ⚠️ `source` n'est PAS `$fillable` : elle s'écrit nominativement.
        User::query()->create(['login' => 'parti', 'role' => 'prof', 'is_active' => false]);
        User::query()->create(['login' => 'externe', 'role' => 'autre', 'is_active' => true])
            ->forceFill(['source' => 'federated'])->save();

        self::assertNull($this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud),
        ));
    }

    // =====================================================================
    // L'espace personnel
    // =====================================================================

    #[Test]
    public function moving_the_personal_space_is_refused_once_a_directory_account_exists(): void
    {
        $this->seedDirectoryAccount();

        $refusal = (string) $this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud),
        );

        self::assertSame(
            'Refusé : l\'espace personnel porte déjà des données. Le déplacer suppose de les déménager, '
            .'ce que le chantier « Epic 64 — la bascule d\'autorité » livrera ; d\'ici là, l\'emplacement '
            .'d\'un espace qui porte des données ne se change pas.',
            $refusal,
        );
    }

    /** Une identité cloud suffit aussi : un espace a été provisionné là-bas. */
    #[Test]
    public function a_cloud_identity_alone_freezes_the_personal_space(): void
    {
        User::query()->create(['login' => 'p.durand', 'role' => 'prof', 'is_active' => false])
            ->forceFill(['source' => 'federated', 'opencloud_user_id' => 'p.durand'])->save();

        self::assertNotNull($this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud),
        ));
    }

    // =====================================================================
    // L'espace partagé
    // =====================================================================

    #[Test]
    public function moving_the_shared_space_is_refused_once_a_group_exists(): void
    {
        UserGroup::factory()->create();

        $refusal = (string) $this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Posix, FileBackendName::Nextcloud, ActiveCloud::Nextcloud),
        );

        self::assertStringContainsString('l\'espace partagé porte déjà des données', $refusal);
        self::assertStringContainsString('Epic 64 — la bascule d\'autorité', $refusal);
    }

    #[Test]
    public function a_managed_network_share_alone_freezes_the_shared_space(): void
    {
        NetworkShare::factory()->create();

        self::assertNotNull($this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Posix, FileBackendName::Nextcloud, ActiveCloud::Nextcloud),
        ));
    }

    // =====================================================================
    // Les deux objets sont INDÉPENDANTS
    // =====================================================================

    #[Test]
    public function the_two_spaces_are_constated_independently(): void
    {
        // Des groupes, mais aucun compte : l'espace personnel bouge, le partagé
        // non.
        UserGroup::factory()->create();

        self::assertNull($this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud),
        ));

        self::assertNotNull($this->guard->refusalFor(
            self::locations(),
            self::locations(FileBackendName::Posix, FileBackendName::Nextcloud, ActiveCloud::Nextcloud),
        ));
    }

    // =====================================================================
    // Ce que la garde NE refuse PAS
    // =====================================================================

    /**
     * Une soumission qui ne bouge aucun des deux emplacements passe, même sur
     * une instance pleine : c'est le cas d'un changement de cloud actif alors
     * que les deux espaces restent sur le serveur de fichiers, ou d'un simple
     * ré-enregistrement.
     */
    #[Test]
    public function an_unchanged_pair_of_locations_is_never_refused(): void
    {
        $this->seedDirectoryAccount();
        UserGroup::factory()->create();

        self::assertNull($this->guard->refusalFor(self::locations(), self::locations()));

        // Bascule d'un produit à l'autre, les deux espaces restant en place.
        self::assertNull($this->guard->refusalFor(
            self::locations(cloud: ActiveCloud::Nextcloud),
            self::locations(cloud: ActiveCloud::OpenCloud),
        ));
    }

    // =====================================================================
    // La garde REJOUÉE côté service
    // =====================================================================

    #[Test]
    public function the_assertion_throws_on_a_refused_change(): void
    {
        $this->seedDirectoryAccount();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/l\'espace personnel porte déjà des données/');

        $this->guard->assertChangeIsAllowed(
            self::locations(),
            self::locations(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud),
        );
    }
}
