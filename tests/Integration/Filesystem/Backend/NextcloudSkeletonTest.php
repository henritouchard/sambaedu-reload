<?php

declare(strict_types=1);

namespace Tests\Integration\Filesystem\Backend;

use App\Enums\FileBackendObservation;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Filesystem\Backend\Support\ThrowawayNextcloudBackend;

/**
 * Story 60.3 — LE SQUELETTE JETABLE, contre une instance RÉELLE.
 *
 * **Ce que ce test prouve, et lui seul** : que les cinq signatures du contrat sont
 * IMPLÉMENTABLES par une classe PHP parlant à un plan de fichiers étranger. Le
 * sondage d'ouverture d'epic avait validé les concepts en lignes de commande ; le
 * backend d'aperçu, n'exécutant rien, ne prouve rien ; le double propagateur
 * transcrit des mesures sans jamais toucher le réseau. Il manquait ce maillon.
 *
 * **SKIPPÉ PAR DÉFAUT**, et jamais en intégration continue : il exige les trois
 * variables d'environnement `NC_SPIKE_URL`, `NC_SPIKE_ADMIN` et
 * `NC_SPIKE_PASSWORD`, et il est hors de la suite par défaut (`phpunit.integration.xml`).
 *
 * Il laisse l'instance dans l'état où il l'a trouvée : le dossier d'épreuve est
 * supprimé en fin de scénario.
 *
 * **Il n'a besoin ni de base ni de conteneur** — d'où le cas de test PHPUnit NU
 * plutôt que le cas de test applicatif : les DTO du contrat sont du PHP pur, et
 * c'est en soi une information sur la ligne de coupe. Le brancher sur le cas de
 * test applicatif le ferait buter sur la garde de base de données du dépôt, pour
 * une base dont il n'a aucun usage.
 */
class NextcloudSkeletonTest extends TestCase
{
    private const GROUP_CLASSE_ID = 7;

    private const USER_PROF_ID = 101;

    private ?ThrowawayNextcloudBackend $backend = null;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('NC_SPIKE_URL') ?: '';
        $admin = getenv('NC_SPIKE_ADMIN') ?: '';
        $password = getenv('NC_SPIKE_PASSWORD') ?: '';

        if ($url === '' || $admin === '' || $password === '') {
            $this->markTestSkipped(
                'squelette jetable : nécessite NC_SPIKE_URL, NC_SPIKE_ADMIN et NC_SPIKE_PASSWORD '
                . '(instance de sondage, exécution manuelle depuis le checkout principal).'
            );
        }

        $this->root = 'Spike603_' . substr((string) time(), -6);

        $this->backend = new ThrowawayNextcloudBackend($url, $admin, $password, [
            PlanSubject::group(self::GROUP_CLASSE_ID)->sortKey() => ['type' => 'group', 'name' => 'spike603classe'],
            PlanSubject::user(self::USER_PROF_ID)->sortKey() => ['type' => 'user', 'name' => 'spike603prof'],
        ]);

        $this->backend->ensureGroup('spike603classe');
        $this->backend->ensureUser('spike603prof', 'Spike603Prof!', 'spike603classe');
        $this->backend->ensureUser('spike603eleve', 'Spike603Eleve!', 'spike603classe');
    }

    protected function tearDown(): void
    {
        $this->backend?->removeFolder($this->root);
        parent::tearDown();
    }

    /**
     * Le plan éprouvé : le cas le plus étroit qui contienne quand même la
     * difficulté — une racine ouverte à la classe, un dossier d'enseignants qui
     * ne lui accorde rien, et un plafond.
     */
    private function plan(): FilePlan
    {
        $classe = PlanSubject::group(self::GROUP_CLASSE_ID);
        $prof = PlanSubject::user(self::USER_PROF_ID);

        return new FilePlan(
            'classe_share',
            $this->root,
            ['classe' => [$classe], 'profs' => [$prof]],
            [
                new PlanNode(
                    PlanNode::ROOT_PATH,
                    'Racine',
                    PlanNodeNature::Partagee,
                    [
                        new PlanGrant('classe', $classe, [PlanGrant::VERB_LIRE]),
                        new PlanGrant('profs', $prof, PlanGrant::VERBS),
                    ],
                ),
                new PlanNode(
                    '_profs',
                    'Espace des enseignants',
                    PlanNodeNature::Partagee,
                    [new PlanGrant('profs', $prof, PlanGrant::VERBS)],
                    true,
                    2147483648,
                    ['classe'],
                ),
            ],
        );
    }

    #[Test]
    public function the_five_signatures_are_implementable_against_a_real_instance(): void
    {
        $plan = $this->plan();
        $backend = $this->backend;

        // --- provision : un statut PAR NŒUD, racine comprise -----------------
        $report = $backend->provision($plan);

        $this->assertSame(2, $report->count());
        $this->assertNotNull($report->for(PlanNode::ROOT_PATH));
        $this->assertSame(FileBackendOutcome::Applique, $report->for(PlanNode::ROOT_PATH)->outcome);

        // --- LA fuite : le dossier des enseignants n'est PAS refermable -------
        $profs = $report->for('_profs');
        $this->assertSame(
            FileBackendOutcome::NonExprimable,
            $profs->outcome,
            'l\'instruction de retrait est acceptée sans effet : le nœud ne peut pas être refermé',
        );
        $this->assertTrue($profs->outcome->isModelLimit());
        $this->assertStringContainsString('classe', (string) $profs->detail);

        // --- idempotence : un rejeu ne casse rien et ne ment pas -------------
        $replay = $backend->provision($plan);
        $this->assertSame(FileBackendOutcome::Conforme, $replay->for(PlanNode::ROOT_PATH)->outcome);
        $this->assertSame(FileBackendOutcome::NonExprimable, $replay->for('_profs')->outcome);
        $this->assertStringNotContainsStringIgnoringCase(
            '405',
            (string) json_encode($replay->toArray()),
            'aucun code de transport ne remonte au-dessus de la ligne de contrat',
        );

        // --- inspect : balayage, et vocabulaire de PLAN ----------------------
        $inspection = $backend->inspect($plan);

        $this->assertSame(2, $inspection->count());
        $this->assertSame(FileBackendObservation::Observe, $inspection->for(PlanNode::ROOT_PATH)->status);

        $subjects = array_map(
            static fn ($g): string => $g->subject->type . '#' . $g->subject->id,
            $inspection->for(PlanNode::ROOT_PATH)->grants,
        );
        $this->assertContains('user_group#' . self::GROUP_CLASSE_ID, $subjects);

        // La relecture du nœud clos porte l'octroi de la classe — la fuite, vue.
        $leaked = array_filter(
            $inspection->for('_profs')->grants,
            static fn ($g): bool => $g->subject->id === self::GROUP_CLASSE_ID,
        );
        $this->assertNotEmpty($leaked, 'la relecture doit rendre l\'octroi que le plan a clos ici');

        // --- quota : décliner sans échouer, et dire POURQUOI -----------------
        $quota = $backend->quota($plan);

        $this->assertSame(['_profs'], array_map(static fn ($e): string => $e->path, $quota->entries));
        $this->assertSame(FileBackendOutcome::NonExprimable, $quota->for('_profs')->outcome);
        $this->assertCount(0, $quota->failures());

        // … et la raison est CONSTATABLE : le quota est une propriété de la
        // personne, pas du dossier.
        $this->assertNotNull($backend->userQuota('spike603eleve'));

        // --- deprovision : révoquer sans détruire ----------------------------
        $removal = $backend->deprovision($plan);
        $this->assertSame(2, $removal->count());
        $this->assertCount(0, $removal->failures());
    }
}
