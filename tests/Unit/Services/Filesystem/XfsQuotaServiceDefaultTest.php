<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Facades\SEConfig;
use App\Jobs\ApplyQuotaJob;
use App\Models\QuotaRule;
use App\Models\User;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.4 — **LA RÉSOLUTION TIENT EN TROIS ÉTAGES, ET AUCUN NE DEVINE.**
 *
 * ---------------------------------------------------------------------------
 * Ce fichier REMPLACE `XfsQuotaServiceItinerantTest`, qui a perdu son sujet : la
 * règle « itinérante » n'existe plus. Elle n'était d'ailleurs jamais rendue par les
 * deux devinettes de profil du dépôt — c'était un quatrième public d'interface pour
 * une branche de code sans rapport, déclenchée par une colonne de rattachement.
 *
 * Ce qui est épinglé ici, dans l'ordre :
 *  - les trois étages et leur priorité (nominative > groupe > défaut d'instance) ;
 *  - « aucune règle » ⇒ illimité, et c'est une réponse, pas un silence ;
 *  - le plafond nul d'une règle de groupe reste prioritaire là où il l'était ;
 *  - **un compte rattaché à un autre établissement reçoit le défaut d'instance
 *    comme tout le monde** — l'étage qui le traitait à part est mort ;
 *  - **deux comptes sans règle propre reçoivent LE MÊME plafond**, quels que
 *    soient leurs groupes : c'est LA propriété que la story livre ;
 *  - et la signature publique, qui ne porte plus que TROIS paramètres — l'assertion
 *    qui prouve que la devinette n'a pas de porte dérobée.
 * ---------------------------------------------------------------------------
 */
class XfsQuotaServiceDefaultTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('users');
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('quota_audit_logs');
        }
        parent::tearDown();
    }

    private function ensureTables(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login')->unique();
                $table->string('school_code')->nullable();
                $table->string('role')->default('eleve');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('quota_rules')) {
            Schema::create('quota_rules', function (Blueprint $table) {
                $table->id();
                $table->string('type', 30);
                $table->string('target')->nullable();
                $table->string('partition');
                $table->integer('quota_soft_mb')->default(0);
                $table->integer('quota_hard_mb')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('quota_audit_logs')) {
            Schema::create('quota_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action');
                $table->string('performed_by');
                $table->string('target_type')->nullable();
                $table->string('target_name')->nullable();
                $table->string('partition')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->boolean('fs_applied')->default(false);
                $table->text('fs_error')->nullable();
                $table->unsignedBigInteger('quota_rule_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
            $this->createdTables = true;
        }
    }

    private function makeService(): XfsQuotaService
    {
        return new XfsQuotaService();
    }

    /**
     * Un service dont les DEUX coutures système sont substituées : l'état de la
     * partition et le relevé d'occupation. Aucun appel système n'est joué.
     *
     * @param  list<string>  $usage  lignes du relevé, `login blocs_ko …`
     */
    private function serviceWithSeams(array $usage = [], int $usageExit = 0): XfsQuotaService
    {
        return new class($usage, $usageExit) extends XfsQuotaService
        {
            /** @param list<string> $usage */
            public function __construct(private array $usage, private int $usageExit)
            {
                parent::__construct();
            }

            protected function probePartitionQuotaState(string $partition): array
            {
                return ['output' => ['  Enforcement: ON'], 'exit_code' => 0];
            }

            protected function probePartitionUsageReport(string $partition): array
            {
                return ['output' => $this->usage, 'exit_code' => $this->usageExit];
            }
        };
    }

    private function createUser(string $login, ?string $schoolCode): User
    {
        return User::query()->create([
            'login' => $login,
            'school_code' => $schoolCode,
            'role' => 'eleve',
            'is_active' => true,
        ]);
    }

    private function rule(string $type, ?string $target, int $soft, int $hard, string $partition = QuotaRule::PARTITION_HOME): QuotaRule
    {
        return QuotaRule::query()->create([
            'type' => $type,
            'target' => $target,
            'partition' => $partition,
            'quota_soft_mb' => $soft,
            'quota_hard_mb' => $hard,
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // Les trois étages
    // =========================================================================

    #[Test]
    public function a_default_rule_reaches_an_account_that_no_other_rule_covers(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);

        $result = $this->makeService()->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME);

        $this->assertSame('default', $result['source']);
        $this->assertSame('Défaut', $result['source_name']);
        $this->assertSame(300, $result['quota_soft_mb']);
        $this->assertSame(360, $result['quota_hard_mb']);
        $this->assertFalse($result['is_unlimited']);
    }

    #[Test]
    public function a_nominative_rule_beats_a_group_rule_which_beats_the_instance_default(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);
        $this->rule(QuotaRule::TYPE_GROUP, 'profs', 1500, 1800);
        $this->rule(QuotaRule::TYPE_USER, 'alice', 1000, 1200);

        $service = $this->makeService();

        $nominative = $service->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME, ['profs']);
        $this->assertSame('user', $nominative['source']);
        $this->assertSame(1000, $nominative['quota_soft_mb']);

        $group = $service->getEffectiveQuota('bob', QuotaRule::PARTITION_HOME, ['profs']);
        $this->assertSame('group', $group['source']);
        $this->assertSame('profs', $group['source_name']);
        $this->assertSame(1500, $group['quota_soft_mb']);

        $default = $service->getEffectiveQuota('carole', QuotaRule::PARTITION_HOME, ['3A']);
        $this->assertSame('default', $default['source']);
        $this->assertSame(300, $default['quota_soft_mb']);
    }

    /** Le plus GRAND quota de groupe gagne — comportement inchangé. */
    #[Test]
    public function the_largest_group_rule_wins(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);
        $this->rule(QuotaRule::TYPE_GROUP, 'petit', 500, 600);
        $this->rule(QuotaRule::TYPE_GROUP, 'grand', 5000, 6000);

        $result = $this->makeService()->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME, ['petit', 'grand']);

        $this->assertSame('grand', $result['source_name']);
        $this->assertSame(5000, $result['quota_soft_mb']);
    }

    /**
     * `0` = illimité, et il reste prioritaire **LÀ OÙ IL L'ÉTAIT DÉJÀ** : sur le
     * défaut d'instance, quand c'est la règle de groupe retenue qui le porte.
     *
     * ⚠️ Il ne bat PAS une autre règle de groupe bornée — voir
     * {@see self::known_gap_2026_08_15_an_unlimited_group_rule_loses_to_a_bounded_one()}.
     */
    #[Test]
    public function an_unlimited_group_rule_stays_priority_over_the_instance_default(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);
        $this->rule(QuotaRule::TYPE_GROUP, 'illimite', 0, 0);

        $result = $this->makeService()->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME, ['illimite']);

        $this->assertSame('group', $result['source']);
        $this->assertSame('illimite', $result['source_name']);
        $this->assertTrue($result['is_unlimited']);
    }

    /**
     * ⚠️ **ÉCART CONNU ET DATÉ — 2026-08-15, story 63.4.**
     *
     * ---------------------------------------------------------------------------
     * **CE QUE CE TEST ÉPINGLE EST UN DÉFAUT, pas une propriété.** Le second étage
     * trie les règles de groupe par plafond dur DÉCROISSANT, où `0` — qui signifie
     * « illimité », donc le plus large de tous — arrive DERNIER. Un compte membre
     * d'un groupe illimité et d'un groupe borné reçoit donc le plafond BORNÉ : la
     * règle la plus large perd.
     *
     * **Le comportement est ANTÉRIEUR à cette story** (vérifié contre `HEAD`), et il
     * n'est pas corrigé ici : le corriger ÉLARGIRAIT des plafonds en vigueur, et un
     * élargissement décidé par un correctif, à l'insu de l'exploitant, n'est pas plus
     * défendable qu'un rétrécissement. Il se corrige avec un geste d'exploitation,
     * pas dans un `orderBy`.
     *
     * ⚠️ **Mais cette story rend le cas BEAUCOUP PLUS PROBABLE**, et c'est pour cela
     * que l'écart est daté ici : elle supprime les plafonds par profil et recommande
     * de poser les budgets particuliers en règle de groupe — or le budget le plus
     * large qu'un exploitant posera est « illimité ». Le jour où quelqu'un le signale
     * comme un bug, ce test doit être le premier endroit trouvé.
     * ---------------------------------------------------------------------------
     */
    #[Test]
    public function known_gap_2026_08_15_an_unlimited_group_rule_loses_to_a_bounded_one(): void
    {
        $this->rule(QuotaRule::TYPE_GROUP, 'illimite', 0, 0);
        $this->rule(QuotaRule::TYPE_GROUP, 'borne', 5000, 6000);

        $result = $this->makeService()->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME, ['illimite', 'borne']);

        $this->assertSame('borne', $result['source_name']);
        $this->assertFalse($result['is_unlimited']);
    }

    #[Test]
    public function without_any_rule_the_answer_is_unlimited(): void
    {
        $result = $this->makeService()->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME, ['3A']);

        $this->assertSame('none', $result['source']);
        $this->assertNull($result['source_name']);
        $this->assertTrue($result['is_unlimited']);
    }

    /** Le défaut est posé PAR PARTITION : celui de l'une ne couvre pas l'autre. */
    #[Test]
    public function the_instance_default_is_per_partition(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360, QuotaRule::PARTITION_HOME);

        $service = $this->makeService();

        $this->assertSame(300, $service->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME)['quota_soft_mb']);
        $this->assertSame('none', $service->getEffectiveQuota('alice', QuotaRule::PARTITION_SAMBAEDU)['source']);
    }

    // =========================================================================
    // Ce que la story RETIRE — et qui doit rester retiré
    // =========================================================================

    /**
     * **UN COMPTE EXTERNE TOMBE SUR LE DÉFAUT D'INSTANCE COMME TOUT LE MONDE.**
     * L'étage qui le traitait à part — déclenché par le rattachement à un autre
     * établissement — est mort avec les quatre défauts par profil.
     */
    #[Test]
    public function an_account_attached_elsewhere_gets_the_instance_default(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $this->createUser('alice', '0770001a');
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);

        $result = $this->makeService()->getEffectiveQuota('alice', QuotaRule::PARTITION_HOME);

        $this->assertSame('default', $result['source']);
        $this->assertSame(300, $result['quota_soft_mb']);
    }

    /**
     * **LA PROPRIÉTÉ QUE LA STORY LIVRE**, épinglée positivement : deux comptes
     * qu'aucune règle nominative ni règle de groupe ne couvre reçoivent le MÊME
     * plafond — même si leurs groupes ressemblent à des publics d'autrefois.
     */
    #[Test]
    public function two_uncovered_accounts_receive_the_very_same_ceiling(): void
    {
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);

        $service = $this->makeService();

        $enseignant = $service->getEffectiveQuota('m.dupont', QuotaRule::PARTITION_HOME, ['profs', 'professeurs']);
        $eleve = $service->getEffectiveQuota('j.martin', QuotaRule::PARTITION_HOME, ['3A']);

        $this->assertSame($enseignant['quota_soft_mb'], $eleve['quota_soft_mb']);
        $this->assertSame($enseignant['quota_hard_mb'], $eleve['quota_hard_mb']);
        $this->assertSame('Défaut', $enseignant['source_name']);
        $this->assertSame('Défaut', $eleve['source_name']);
    }

    /**
     * **L'ASSERTION QUI PROUVE QUE LA DEVINETTE N'A PAS DE PORTE DÉROBÉE.** Elle
     * exigeait EXACTEMENT quatre paramètres, dont un profil ; elle en exige
     * maintenant trois, et aucun ne nomme un public.
     */
    #[Test]
    public function the_public_signature_carries_exactly_three_parameters(): void
    {
        $reflection = new \ReflectionMethod(XfsQuotaService::class, 'getEffectiveQuota');
        $params = $reflection->getParameters();

        $this->assertCount(3, $params, 'getEffectiveQuota ne doit plus porter de profil.');
        $this->assertSame('username', $params[0]->getName());
        $this->assertSame('partition', $params[1]->getName());
        $this->assertSame('userGroups', $params[2]->getName());
        $this->assertTrue($params[2]->isOptional());
    }

    /** La méthode qui traduisait un profil en type de règle n'existe plus. */
    #[Test]
    public function the_profile_to_policy_translation_is_gone(): void
    {
        $this->assertFalse(
            method_exists(XfsQuotaService::class, 'setDefaultPolicy'),
            'setDefaultPolicy portait le `match` de profil : sa disparition EST le retrait de la classification.',
        );
        $this->assertFalse(method_exists(XfsQuotaService::class, 'getUserProfile'));
    }

    // =========================================================================
    // Story 63.4, correction de revue — **LE PLAFOND ATTEINT LE DISQUE**
    // =========================================================================

    /**
     * ⚠️ **LE DÉFAUT D'INSTANCE TRAVERSAIT L'APPLICATION SANS RIEN FAIRE.** La
     * méthode de mise en file ne connaissait que les règles nominatives et de groupe :
     * un défaut d'instance passait par ses deux branches sans en emprunter aucune, et
     * le plafond saisi à l'écran n'atteignait JAMAIS le système de fichiers. La story
     * aurait remplacé « un formulaire qui n'applique rien » par « une ligne en base
     * qui n'atteint personne ».
     */
    #[Test]
    public function applying_the_instance_default_queues_one_job_per_covered_account(): void
    {
        Bus::fake();

        $this->createUser('alice', null);
        $this->createUser('bob', null);
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);

        $dispatched = $this->serviceWithSeams()->applyInstanceDefault(QuotaRule::PARTITION_HOME, 'admin');

        $this->assertSame(2, $dispatched);
        Bus::assertDispatchedTimes(ApplyQuotaJob::class, 2);
    }

    /** Un compte que sa PROPRE règle couvre n'est pas concerné par le défaut. */
    #[Test]
    public function an_account_with_its_own_rule_is_left_alone(): void
    {
        Bus::fake();

        $this->createUser('alice', null);
        $this->createUser('bob', null);
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);
        $this->rule(QuotaRule::TYPE_USER, 'alice', 9000, 10000);

        $this->assertSame(
            1,
            $this->serviceWithSeams()->applyInstanceDefault(QuotaRule::PARTITION_HOME, 'admin'),
        );
        Bus::assertDispatchedTimes(ApplyQuotaJob::class, 1);
    }

    /**
     * **ET LA BRANCHE EST BIEN CÂBLÉE** : demander l'application immédiate d'un défaut
     * d'instance met effectivement des travaux en file. C'est l'assertion qui
     * manquait — l'ancienne suite posait un faux de bus sans jamais l'interroger.
     */
    #[Test]
    public function writing_the_default_with_immediate_application_dispatches(): void
    {
        Bus::fake();

        $this->createUser('alice', null);

        $this->serviceWithSeams()->setQuotaRule(
            QuotaRule::TYPE_DEFAULT,
            null,
            QuotaRule::PARTITION_HOME,
            300,
            360,
            'admin',
            applyImmediately: true,
        );

        Bus::assertDispatchedTimes(ApplyQuotaJob::class, 1);
    }

    /** …et l'écran, lui, n'applique rien : il enregistre. */
    #[Test]
    public function writing_the_default_without_immediate_application_dispatches_nothing(): void
    {
        Bus::fake();

        $this->createUser('alice', null);

        $this->serviceWithSeams()->setQuotaRule(
            QuotaRule::TYPE_DEFAULT,
            null,
            QuotaRule::PARTITION_HOME,
            300,
            360,
            'admin',
            applyImmediately: false,
        );

        Bus::assertNothingDispatched();
    }

    /**
     * **CE QUE LE GESTE COÛTERAIT, ANNONCÉ AVANT LE CLIC** : combien de comptes, et
     * combien basculeraient en dépassement immédiat.
     */
    #[Test]
    public function the_coverage_says_how_many_accounts_and_how_many_would_go_over(): void
    {
        $this->createUser('alice', null);
        $this->createUser('bob', null);
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);

        // `alice` occupe 500 Mo (512000 Ko) — au-dessus du plafond dur ; `bob` 10 Mo.
        $coverage = $this->serviceWithSeams([
            'alice 512000 0 0 00 [--------]',
            'bob 10240 0 0 00 [--------]',
        ])->instanceDefaultCoverage(QuotaRule::PARTITION_HOME);

        $this->assertSame(2, $coverage['couverts']);
        $this->assertSame(1, $coverage['depassements']);
        $this->assertTrue($coverage['mesure']);
    }

    /**
     * **ZÉRO CONSTATÉ N'EST PAS ZÉRO MESURÉ.** Quand le relevé ne répond pas, l'écran
     * doit dire qu'il ne sait pas — jamais annoncer « personne ne bascule ».
     */
    #[Test]
    public function an_unreadable_usage_report_says_it_is_not_measured(): void
    {
        $this->createUser('alice', null);
        $this->rule(QuotaRule::TYPE_DEFAULT, null, 300, 360);

        $coverage = $this->serviceWithSeams([], 1)->instanceDefaultCoverage(QuotaRule::PARTITION_HOME);

        $this->assertSame(1, $coverage['couverts']);
        $this->assertSame(0, $coverage['depassements']);
        $this->assertFalse($coverage['mesure'], 'zéro constaté n\'est pas zéro mesuré');
    }

    /**
     * `null` N'EST PAS UNE LISTE VIDE — la doctrine survit, transposée aux groupes.
     * Un annuaire muet rend `null`, jamais `['groups' => []]`.
     */
    #[Test]
    public function a_silent_directory_resolves_to_null_never_to_an_empty_group_list(): void
    {
        $service = new class extends XfsQuotaService
        {
            protected function readDirectoryIdentity(string $username): ?array
            {
                return $username === 'connu' ? ['groups' => ['3A']] : null;
            }
        };

        $this->assertNull($service->resolveDirectoryIdentity('inconnu'));
        $this->assertSame(['groups' => ['3A']], $service->resolveDirectoryIdentity('connu'));
    }
}
