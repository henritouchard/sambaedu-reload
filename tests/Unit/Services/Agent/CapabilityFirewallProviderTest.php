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
use App\Services\Agent\Providers\FirewallAuthoringGuard;
use App\Services\Agent\Providers\FirewallCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.2 — Tests Unit du provider `firewall` CAPABILITY-FIRST + du guard
 * d'authoring `FirewallAuthoringGuard` (Q3 — intersection MATHÉMATIQUE
 * d'intervalles IPv4/IPv6).
 *
 * Le provider EXPANSE une capacité → items CONCRETS `{rule_id, direction,
 * action, remote_scope, protocol, ensure}` (+ conditionnels). Lecture Postgres
 * pure (NFR7). Invariant central 27.12 : jamais d'id/key de capacité au payload.
 */
class CapabilityFirewallProviderTest extends TestCase
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

    private function provider(): FirewallCapabilityProvider
    {
        return new FirewallCapabilityProvider();
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     */
    private function makeCapability(string $key, string $default, array $rules): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FIREWALL,
            'spec' => ['rules' => $rules],
        ]);

        return $cap;
    }

    // ── Type / sémantique / portée ────────────────────────────────────────

    #[Test]
    public function provider_declares_firewall_exclusive_machine(): void
    {
        $p = $this->provider();
        self::assertSame('firewall', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());
    }

    // ── (a) Expansion : 6 clés strings, jamais d'id ───────────────────────

    #[Test]
    public function expansion_emits_six_string_keys_without_capability_id(): void
    {
        $this->makeCapability('cap_off', 'off', [[
            'rule_id' => 'internet-block',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['off' => 'present', 'on' => 'absent'],
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame(['rule_id', 'direction', 'action', 'remote_scope', 'protocol', 'ensure'], array_keys($payload));
        foreach ($payload as $v) {
            self::assertIsString($v, '6 strings, zéro float');
        }
        self::assertSame('present', $payload['ensure']);
        foreach (['id', 'key', 'capability_id', 'label', 'spec'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload);
        }
    }

    #[Test]
    public function explicit_scope_emits_remote_addresses_and_ports(): void
    {
        $this->makeCapability('cap_proxy', 'off', [[
            'rule_id' => 'block-proxy',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'explicit',
            'protocol' => 'tcp',
            'remote_addresses' => ['8.8.8.8', '203.0.113.0/24'],
            'ports' => ['443', '8080-8090'],
            'ensure' => ['off' => 'present'],
        ]]);

        $payload = $this->provider()->itemsFor($this->ctx())->first()->payload;
        self::assertSame(['8.8.8.8', '203.0.113.0/24'], $payload['remote_addresses']);
        self::assertSame(['443', '8080-8090'], $payload['ports']);
    }

    // ── (b) Map ensure + sentinelle UNMANAGED + assoc inattendue ──────────

    #[Test]
    public function unmanaged_sentinel_emits_nothing(): void
    {
        $this->makeCapability('cap_u', 'unmanaged', [[
            'rule_id' => 'internet-block',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['off' => 'present', 'on' => 'absent'],
        ]]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function on_value_emits_an_absent_item_from_the_map(): void
    {
        $this->makeCapability('cap_on', 'on', [[
            'rule_id' => 'internet-block',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['off' => 'present', 'on' => 'absent'],
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('absent', $items->first()->payload['ensure']);
        self::assertSame('internet-block', $items->first()->payload['rule_id']);
    }

    #[Test]
    public function unexpected_assoc_ensure_form_emits_nothing_defensively(): void
    {
        $this->makeCapability('cap_weird', 'x', [[
            'rule_id' => 'r',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['x' => ['nested' => 'shape']],
        ]]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function ensure_defaults_to_present_when_absent_from_spec(): void
    {
        $this->makeCapability('cap_def', 'on', [[
            'rule_id' => 'r',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            // pas de clé `ensure` → défaut `present` (piège #2/#13).
        ]]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('present', $items->first()->payload['ensure']);
    }

    // ── (c) Enums hors domaine / incohérences conditionnelles non émis ────

    #[Test]
    public function out_of_domain_or_incoherent_entries_emit_nothing(): void
    {
        $this->makeCapability('cap_bad', 'on', [
            ['rule_id' => 'a', 'direction' => 'sideways', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'ensure' => 'present'],
            ['rule_id' => 'b', 'direction' => 'out', 'action' => 'log', 'remote_scope' => 'internet', 'protocol' => 'any', 'ensure' => 'present'],
            ['rule_id' => 'c', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'lan', 'protocol' => 'any', 'ensure' => 'present'],
            ['rule_id' => 'd', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'icmp', 'ensure' => 'present'],
            ['rule_id' => '', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'ensure' => 'present'],
            // explicit sans adresses.
            ['rule_id' => 'e', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'explicit', 'protocol' => 'any', 'ensure' => 'present'],
            // internet avec adresses.
            ['rule_id' => 'f', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'remote_addresses' => ['8.8.8.8'], 'ensure' => 'present'],
            // ports avec any.
            ['rule_id' => 'g', 'direction' => 'out', 'action' => 'block', 'remote_scope' => 'internet', 'protocol' => 'any', 'ports' => ['80'], 'ensure' => 'present'],
        ]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── (d) exclusiveKey : rule_id minuscule (1 segment) ──────────────────

    #[Test]
    public function exclusive_key_is_lowercase_rule_id(): void
    {
        $p = $this->provider();
        self::assertSame('internet-block', $p->exclusiveKey(['rule_id' => 'Internet-Block']));
        self::assertSame(0, substr_count($p->exclusiveKey(['rule_id' => 'a']), '|'), '1 segment');
    }

    // ── (e) Provider Postgres pur (NFR7) ──────────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_dependency(): void
    {
        foreach ([
            app_path('Services/Agent/Providers/FirewallCapabilityProvider.php'),
            app_path('Services/Agent/Providers/FirewallAuthoringGuard.php'),
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

    private function guard(): FirewallAuthoringGuard
    {
        return new FirewallAuthoringGuard();
    }

    /**
     * @param  list<array<string,mixed>>  $rules
     * @return list<string>
     */
    private function guardOne(string $capability, ?string $warning, array $rules): array
    {
        return $this->guard()->violations([[
            'capability' => $capability,
            'warning' => $warning,
            'spec' => ['rules' => $rules],
        ]]);
    }

    private function blockRule(string $scope, array $addrs = []): array
    {
        $rule = [
            'rule_id' => 'r',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => $scope,
            'protocol' => 'any',
            'ensure' => 'present',
        ];
        if ($addrs !== []) {
            $rule['remote_addresses'] = $addrs;
        }

        return $rule;
    }

    #[Test]
    public function guard_accepts_block_internet(): void
    {
        // Usage nominal Q3 : block internet est SÛR par construction.
        $v = $this->guardOne('ok', 'attention block', [$this->blockRule('internet')]);
        self::assertSame([], $v);
    }

    #[Test]
    public function guard_refuses_block_covering_the_lan_by_interval_intersection(): void
    {
        // RFC1918 littéral, CIDR englobant SANS écrire 192.168, /0 v4 et v6, ULA.
        foreach (['192.168.0.0/16', '192.160.0.0/12', '0.0.0.0/0', '::/0', '10.0.0.5', '127.0.0.1', '169.254.1.1', 'fc00::/7', 'fe80::/10', '::1'] as $addr) {
            $v = $this->guardOne('rogue', 'w', [$this->blockRule('explicit', [$addr])]);
            self::assertNotEmpty($v, "block explicit sur '{$addr}' doit être refusé (Q3, intersection)");
        }
    }

    #[Test]
    public function guard_accepts_block_explicit_on_public_addresses(): void
    {
        // Échappatoire assumée Q3 : adresses publiques uniquement.
        $v = $this->guardOne('ok', 'w', [$this->blockRule('explicit', ['8.8.8.8', '203.0.113.0/24', '2001:4860:4860::8888'])]);
        self::assertSame([], $v);
    }

    #[Test]
    public function guard_refuses_unparsable_explicit_addresses(): void
    {
        foreach (['LocalSubnet', '1.0.0.0-2.0.0.0', 'not-an-ip', '10.0.0.0/33'] as $addr) {
            $v = $this->guardOne('bad', 'w', [$this->blockRule('explicit', [$addr])]);
            self::assertNotEmpty($v, "adresse '{$addr}' non parsable / plage a-b / mot-clé Windows refusée");
        }
    }

    #[Test]
    public function guard_refuses_explicit_without_addresses_and_internet_with_addresses(): void
    {
        self::assertNotEmpty($this->guardOne('x', 'w', [$this->blockRule('explicit')]), 'explicit sans adresses refusé');
        self::assertNotEmpty($this->guardOne('x', 'w', [$this->blockRule('internet', ['8.8.8.8'])]), 'internet avec adresses refusé');
    }

    #[Test]
    public function guard_refuses_out_of_domain_enums_and_bad_slug(): void
    {
        $v = $this->guardOne('x', 'w', [[
            'rule_id' => 'Bad Slug!', 'direction' => 'sideways', 'action' => 'log',
            'remote_scope' => 'lan', 'protocol' => 'icmp', 'ensure' => 'present',
        ]]);
        self::assertNotEmpty($v);
        self::assertGreaterThanOrEqual(5, count($v), 'slug + 4 enums hors domaine');
    }

    #[Test]
    public function guard_refuses_ports_with_any_and_out_of_range_ports(): void
    {
        $rule = fn (string $protocol, array $ports): array => [
            'rule_id' => 'r', 'direction' => 'out', 'action' => 'allow',
            'remote_scope' => 'internet', 'protocol' => $protocol, 'ports' => $ports, 'ensure' => 'present',
        ];
        self::assertNotEmpty($this->guardOne('x', 'w', [$rule('any', ['80'])]), 'ports avec any refusés');
        self::assertNotEmpty($this->guardOne('x', 'w', [$rule('tcp', ['0'])]), 'port 0 refusé');
        self::assertNotEmpty($this->guardOne('x', 'w', [$rule('tcp', ['70000'])]), 'port > 65535 refusé');
        self::assertNotEmpty($this->guardOne('x', 'w', [$rule('tcp', ['100-50'])]), 'plage inversée refusée');
        self::assertSame([], $this->guardOne('x', null, [$rule('tcp', ['443', '8080-8090'])]), 'ports valides acceptés (pas de block ⇒ pas de warning requis)');
    }

    #[Test]
    public function guard_refuses_block_without_warning(): void
    {
        $v = $this->guardOne('nowarn', null, [$this->blockRule('internet')]);
        self::assertNotEmpty($v, 'une projection avec un block sans warning non vide est refusée');
    }

    // ── Garde-fou Q5 : `allow` ENTRANT ouvert sur Internet ⇒ warning exigé ──

    private function allowInRule(string $scope, array $addrs = []): array
    {
        $rule = [
            'rule_id' => 'r',
            'direction' => 'in',
            'action' => 'allow',
            'remote_scope' => $scope,
            'protocol' => 'any',
            'ensure' => 'present',
        ];
        if ($addrs !== []) {
            $rule['remote_addresses'] = $addrs;
        }

        return $rule;
    }

    #[Test]
    public function guard_refuses_open_allow_in_without_warning(): void
    {
        // internet + /0 explicite (v4 & v6) : « ouverts sur l'Internet » par
        // intervalle (chaque plage englobe /0) → warning EXIGÉ (absent = KO). Le
        // critère est PAR PLAGE (iso le refus Q3 `block`, par adresse) : une plage
        // qui englobe /0, jamais une union de plages plus étroites.
        $cases = [
            'internet' => $this->allowInRule('internet'),
            '0.0.0.0/0' => $this->allowInRule('explicit', ['0.0.0.0/0']),
            '::/0' => $this->allowInRule('explicit', ['::/0']),
        ];
        foreach ($cases as $label => $rule) {
            $v = $this->guardOne('nowarn', null, [$rule]);
            self::assertNotEmpty($v, "allow in ouvert ({$label}) sans warning doit être refusé (Q5)");
            self::assertStringContainsString('exposé en entrée à tout l\'Internet', implode(' ', $v));
        }
    }

    #[Test]
    public function guard_accepts_open_allow_in_with_warning(): void
    {
        self::assertSame([], $this->guardOne('ok', 'attention exposition', [$this->allowInRule('internet')]));
        self::assertSame([], $this->guardOne('ok', 'attention exposition', [$this->allowInRule('explicit', ['0.0.0.0/0'])]));
    }

    #[Test]
    public function guard_accepts_narrow_or_outbound_allow_without_warning(): void
    {
        // Plage ÉTROITE (host public précis, /24 privé) entrante : NON concernée.
        self::assertSame([], $this->guardOne('x', null, [$this->allowInRule('explicit', ['203.0.113.7'])]));
        self::assertSame([], $this->guardOne('x', null, [$this->allowInRule('explicit', ['192.168.1.0/24'])]));
        // `allow out` ouvert sur Internet : NON concerné (seul l'ENTRANT expose).
        $allowOut = array_merge($this->allowInRule('internet'), ['direction' => 'out']);
        self::assertSame([], $this->guardOne('x', null, [$allowOut]));
    }

    #[Test]
    public function guard_refuses_ensure_as_list(): void
    {
        // corr. review #5 : un `ensure` en LISTE (ni littéral ni map) est une
        // forme d'authoring malformée — REFUSÉE explicitement (auparavant passée
        // en silence : aucune valeur validée).
        $rule = [
            'rule_id' => 'r',
            'direction' => 'out',
            'action' => 'allow',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['present', 'absent'], // liste (array_is_list)
        ];
        $v = $this->guardOne('listshape', 'w', [$rule]);
        self::assertNotEmpty($v, 'un `ensure` en liste doit être refusé (forme inattendue)');
        self::assertStringContainsString('forme `ensure` inattendue', implode(' ', $v));

        // Contrôle : un littéral et une map restent acceptés.
        self::assertSame([], $this->guardOne('lit', null, [array_merge($rule, ['ensure' => 'present'])]));
        self::assertSame([], $this->guardOne('map', null, [array_merge($rule, ['ensure' => ['off' => 'present', 'on' => 'absent']])]));
    }

    // ── Piège #10/#15 : override UserGroup sans effet (compile machine-only)

    #[Test]
    public function user_group_override_never_reaches_a_firewall_item(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('cap_mo', 'unmanaged', [[
            'rule_id' => 'internet-block',
            'direction' => 'out',
            'action' => 'block',
            'remote_scope' => 'internet',
            'protocol' => 'any',
            'ensure' => ['off' => 'present', 'on' => 'absent'],
        ]]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->provider()->itemsFor(TargetContext::for($this->ws, null));
        $userItem = $items->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::UserGroup);

        self::assertNull($userItem, 'un override UserGroup n\'atteint JAMAIS un item firewall (portée Machine)');
        self::assertCount(0, $items, 'défaut unmanaged + override user sans effet ⇒ rien émis');
    }
}
