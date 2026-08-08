<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\PlanNodeNature;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Backend\Posix\PosixVerbRendering;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.4 — LA BOUCLE : compiler, puis relire, pour chaque ligne de la matrice.
 *
 * ---------------------------------------------------------------------------
 * **CE QUE LA BOUCLE PEUT PROUVER, ET CE QU'ELLE NE PEUT PAS.**
 *
 * La relecture porte sur le répertoire de TÊTE — limite assumée depuis l'Epic 34,
 * reconduite en 60.4. Le niveau d'un dossier dit ce qu'on peut y faire ; il ne dit
 * rien du CONTENU des fichiers qu'il abrite. Une combinaison dont les fichiers et
 * les dossiers reçoivent des niveaux différents ne peut donc PAS se relire
 * exactement — et ce test ne prétend pas le contraire :
 *
 *  - **combinaisons à niveau UNIQUE** — la boucle FERME : compiler puis relire
 *    redonne exactement les verbes rendus, ou rien du tout (mode non réductible,
 *    compté en écart). Jamais une autre réponse ;
 *  - **combinaisons DIFFÉRENCIÉES** — la relecture est APPROCHÉE, et la
 *    comparaison les rapporte donc en ÉCART, jamais en conforme. Un écart de trop
 *    se voit et se discute ; une conformité de trop est une fuite silencieuse.
 *
 * Et le point qui compte pour une instance en place : les DEUX combinaisons que
 * portent les recettes après la migration (« lire » seul, les quatre verbes) sont
 * toutes deux à niveau unique, et ferment la boucle exactement. Aucun bruit de
 * dérive n'apparaît nulle part.
 */
class PosixVerbRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-roundtrip-' . uniqid();
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * LA BOUCLE, sur les quinze lignes.
     *
     * On compile un nœud portant l'octroi, on prend la liste de DOSSIER que la
     * compilation produit (c'est elle que le disque porte sur la tête), on la rend
     * au backend comme si elle était relue, et on regarde ce qui remonte.
     */
    #[Test]
    public function compiling_then_reading_back_never_lies(): void
    {
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $subject = PlanSubject::group((int) $classe->id);

        $closed = 0;
        $unreadable = 0;
        $approximate = 0;

        foreach (PosixVerbMatrixTest::matrix() as $label => [$verbs, $rendered, , , $restriction]) {
            $canonical = PlanGrant::canonicalize($verbs);
            $rendering = PosixVerbRendering::of($canonical, $restriction);

            $node = new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, [
                new PlanGrant('classe', $subject, $canonical),
            ]);

            // La sonde d'annuaire doit réussir pour que la compilation écrive
            // quelque chose. Le motif de relecture est posé DÈS MAINTENANT : le
            // double truquage conserve l'ordre des motifs, et un attrape-tout
            // enregistré en premier masquerait le motif de relecture.
            $this->fakeGetfacl('');
            $compiled = app(PosixAclCompiler::class)->compile($node);

            $observed = $this->readBack($compiled->acls, $compiled->restrictsDeletion, $node);

            if ($rendering->isEmpty()) {
                self::assertSame([], $observed, "{$label} : aucune entrée écrite, donc rien à relire");

                continue;
            }

            if ($rendering->isDifferentiated()) {
                // La relecture de tête ne peut pas distinguer l'édition du contenu :
                // elle rend soit rien (mode non réductible), soit un ensemble qui
                // DIFFÈRE des verbes rendus. Dans les deux cas la comparaison verra
                // un ÉCART — jamais une conformité usurpée.
                self::assertNotSame(
                    [$rendering->rendered],
                    $observed,
                    "{$label} : une combinaison différenciée ne doit JAMAIS se relire conforme",
                );
                $approximate++;

                continue;
            }

            if ($observed === []) {
                // Mode non réductible : compté en écart, jamais approximé.
                $unreadable++;

                continue;
            }

            self::assertSame(
                [$rendering->rendered],
                $observed,
                "{$label} : la boucle doit fermer sur les verbes RENDUS",
            );
            $closed++;
        }

        // Contrôle inverse : si la boucle ne fermait sur rien, tout ce qui précède
        // serait vrai pour la pire des raisons.
        self::assertGreaterThanOrEqual(4, $closed, 'la boucle doit fermer sur les combinaisons à niveau unique');
        self::assertGreaterThanOrEqual(1, $unreadable, 'les modes non réductibles doivent exister et être vus');
        self::assertGreaterThanOrEqual(1, $approximate, 'les combinaisons différenciées doivent être exercées');
    }

    /**
     * LES DEUX LIGNES QUI COMPTENT POUR UNE INSTANCE EN PLACE : celles que la
     * migration produit. Elles ferment la boucle EXACTEMENT, donc aucune dérive
     * fantôme n'apparaîtra à la première relecture après déploiement.
     */
    #[Test]
    public function the_two_migrated_combinations_close_the_loop_exactly(): void
    {
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $subject = PlanSubject::group((int) $classe->id);

        foreach ([[PlanGrant::VERB_LIRE], PlanGrant::VERBS] as $verbs) {
            $node = new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, [
                new PlanGrant('classe', $subject, $verbs),
            ]);

            $this->fakeGetfacl('');
            $compiled = app(PosixAclCompiler::class)->compile($node);

            self::assertSame(
                [$verbs],
                $this->readBack($compiled->acls, $compiled->restrictsDeletion, $node),
            );
        }
    }

    /**
     * La restriction de suppression est LUE, et c'est ce qui rend l'idempotence
     * vraie : sans l'en-tête, elle serait invisible et se reposerait à chaque
     * passage.
     */
    #[Test]
    public function the_deletion_restriction_is_read_from_the_header_and_changes_the_reading(): void
    {
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $node = new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, [
            new PlanGrant('classe', PlanSubject::group((int) $classe->id), PlanGrant::VERBS),
        ]);

        $acls = ['user::rwx', 'group:classe_3emea:rwx', 'mask::rwx', 'other::---'];

        // Sans restriction : les quatre verbes.
        self::assertSame([PlanGrant::VERBS], $this->readBack($acls, false, $node));

        // AVEC : la suppression du travail des autres n'est plus là, et la lecture
        // le dit — c'est exactement le rendu approché que la compilation produit.
        self::assertSame(
            [[PlanGrant::VERB_LIRE, PlanGrant::VERB_EDITER, PlanGrant::VERB_CREER]],
            $this->readBack($acls, true, $node),
        );
    }

    /**
     * Truque les processus en gardant TOUJOURS le motif de relecture AVANT
     * l'attrape-tout : le truquage se fusionne d'un appel à l'autre en conservant
     * l'ordre de première apparition des motifs, et un attrape-tout posé en premier
     * avalerait la relecture — le nœud se lirait alors « observé, aucun octroi »,
     * c'est-à-dire faux et silencieux.
     */
    private function fakeGetfacl(string $output): void
    {
        Process::fake([
            'sudo getfacl *' => Process::result(output: $output, exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
    }

    /**
     * Rend au backend une liste d'entrées comme si le disque la portait, et
     * remonte les verbes observés, un tableau par octroi relu.
     *
     * @param  list<string>  $acls
     * @return list<list<string>>
     */
    private function readBack(array $acls, bool $restricted, PlanNode $node): array
    {
        $header = ['# file: proj', '# owner: www-admin'];
        if ($restricted) {
            // L'en-tête n'est émis par l'outil QUE si un drapeau est posé — son
            // absence est donc une réponse, pas une ignorance.
            $header[] = '# flags: --t';
        }

        $this->fakeGetfacl(implode("\n", [...$header, ...$acls]));

        $plan = new FilePlan('@partage', 'proj', [], [$node]);
        $observation = app(PosixFileBackend::class)->inspect($plan)->for(PlanNode::ROOT_PATH);

        return array_map(
            static fn ($grant): array => $grant->verbs,
            $observation?->grants ?? [],
        );
    }
}
