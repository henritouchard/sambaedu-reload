<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Se4PruneAdParcsCommand;
use App\Models\AppProfile;
use App\Models\WorkstationGroup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.7 / AC4 — `se4:prune-ad-parcs` en dry-run : liste les CN de OU=Parcs,
 * journalise nommément les exclusions (app_profiles + salles physiques), et
 * n'émet AUCUNE écriture LDAP. Le seam `parcsEntriesSeam` injecte des entrées
 * factices (tests HÔTE, sans AD).
 */
class Se4PruneAdParcsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Les fixtures créent un groupe physique → l'observer dispatcherait un
        // job de sync AD (queue sync → LDAP). On neutralise le bus : la commande
        // elle-même ne dispatche aucun job.
        Bus::fake();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        Se4PruneAdParcsCommand::$parcsEntriesSeam = null;
        parent::tearDown();
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->boolean('is_physical')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('app_profiles')) {
            Schema::create('app_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    /**
     * @param  array<int, array{name: string, dn: string}>  $entries
     */
    private function seedEntries(array $entries): void
    {
        Se4PruneAdParcsCommand::$parcsEntriesSeam = array_map(
            fn (array $e) => new class($e['name'], $e['dn']) {
                public function __construct(private string $name, private string $dn) {}

                public function getParcName(): ?string
                {
                    return $this->name;
                }

                public function getDn(): string
                {
                    return $this->dn;
                }

                public function delete(): void
                {
                    throw new \RuntimeException('delete() ne doit JAMAIS être appelé en dry-run');
                }
            },
            $entries,
        );
    }

    #[Test]
    public function dry_run_lists_targets_and_excludes_profiles_and_physical_groups(): void
    {
        // Groupe physique (miroir de salle vivant → exclu).
        WorkstationGroup::create(['name' => 'salle101', 'is_physical' => true]);
        // Groupe logique (pas une exclusion en soi ; sa collision est gérée par le profil).
        WorkstationGroup::create(['name' => 'parc-orphelin', 'is_physical' => false]);
        // Profil applicatif homonyme d'un CN (collision → exclu).
        AppProfile::create(['name' => 'profil-collision', 'is_active' => true]);

        $this->seedEntries([
            ['name' => 'parc-orphelin', 'dn' => 'CN=parc-orphelin,OU=Parcs,DC=x'],
            ['name' => 'salle101', 'dn' => 'CN=salle101,OU=Parcs,DC=x'],
            ['name' => 'profil-collision', 'dn' => 'CN=profil-collision,OU=Parcs,DC=x'],
            ['name' => 'vieux-parc-se4', 'dn' => 'CN=vieux-parc-se4,OU=Parcs,DC=x'],
        ]);

        $this->artisan('se4:prune-ad-parcs')
            ->assertSuccessful()
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('CN=parc-orphelin,OU=Parcs,DC=x')
            ->expectsOutputToContain('CN=vieux-parc-se4,OU=Parcs,DC=x')
            ->expectsOutputToContain('[exclu — profil applicatif] CN=profil-collision,OU=Parcs,DC=x')
            ->expectsOutputToContain('[exclu — salle physique]    CN=salle101,OU=Parcs,DC=x');
    }

    #[Test]
    public function dry_run_never_deletes(): void
    {
        // Si un delete() était appelé, l'entrée factice lèverait — dry-run = SUCCESS.
        $this->seedEntries([
            ['name' => 'vieux-parc-se4', 'dn' => 'CN=vieux-parc-se4,OU=Parcs,DC=x'],
        ]);

        $this->artisan('se4:prune-ad-parcs')->assertSuccessful();
    }
}
