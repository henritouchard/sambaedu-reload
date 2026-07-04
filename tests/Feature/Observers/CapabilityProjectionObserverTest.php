<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Exceptions\FirewallAuthoringException;
use App\Exceptions\FsAclAuthoringException;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Observers\CapabilityProjectionObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.1 (corr. review #2b) — wiring SERVEUR du garde-fou d'authoring
 * `fs_acl` : l'observer {@see CapabilityProjectionObserver} rend la décision Q2
 * RÉELLE (avant cet observer, {@see \App\Services\Agent\Providers\FsAclAuthoringGuard}
 * n'avait AUCUN appelant hors tests).
 *
 * L'observer est enregistré hors environnement de test dans
 * `AppServiceProvider::boot` (les tests unitaires du provider fabriquent des
 * specs adversariales via factory) — on le ré-enregistre ICI explicitement pour
 * prouver son comportement (patron {@see WorkstationObserverTest}).
 */
class CapabilityProjectionObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CapabilityProjection::observe(CapabilityProjectionObserver::class);
    }

    protected function tearDown(): void
    {
        // Ne pas fuiter l'observer dans les autres classes de la suite (même
        // process) : CapabilityProjection n'a aucun autre listener par défaut.
        CapabilityProjection::flushEventListeners();
        parent::tearDown();
    }

    #[Test]
    public function it_refuses_to_persist_a_forbidden_q2_fs_acl_projection(): void
    {
        $cap = Capability::factory()->create([
            'key' => 'rogue_fs_acl',
            'warning' => 'attention deny',
        ]);

        $this->expectException(FsAclAuthoringException::class);

        // Combo interdit Q2 : deny à héritage descendant sur C:\Windows.
        CapabilityProjection::create([
            'capability_id' => $cap->id,
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => [[
                'path' => 'C:\\Windows',
                'ace_type' => 'deny',
                'rights' => 'modify',
                'applies_to' => 'folder_subfolders_files',
                'trustee' => '@eleves',
                'ensure' => 'present',
            ]]],
        ]);
    }

    #[Test]
    public function forbidden_projection_is_not_written(): void
    {
        $cap = Capability::factory()->create(['key' => 'rogue2', 'warning' => 'w']);

        try {
            CapabilityProjection::create([
                'capability_id' => $cap->id,
                'os' => 'windows',
                'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
                'spec' => ['aces' => [[
                    'path' => 'C:\\ProgramData',
                    'ace_type' => 'deny',
                    'rights' => 'modify',
                    'applies_to' => 'subfolders_files_only',
                    'trustee' => '@eleves',
                    'ensure' => 'present',
                ]]],
            ]);
            self::fail('la projection interdite aurait dû lever');
        } catch (FsAclAuthoringException) {
            // L'INSERT a bien été annulé (saving lève avant écriture).
            self::assertDatabaseMissing('capability_projections', [
                'capability_id' => $cap->id,
            ]);
        }
    }

    #[Test]
    public function it_refuses_a_deny_on_a_builtin_authority_trustee(): void
    {
        // Corr. review #4 : un deny sur `BUILTIN\Backup Operators` passait le
        // guard avant l'alignement des principals — désormais refusé.
        $cap = Capability::factory()->create(['key' => 'rogue_builtin', 'warning' => 'w']);

        $this->expectException(FsAclAuthoringException::class);

        CapabilityProjection::create([
            'capability_id' => $cap->id,
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => [[
                'path' => 'C:\\Data',
                'ace_type' => 'deny',
                'rights' => 'modify',
                'applies_to' => 'folder_only',
                'trustee' => 'BUILTIN\\Backup Operators',
                'ensure' => 'present',
            ]]],
        ]);
    }

    #[Test]
    public function it_persists_a_valid_fs_acl_projection(): void
    {
        $cap = Capability::factory()->create([
            'key' => 'valid_fs_acl',
            'warning' => 'l\'Explorateur ne montrera plus le contenu',
        ]);

        $projection = CapabilityProjection::create([
            'capability_id' => $cap->id,
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => [[
                'path' => 'C:\\Program Files',
                'ace_type' => 'deny',
                'rights' => 'list_folder',
                'applies_to' => 'folder_only',
                'trustee' => '@eleves',
                'ensure' => 'present',
            ]]],
        ]);

        self::assertTrue($projection->exists);
        self::assertDatabaseHas('capability_projections', [
            'id' => $projection->id,
            'mechanism' => 'fs_acl',
        ]);
    }

    #[Test]
    public function it_ignores_projections_of_other_mechanisms(): void
    {
        // Un autre mécanisme n'est PAS concerné, même avec un spec qui serait
        // invalide pour fs_acl (l'observer retourne immédiatement).
        $cap = Capability::factory()->create(['key' => 'reg_cap']);

        $projection = CapabilityProjection::create([
            'capability_id' => $cap->id,
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => ['keys' => [[
                'hive' => 'HKLM',
                'path' => 'Software\\Whatever',
                'name' => 'X',
                'type' => 'REG_DWORD',
                'value' => 1,
            ]]],
        ]);

        self::assertTrue($projection->exists, 'une projection registry ne passe jamais par le guard fs_acl');
    }

    // ── Story 36.2 — dispatch par mécanisme : firewall (Q3) ────────────────

    #[Test]
    public function it_refuses_to_persist_a_q3_firewall_projection_covering_the_lan(): void
    {
        $cap = Capability::factory()->create(['key' => 'rogue_firewall', 'warning' => 'attention block']);

        $this->expectException(FirewallAuthoringException::class);

        // Combo interdit Q3 : block explicit couvrant RFC1918.
        CapabilityProjection::create([
            'capability_id' => $cap->id,
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_FIREWALL,
            'spec' => ['rules' => [[
                'rule_id' => 'lan-block',
                'direction' => 'out',
                'action' => 'block',
                'remote_scope' => 'explicit',
                'protocol' => 'any',
                'remote_addresses' => ['192.168.0.0/16'],
                'ensure' => 'present',
            ]]],
        ]);
    }

    #[Test]
    public function it_refuses_a_firewall_projection_blocking_everything(): void
    {
        $cap = Capability::factory()->create(['key' => 'rogue_all', 'warning' => 'w']);

        try {
            CapabilityProjection::create([
                'capability_id' => $cap->id,
                'os' => 'windows',
                'mechanism' => CapabilityProjection::MECHANISM_FIREWALL,
                'spec' => ['rules' => [[
                    'rule_id' => 'all-block',
                    'direction' => 'out',
                    'action' => 'block',
                    'remote_scope' => 'explicit',
                    'protocol' => 'any',
                    'remote_addresses' => ['0.0.0.0/0'],
                    'ensure' => 'present',
                ]]],
            ]);
            self::fail('la projection block /0 aurait dû lever');
        } catch (FirewallAuthoringException) {
            self::assertDatabaseMissing('capability_projections', ['capability_id' => $cap->id]);
        }
    }

    #[Test]
    public function it_persists_a_valid_block_internet_firewall_projection(): void
    {
        $cap = Capability::factory()->create(['key' => 'valid_firewall', 'warning' => 'Coupe l\'accès Internet']);

        $projection = CapabilityProjection::create([
            'capability_id' => $cap->id,
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_FIREWALL,
            'spec' => ['rules' => [[
                'rule_id' => 'internet-block',
                'direction' => 'out',
                'action' => 'block',
                'remote_scope' => 'internet',
                'protocol' => 'any',
                'ensure' => 'present',
            ]]],
        ]);

        self::assertTrue($projection->exists, 'un block internet valide passe (sûr par construction Q3)');
    }

    #[Test]
    public function the_seed_capability_is_present_and_not_blocked(): void
    {
        // Le seed program_files_browse_denied (migration RefreshDatabase, écrit
        // via Query Builder) est propre ET n'a pas été bloqué par l'observer.
        self::assertDatabaseHas('capabilities', ['key' => 'program_files_browse_denied']);
        $capId = Capability::where('key', 'program_files_browse_denied')->value('id');
        self::assertDatabaseHas('capability_projections', [
            'capability_id' => $capId,
            'mechanism' => 'fs_acl',
        ]);
    }
}
