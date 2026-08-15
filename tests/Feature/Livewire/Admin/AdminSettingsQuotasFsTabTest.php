<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Models\QuotaRule;
use App\Models\QuotaSetting;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 63.4 — **LES DEUX CARTES DANS LEUR NOUVEL HÔTE.**
 *
 * ---------------------------------------------------------------------------
 * Ce fichier était la suite de l'onglet « Quotas & FS », retiré le 2026-08-05. Il
 * n'a PAS été supprimé avec lui : ses assertions — plancher de l'espace personnel,
 * double garde, soumission forgée, purge de corbeille — sont la seule couverture qui
 * ait jamais existé sur ces réglages. Elles sont ici REPORTÉES sur les deux cartes du
 * bloc « Réglages » de l'onglet des emplacements.
 *
 * **ET IL PORTE MAINTENANT LE TEST QUI DÉFINIT LA STORY** : après enregistrement
 * depuis l'écran, la résolution rend la valeur saisie. Il échouait avant, parce que
 * l'écran écrivait dans un magasin que la résolution ne lisait pas.
 *
 * **Aucun appel système n'est joué** : le service est substitué par une sous-classe
 * qui porte la couture d'état de partition et neutralise la pose de la période de
 * grâce. C'est ce qui permet d'exercer les trois issues de disponibilité sur un hôte
 * où l'outil n'existe pas.
 * ---------------------------------------------------------------------------
 */
class AdminSettingsQuotasFsTabTest extends TestCase
{
    use DatabaseTransactions;

    private const QUOTAS_CARD = 'pages::admin.settings.files._partials.quotas-card';

