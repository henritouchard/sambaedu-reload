<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendName;
use App\Enums\FileBackendOutcome;
use App\Enums\PlanNodeNature;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
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
 * Story 60.4 — LE BACKEND RÉEL : réconciliation, précédence, idempotence,
 * garde-fou d'échelle, plafond décliné.
 *
 * La simulation d'exécution est ici l'outil NORMAL : ce code exécute, c'est sa
 * fonction. Elle reste interdite au-dessus de la ligne.
 */
class PosixFileBackendTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/se5-posix-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        foreach (['commun', 'existe', 'compose', 'echelle', 'plafond', 'cree'] as $dir) {
            @rmdir($this->tempRoot . '/' . $dir);
        }
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function backend(): PosixFileBackend
    {
        return app(PosixFileBackend::class);
    }

    /** @param list<PlanGrant> $grants */
    private function plan(string $root, array $grants = [], ?int $plafond = null): FilePlan
    {
        return new FilePlan('@partage', $root, [], [
            new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre, $grants, true, $plafond),
        ]);
    }

    // =========================================================================
    // Réconciliation
    // =========================================================================

    #[Test]
    public function a_missing_directory_is_created_and_its_rights_are_applied(): void
    {
        Process::fake();

        $report = $this->backend()->provision($this->plan('cree'));

        self::assertSame(FileBackendOutcome::Applique, $report->for(PlanNode::ROOT_PATH)->outcome);
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'mkdir -p') && str_contains($p->command, 'cree'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl') && str_contains($p->command, '-b'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'chown www-admin'));
        Process::assertRan(fn ($p): bool => str_contains($p->command, "chgrp 'domain admins'"));
    }

    /**
     * L'IDEMPOTENCE CONTRACTUELLE. La séquence historique réécrivait toujours ;
     * le contrat exige qu'un second passage sur un état déjà conforme rende
     * `conforme` SANS écriture. C'est aussi ce qui rend vraie la promesse « rien
     * ne bouge sur une instance en place ».
     */
    #[Test]
    public function a_directory_already_conform_is_reported_conforme_and_nothing_is_written(): void
    {
        @mkdir($this->tempRoot . '/existe', 0o755, true);

        Process::fake([
            'sudo getfacl *' => Process::result(output: implode("\n", PosixAclCompiler::BASE_ACLS), exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $report = $this->backend()->provision($this->plan('existe'));

        self::assertSame(FileBackendOutcome::Conforme, $report->for(PlanNode::ROOT_PATH)->outcome);
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'setfacl'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'chown'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'chgrp'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'mkdir'));
    }

    /**
     * LA CONSÉQUENCE PHYSIQUE D'UN DOUTE — le test qui compte.
     *
     * La pose commence par PURGER les droits étendus du répertoire, puis réécrit
     * ce que la compilation a produit. Si un octroi de groupe était simplement
     * laissé de côté parce que la sonde d'existence n'a pas pu répondre, la purge
     * aurait quand même lieu et le répertoire ressortirait SANS cet accès : une
     * panne de résolution de noms se convertirait en révocation, sur chaque
     * répertoire réconcilié pendant la panne.
     *
     * Le refus est donc bloquant, et rien n'est tenté sur le nœud.
     */
    #[Test]
    public function a_probe_that_could_not_answer_leaves_the_directory_untouched(): void
    {
        @mkdir($this->tempRoot . '/existe', 0o755, true);

        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 127),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $classe = UserGroup::factory()->create(['type' => 'classe', 'name' => 'PosixDoute']);

        $report = $this->backend()->provision($this->plan('existe', [
            new PlanGrant('@member', PlanSubject::group((int) $classe->id), PlanGrant::ACCESS_RW),
        ]));

        self::assertSame(FileBackendOutcome::Echec, $report->for(PlanNode::ROOT_PATH)->outcome);
        self::assertStringContainsString('impossible de savoir', (string) $report->for(PlanNode::ROOT_PATH)->detail);

        // AUCUNE écriture — et surtout aucune PURGE.
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'setfacl'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'chown'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'chgrp'));
    }

    /**
     * La comparaison préalable est SÉMANTIQUE : la forme abrégée en entrée et la
     * forme canonique en sortie disent la même chose. Sans cela, tout passage se
     * croirait dérivé et réécrirait — et « rien ne bouge » serait faux.
     */
    #[Test]
    public function the_conformity_reading_compares_meanings_not_spellings(): void
    {
        @mkdir($this->tempRoot . '/existe', 0o755, true);
        $alice = User::factory()->create(['login' => 'alice']);

        $canonical = array_merge(PosixAclCompiler::BASE_ACLS, [
            'user:alice:r-x',            // forme de SORTIE
            'default:user:alice:r-x',
        ]);

        Process::fake([
            'sudo getfacl *' => Process::result(output: implode("\n", $canonical), exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $report = $this->backend()->provision($this->plan('existe', [
            new PlanGrant('@assignation', PlanSubject::user((int) $alice->id), PlanGrant::ACCESS_RO),
        ]));

        self::assertSame(FileBackendOutcome::Conforme, $report->for(PlanNode::ROOT_PATH)->outcome);
    }

    #[Test]
    public function a_drifted_directory_is_rewritten_and_reported_applique(): void
    {
        @mkdir($this->tempRoot . '/existe', 0o755, true);

        Process::fake([
            'sudo getfacl *' => Process::result(output: "user::rwx\nother::rwx", exitCode: 0),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $report = $this->backend()->provision($this->plan('existe'));

        self::assertSame(FileBackendOutcome::Applique, $report->for(PlanNode::ROOT_PATH)->outcome);
        Process::assertRan(fn ($p): bool => str_contains($p->command, 'setfacl'));
    }

    // =========================================================================
    // Précédence (AC9)
    // =========================================================================

    /**
     * LE TEST COMPOSITE DE PRÉCÉDENCE. Quatre situations sur la même échelle :
     * un échec de propriétaire ne se laisse pas masquer par des droits posés ; une
     * dette de projection prime sur « j'ai créé le dossier » ; un état inchangé se
     * dit `conforme` ; un écart corrigé se dit `applique`.
     */
    #[Test]
    public function the_collapse_of_several_gestures_follows_the_announced_precedence(): void
    {
        // 1. Le changement de propriétaire échoue, les droits sont pourtant posés.
        Process::fake([
            'sudo chown *' => Process::result(output: '', errorOutput: 'operation not permitted', exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $failed = $this->backend()->provision($this->plan('compose'))->for(PlanNode::ROOT_PATH);
        self::assertSame(FileBackendOutcome::Echec, $failed->outcome, 'un échec ne se laisse jamais masquer');
        self::assertNotNull($failed->detail);
        @rmdir($this->tempRoot . '/compose');
    }

    #[Test]
    public function an_unprojected_edge_role_wins_over_the_directory_creation(): void
    {
        Process::fake();
        $group = UserGroup::create(['name' => 'ProjetX', 'type' => 'projet']);

        $entry = $this->backend()->provision($this->plan('compose', [
            new PlanGrant('@role', PlanSubject::group((int) $group->id, 'manager'), PlanGrant::ACCESS_RW),
        ]))->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendOutcome::NonImplemente, $entry->outcome);
        self::assertStringContainsString('manager', (string) $entry->detail);
        self::assertStringContainsString('projet', (string) $entry->detail);
        @rmdir($this->tempRoot . '/compose');
    }

    /**
     * Un octroi sur un groupe que le système ne connaît pas ne s'écrit PAS et le
     * nœud rend un ÉCHEC nommant le groupe attendu — c'est l'incident du groupe
     * sans suffixe d'établissement, rendu visible au lieu d'être journalisé.
     */
    #[Test]
    public function an_unresolvable_group_is_a_failure_that_names_the_expected_name(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $group = UserGroup::create(['name' => '3SB', 'type' => 'classe']);

        $entry = $this->backend()->provision($this->plan('compose', [
            new PlanGrant('@assignation', PlanSubject::group((int) $group->id), PlanGrant::ACCESS_RW),
        ]))->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendOutcome::Echec, $entry->outcome);
        self::assertStringContainsString('classe_3sb', (string) $entry->detail);
        // Le nom a bien été SONDÉ (lecture seule, sans élévation) mais jamais POSÉ.
        Process::assertRan(fn ($p): bool => str_starts_with($p->command, 'getent group ')
            && str_contains($p->command, 'classe_3sb'));
        Process::assertNotRan(fn ($p): bool => str_contains($p->command, 'setfacl')
            && str_contains($p->command, 'classe_3sb'));
        @rmdir($this->tempRoot . '/compose');
    }

    /**
     * Le compte au nom d'ouverture de session inutilisable : le disque est
     * IDENTIQUE à hier (aucune entrée écrite), mais le silence est aboli.
     */
    #[Test]
    public function an_unusable_login_is_skipped_on_disk_and_reported_as_a_failure(): void
    {
        Process::fake();
        $bad = User::factory()->create(['login' => 'in valid']);

        $entry = $this->backend()->provision($this->plan('compose', [
            new PlanGrant('@assignation', PlanSubject::user((int) $bad->id), PlanGrant::ACCESS_RW),
        ]))->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendOutcome::Echec, $entry->outcome);
        self::assertStringContainsString((string) $bad->id, (string) $entry->detail);
        @rmdir($this->tempRoot . '/compose');
    }

    // =========================================================================
    // Garde-fou d'échelle (AC10)
    // =========================================================================

    #[Test]
    public function a_node_beyond_the_nominative_ceiling_writes_nothing_and_says_the_numbers(): void
    {
        Process::fake();

        $grants = [];
        for ($i = 0; $i <= PosixAclCompiler::NOMINATIVE_ENTRIES_CEILING; $i++) {
            $user = User::factory()->create(['login' => 'eleve' . $i]);
            $grants[] = new PlanGrant('@assignation', PlanSubject::user((int) $user->id), PlanGrant::ACCESS_RO);
        }

        $entry = $this->backend()->provision($this->plan('echelle', $grants))->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendOutcome::Echec, $entry->outcome);
        self::assertStringContainsString((string) (PosixAclCompiler::NOMINATIVE_ENTRIES_CEILING + 1), (string) $entry->detail);
        self::assertStringContainsString((string) PosixAclCompiler::NOMINATIVE_ENTRIES_CEILING, (string) $entry->detail);
        self::assertStringContainsString('groupe dérivé', (string) $entry->detail);
        Process::assertNothingRan();
    }

    /**
     * Les nœuds « par membre » ne sont PAS concernés par construction : une entrée
     * nominative par nœud, jamais une audience entière sur le même nœud.
     */
    #[Test]
    public function per_member_nodes_are_out_of_reach_of_the_ceiling_by_construction(): void
    {
        Process::fake();

        $nodes = [new PlanNode(PlanNode::ROOT_PATH, 'Racine', PlanNodeNature::ContenuLibre)];
        for ($i = 0; $i < PosixAclCompiler::NOMINATIVE_ENTRIES_CEILING + 5; $i++) {
            $user = User::factory()->create(['login' => 'membre' . $i]);
            $nodes[] = new PlanNode('membre' . $i, 'Membre', PlanNodeNature::ParMembre, [
                new PlanGrant('@nominatif', PlanSubject::user((int) $user->id), PlanGrant::ACCESS_RW),
            ]);
        }

        $report = $this->backend()->provision(new FilePlan('@arbre', 'echelle', [], $nodes));

        self::assertSame([], $report->failures(), 'aucun nœud par membre ne doit buter sur le plafond');

        for ($i = 0; $i < PosixAclCompiler::NOMINATIVE_ENTRIES_CEILING + 5; $i++) {
            @rmdir($this->tempRoot . '/echelle/membre' . $i);
        }
        @rmdir($this->tempRoot . '/echelle');
    }

    // =========================================================================
    // Plafond (AC11)
    // =========================================================================

    #[Test]
    public function a_capped_node_declines_honestly_as_a_debt_never_as_a_model_limit(): void
    {
        Process::fake();

        $entry = $this->backend()->quota($this->plan('plafond', [], 5_000_000))->for(PlanNode::ROOT_PATH);

        self::assertSame(FileBackendOutcome::NonImplemente, $entry->outcome);
        self::assertTrue($entry->outcome->isImplementationDebt());
        self::assertFalse($entry->outcome->isModelLimit());
        self::assertStringContainsString('suspendue', (string) $entry->detail);
        Process::assertNothingRan();
    }

    #[Test]
    public function a_plan_without_a_cap_gives_an_empty_and_valid_quota_report(): void
    {
        Process::fake();

        $report = $this->backend()->quota($this->plan('plafond'));

        self::assertSame(0, $report->count());
        self::assertSame(FileBackendName::Posix, $report->backend);
    }

    // =========================================================================
    // Verrou
    // =========================================================================

    #[Test]
    public function a_pass_held_by_another_writes_nothing_and_says_so_on_every_node(): void
    {
        Process::fake();

        $lock = \Illuminate\Support\Facades\Cache::store('file')->lock('network-shares:provision:commun', 60);
        self::assertTrue($lock->get());

        try {
            $report = $this->backend()->provision($this->plan('commun'));

            self::assertSame(FileBackendOutcome::Echec, $report->for(PlanNode::ROOT_PATH)->outcome);
            Process::assertNothingRan();
        } finally {
            $lock->release();
        }
    }
}
