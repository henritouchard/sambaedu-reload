<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\FsAclAuthoringGuard;
use App\Services\Agent\Providers\FsAclCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.1 — Tests Unit du provider `fs_acl` CAPABILITY-FIRST + du guard
 * d'authoring `FsAclAuthoringGuard`.
 *
 * Le provider EXPANSE une capacité → items CONCRETS 6 clés `{path, trustee,
 * ace_type, rights, applies_to, ensure}` (jetons d'audience résolus par
 * convention, Q1). Lecture Postgres pure (NFR7 — la résolution SID est côté
 * POSTE). Invariant central 27.12 : jamais d'id/key de capacité au payload.
 */
class CapabilityFsAclProviderTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $parc;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        // Catalogue VIDE : on contrôle exactement ce que le provider émet
        // (la capacité de preuve est seedée par migration — testée ailleurs).
        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->ws = Workstation::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($this->parc->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, null);
    }

    private function provider(): FsAclCapabilityProvider
    {
        return new FsAclCapabilityProvider();
    }

    /**
     * @param  list<array<string,mixed>>  $aces
     */
    private function makeCapability(string $key, string $default, array $aces): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => $aces],
        ]);

        return $cap;
    }

    private function eleves(): void
    {
        UserGroup::factory()->create(['name' => 'Eleves', 'type' => 'role']);
    }

    // ── Type / sémantique / portée ────────────────────────────────────────

    #[Test]
    public function provider_declares_fs_acl_exclusive_machine(): void
    {
        $p = $this->provider();
        self::assertSame('fs_acl', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());
    }

    // ── (a) Expansion : payload EXACTEMENT 6 clés strings, jamais d'id ─────

    #[Test]
    public function expansion_emits_exactly_six_string_keys_without_capability_id(): void
    {
        // Trustee littéral (Domain Users) → émis sans dépendance user_groups.
        $this->makeCapability('cap_lit', 'tous', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['tous' => 'Domain Users'],
            'ensure' => ['tous' => 'present'],
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame(['path', 'trustee', 'ace_type', 'rights', 'applies_to', 'ensure'], array_keys($payload));
        foreach ($payload as $v) {
            self::assertIsString($v, 'zéro float — 6 strings');
        }
        self::assertSame('Domain Users', $payload['trustee']);
        self::assertSame('present', $payload['ensure']);
        foreach (['id', 'key', 'capability_id', 'label', 'spec'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload);
        }
    }

    // ── (b) Map trustee/ensure + sentinelle UNMANAGED + assoc inattendue ──

    #[Test]
    public function unmanaged_sentinel_emits_nothing(): void
    {
        // `unmanaged` absent de toutes les maps ⇒ RIEN (sentinelle).
        $this->makeCapability('cap_u', 'unmanaged', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['tous' => 'Domain Users'],
            'ensure' => ['tous' => 'present'],
        ]]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function off_value_emits_an_absent_item_from_the_map(): void
    {
        $this->makeCapability('cap_off', 'off', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['tous' => 'Domain Users', 'off' => 'Domain Users'],
            'ensure' => ['tous' => 'present', 'off' => 'absent'],
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('absent', $items->first()->payload['ensure']);
        self::assertSame('Domain Users', $items->first()->payload['trustee']);
    }

    #[Test]
    public function unexpected_assoc_trustee_form_emits_nothing_defensively(): void
    {
        // Une forme assoc inattendue (ni littéral, ni valeur de map scalaire) ⇒
        // non émise défensif, jamais d'exception au render.
        $this->makeCapability('cap_weird', 'x', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['x' => ['nested' => 'shape']],
            'ensure' => ['x' => 'present'],
        ]]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── (c) Jeton d'audience résolu (user_groups seedé) ───────────────────

    #[Test]
    public function audience_token_is_resolved_to_the_conventional_group_name(): void
    {
        $this->eleves();
        $this->makeCapability('cap_tok', 'eleves', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['eleves' => '@eleves'],
            'ensure' => ['eleves' => 'present'],
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('Eleves', $items->first()->payload['trustee'], '@eleves résolu vers le nom conventionnel');
    }

    // ── (d) Jeton inconnu / groupe absent ⇒ non émis + warning ────────────

    #[Test]
    public function audience_token_with_missing_group_emits_nothing_and_logs_a_warning(): void
    {
        // Groupe `Eleves` ABSENT de user_groups → @eleves irrésoluble.
        Log::spy();
        $this->makeCapability('cap_missing', 'eleves', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['eleves' => '@eleves'],
            'ensure' => ['eleves' => 'present'],
        ]]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function unknown_audience_token_emits_nothing(): void
    {
        Log::spy();
        $this->makeCapability('cap_bad_tok', 'x', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['x' => '@inconnu'],
            'ensure' => ['x' => 'present'],
        ]]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ── (e) Trustee littéral verbatim ─────────────────────────────────────

    #[Test]
    public function literal_trustee_is_emitted_verbatim(): void
    {
        // Aucun groupe seedé : un littéral part quand même (résolu par l'agent).
        $this->makeCapability('cap_verbatim', 'on', [[
            'path' => 'D:\\Data',
            'ace_type' => 'allow',
            'rights' => 'read',
            'applies_to' => 'folder_subfolders_files',
            'trustee' => 'MONDOMAINE\\Profs',
            'ensure' => 'present',
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('MONDOMAINE\\Profs', $items->first()->payload['trustee']);
    }

    #[Test]
    public function ensure_defaults_to_present_when_absent_from_spec(): void
    {
        $this->makeCapability('cap_default_ensure', 'on', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => 'Domain Users',
            // pas de clé `ensure` → défaut `present` (TOUJOURS émis, piège #13).
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('present', $items->first()->payload['ensure']);
    }

    // ── (f) Enums hors domaine non émis (défensif) ────────────────────────

    #[Test]
    public function out_of_domain_enums_emit_nothing(): void
    {
        $this->makeCapability('cap_enum', 'on', [
            ['path' => 'C:\\P', 'ace_type' => 'audit', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'X', 'ensure' => 'present'],
            ['path' => 'C:\\P', 'ace_type' => 'deny', 'rights' => 'full', 'applies_to' => 'folder_only', 'trustee' => 'X', 'ensure' => 'present'],
            ['path' => 'C:\\P', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'everywhere', 'trustee' => 'X', 'ensure' => 'present'],
            ['path' => '', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => 'X', 'ensure' => 'present'],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── (g) exclusiveKey : 3 segments minuscules ──────────────────────────

    #[Test]
    public function exclusive_key_is_three_lowercase_segments(): void
    {
        $p = $this->provider();
        $a = $p->exclusiveKey(['path' => 'C:\\Program Files', 'trustee' => 'Eleves', 'ace_type' => 'DENY']);
        $b = $p->exclusiveKey(['path' => 'c:\\program files', 'trustee' => 'eleves', 'ace_type' => 'deny']);

        self::assertSame($a, $b, 'identité insensible à la casse');
        self::assertSame('c:\\program files|eleves|deny', $a);
        self::assertSame(2, substr_count($a, '|'), '3 segments');
    }

    // ── (h) Provider Postgres pur (NFR7) ──────────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_dependency(): void
    {
        foreach ([
            app_path('Services/Agent/Providers/FsAclCapabilityProvider.php'),
            app_path('Services/Agent/Providers/AudienceTokens.php'),
            app_path('Services/Agent/Providers/FsAclAuthoringGuard.php'),
        ] as $file) {
            $src = (string) file_get_contents($file);
            $codeOnly = preg_replace('#/\*.*?\*/#s', '', $src);
            $codeOnly = preg_replace('#//.*#', '', (string) $codeOnly);

            foreach (['LdapRecord', 'samba-tool', 'Cache::', 'apcu_'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    (string) $codeOnly,
                    "NFR7 : '{$forbidden}' interdit dans ".basename($file),
                );
            }
        }
    }

    // ── Guard d'authoring (AC3) — service PUR, sans DB ────────────────────

    private function guard(): FsAclAuthoringGuard
    {
        return new FsAclAuthoringGuard();
    }

    /**
     * @param  list<array<string,mixed>>  $aces
     * @return list<string>
     */
    private function guardOne(string $capability, ?string $warning, array $aces): array
    {
        return $this->guard()->violations([[
            'capability' => $capability,
            'warning' => $warning,
            'spec' => ['aces' => $aces],
        ]]);
    }

    #[Test]
    public function guard_accepts_deny_list_folder_folder_only_on_program_files(): void
    {
        $v = $this->guardOne('ok', 'attention deny', [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => '@eleves', 'ensure' => 'present'],
        ]);
        self::assertSame([], $v, 'masquer sans casser sur Program Files est AUTORISÉ');
    }

    #[Test]
    public function guard_refuses_deny_on_system_principals(): void
    {
        foreach (['SYSTEM', 'Administrators', 'NT AUTHORITY\\SYSTEM', 'BUILTIN\\Administrators', 'Everyone', 'Authenticated Users', 'TrustedInstaller'] as $principal) {
            $v = $this->guardOne('sys', 'w', [
                ['path' => 'C:\\Data', 'ace_type' => 'deny', 'rights' => 'modify', 'applies_to' => 'folder_only', 'trustee' => $principal, 'ensure' => 'present'],
            ]);
            self::assertNotEmpty($v, "deny sur le principal système '{$principal}' doit être refusé");
        }
    }

    #[Test]
    public function guard_refuses_descendant_deny_on_every_protected_root(): void
    {
        foreach (FsAclAuthoringGuard::PROTECTED_ROOTS as $root) {
            foreach (['folder_subfolders_files', 'subfolders_files_only'] as $applies) {
                $v = $this->guardOne('root', 'w', [
                    ['path' => $root, 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => $applies, 'trustee' => '@eleves', 'ensure' => 'present'],
                ]);
                self::assertNotEmpty($v, "deny descendant ({$applies}) sur '{$root}' doit être refusé (Q2)");
            }
        }
    }

    #[Test]
    public function guard_allows_descendant_deny_on_a_non_protected_path(): void
    {
        $v = $this->guardOne('ok', 'w', [
            ['path' => 'C:\\Program Files\\SomeApp', 'ace_type' => 'deny', 'rights' => 'modify', 'applies_to' => 'folder_subfolders_files', 'trustee' => '@eleves', 'ensure' => 'present'],
        ]);
        self::assertSame([], $v, 'un chemin NON racine protégée admet le deny descendant');
    }

    #[Test]
    public function guard_refuses_deny_without_warning(): void
    {
        $v = $this->guardOne('nowarn', null, [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => '@eleves', 'ensure' => 'present'],
        ]);
        self::assertNotEmpty($v, 'une capacité avec un deny sans warning non vide est refusée');
    }

    #[Test]
    public function guard_refuses_unknown_audience_token_and_non_absolute_path(): void
    {
        $v = $this->guardOne('bad', 'w', [
            ['path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => '@inconnu', 'ensure' => 'present'],
        ]);
        self::assertNotEmpty($v, 'jeton d\'audience inconnu refusé');

        $v = $this->guardOne('bad2', 'w', [
            ['path' => 'Software\\X', 'ace_type' => 'allow', 'rights' => 'read', 'applies_to' => 'folder_only', 'trustee' => 'Profs', 'ensure' => 'present'],
        ]);
        self::assertNotEmpty($v, 'chemin non absolu refusé');
    }

    #[Test]
    public function guard_refuses_deny_on_builtin_and_nt_service_authorities(): void
    {
        // Corr. review #4 : alignement serveur↔agent (S-1-5-32-* / S-1-5-80-).
        // Un deny sur ces autorités passait le guard puis échouait à chaque
        // cycle agent (capacité inerte silencieuse) — désormais refusé au NOM.
        foreach (['BUILTIN\\Backup Operators', 'builtin\\Users', 'NT SERVICE\\TrustedInstaller', 'NT Service\\MSSQLSERVER'] as $principal) {
            $v = $this->guardOne('auth', 'w', [
                ['path' => 'C:\\Data', 'ace_type' => 'deny', 'rights' => 'modify', 'applies_to' => 'folder_only', 'trustee' => $principal, 'ensure' => 'present'],
            ]);
            self::assertNotEmpty($v, "deny sur l'autorité système '{$principal}' doit être refusé (alignement agent)");
        }
    }

    #[Test]
    public function guard_refuses_a_short_name_8_3_path(): void
    {
        // Corr. review #3 : `C:\PROGRA~1` désigne C:\Program Files sans matcher
        // aucune racine protégée littéralement → contournement de Q2, refusé.
        $v = $this->guardOne('short', 'w', [
            ['path' => 'C:\\PROGRA~1', 'ace_type' => 'deny', 'rights' => 'list_folder', 'applies_to' => 'folder_only', 'trustee' => '@eleves', 'ensure' => 'present'],
        ]);
        self::assertNotEmpty($v, 'un chemin en nom court 8.3 (~1) doit être refusé');
    }

    #[Test]
    public function guard_allows_allow_ace_on_a_system_principal(): void
    {
        // Le refus ne vaut que pour `deny` : accorder (allow) à SYSTEM est légitime.
        $v = $this->guardOne('ok', null, [
            ['path' => 'C:\\Data', 'ace_type' => 'allow', 'rights' => 'modify', 'applies_to' => 'folder_only', 'trustee' => 'SYSTEM', 'ensure' => 'present'],
        ]);
        self::assertSame([], $v);
    }

    // ── Piège #10 : override UserGroup sans effet (compile machine-only) ───

    #[Test]
    public function user_group_override_never_reaches_an_fs_acl_item(): void
    {
        $this->eleves();
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('cap_mo', 'unmanaged', [[
            'path' => 'C:\\Program Files',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'trustee' => ['eleves' => '@eleves'],
            'ensure' => ['eleves' => 'present'],
        ]]);
        // Override UserGroup vers `eleves` : SANS EFFET (portée Machine, pas de user).
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'eleves',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Contexte MACHINE (user null → userGroupIds = []).
        $items = $this->provider()->itemsFor(TargetContext::for($this->ws, null));
        $eleves = $items->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::UserGroup);

        self::assertNull($eleves, 'un override UserGroup n\'atteint JAMAIS un item fs_acl (portée Machine)');
        self::assertCount(0, $items, 'défaut unmanaged + override user sans effet ⇒ rien émis');
    }
}
