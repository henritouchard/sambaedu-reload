<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Exceptions\Filesystem\QuotaPartitionUnavailableException;
use App\Models\QuotaRule;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.4 — **TROIS ÉTATS, TROIS ISSUES. Plus jamais un booléen pour trois
 * situations qui ne demandent pas le même geste.**
 *
 * ---------------------------------------------------------------------------
 * La lecture d'état existante avalait son propre code de retour : une partition qui
 * ne porte pas de quota, une partition saine dont l'application est éteinte, et une
 * élévation de privilège cassée rendaient TOUTES la même valeur. L'exploitant ne
 * pouvait donc pas savoir si le geste à faire était sur le serveur, ou nulle part.
 *
 * Ces tests épinglent les trois issues, LEURS MOTIFS LITTÉRAUX, et le fait que la
 * sortie système est NEUTRALISÉE avant de remonter — un motif d'erreur brut porte
 * un chemin absolu, un nom de commande et parfois un nom de groupe d'annuaire.
 *
 * Ils passent par la couture `protected` : sans elle, aucune assertion ne serait
 * possible sur l'hôte, où l'outil n'existe pas.
 * ---------------------------------------------------------------------------
 */
class XfsQuotaPartitionAvailabilityTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

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

        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('quota_audit_logs');
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('system_settings');
        }
        parent::tearDown();
    }

    /**
     * Un service dont la SEULE lecture système est substituée — la couture, et rien
     * d'autre.
     *
     * @param  list<string>  $output
     */
    private function service(array $output, int $exitCode): XfsQuotaService
    {
        return new class($output, $exitCode) extends XfsQuotaService
        {
            /** @param list<string> $probeOutput */
            public function __construct(private array $probeOutput, private int $probeExit)
            {
                parent::__construct();
            }

            protected function probePartitionQuotaState(string $partition): array
            {
                return ['output' => $this->probeOutput, 'exit_code' => $this->probeExit];
            }
        };
    }

    // =========================================================================
    // Les trois issues
    // =========================================================================

    #[Test]
    public function an_enforced_partition_is_available_without_any_reason(): void
    {
        $service = $this->service([
            'User quota state on /mnt/donnees (/dev/sdb1)',
            '  Accounting: ON',
            '  Enforcement: ON',
            'Blocks grace time: [7 days]',
        ], 0);

        $this->assertSame(
            ['available' => true, 'reason' => null],
            $service->partitionQuotaAvailability(QuotaRule::PARTITION_HOME),
        );
    }

    #[Test]
    public function an_answered_but_disabled_enforcement_names_the_gesture_to_do_on_the_server(): void
    {
        $service = $this->service([
            '  Accounting: ON',
            '  Enforcement: OFF',
        ], 0);

        $availability = $service->partitionQuotaAvailability(QuotaRule::PARTITION_HOME);

        $this->assertFalse($availability['available']);
        $this->assertSame(XfsQuotaService::REASON_ENFORCEMENT_OFF, $availability['reason']);
        $this->assertSame(
            'Les quotas ne sont pas appliqués sur cette partition. Activez-les sur le serveur avant '
            .'d\'y poser un plafond.',
            $availability['reason'],
        );
    }

    /**
     * **LA TROISIÈME ISSUE : « JE NE SAIS PAS », ET SURTOUT PAS UN CONSTAT.**
     *
     * ---------------------------------------------------------------------------
     * Un code de retour non nul recouvre DEUX réalités disjointes : la partition ne
     * porte réellement pas de quota, ou bien on n'a pas pu le mesurer (élévation
     * refusée, outil absent, chemin d'exécution du serveur d'application). Trancher
     * la première serait AFFIRMER un fait non observé — et, comme cette issue ferme
     * l'écriture du plafond, une élévation cassée VERROUILLERAIT le réglage en
     * annonçant une contre-vérité.
     *
     * C'est la distinction que le contrat de backend a apprise : « conforme » et
     * « non mesurable » ne se confondent jamais.
     * ---------------------------------------------------------------------------
     */
    #[Test]
    public function a_failing_command_says_it_could_not_measure_never_that_there_is_no_quota(): void
    {
        $service = $this->service(['XFS_GETQUOTA: Invalid argument'], 1);

        $availability = $service->partitionQuotaAvailability(QuotaRule::PARTITION_HOME);

        $this->assertFalse($availability['available']);
        $this->assertStringStartsWith(
            'Impossible de déterminer si cette partition porte un quota : ',
            (string) $availability['reason'],
        );
        $this->assertStringContainsString('Invalid argument', (string) $availability['reason']);
        // Le motif dit le geste à faire, et il ne le situe PAS sur la partition.
        $this->assertStringContainsString(
            'Vérifiez l\'outil et l\'élévation sur le serveur.',
            (string) $availability['reason'],
        );
        // …et surtout, il n'AFFIRME rien de la partition.
        $this->assertStringNotContainsString('ne porte pas de quota :', (string) $availability['reason']);
    }

    /** Une sortie VIDE n'est pas un silence à masquer : elle se dit. */
    #[Test]
    public function a_failing_command_without_output_still_produces_a_readable_reason(): void
    {
        $availability = $this->service([], 127)->partitionQuotaAvailability(QuotaRule::PARTITION_HOME);

        $this->assertFalse($availability['available']);
        $this->assertSame(
            'Impossible de déterminer si cette partition porte un quota : le système n\'a produit '
            .'aucune sortie d\'erreur. Vérifiez l\'outil et l\'élévation sur le serveur.',
            $availability['reason'],
        );
    }

    /**
     * **LA SORTIE SYSTÈME EST NEUTRALISÉE.** Un motif brut porte un chemin absolu et
     * un nom de commande ; le motif affiché garde la CAUSE et jette le vocabulaire.
     */
    #[Test]
    public function the_system_output_is_neutralised_before_it_reaches_a_screen(): void
    {
        $service = $this->service([
            'sudo: xfs_quota: command not found on /var/sambaedu/Partages',
        ], 127);

        $reason = (string) $service->partitionQuotaAvailability(QuotaRule::PARTITION_HOME)['reason'];

        $this->assertStringNotContainsString('sudo', $reason);
        $this->assertStringNotContainsString('/var/sambaedu', $reason);
        $this->assertStringContainsString('not found', $reason);
    }

    // =========================================================================
    // La garde REJOUÉE côté service — la soumission forgée
    // =========================================================================

    /**
     * *Une garde qui ne vit que dans l'écran protège l'étourderie, pas la requête
     * forgée.* Refusée, l'écriture ne laisse **RIEN** : ni règle, ni audit.
     */
    #[Test]
    public function writing_the_instance_default_on_an_unavailable_partition_writes_nothing_at_all(): void
    {
        $service = $this->service(['XFS_GETQUOTA: Invalid argument'], 1);

        try {
            $service->setQuotaRule(
                QuotaRule::TYPE_DEFAULT,
                null,
                QuotaRule::PARTITION_HOME,
                300,
                360,
                'admin',
                applyImmediately: false,
            );
            $this->fail('le service devait refuser en nommant');
        } catch (QuotaPartitionUnavailableException $e) {
            $this->assertStringContainsString(
                'Impossible de déterminer si cette partition porte un quota',
                $e->getMessage(),
            );
            $this->assertSame(QuotaRule::PARTITION_HOME, $e->partition);
        }

        $this->assertSame(0, DB::table('quota_rules')->count());
        $this->assertSame(0, DB::table('quota_audit_logs')->count());
    }

    #[Test]
    public function writing_the_instance_default_is_accepted_when_the_partition_carries_quota(): void
    {
        $service = $this->service(['  Enforcement: ON'], 0);

        $rule = $service->setQuotaRule(
            QuotaRule::TYPE_DEFAULT,
            null,
            QuotaRule::PARTITION_HOME,
            300,
            360,
            'admin',
            applyImmediately: false,
        );

        $this->assertSame(QuotaRule::TYPE_DEFAULT, $rule->type);
        $this->assertSame(300, $rule->quota_soft_mb);
        $this->assertSame(1, DB::table('quota_audit_logs')->count());
    }

    /**
     * ⚠️ **La garde ne porte PAS sur les règles nominatives et de groupe.** Elles
     * s'écrivent depuis trois autres endroits du produit, et les refuser ici serait
     * une régression hors périmètre.
     */
    #[Test]
    public function the_guard_never_blocks_a_nominative_or_group_rule(): void
    {
        $service = $this->service(['XFS_GETQUOTA: Invalid argument'], 1);

        $service->setQuotaRule(QuotaRule::TYPE_USER, 'alice', QuotaRule::PARTITION_HOME, 300, 360, 'admin', false);
        $service->setQuotaRule(QuotaRule::TYPE_GROUP, 'profs', QuotaRule::PARTITION_HOME, 500, 600, 'admin', false);

        $this->assertSame(2, DB::table('quota_rules')->count());
    }

    /**
     * ⚠️ **LA GARDE NE VAUT PAS SUR UN ESPACE QUI N'EST PLUS SERVI PAR LE SERVEUR DE
     * FICHIERS** (correction de revue).
     *
     * ---------------------------------------------------------------------------
     * Quand l'espace personnel vit au cloud, cette même règle est ce que le
     * provisionnement lit pour poser le plafond du compte sur l'instance. Laisser un
     * système de fichiers local hors sujet fermer cette écriture fermerait le SEUL
     * écran où se règle le plafond du cloud — un refus exact, appliqué à la mauvaise
     * question.
     * ---------------------------------------------------------------------------
     */
    #[Test]
    public function the_guard_steps_aside_when_the_space_no_longer_lives_on_the_file_server(): void
    {
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));

        // La sonde échoue : sur le serveur de fichiers, ce plafond serait refusé.
        $service = $this->service(['XFS_GETQUOTA: Invalid argument'], 1);

        $rule = $service->setQuotaRule(
            QuotaRule::TYPE_DEFAULT,
            null,
            QuotaRule::PARTITION_HOME,
            300,
            360,
            'admin',
            applyImmediately: false,
        );

        $this->assertSame(300, $rule->quota_soft_mb);
        $this->assertSame(1, DB::table('quota_rules')->count());
    }

    /** …et elle reprend dès que l'espace redescend sur le serveur de fichiers. */
    #[Test]
    public function the_guard_applies_again_when_the_space_lives_on_the_file_server(): void
    {
        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));

        $service = $this->service(['XFS_GETQUOTA: Invalid argument'], 1);

        $this->expectException(QuotaPartitionUnavailableException::class);

        $service->setQuotaRule(
            QuotaRule::TYPE_DEFAULT,
            null,
            QuotaRule::PARTITION_HOME,
            300,
            360,
            'admin',
            applyImmediately: false,
        );
    }
}
