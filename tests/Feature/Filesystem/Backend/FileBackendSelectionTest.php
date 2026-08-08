<?php

declare(strict_types=1);

namespace Tests\Feature\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Models\DirectoryTemplate;
use App\Models\NetworkShare;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\Backend\FileBackendSelection;
use App\Services\Filesystem\DirectoryTemplateService;
use App\Services\Filesystem\Plan\PlanGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 61.3 — CE QUI EST POSABLE, ET CE QUI NE SE BASCULE JAMAIS.
 *
 * Deux propriétés, et la seconde est celle qui tient D9 : une case n'est proposée
 * que si le système peut la tenir, et une fois posée, elle ne change plus.
 */
class FileBackendSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function selection(): FileBackendSelection
    {
        return app(FileBackendSelection::class);
    }

    private function enableNextcloud(bool $enabled): void
    {
        FilePolicyService::setGlobal(true, true, $enabled, 'https://nuage.exemple.fr', 'admin', 'se4fs', true);
    }

    /** Capacité active : la case est posable, avec sa description de chemin d'accès. */
    #[Test]
    public function the_cloud_case_is_selectable_once_the_capability_is_on(): void
    {
        $this->enableNextcloud(true);

        self::assertSame(
            [FileBackendName::Posix, FileBackendName::Nextcloud],
            $this->selection()->selectable(),
        );
        self::assertNull($this->selection()->refusalFor(FileBackendName::Nextcloud));
        self::assertStringContainsString('PAS de lecteur réseau SMB', FileBackendName::Nextcloud->description());
    }

    /**
     * **CAPACITÉ ÉTEINTE ⇒ CASE ABSENTE, AVEC LE MOTIF DIT.** Proposer puis refuser à
     * l'application est le défaut du signal accepté sans destinataire — et un partage
     * créé ainsi ne pourrait JAMAIS se réconcilier.
     */
    #[Test]
    public function a_disabled_capability_removes_the_case_and_says_why(): void
    {
        $this->enableNextcloud(false);

        self::assertSame([FileBackendName::Posix], $this->selection()->selectable());

        $refusal = $this->selection()->refusalFor(FileBackendName::Nextcloud);
        self::assertNotNull($refusal);
        self::assertStringContainsString('Accès Nextcloud', $refusal);
        self::assertStringContainsString('Administration', $refusal);

        $this->expectException(InvalidArgumentException::class);
        $this->selection()->resolve('nextcloud');
    }

    /** L'aperçu n'écrit aucun droit : il n'est jamais un choix d'exploitation. */
    #[Test]
    public function the_preview_backend_is_never_selectable_for_a_real_share(): void
    {
        $this->enableNextcloud(true);

        self::assertNotContains(FileBackendName::Preview, $this->selection()->selectable());
        $this->expectException(InvalidArgumentException::class);
        $this->selection()->resolve('preview');
    }

    /** Un nom hors vocabulaire est refusé en NOMMANT l'attendu, jamais ramené au défaut. */
    #[Test]
    public function an_unknown_name_is_refused_never_defaulted(): void
    {
        $this->enableNextcloud(true);

        self::assertSame(FileBackendName::Posix, $this->selection()->resolve(null));
        self::assertSame(FileBackendName::Posix, $this->selection()->resolve(''));

        $this->expectException(InvalidArgumentException::class);
        $this->selection()->resolve('nextcloud_delegue');
    }

    // =========================================================================
    // D9 — le choix se fait à la création, et jamais après
    // =========================================================================

    /** La matérialisation d'une recette porte le choix, et l'écrit hors du remplissage de masse. */
    #[Test]
    public function a_materialised_share_carries_the_chosen_backend(): void
    {
        Queue::fake();
        $this->enableNextcloud(true);

        $result = app(DirectoryTemplateService::class)->materialize(
            $this->template(),
            [
                'name' => 'Échange',
                'directory_name' => 'echange_cloud',
                'backend' => 'nextcloud',
                'roles' => ['cible' => [$this->targetGroup()->id]],
            ],
            deferProvisioning: true,
        );

        self::assertSame(FileBackendName::Nextcloud, $result->share->fresh()->backendName());
    }

    /** Capacité éteinte : la matérialisation est refusée AVANT toute écriture. */
    #[Test]
    public function a_materialisation_on_a_disabled_backend_is_refused_before_any_write(): void
    {
        Queue::fake();
        $this->enableNextcloud(false);

        try {
            app(DirectoryTemplateService::class)->materialize(
                $this->template(),
                [
                    'name' => 'Échange',
                    'directory_name' => 'echange_cloud',
                    'backend' => 'nextcloud',
                    'roles' => ['cible' => [$this->targetGroup()->id]],
                ],
                deferProvisioning: true,
            );
            self::fail('la matérialisation aurait dû être refusée');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Accès Nextcloud', $e->getMessage());
        }

        self::assertSame(0, NetworkShare::query()->count(), 'aucune écriture partielle');
    }

    /**
     * **AUCUN CHEMIN DE BASCULE.** La colonne reste hors du remplissage de masse : un
     * `create()` ou un `fill()` ne peut pas la faire entrer, et le seul écrivain est
     * le geste de création. La migration outillée d'un partage provisionné est le
     * chantier D9 — cette story la rend nécessaire, elle ne la livre pas.
     */
    #[Test]
    public function a_provisioned_share_never_switches_backend(): void
    {
        Queue::fake();
        $this->enableNextcloud(true);

        $share = NetworkShare::query()->create([
            'name' => 'Historique',
            'directory_name' => 'historique',
            'backend' => 'nextcloud',
        ]);

        self::assertSame(
            FileBackendName::Posix,
            $share->fresh()->backendName(),
            'la colonne est hors $fillable : un remplissage de masse ne la fait pas entrer',
        );

        $share->fill(['backend' => 'nextcloud']);
        self::assertSame(FileBackendName::Posix, $share->fresh()->backendName());
    }

    private function targetGroup(): UserGroup
    {
        UserGroupObserver::disableSync();

        return UserGroup::query()->firstOrCreate(['name' => 'direction'], ['type' => 'equipe']);
    }

    private function template(): DirectoryTemplate
    {
        return DirectoryTemplate::query()->create([
            'key' => 'echange_test',
            'label' => 'Échange',
            'description' => 'Décor de test',
            'roles_spec' => [[
                'key' => 'cible',
                'label' => 'Destinataires',
                'maille' => UserGroup::class,
                'verbs' => PlanGrant::VERBS,
                'cardinality' => 'one',
            ]],
            'path_pattern' => '{share.directory_name}',
            'nodes_spec' => [
                ['path' => '.', 'label' => 'Racine', 'nature' => 'partagee', 'grants' => [
                    ['role' => 'cible', 'verbs' => PlanGrant::VERBS],
                ]],
            ],
        ]);
    }
}