    private const CORBEILLE_CARD = 'pages::admin.settings.files._partials.corbeille-card';

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }

        $this->createTablesIfNeeded();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('system_settings');
            Schema::dropIfExists('quota_settings');
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('quota_audit_logs');
            Schema::dropIfExists('model_has_permissions');
            Schema::dropIfExists('model_has_roles');
            Schema::dropIfExists('role_has_permissions');
            Schema::dropIfExists('permissions');
            Schema::dropIfExists('roles');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function createTablesIfNeeded(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('quota_settings')) {
            Schema::create('quota_settings', function (Blueprint $table) {
                $table->id();
                $table->string('partition')->unique();
                $table->integer('grace_period_days')->default(7);
                $table->integer('default_overage_percent')->default(20);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('quota_rules')) {
            Schema::create('quota_rules', function (Blueprint $table) {
                $table->id();
                $table->string('type');
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

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'asq_mhp');
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'asq_mhr');
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->createdTables = true;
        }

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    /**
     * Le service substitué : la couture d'état de partition, et la pose de grâce
     * neutralisée. **Aucun appel système n'est joué par cette suite.**
     *
     * @param  array<string, array{output: list<string>, exit_code: int}>  $states  partition => sonde
     */
    private function fakeQuotaService(
        array $states = [],
        bool $graceApplies = true,
        array $usage = [],
        int $usageExit = 0,
    ): XfsQuotaService {
        $service = new class($states, $graceApplies, $usage, $usageExit) extends XfsQuotaService
        {
            /** @var array<string, array{output: list<string>, exit_code: int}> */
            public array $states;

            public bool $graceApplies;

            /** @var list<string> */
            public array $usage;

            public int $usageExit;

            public int $graceCalls = 0;

            public function __construct(array $states, bool $graceApplies, array $usage, int $usageExit)
            {
                parent::__construct();

                $this->states = $states;
                $this->graceApplies = $graceApplies;
                $this->usage = $usage;
                $this->usageExit = $usageExit;
            }

            protected function probePartitionQuotaState(string $partition): array
            {
                // Défaut : la partition porte un quota appliqué.
                return $this->states[$partition] ?? ['output' => ['  Enforcement: ON'], 'exit_code' => 0];
            }

            protected function probePartitionUsageReport(string $partition): array
            {
                return ['output' => $this->usage, 'exit_code' => $this->usageExit];
            }

            public function setGracePeriod(string $partition, int $days, string $performedBy): array
            {
                $this->graceCalls++;

                return $this->graceApplies
                    ? ['success' => true, 'error' => null]
                    : ['success' => false, 'error' => 'indisponible'];
            }
        };

        $this->app->instance(XfsQuotaService::class, $service);

        return $service;
    }

    // =========================================================================
    // LE TEST QUI DÉFINIT LA STORY
    // =========================================================================

    /**
     * **Après enregistrement depuis l'écran, la résolution rend la valeur saisie.**
     * Ce test échouait avant la story : l'écran écrivait une clé de réglage que la
     * résolution ne lisait pas, et répondait pourtant « Réglages enregistrés ».
     */
    public function test_the_saved_ceiling_is_the_one_the_resolution_returns(): void
    {
        Bus::fake();
        $service = $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-defaut'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->set('overage.home', 20)
            ->call('save', 'home')
            ->assertDispatched('toastMagic');

        $effective = $service->getEffectiveQuota('un-compte-sans-regle', QuotaRule::PARTITION_HOME);

        $this->assertSame('default', $effective['source']);
        $this->assertSame('Défaut', $effective['source_name']);
        $this->assertSame(300, $effective['quota_soft_mb']);
        $this->assertSame(360, $effective['quota_hard_mb']);

        // Le même chemin d'audit que les autres règles.
        $this->assertDatabaseHas('quota_audit_logs', [
            'target_type' => QuotaRule::TYPE_DEFAULT,
            'partition' => QuotaRule::PARTITION_HOME,
        ]);
    }

    /** Une seule ligne par partition, sans cible : le défaut ne vise personne. */
    public function test_it_writes_exactly_one_default_rule_per_partition(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-une-ligne'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->set('overage.home', 20)
            ->call('save', 'home')
            ->set('soft.home', 500)
            ->call('save', 'home');

        $rules = QuotaRule::query()->where('type', QuotaRule::TYPE_DEFAULT)->get();

        $this->assertCount(1, $rules);
        $this->assertNull($rules->first()->target);
        $this->assertSame(500, $rules->first()->quota_soft_mb);
    }

    // =========================================================================
    // ENREGISTRER N'EST PAS APPLIQUER — et le second geste est EXPLICITE
    // =========================================================================

    /**
     * ⚠️ **ENREGISTRER NE MET RIEN EN FILE.** Ce n'est pas un oubli : appliquer un
     * plafond à toute une population au moment où on le saisit mettrait d'un coup des
     * comptes en dépassement sans que personne ne l'ait demandé.
     */
    public function test_saving_the_ceiling_applies_nothing_by_itself(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-sans-application'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->call('save', 'home');

        Bus::assertNothingDispatched();
    }

    /**
     * **LA CARTE ANNONCE CE QUE LE GESTE COÛTERAIT, AVANT LE CLIC** : combien de
     * comptes sont couverts, et combien basculeraient en dépassement immédiat.
     */
    public function test_the_card_announces_the_cost_of_applying_before_the_click(): void
    {
        Bus::fake();
        // `sature` occupe 500 Mo, au-dessus du plafond dur de 360.
        $this->fakeQuotaService(usage: ['sature 512000 0 0 00 [------]', 'sobre 1024 0 0 00 [------]']);
        $this->actingAs($this->makeAdmin('admin-annonce'));

        User::query()->create(['login' => 'sature', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'sobre', 'role' => 'eleve', 'is_active' => true]);

        $component = Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->call('save', 'home');

        $component->assertSet('coverage.home.couverts', 3)   // les deux comptes + l'administrateur
            ->assertSet('coverage.home.depassements', 1)
            ->assertSet('coverage.home.mesure', true);

        $html = $component->html();

        $this->assertStringContainsString('quota-coverage-home', $html);
        $this->assertStringContainsString('quota-apply-home', $html);
        $this->assertStringContainsString('wire:confirm', $html);
    }

    /**
     * ⚠️ **ZÉRO CONSTATÉ N'EST PAS ZÉRO MESURÉ.** Quand le relevé d'occupation ne
     * répond pas, l'écran doit le DIRE — jamais annoncer « personne ne bascule ».
     */
    public function test_the_card_says_when_the_overage_count_could_not_be_measured(): void
    {
        Bus::fake();
        $this->fakeQuotaService(usage: [], usageExit: 1);
        $this->actingAs($this->makeAdmin('admin-non-mesure'));

        $component = Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->call('save', 'home');

        $component->assertSet('coverage.home.mesure', false);

        $this->assertStringContainsString(
            'n\'a pas pu être mesuré',
            (string) preg_replace('/\s+/u', ' ', strip_tags($component->html())),
        );
    }

    /**
     * **LE SECOND GESTE PORTE LE PLAFOND SUR LE DISQUE.** C'est la seule chose qui
     * transforme la ligne en base en plafond réel — et c'est aussi ce qui manquait
     * complètement : le défaut d'instance traversait la mise en file sans emprunter
     * aucune branche.
     */
    public function test_applying_to_covered_accounts_queues_one_job_per_account(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-applique'));

        User::query()->create(['login' => 'eleve-un', 'role' => 'eleve', 'is_active' => true]);

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->call('save', 'home')
            ->call('applyToCovered', 'home')
            ->assertDispatched('toastMagic');

        Bus::assertDispatchedTimes(\App\Jobs\ApplyQuotaJob::class, 2);
    }

    /** Sans plafond enregistré, il n'y a rien à porter : le geste refuse en nommant. */
    public function test_it_refuses_to_apply_without_a_saved_ceiling(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-applique-sans-regle'));

        Livewire::test(self::QUOTAS_CARD)
            ->call('applyToCovered', 'home')
            ->assertDispatched('toastMagic');

        Bus::assertNothingDispatched();
    }

    /** Le bouton d'application est ABSENT tant qu'aucun plafond n'est posé. */
    public function test_the_apply_button_is_absent_without_a_saved_ceiling(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-bouton-absent'));

        $this->assertStringNotContainsString(
            'quota-apply-home',
            Livewire::test(self::QUOTAS_CARD)->html(),
        );
    }

    /**
     * ⚠️ **LES DEUX CARTES NE DÉPENDENT D'AUCUNE DÉCISION D'EMPLACEMENT**, et elles
     * doivent MONTER même quand cette décision est illisible : c'est le sujet d'un
     * autre bloc de l'écran, qui le dit déjà.
     */
    public function test_both_cards_mount_even_when_the_locations_row_is_unreadable(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-emplacements-illisibles'));

        SystemSetting::set(FileLocationService::SETTING_KEY, ['espace_perso.autorite' => 'posix']);

        Livewire::test(self::QUOTAS_CARD)->assertStatus(200);
        Livewire::test(self::CORBEILLE_CARD)->assertStatus(200);
    }

    /**
     * ⚠️ **LA GARDE S'EFFACE QUAND L'ESPACE N'EST PLUS SERVI PAR LE SERVEUR DE
     * FICHIERS.** Sinon un système de fichiers local hors sujet fermerait le seul
     * écran où se règle le plafond du cloud — c'est la même règle que lit le
     * provisionnement.
     */
    public function test_a_space_that_lives_on_the_cloud_keeps_its_fields_open(): void
    {
        Bus::fake();
        $this->fakeQuotaService([
            QuotaRule::PARTITION_HOME => ['output' => ['XFS_GETQUOTA: Invalid argument'], 'exit_code' => 1],
        ]);
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));
        $this->actingAs($this->makeAdmin('admin-espace-cloud'));

        $component = Livewire::test(self::QUOTAS_CARD);
        $html = $component->html();

        // Le champ est OUVERT, le motif de la sonde n'est pas affiché, et la carte dit
        // ce que ce plafond gouverne désormais.
        $this->assertStringNotContainsString('Impossible de déterminer', $html);
        $this->assertStringContainsString('quota-off-smb-home', $html);
        $this->assertStringContainsString('quota-save-home', $html);
        // …et il n'y a rien à porter sur un disque : pas de bouton d'application.
        $this->assertStringNotContainsString('quota-apply-home', $html);

        $component->set('soft.home', 300)->call('save', 'home');

        $this->assertDatabaseHas('quota_rules', [
            'type' => QuotaRule::TYPE_DEFAULT,
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 300,
        ]);
    }

    // =========================================================================
    // Le REGROUPEMENT des anciens plafonds — annoncé jusqu'au premier geste
    // =========================================================================

    /**
     * ⚠️ **ÉLARGIR N'EST PAS ANODIN, ET ÇA SE DIT FORT.** La migration de bascule ne
     * rétrécit jamais un plafond : elle retient la valeur la plus large. Personne ne
     * perd de place — mais personne n'a demandé ce plafond-là non plus. Tant que
     * l'administrateur n'a pas enregistré une valeur lui-même, la carte nomme le
     * regroupement et affiche les valeurs LITTÉRALES qui ont été fondues.
     */
    public function test_it_warns_about_the_collapse_until_a_value_is_saved_by_hand(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-regroupement'));

        SystemSetting::set('quota.profils_regroupes', [
            'fondus' => ['500/600 Mo', '1000/1200 Mo', '2000/2400 Mo', '200/240 Mo'],
        ]);

        $component = Livewire::test(self::QUOTAS_CARD);
        $texte = (string) preg_replace('/\s+/u', ' ', strip_tags($component->html()));

        $this->assertStringContainsString('quota-collapse-notice', $component->html());
        $this->assertStringContainsString('la valeur la plus large', $texte);
        $this->assertStringContainsString('500/600 Mo', $texte);
        $this->assertStringContainsString('2000/2400 Mo', $texte);

        // …et il disparaît au PREMIER enregistrement manuel.
        $component->set('soft.home', 300)->call('save', 'home');

        $this->assertNull(SystemSetting::get('quota.profils_regroupes'));
        $this->assertStringNotContainsString('quota-collapse-notice', $component->html());
    }

    // =========================================================================
    // Les validations, conservées à l'identique
    // =========================================================================

    public function test_it_rejects_a_ceiling_below_the_floor_on_the_personal_space(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-plancher'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 5)
            ->call('save', 'home')
            ->assertHasErrors('soft.home');

        $this->assertSame(0, QuotaRule::query()->count());
    }

    /** Le plancher ne vaut QUE pour l'espace personnel — l'autre partition l'ignore. */
    public function test_the_floor_does_not_apply_to_the_other_partition(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-plancher-autre'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.sambaedu', 5)
            ->call('save', 'sambaedu')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quota_rules', [
            'type' => QuotaRule::TYPE_DEFAULT,
            'partition' => QuotaRule::PARTITION_SAMBAEDU,
            'quota_soft_mb' => 5,
        ]);
    }

    /** `0` reste « illimité », et il est accepté sur les deux partitions. */
    public function test_zero_is_accepted_as_unlimited(): void
    {
        Bus::fake();
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-illimite'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 0)
            ->call('save', 'home')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quota_rules', [
            'type' => QuotaRule::TYPE_DEFAULT,
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_soft_mb' => 0,
            'quota_hard_mb' => 0,
        ]);
    }

    public function test_it_rejects_an_overage_above_one_hundred(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-borne'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->set('overage.home', 250)
            // Le composant borne AVANT de valider : la valeur retenue est 100, et
            // c'est ce qui doit se retrouver en base — jamais 250.
            ->call('save', 'home')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('quota_rules', [
            'type' => QuotaRule::TYPE_DEFAULT,
            'partition' => QuotaRule::PARTITION_HOME,
            'quota_hard_mb' => 600,
        ]);
    }

    public function test_it_rejects_a_grace_period_beyond_thirty_days(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-grace-borne'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->set('grace.home', 90)
            ->call('save', 'home')
            ->assertHasErrors('grace.home');

        $this->assertSame(0, QuotaRule::query()->count());
    }

    // =========================================================================
    // La période de grâce — elle échoue MOLLEMENT, et l'écran le dit
    // =========================================================================

    public function test_it_persists_the_grace_period_and_applies_it(): void
    {
        Bus::fake();
        $service = $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-grace'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->set('grace.home', 14)
            ->call('save', 'home')
            ->assertDispatched('toastMagic');

        $this->assertSame(14, (int) QuotaSetting::forPartition(QuotaRule::PARTITION_HOME)->grace_period_days);
        $this->assertSame(1, $service->graceCalls);
    }

    /**
     * **LE COMPORTEMENT EST CONSERVÉ EXACTEMENT** : la valeur est persistée en base
     * même quand le geste système échoue, et l'écran l'annonce. Le corriger est un
     * autre sujet ; le changer sans le dire serait une régression invisible.
     */
    public function test_the_grace_period_is_persisted_even_when_the_system_gesture_fails(): void
    {
        Bus::fake();
        $this->fakeQuotaService(graceApplies: false);
        $this->actingAs($this->makeAdmin('admin-grace-molle'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->set('grace.home', 21)
            ->call('save', 'home')
            ->assertDispatched('toastMagic');

        $this->assertSame(21, (int) QuotaSetting::forPartition(QuotaRule::PARTITION_HOME)->grace_period_days);
    }

    // =========================================================================
    // Un plafond non posable : CHAMP FERMÉ AVEC SON MOTIF
    // =========================================================================

    public function test_an_unavailable_partition_closes_its_fields_and_shows_the_reason(): void
    {
        $this->fakeQuotaService([
            QuotaRule::PARTITION_HOME => ['output' => ['XFS_GETQUOTA: Invalid argument'], 'exit_code' => 1],
        ]);
        $this->actingAs($this->makeAdmin('admin-ferme'));

        $html = Livewire::test(self::QUOTAS_CARD)->html();

        // Le motif est AFFICHÉ, et il dit « je ne sais pas », jamais « il n'y en a pas ».
        $this->assertStringContainsString('Impossible de déterminer si cette partition porte un quota', $html);
        // …les champs sont fermés…
        $this->assertStringContainsString('wire:model="soft.home" disabled', $html);
        $this->assertStringContainsString('wire:model="grace.home" disabled', $html);
        // …et le bouton d'enregistrement de CETTE partition est ABSENT.
        $this->assertStringNotContainsString('quota-save-home', $html);
        // L'autre partition, elle, reste utilisable.
        $this->assertStringContainsString('quota-save-sambaedu', $html);
    }

    public function test_an_enforcement_that_is_off_names_the_gesture_to_do_on_the_server(): void
    {
        $this->fakeQuotaService([
            QuotaRule::PARTITION_HOME => ['output' => ['  Enforcement: OFF'], 'exit_code' => 0],
        ]);
        $this->actingAs($this->makeAdmin('admin-off'));

        $this->assertStringContainsString(
            'Les quotas ne sont pas appliqués sur cette partition.',
            Livewire::test(self::QUOTAS_CARD)->html(),
        );
    }

    /**
     * **LA SOUMISSION FORGÉE.** Une garde qui ne vit que dans l'écran protège
     * l'étourderie, pas la requête forgée : le geste est refusé, et **rien** n'est
     * écrit — ni règle, ni ligne d'audit.
     */
    public function test_a_forged_save_on_an_unavailable_partition_writes_nothing(): void
    {
        $this->fakeQuotaService([
            QuotaRule::PARTITION_HOME => ['output' => ['XFS_GETQUOTA: Invalid argument'], 'exit_code' => 1],
        ]);
        $this->actingAs($this->makeAdmin('admin-forge'));

        Livewire::test(self::QUOTAS_CARD)
            ->set('soft.home', 300)
            ->call('save', 'home')
            ->assertDispatched('toastMagic');

        $this->assertSame(0, QuotaRule::query()->count());
        $this->assertSame(0, \App\Models\QuotaAuditLog::query()->count());
    }

    /** Une clé de partition inventée ne produit ni écriture ni erreur serveur. */
    public function test_a_forged_partition_key_is_refused(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-cle-forgee'));

        Livewire::test(self::QUOTAS_CARD)
            ->call('save', '../../etc')
            ->assertDispatched('toastMagic');

        $this->assertSame(0, QuotaRule::query()->count());
    }

    // =========================================================================
    // Les gardes
    // =========================================================================

    public function test_it_blocks_the_cards_without_server_admin(): void
    {
        $this->fakeQuotaService();

        $viewer = User::query()->create(['login' => 'viewer-cartes', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        Livewire::test(self::QUOTAS_CARD)->assertStatus(403);
        Livewire::test(self::CORBEILLE_CARD)->assertStatus(403);
    }

    /**
     * Défense en profondeur : un administrateur qui perd sa permission APRÈS le
     * montage doit être arrêté par CHAQUE méthode publique.
     */
    public function test_every_public_method_rechecks_the_permission(): void
    {
        $this->fakeQuotaService();

        foreach ([
            [self::QUOTAS_CARD, 'save', ['home']],
            [self::QUOTAS_CARD, 'applyToCovered', ['home']],
            [self::CORBEILLE_CARD, 'save', []],
            [self::CORBEILLE_CARD, 'purgeNow', []],
        ] as [$component, $method, $args]) {
            $admin = $this->makeAdmin('admin-revoke-'.$component.'-'.$method);
            $this->actingAs($admin);

            $mounted = Livewire::test($component);

            $admin->revokePermissionTo('server.admin');
            $admin->refresh();
            $this->actingAs($admin);

            $mounted->call($method, ...$args)->assertStatus(403);
        }
    }

    // =========================================================================
    // La corbeille — un REBRANCHEMENT, et un libellé qui ne ment pas
    // =========================================================================

    public function test_it_persists_the_trash_retention_and_the_automatic_purge(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-corbeille'));

        Livewire::test(self::CORBEILLE_CARD)
            ->set('ttlDays', 60)
            ->set('purgeAuto', true)
            ->call('save')
            ->assertDispatched('toastMagic');

        $stored = SystemSetting::get('quota.trash');

        $this->assertSame(60, $stored['ttl_days']);
        $this->assertTrue($stored['purge_auto']);
    }

    public function test_it_rejects_a_retention_outside_its_bounds(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-corbeille-borne'));

        Livewire::test(self::CORBEILLE_CARD)
            ->set('ttlDays', 400)
            ->call('save')
            ->assertHasErrors('ttlDays');

        $this->assertNull(SystemSetting::get('quota.trash'));
    }

    /**
     * **LE LIBELLÉ NE MENT PAS.** Ce n'est pas une corbeille d'utilisateur : ce que
     * la purge supprime, c'est le répertoire personnel d'un compte DÉSACTIVÉ.
     */
    public function test_the_card_says_exactly_what_it_purges(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-corbeille-libelle'));

        $texte = (string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags(Livewire::test(self::CORBEILLE_CARD)->html()), ENT_QUOTES),
        );

        $this->assertStringContainsString('Corbeille des répertoires personnels', $texte);
        $this->assertStringContainsString(
            'Les répertoires personnels archivés lors de la désactivation d\'un compte. Passé ce délai, '
            .'ils sont supprimés définitivement et la réactivation du compte ne les retrouve plus.',
            $texte,
        );
    }

    /** Sur le serveur de fichiers, la carte ne parle PAS du cloud. */
    public function test_no_cloud_sentence_when_the_personal_space_lives_on_the_file_server(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-corbeille-smb'));

        $this->assertStringNotContainsString(
            'corbeille-cloud-notice',
            Livewire::test(self::CORBEILLE_CARD)->html(),
        );
    }

    /**
     * ⚠️ **Elle ne prétend rien gouverner du cloud** : une phrase, et RIEN d'autre —
     * aucun réglage, aucun appel réseau.
     */
    public function test_it_says_the_cloud_trash_is_set_in_the_instance_itself(): void
    {
        $this->fakeQuotaService();
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));
        $this->actingAs($this->makeAdmin('admin-corbeille-cloud'));

        $html = Livewire::test(self::CORBEILLE_CARD)->html();

        $this->assertStringContainsString('corbeille-cloud-notice', $html);
        $this->assertStringContainsString(
            'La corbeille de l\'instance cloud est réglée dans l\'instance elle-même.',
            $html,
        );
    }

    // =========================================================================
    // « Purger maintenant » — à l'air libre, avec sa confirmation
    // =========================================================================

    public function test_it_purges_now_when_the_retention_is_configured(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-purge'));

        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('trash:purge', Mockery::any())
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->andReturn('Purgé : 5 dossier(s). Conservé : 12 dossier(s). Erreurs : 0.');

        Livewire::test(self::CORBEILLE_CARD)
            ->call('purgeNow')
            ->assertDispatched('toastMagic');
    }

    /**
     * Sans durée enregistrée, la commande rendrait « 0 supprimé » avec un code de
     * succès : l'écran afficherait un message VERT pour une purge qui n'a pas eu
     * lieu. Le pré-contrôle évite ce faux succès.
     */
    public function test_it_refuses_to_purge_when_no_retention_is_configured(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-purge-sans-ttl'));

        Artisan::shouldReceive('call')->never();

        Livewire::test(self::CORBEILLE_CARD)
            ->call('purgeNow')
            ->assertDispatched('toastMagic');
    }

    public function test_it_reports_a_failing_purge(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-purge-echec'));

        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('trash:purge', Mockery::any())
            ->andReturn(1);
        Artisan::shouldReceive('output')->andReturn('Purgé : 0 dossier(s). Erreurs : 3.');

        Livewire::test(self::CORBEILLE_CARD)
            ->call('purgeNow')
            ->assertDispatched('toastMagic');
    }

    /** La confirmation est portée par le bouton lui-même — jamais depuis une modale. */
    public function test_the_purge_button_carries_its_confirmation_in_the_open(): void
    {
        $this->fakeQuotaService();
        $this->actingAs($this->makeAdmin('admin-purge-confirm'));

        $html = Livewire::test(self::CORBEILLE_CARD)->html();

        $this->assertStringContainsString('wire:confirm', $html);
        $this->assertStringContainsString('corbeille-purge-now', $html);
    }
}
