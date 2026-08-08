<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixFileBackend;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.4 — LES DEUX FAÇONS DE DÉCLINER, ALIGNÉES CÔTE À CÔTE.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI CE FICHIER EXISTE.**
 *
 * « Limite du modèle » et « dette de notre code » se disent tous les deux
 * « le backend n'a rien fait », et les écraser l'un sur l'autre est la
 * simplification la plus tentante du dépôt — assez tentante pour avoir coûté DEUX
 * corrections d'Henri en 60.3 et 60.4. Elles ne veulent pourtant pas dire la même
 * chose, elles n'appartiennent pas au même propriétaire, elles n'ont pas la même
 * durée de vie, et une future interface ne les rend PAS de la même façon :
 *
 *  | déclin            | ce qui est vrai                       | propriétaire | durée      | UI    |
 *  |-------------------|---------------------------------------|--------------|------------|-------|
 *  | `non_exprimable`  | le MODÈLE n'a pas le concept          | le backend   | permanent  | masque|
 *  | `non_implemente`  | le mécanisme existe, SE5 ne le pilote pas | notre code | temporaire | grise |
 *
 * Jusqu'à cette story, le serveur de fichiers historique ne produisait QUE le
 * second — au point que son docblock affirmait ne jamais produire le premier. Les
 * quatre verbes ont fait naître de vraies limites de modèle : les deux coexistent
 * désormais dans le même backend, sur le même passage, et c'est exactement là que
 * la confusion pourrait se réintroduire sans que rien ne tombe. D'où ce test.
 */
class PosixDeclineKindsTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-declines-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->tempRoot . '/*') as $dir) {
            @rmdir((string) $dir);
        }
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    /** @param list<PlanGrant> $grants */
    private function provision(string $root, array $grants)
    {
        $plan = new FilePlan('@partage', $root, [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, $grants),
        ]);

        return app(PosixFileBackend::class)->provision($plan)->for(PlanNode::ROOT_PATH);
    }

    /**
     * LES DEUX, SUR LE MÊME PASSAGE, dans le même fichier de test, à trois lignes
     * l'un de l'autre. Les confondre demanderait de faire tomber ce test.
     */
    #[Test]
    public function a_model_limit_and_an_implementation_debt_never_say_the_same_thing(): void
    {
        Process::fake();

        // --- DETTE : le rôle d'arête « encadrant » d'un groupe hors du trio
        //     d'annuaire. Le mécanisme EXISTE (l'annuaire le fait pour les
        //     classes) ; SE5 ne le projette pas ailleurs. C'est notre code qui
        //     manque, et la story qui le comblerait est datée (62.7).
        $projet = UserGroup::create(['name' => 'ProjetX', 'type' => 'projet']);
        $debt = $this->provision('dette', [
            new PlanGrant('@role', PlanSubject::group((int) $projet->id, 'manager'), PlanGrant::VERBS),
        ]);

        // --- LIMITE DE MODÈLE : supprimer sans créer. Les deux verbes passent par
        //     le même levier ; aucune story ne le changera, c'est le mécanisme.
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $limit = $this->provision('limite', [
            new PlanGrant('classe', PlanSubject::group((int) $classe->id), [
                PlanGrant::VERB_LIRE, PlanGrant::VERB_SUPPRIMER,
            ]),
        ]);

        self::assertSame(FileBackendOutcome::NonImplemente, $debt->outcome);
        self::assertTrue($debt->outcome->isImplementationDebt(), 'la dette est TEMPORAIRE, propriété de notre code');
        self::assertFalse($debt->outcome->isModelLimit());

        self::assertSame(FileBackendOutcome::NonExprimable, $limit->outcome);
        self::assertTrue($limit->outcome->isModelLimit(), 'la limite est PERMANENTE, propriété du backend');
        self::assertFalse($limit->outcome->isImplementationDebt());

        // Les deux EXIGENT un détail, et les deux le portent : un déclin sans
        // raison est exactement le silence que l'epic supprime.
        self::assertNotSame('', trim((string) $debt->detail));
        self::assertNotSame('', trim((string) $limit->detail));
        self::assertNotSame($debt->detail, $limit->detail);
    }

    /**
     * LA PRÉCÉDENCE, exercée avec un VRAI `non_exprimable` — ce que le test
     * composite de 60.4 ne pouvait pas faire, puisque le backend n'en produisait
     * aucun.
     *
     * Un nœud portant À LA FOIS une dette de projection et une limite de modèle
     * rend `non_exprimable`. L'ordre annoncé au docblock du backend
     * (`echec > non_exprimable > non_implemente > applique > conforme`) cesse ici
     * d'être une intention pour devenir une propriété vérifiée.
     */
    #[Test]
    public function a_model_limit_wins_over_an_implementation_debt_on_the_same_node(): void
    {
        Process::fake();

        $projet = UserGroup::create(['name' => 'ProjetX', 'type' => 'projet']);
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $entry = $this->provision('les-deux', [
            // La dette…
            new PlanGrant('@role', PlanSubject::group((int) $projet->id, 'manager'), PlanGrant::VERBS),
            // …et la limite, sur le MÊME nœud.
            new PlanGrant('classe', PlanSubject::group((int) $classe->id), [
                PlanGrant::VERB_LIRE, PlanGrant::VERB_SUPPRIMER,
            ]),
        ]);

        self::assertSame(FileBackendOutcome::NonExprimable, $entry->outcome, 'la limite prime sur la dette');

        // Le détail porte LES DEUX causes : l'effondrement choisit un état, il ne
        // jette pas la moitié de ce qu'il sait.
        self::assertStringContainsString('manager', (string) $entry->detail);
        self::assertStringContainsString('suppression', (string) $entry->detail);
    }

    /**
     * ET L'ÉCHEC PRIME SUR TOUT. Sans cette ligne, on aurait seulement prouvé que
     * la limite de modèle prime sur la dette — pas qu'elle reste à sa place dans
     * l'échelle.
     */
    #[Test]
    public function a_real_failure_still_wins_over_a_model_limit(): void
    {
        Process::fake([
            'sudo chown *' => Process::result(output: '', errorOutput: 'operation not permitted', exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $entry = $this->provision('echec', [
            new PlanGrant('classe', PlanSubject::group((int) $classe->id), [
                PlanGrant::VERB_LIRE, PlanGrant::VERB_SUPPRIMER,
            ]),
        ]);

        self::assertSame(FileBackendOutcome::Echec, $entry->outcome);
    }

    /**
     * Story 62.4 — LE SORT DES RAPPORTS DÉJÀ EN CACHE, tranché et VÉRIFIÉ.
     *
     * Le dernier rapport de réconciliation voyage en TABLEAU sous une clé de cache
     * à durée courte. Si ce tableau portait un vocabulaire d'accès, un vieux
     * tableau se relirait dans le nouveau monde — et il aurait fallu versionner ou
     * purger la clé. Le constat est qu'il n'en porte aucun : un rapport dit un
     * CHEMIN, un ÉTAT et une CAUSE, jamais un niveau de droit. Aucune purge n'est
     * donc nécessaire, et ce test est ce qui empêche l'affirmation de vieillir.
     */
    #[Test]
    public function the_report_that_travels_to_the_cache_carries_no_access_vocabulary(): void
    {
        Process::fake();
        $classe = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);

        $plan = new FilePlan('@partage', 'cache', [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, [
                new PlanGrant('classe', PlanSubject::group((int) $classe->id), PlanGrant::VERBS),
            ]),
        ]);

        $array = app(PosixFileBackend::class)->provision($plan)->toArray();

        self::assertSame(['path', 'outcome', 'detail'], array_keys($array['nodes'][0]));

        $flat = json_encode($array, JSON_UNESCAPED_UNICODE);
        foreach (['"ro"', '"rw"', 'access', 'verbs'] as $vocabulary) {
            self::assertStringNotContainsString($vocabulary, (string) $flat, 'vocabulaire de droits dans un rapport');
        }
    }

    /**
     * LE DOCBLOCK DU BACKEND A ÉTÉ RÉÉCRIT, et le test l'épingle.
     *
     * Il affirmait « `non_exprimable` n'est jamais produit ici ». Cette phrase est
     * devenue fausse avec les quatre verbes, et une garantie périmée dans un
     * docblock est pire qu'une garantie absente : elle est LUE, et crue.
     */
    #[Test]
    public function the_backend_docblock_no_longer_claims_it_never_declines_by_model_limit(): void
    {
        $raw = (string) file_get_contents(
            dirname(__DIR__, 6) . '/app/Services/Filesystem/Backend/Posix/PosixFileBackend.php'
        );
        $source = (string) preg_replace('/\s+/u', ' ', str_replace('*', ' ', $raw));

        self::assertStringNotContainsString(
            'non_exprimable` n\'est jamais produit ici',
            $source,
            'le docblock affirme encore une garantie que le code ne tient plus',
        );
        self::assertStringContainsString(
            '`non_exprimable` EST produit ici',
            $source,
            'le docblock doit nommer ce qu\'il produit désormais',
        );
        // Et la distinction reste écrite : sans elle, la réécriture aurait pu
        // effacer la nuance en même temps que la phrase fausse.
        self::assertStringContainsString('non_implemente', $source);
    }
}
