<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Services\Filesystem\Backend\FileBackendRegistry;
use App\Services\Filesystem\Backend\Nextcloud\NextcloudPermissionBits;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CE QU'UN BACKEND DÉCLARE RENDRE D'UN OCTROI DOIT ÊTRE CE QU'IL ÉCRIT.
 *
 * La méthode de déclaration existe pour qu'un écran de composition dise la vérité
 * de l'autorité qui exécutera. Le risque qu'elle porte est celui de toute réponse
 * donnée à l'avance : devenir un SECOND endroit où la vérité est écrite, et
 * diverger de l'exécution sans que rien ne le dise. Ces épreuves confrontent donc
 * la déclaration au produit de l'exécution, sur TOUTES les combinaisons de verbes.
 */
class GrantRenderingAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
        Process::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Le serveur de fichiers historique : « rendu exactement » DOIT coïncider avec
     * « compilé sans le moindre refus ».
     *
     * Les deux réponses viennent de chemins de code distincts — la déclaration de
     * verbes d'un côté, l'émission des refus du compilateur de l'autre. Leur accord
     * n'est donc pas une tautologie : c'est ce qui rend la déclaration digne de foi.
     */
    #[Test]
    public function the_historical_file_server_declares_exactly_what_it_compiles_without_refusal(): void
    {
        $backend = app(FileBackendRegistry::class)->get(FileBackendName::Posix);
        $compiler = app(PosixAclCompiler::class);
        $group = PlanSubject::group($this->groupId());

        foreach ($this->everyCombination() as $verbs) {
            $grant = new PlanGrant('classe', $group, $verbs);
            $node = new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [$grant]);

            $declared = $backend->rendering($node, $grant);
            $compiled = $compiler->compile($node);

            // Les seuls refus comparables sont ceux du MODÈLE. Un échec
            // d'identité (le groupe système ne se résout pas hors instance) ne dit
            // rien des verbes et n'a pas à peser ici.
            $losses = array_values(array_filter(
                $compiled->refusals,
                static fn ($r): bool => $r->outcome === FileBackendOutcome::NonExprimable,
            ));

            self::assertSame(
                $losses === [],
                $declared->isExact(),
                'incohérence sur [' . implode(', ', $verbs) . ']',
            );
        }
    }

    /**
     * Le dossier d'équipe : les verbes déclarés rendus DOIVENT être exactement ceux
     * que portent les bits écrits.
     */
    #[Test]
    public function the_team_folder_declares_exactly_the_verbs_its_bits_carry(): void
    {
        $backend = app(FileBackendRegistry::class)->get(FileBackendName::Nextcloud);
        $group = PlanSubject::group($this->groupId());

        foreach ($this->everyCombination() as $verbs) {
            $grant = new PlanGrant('classe', $group, $verbs);
            $node = new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [$grant]);

            self::assertSame(
                NextcloudPermissionBits::toVerbs(NextcloudPermissionBits::fromVerbs($verbs)),
                $backend->rendering($node, $grant)->rendered,
                'incohérence sur [' . implode(', ', $verbs) . ']',
            );
        }
    }

    /**
     * Et le constat qui a motivé le branchement : le dossier d'équipe ne perd RIEN
     * et n'interdit RIEN, là où le serveur historique perd sur cinq combinaisons.
     */
    #[Test]
    public function the_team_folder_loses_nothing_where_the_file_server_does(): void
    {
        $registry = app(FileBackendRegistry::class);
        $group = PlanSubject::group($this->groupId());

        $lostOnPosix = 0;

        foreach ($this->everyCombination() as $verbs) {
            $grant = new PlanGrant('classe', $group, $verbs);
            $node = new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [$grant]);

            $cloud = $registry->get(FileBackendName::Nextcloud)->rendering($node, $grant);
            self::assertTrue($cloud->isExact(), 'perte inattendue sur [' . implode(', ', $verbs) . ']');
            self::assertSame([], $cloud->inexpressible);

            if (! $registry->get(FileBackendName::Posix)->rendering($node, $grant)->isExact()) {
                $lostOnPosix++;
            }
        }

        self::assertGreaterThan(
            0,
            $lostOnPosix,
            'sans écart entre les deux autorités, l\'écran n\'avait rien à corriger',
        );
    }

    /**
     * L'aperçu n'exécute rien : il ne doit porter les limites d'aucun mécanisme.
     */
    #[Test]
    public function the_preview_backend_never_declares_a_limit(): void
    {
        $backend = app(FileBackendRegistry::class)->get(FileBackendName::Preview);
        $group = PlanSubject::group($this->groupId());

        foreach ($this->everyCombination() as $verbs) {
            $grant = new PlanGrant('classe', $group, $verbs);
            $node = new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [$grant]);

            self::assertTrue($backend->rendering($node, $grant)->isExact());
        }
    }

    // =========================================================================
    // Décor
    // =========================================================================

    /**
     * Les combinaisons NON VIDES des quatre verbes. L'octroi vide est exclu : ce
     * n'est pas une combinaison, c'est une suspension, et elle a ses épreuves.
     *
     * @return list<list<string>>
     */
    private function everyCombination(): array
    {
        $combinations = [];

        for ($mask = 1; $mask < 16; $mask++) {
            $verbs = [];
            foreach (PlanGrant::VERBS as $position => $verb) {
                if (($mask & (1 << $position)) !== 0) {
                    $verbs[] = $verb;
                }
            }
            $combinations[] = $verbs;
        }

        return $combinations;
    }

    private ?int $groupId = null;

    private function groupId(): int
    {
        $this->groupId ??= (int) UserGroup::create([
            'name' => 'Classe_3emeA',
            'type' => 'classe',
        ])->id;

        return $this->groupId;
    }
}
