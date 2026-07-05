<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Exceptions\PrivilegeAuthoringException;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\CapabilityProjectionObserver;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\PrivilegeAuthoringGuard;
use App\Services\Agent\Providers\PrivilegeCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 35.6 — Tests Unit du provider `privilege` CAPABILITY-FIRST + du guard
 * d'authoring `PrivilegeAuthoringGuard` + du wiring observer (dispatch par
 * mécanisme).
 *
 * Le provider EXPANSE une capacité → AU PLUS un item CONCRET 2 clés
 * `{privilege, accounts}` (jetons d'audience résolus par convention, D6 —
 * `AudienceTokens` de 36.1 réutilisé). Lecture Postgres pure (NFR7 — la
 * résolution SID est côté POSTE, LSA). Invariant central 27.12 : jamais d'id/
 * key de capacité au payload.
 */
class CapabilityPrivilegeProviderTest extends TestCase
{
    use RefreshDatabase;

    private const RDP_DENY = 'SeDenyRemoteInteractiveLogonRight';

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

    private function provider(): PrivilegeCapabilityProvider
    {
        return new PrivilegeCapabilityProvider();
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    private function makeCapability(string $key, string $default, array $spec): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_PRIVILEGE,
            'spec' => $spec,
        ]);

        return $cap;
    }

    private function eleves(): void
    {
        UserGroup::factory()->create(['name' => 'Eleves', 'type' => 'role']);
    }

    // ── Type / sémantique / portée ────────────────────────────────────────

    #[Test]
    public function provider_declares_privilege_exclusive_machine(): void
    {
        $p = $this->provider();
        self::assertSame('privilege', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());
    }

    // ── (a) Expansion : payload EXACTEMENT 2 clés, accounts trié, pas d'id ─

    #[Test]
    public function expansion_emits_exactly_two_keys_with_sorted_accounts_and_no_capability_id(): void
    {
        // Comptes littéraux VOLONTAIREMENT hors ordre : le payload sort TRIÉ.
        $this->makeCapability('cap_lit', 'on', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['Zeta', 'Alpha'],
        ]);

        $items = $this->provider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame(['privilege', 'accounts'], array_keys($payload));
        self::assertSame(self::RDP_DENY, $payload['privilege']);
        self::assertSame(['Alpha', 'Zeta'], $payload['accounts'], 'liste triée (byte-identité du hash, piège #13)');
        foreach ($payload['accounts'] as $account) {
            self::assertIsString($account, 'zéro float — accounts = list<string>');
        }
        foreach (['id', 'key', 'capability_id', 'label', 'spec'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload);
        }
    }

    // ── (b) Map accounts + sentinelle UNMANAGED + forme inattendue ────────

    #[Test]
    public function unmanaged_sentinel_emits_nothing(): void
    {
        // `unmanaged` absent de la map ⇒ RIEN (sentinelle).
        $this->makeCapability('cap_u', 'unmanaged', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['eleves' => ['@eleves'], 'off' => []],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function unexpected_accounts_form_emits_nothing_defensively(): void
    {
        // Scalaire nu (ni liste ni map) ⇒ non émis défensif, jamais d'exception.
        $this->makeCapability('cap_scalar', 'on', [
            'privilege' => self::RDP_DENY,
            'accounts' => 'Eleves',
        ]);
        // Map dont la valeur n'est pas une liste ⇒ non émis défensif.
        $this->makeCapability('cap_nested', 'x', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['x' => ['nested' => 'shape']],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── (c) Jeton d'audience résolu (user_groups seedé) ───────────────────

    #[Test]
    public function audience_token_is_resolved_to_the_conventional_group_name(): void
    {
        $this->eleves();
        $this->makeCapability('cap_tok', 'eleves', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['eleves' => ['@eleves'], 'off' => []],
        ]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame(['Eleves'], $items->first()->payload['accounts'], '@eleves résolu vers le nom conventionnel');
    }

    // ── (d) Jeton inconnu / groupe absent ⇒ item ENTIER non émis + warning ─

    #[Test]
    public function audience_token_with_missing_group_emits_nothing_and_logs_a_warning(): void
    {
        // Groupe `Eleves` ABSENT de user_groups → @eleves irrésoluble. L'item
        // ENTIER est retenu (jamais une liste partielle qui sous-refuserait).
        Log::spy();
        $this->makeCapability('cap_missing', 'eleves', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['eleves' => ['@eleves', 'Invites']],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    #[Test]
    public function unknown_audience_token_emits_nothing(): void
    {
        Log::spy();
        $this->makeCapability('cap_bad_tok', 'on', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['@inconnu'],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ── (e) Compte littéral verbatim ──────────────────────────────────────

    #[Test]
    public function literal_account_is_emitted_verbatim(): void
    {
        // Aucun groupe seedé : un littéral part quand même (résolu par l'agent
        // via LSA sur le poste joint — piège #7).
        $this->makeCapability('cap_verbatim', 'on', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['MONDOMAINE\\Eleves'],
        ]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame(['MONDOMAINE\\Eleves'], $items->first()->payload['accounts']);
    }

    // ── (f) accounts: [] (off) ⇒ item ÉMIS avec liste vide ────────────────

    #[Test]
    public function off_value_emits_the_item_with_an_empty_accounts_list(): void
    {
        $this->makeCapability('cap_off', 'off', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['eleves' => ['@eleves'], 'off' => []],
        ]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items, 'off RÉEL : l\'item est émis (le handler VIDE le privilège — piège #6)');
        self::assertSame([], $items->first()->payload['accounts']);
        self::assertSame(self::RDP_DENY, $items->first()->payload['privilege']);
    }

    // ── (g) Privilège hors SeDeny* / vide ⇒ non émis (défensif) ───────────

    #[Test]
    public function out_of_allowlist_privilege_emits_nothing(): void
    {
        // Droit *grant* (verrouillerait la machine) — le guard refuse en amont,
        // le provider n'émet pas non plus (défensif).
        $this->makeCapability('cap_grant', 'on', [
            'privilege' => 'SeRemoteInteractiveLogonRight',
            'accounts' => ['Eleves'],
        ]);
        $this->makeCapability('cap_unknown', 'on', [
            'privilege' => 'SeDenyEverythingRight',
            'accounts' => ['Eleves'],
        ]);
        $this->makeCapability('cap_empty', 'on', [
            'privilege' => '',
            'accounts' => ['Eleves'],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── (h) exclusiveKey : 1 segment minuscule ────────────────────────────

    #[Test]
    public function exclusive_key_is_the_lowercase_privilege_name_single_segment(): void
    {
        $p = $this->provider();
        $a = $p->exclusiveKey(['privilege' => self::RDP_DENY, 'accounts' => ['Eleves']]);
        $b = $p->exclusiveKey(['privilege' => strtoupper(self::RDP_DENY), 'accounts' => []]);

        self::assertSame($a, $b, 'identité insensible à la casse, indépendante des accounts');
        self::assertSame('sedenyremoteinteractivelogonright', $a);
        self::assertStringNotContainsString('|', $a, '1 segment (piège #4 : la maille gagne la liste ENTIÈRE)');
    }

    // ── (i) Provider Postgres pur (NFR7) ──────────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_dependency(): void
    {
        foreach ([
            app_path('Services/Agent/Providers/PrivilegeCapabilityProvider.php'),
            app_path('Services/Agent/Providers/PrivilegeAuthoringGuard.php'),
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

    // ── Piège #11 : override UserGroup sans effet (contexte machine-only) ──

    #[Test]
    public function user_group_override_never_reaches_a_privilege_item(): void
    {
        $this->eleves();
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('cap_mo', 'unmanaged', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['eleves' => ['@eleves'], 'off' => []],
        ]);
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
        $fromUserGroup = $items->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::UserGroup);

        self::assertNull($fromUserGroup, 'un override UserGroup n\'atteint JAMAIS un item privilege (portée Machine)');
        self::assertCount(0, $items, 'défaut unmanaged + override user sans effet ⇒ rien émis');
    }

    // ── Guard d'authoring (AC3) — service PUR, sans DB ────────────────────

    private function guard(): PrivilegeAuthoringGuard
    {
        return new PrivilegeAuthoringGuard();
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return list<string>
     */
    private function guardOne(string $capability, ?string $warning, array $spec): array
    {
        return $this->guard()->violations([[
            'capability' => $capability,
            'warning' => $warning,
            'spec' => $spec,
        ]]);
    }

    #[Test]
    public function guard_accepts_each_of_the_five_sedeny_rights(): void
    {
        foreach (PrivilegeAuthoringGuard::ALLOWED_PRIVILEGES as $privilege) {
            $v = $this->guardOne('ok', 'attention refus de logon', [
                'privilege' => $privilege,
                'accounts' => ['@eleves'],
            ]);
            self::assertSame([], $v, "le droit SeDeny* '{$privilege}' doit être accepté");
        }
    }

    #[Test]
    public function guard_refuses_grant_rights_with_a_lockout_message(): void
    {
        foreach (['SeInteractiveLogonRight', 'SeRemoteInteractiveLogonRight', 'SeNetworkLogonRight', 'SeShutdownPrivilege'] as $grant) {
            $v = $this->guardOne('rogue', 'w', [
                'privilege' => $grant,
                'accounts' => ['Eleves'],
            ]);
            self::assertNotEmpty($v, "le droit grant '{$grant}' doit être refusé");
            self::assertStringContainsString('VERROUILLÉE', implode(' ', $v), 'le message explicite le risque de verrouillage machine');
        }
    }

    #[Test]
    public function guard_refuses_an_empty_or_missing_privilege(): void
    {
        self::assertNotEmpty($this->guardOne('empty', 'w', ['privilege' => '', 'accounts' => ['Eleves']]));
        self::assertNotEmpty($this->guardOne('missing', 'w', ['accounts' => ['Eleves']]));
    }

    #[Test]
    public function guard_refuses_privilege_without_warning(): void
    {
        $v = $this->guardOne('nowarn', null, [
            'privilege' => self::RDP_DENY,
            'accounts' => ['@eleves'],
        ]);
        self::assertNotEmpty($v, 'une projection privilege sans warning non vide est refusée (mécanisme de refus par nature)');
    }

    #[Test]
    public function guard_refuses_an_unknown_audience_token(): void
    {
        // Dans une liste littérale ET dans une map valeur-capacité.
        self::assertNotEmpty($this->guardOne('bad_list', 'w', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['@inconnu'],
        ]));
        self::assertNotEmpty($this->guardOne('bad_map', 'w', [
            'privilege' => self::RDP_DENY,
            'accounts' => ['eleves' => ['@inconnu'], 'off' => []],
        ]));
    }

    #[Test]
    public function guard_accepts_an_empty_accounts_list(): void
    {
        // Une liste vide EST légitime (= off, privilège vidé — piège #6).
        $v = $this->guardOne('off_ok', 'attention refus de logon', [
            'privilege' => self::RDP_DENY,
            'accounts' => [],
        ]);
        self::assertSame([], $v, 'accounts: [] (off réel) ne doit PAS être une violation');
    }

    #[Test]
    public function guard_refuses_broad_principals_in_accounts(): void
    {
        // Une SeDeny* LÉGITIME (passe l'allowlist) posée sur un principal trop
        // large verrouille le poste — refus « portée » (nom bien connu FR/EN,
        // avec/sans préfixe domaine, ou SID well-known / RID de domaine).
        $broad = [
            'Everyone', 'Tout le monde', 'Authenticated Users', 'Utilisateurs authentifiés',
            'Domain Users', 'Utilisateurs du domaine', 'Users', 'Administrators', 'SYSTEM',
            'Interactive', 'NT AUTHORITY\\Authenticated Users', 'SE4\\Domain Users',
            'S-1-1-0', 'S-1-5-11', 'S-1-5-32-545', 'S-1-5-21-10-20-30-513',
        ];
        foreach ($broad as $account) {
            $v = $this->guardOne('broad', 'attention refus de logon', [
                'privilege' => 'SeDenyInteractiveLogonRight',
                'accounts' => [$account],
            ]);
            self::assertNotEmpty($v, "le principal large '{$account}' doit être refusé");
            self::assertStringContainsString('VERROUILLÉ', implode(' ', $v), 'le message explicite le risque de verrouillage');
        }
    }

    #[Test]
    public function guard_still_accepts_a_named_business_group_in_accounts(): void
    {
        // La borne portée ne doit PAS bloquer un groupe MÉTIER nommé légitime.
        foreach (['Eleves', 'SE4\\Eleves', '@eleves', 'Profs_Salle_A'] as $account) {
            $v = $this->guardOne('ok', 'attention refus de logon', [
                'privilege' => self::RDP_DENY,
                'accounts' => [$account],
            ]);
            self::assertSame([], $v, "le groupe métier '{$account}' doit rester accepté");
        }
    }

    // ── Observer (AC3) : enforcement serveur au `saving` ──────────────────

    #[Test]
    public function observer_refuses_to_persist_a_grant_privilege_projection(): void
    {
        // L'observer est enregistré hors env `testing` (AppServiceProvider) :
        // on le câble explicitement ICI (patron CapabilityProjectionObserverTest).
        CapabilityProjection::observe(CapabilityProjectionObserver::class);

        try {
            $cap = Capability::factory()->create(['key' => 'rogue_privilege', 'warning' => 'w']);

            try {
                CapabilityProjection::create([
                    'capability_id' => $cap->id,
                    'os' => 'windows',
                    'mechanism' => CapabilityProjection::MECHANISM_PRIVILEGE,
                    'spec' => [
                        'privilege' => 'SeRemoteInteractiveLogonRight', // grant → verrouillage
                        'accounts' => ['Eleves'],
                    ],
                ]);
                self::fail('la projection grant aurait dû lever PrivilegeAuthoringException');
            } catch (PrivilegeAuthoringException) {
                // L'INSERT a bien été annulé (saving lève avant écriture).
                self::assertDatabaseMissing('capability_projections', [
                    'capability_id' => $cap->id,
                ]);
            }
        } finally {
            // Ne pas fuiter l'observer dans les autres tests (même process).
            CapabilityProjection::flushEventListeners();
        }
    }

    #[Test]
    public function observer_persists_a_valid_sedeny_projection(): void
    {
        CapabilityProjection::observe(CapabilityProjectionObserver::class);

        try {
            $cap = Capability::factory()->create([
                'key' => 'valid_privilege',
                'warning' => 'Refus de logon RDP : effet au logon suivant.',
            ]);

            $projection = CapabilityProjection::create([
                'capability_id' => $cap->id,
                'os' => 'windows',
                'mechanism' => CapabilityProjection::MECHANISM_PRIVILEGE,
                'spec' => [
                    'privilege' => self::RDP_DENY,
                    'accounts' => ['eleves' => ['@eleves'], 'off' => []],
                ],
            ]);

            self::assertTrue($projection->exists);
            self::assertDatabaseHas('capability_projections', [
                'id' => $projection->id,
                'mechanism' => 'privilege',
            ]);
        } finally {
            CapabilityProjection::flushEventListeners();
        }
    }
}
