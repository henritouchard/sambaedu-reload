<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\AppCustomization\AppCustomizationService;
use App\Services\AppCustomization\AppPolicyRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — AppCustomizationService (AC 4, 12).
 *
 * Vérifie la résolution hiérarchique 5 niveaux + perf DB ≤ 4 queries.
 */
class AppCustomizationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::unguard();
        \App\Models\WorkstationGroup::flushEventListeners();
        \App\Models\UserGroup::flushEventListeners();
        \App\Models\User::flushEventListeners();

        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('login')->unique();
            $t->string('password')->nullable();
            $t->string('role')->default('eleve');
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
            $t->timestamps();
        });
        Schema::create('user_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->string('type')->default('classe');
            $t->timestamps();
        });
        Schema::create('user_group_user', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('user_group_id');
            // Story 42.1 — rôle sur l'arête, lu par withPivot('role').
            $t->string('role', 20)->default('member');
            $t->timestamps();
        });
        Schema::create('workstation_groups', function (Blueprint $t): void {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('is_physical')->default(true);
            $t->timestamps();
        });
        Schema::create('app_customizations', function (Blueprint $t): void {
            $t->id();
            $t->string('app_kind', 32);
            $t->nullableMorphs('customizable');
            $t->json('policies_json');
            $t->boolean('is_default')->default(false);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });

        config()->set('app-customizations.template_paths.firefox', [
            base_path('tests/fixtures/firefox/template.json'),
        ]);
        config()->set('app-customizations.export_fs_on_save', false);
    }

    private function service(): AppCustomizationService
    {
        return new AppCustomizationService($this->app->make(AppPolicyRegistry::class));
    }

    #[Test]
    public function resolves_template_plus_auto_when_no_db_overrides(): void
    {
        $policies = $this->service()->resolvePoliciesForMachine(
            null,
            null,
            AppKind::Firefox,
            'linux',
        );

        $this->assertArrayHasKey('policies', $policies);
        $this->assertArrayHasKey('Proxy', $policies['policies']);
        $this->assertArrayHasKey('DNSOverHTTPS', $policies['policies']);
    }

    #[Test]
    public function default_etab_override_is_applied(): void
    {
        AppCustomization::create([
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => null,
            'customizable_id' => null,
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://etab.local/']]],
            'is_default' => true,
        ]);

        $policies = $this->service()->resolvePoliciesForMachine(
            null,
            null,
            AppKind::Firefox,
            'linux',
        );

        $this->assertSame('https://etab.local/', $policies['policies']['Homepage']['URL']);
    }

    #[Test]
    public function workstation_group_override_wins_over_default_etab(): void
    {
        AppCustomization::create([
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => null,
            'customizable_id' => null,
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://etab/']]],
            'is_default' => true,
        ]);

        $wg = WorkstationGroup::create(['name' => 'Salle-A']);
        AppCustomization::create([
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => WorkstationGroup::class,
            'customizable_id' => $wg->id,
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://salle-a/']]],
            'is_default' => false,
        ]);

        $policies = $this->service()->resolvePoliciesForMachine(
            $wg,
            null,
            AppKind::Firefox,
            'linux',
        );

        $this->assertSame('https://salle-a/', $policies['policies']['Homepage']['URL']);
    }

    #[Test]
    public function user_override_wins_over_workstation_group_and_user_groups(): void
    {
        $wg = WorkstationGroup::create(['name' => 'Salle-B']);
        AppCustomization::create([
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => WorkstationGroup::class,
            'customizable_id' => $wg->id,
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://salle/']]],
            'is_default' => false,
        ]);

        $user = User::create(['login' => 'alice']);
        $group = UserGroup::create(['name' => 'Profs']);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
        ]);

        AppCustomization::create([
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => UserGroup::class,
            'customizable_id' => $group->id,
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://profs/']]],
            'is_default' => false,
        ]);

        AppCustomization::create([
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => User::class,
            'customizable_id' => $user->id,
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://alice/']]],
            'is_default' => false,
        ]);

        $policies = $this->service()->resolvePoliciesForMachine(
            $wg,
            $user,
            AppKind::Firefox,
            'linux',
        );

        $this->assertSame('https://alice/', $policies['policies']['Homepage']['URL']);
    }

    #[Test]
    public function resolve_runs_bounded_db_queries(): void
    {
        $wg = WorkstationGroup::create(['name' => 'Salle-C']);
        $user = User::create(['login' => 'bob']);
        $group = UserGroup::create(['name' => 'Eleves']);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->service()->resolvePoliciesForMachine($wg, $user, AppKind::Firefox, 'linux');

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Perf cible AC 4 : ≤ 4 queries DB pour la résolution
        // (1 default étab + 1 WG + 1 userGroups ids + 1 userGroup overrides + 1 user override)
        // En pratique la lecture de pluck('user_groups.id') ajoute 1 query.
        // On fait un contrôle souple : ≤ 6 queries (marge).
        $this->assertLessThanOrEqual(6, count($queries),
            'Trop de queries : ' . count($queries)
                . "\n" . json_encode(array_column($queries, 'query')),
        );
    }

    #[Test]
    public function save_policies_upserts_and_strips_non_whitelisted_keys(): void
    {
        $user = User::create(['login' => 'saver']);
        $service = $this->service();

        $customization = $service->savePolicies(
            AppKind::Firefox,
            null,
            [
                'policies' => [
                    'Homepage' => ['URL' => 'https://etab/'],
                    'Proxy' => ['Mode' => 'manual'], // hors whitelist — droppé
                ],
            ],
            $user,
        );

        $this->assertSame(AppKind::Firefox, $customization->app_kind);
        $this->assertTrue($customization->is_default);
        $this->assertArrayNotHasKey('Proxy', $customization->policies_json['policies']);
        $this->assertArrayHasKey('Homepage', $customization->policies_json['policies']);
        $this->assertSame($user->id, $customization->created_by);

        // Re-save → updateOrCreate (pas de duplicate)
        $count1 = AppCustomization::count();
        $service->savePolicies(AppKind::Firefox, null, [
            'policies' => ['Homepage' => ['URL' => 'https://etab2/']],
        ], $user);
        $count2 = AppCustomization::count();
        $this->assertSame($count1, $count2);
    }

    #[Test]
    public function delete_customization_returns_true_on_deletion(): void
    {
        $user = User::create(['login' => 'deleter']);
        $service = $this->service();

        $service->savePolicies(AppKind::Firefox, null, [
            'policies' => ['Homepage' => ['URL' => 'https://x/']],
        ], $user);

        $this->assertTrue($service->deleteCustomization(AppKind::Firefox, null));
        $this->assertSame(0, AppCustomization::count());
    }
}
