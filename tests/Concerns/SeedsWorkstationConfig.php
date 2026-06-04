<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Story 16.13 — helper de tests Feature.
 *
 * Crée les schémas SQLite minimaux (`workstations`,
 * `workstation_groups`, pivot `workstation_group_workstation`, `users`)
 * + seed un poste résolvable par `WorkstationConfigContextResolver`.
 *
 * Iso-pattern `IssuesWorkstationJwt::ensureAuthV1Tables` + tests
 * `LegacyOutEndpointTest::setUp` (4.7).
 */
trait SeedsWorkstationConfig
{
    protected string $seededWorkstationUuid = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
    protected string $seededMachineName = 'post01';
    protected string $seededSalleName = 'salle-test';
    protected string $seededUserLogin = 'jdoe';

    protected function seedWorkstationContextSchemas(): void
    {
        if (config('database.default') !== 'sqlite') {
            return;
        }

        Model::unguard();
        Workstation::flushEventListeners();
        WorkstationGroup::flushEventListeners();
        User::flushEventListeners();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t): void {
                $t->id();
                $t->string('login')->unique();
                $t->string('password')->nullable();
                $t->string('role')->default('eleve');
                $t->boolean('is_active')->default(true);
                $t->unsignedBigInteger('ad_rights_bitmask')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $t): void {
                $t->id();
                $t->string('name')->unique();
                $t->string('type')->default('classe');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $t): void {
                $t->id();
                $t->string('name')->unique();
                $t->boolean('is_physical')->default(true);
                $t->boolean('is_active')->default(true);
                $t->boolean('managed_by_control_hub')->default(false);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('workstations')) {
            Schema::create('workstations', function (Blueprint $t): void {
                $t->id();
                $t->string('name');
                $t->string('uuid')->nullable()->index();
                $t->string('status')->default('active');
                $t->string('os')->nullable();
                $t->string('ip')->nullable();
                $t->string('mac')->nullable();
                $t->boolean('managed_by_control_hub')->default(false);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('workstation_group_workstation')) {
            Schema::create('workstation_group_workstation', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('workstation_id');
                $t->unsignedBigInteger('workstation_group_id');
                $t->timestamps();
            });
        }
    }

    /**
     * Seed un poste résolvable + son groupe physique + un user (optionnel).
     *
     * @return array{workstation: Workstation, group: WorkstationGroup, user: ?User}
     */
    protected function seedWorkstationContext(
        ?string $uuid = null,
        ?string $name = null,
        ?string $salleName = null,
        ?string $userLogin = null,
    ): array {
        $uuid ??= $this->seededWorkstationUuid;
        $name ??= $this->seededMachineName;
        $salleName ??= $this->seededSalleName;
        $userLogin ??= $this->seededUserLogin;

        $ws = Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'status' => 'active',
        ]);

        $wg = WorkstationGroup::create([
            'name' => $salleName,
            'is_physical' => true,
        ]);
        $ws->groups()->attach($wg->id);

        $user = null;
        if ($userLogin !== '') {
            $user = User::create(['login' => $userLogin]);
        }

        return ['workstation' => $ws, 'group' => $wg, 'user' => $user];
    }
}
