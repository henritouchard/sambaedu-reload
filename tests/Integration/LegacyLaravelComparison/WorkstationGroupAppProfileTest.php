<?php

declare(strict_types=1);

namespace Tests\Integration\LegacyLaravelComparison;

use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Story 38.7 (AC8) — ce test PROUVE la NON-création automatique d'AppProfile.
 *
 * Avant 38.7 : créer un groupe avec `app_profile_name` rempli déclenchait
 * l'Observer qui créait un AppProfile homonyme + un CN dans OU=Parcs, et un
 * renommage de groupe renommait tout profil homonyme. Tout cela a été RETIRÉ.
 *
 * Le test ne dépend plus d'un annuaire réel (la vérification portait sur le CN
 * dans OU=Parcs, désormais jamais écrit) : c'est du SQL + Observer pur.
 */
class WorkstationGroupAppProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_physical')->default(true);
                $table->string('display_name')->nullable();
                $table->text('description')->nullable();
                $table->string('app_profile_name')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('locked')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->text('description')->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->string('ad_dn', 512)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('app_profile_workstation_group')) {
            Schema::create('app_profile_workstation_group', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('app_profile_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->timestamps();
            });
        }
    }

    public function test_creating_group_with_app_profile_name_creates_no_profile_and_no_pivot(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create([
            'name' => 'salle-x',
            'description' => 'Test non-création',
            'app_profile_name' => 'salle-x',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->assertSame(0, AppProfile::count());
        $this->assertFalse($group->appProfiles()->exists());
    }

    public function test_renaming_group_never_touches_a_homonymous_profile(): void
    {
        Bus::fake();

        $group = WorkstationGroup::create(['name' => 'salle-x', 'is_physical' => true, 'is_active' => true, 'ad_guid' => 'g']);
        $profile = AppProfile::create(['name' => 'salle-x', 'is_active' => true]);

        $group->update(['name' => 'salle-y']);

        $this->assertSame('salle-x', $profile->fresh()->name);
        $this->assertNull(AppProfile::where('name', 'salle-y')->first());
    }
}
