<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\FileAssociation;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\AssociationsStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `AssociationsStateProvider` — Story 27.3bis (AC1, AC3, AC4).
 *
 * Catalogue `file_associations` × pivot `file_association_assignables` → candidats
 * CONCRETS `{identifier, progid, type}` par maille, SANS hash ni SID (calculés
 * agent-side, piège n° 2). Exclusive PAR IDENTIFIANT, portée session (HKCU).
 * Lecture Postgres pure (NFR7). Invariant central : JAMAIS d'id de catalogue au
 * payload.
 */
class AssociationsStateProviderTest extends TestCase
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

    private function provider(): AssociationsStateProvider
    {
        return new AssociationsStateProvider();
    }

    // ── Type / sémantique / portée ────────────────────────────────────────

    #[Test]
    public function provider_declares_associations_exclusive_session(): void
    {
        $p = $this->provider();
        self::assertSame(FileAssociation::TYPE_ASSOCIATIONS, $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Session, $p->scope());
    }

    // ── AC1/AC3 — catalogue → item CONCRET sans hash/SID ──────────────────

    #[Test]
    public function emits_concrete_payload_without_hash_sid_or_catalog_id(): void
    {
        $assoc = FileAssociation::factory()->file()->create([
            'identifier' => '.pdf',
            'progid' => 'Acrobat.Document.DC',
        ]);
        $assoc->workstationGroups()->attach($this->parc->id);

        $items = $this->provider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        /** @var StateCandidate $c */
        $c = $items->first();

        // Payload CONCRET, EXACTEMENT 3 clés (invariant central + piège n° 2).
        self::assertSame(['identifier', 'progid', 'type'], array_keys($c->payload));
        self::assertSame('.pdf', $c->payload['identifier']);
        self::assertSame('Acrobat.Document.DC', $c->payload['progid']);
        self::assertSame('file', $c->payload['type']);

        // JAMAIS de hash/SID au payload (calculés agent-side).
        self::assertArrayNotHasKey('hash', $c->payload);
        self::assertArrayNotHasKey('sid', $c->payload);

        // JAMAIS d'id/key de catalogue au payload (invariant central).
        self::assertArrayNotHasKey('id', $c->payload);
        self::assertArrayNotHasKey('key', $c->payload);
        self::assertArrayNotHasKey('label', $c->payload);
    }

    #[Test]
    public function protocol_association_emits_type_protocol(): void
    {
        $assoc = FileAssociation::factory()->protocol()->create([
            'identifier' => 'http',
            'progid' => 'FirefoxURL',
        ]);
        $assoc->workstationGroups()->attach($this->parc->id);

        $c = $this->provider()->itemsFor($this->ctx())->first();

        self::assertSame('http', $c->payload['identifier']);
        self::assertSame('protocol', $c->payload['type']);
        self::assertSame('FirefoxURL', $c->payload['progid']);
    }

    #[Test]
    public function unassigned_association_emits_no_item(): void
    {
        FileAssociation::factory()->file()->create();

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function inactive_association_is_ignored(): void
    {
        $assoc = FileAssociation::factory()->file()->create(['is_active' => false]);
        $assoc->workstationGroups()->attach($this->parc->id);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function candidate_is_tagged_with_logical_group_maille(): void
    {
        $assoc = FileAssociation::factory()->file()->create();
        $assoc->workstationGroups()->attach($this->parc->id); // parc = logique

        $c = $this->provider()->itemsFor($this->ctx())->first();

        self::assertSame(StateMaille::LogicalGroup, $c->maille);
    }

    // ── exclusiveKey : identité = identifier, insensible à la casse ───────

    #[Test]
    public function exclusive_key_is_case_insensitive_identifier(): void
    {
        $p = $this->provider();
        self::assertSame(
            $p->exclusiveKey(['identifier' => '.PDF']),
            $p->exclusiveKey(['identifier' => '.pdf']),
            'l\'identité est l\'identifiant insensible à la casse',
        );
    }

    #[Test]
    public function exclusive_key_differs_for_different_identifiers(): void
    {
        $p = $this->provider();
        self::assertNotSame(
            $p->exclusiveKey(['identifier' => '.pdf']),
            $p->exclusiveKey(['identifier' => '.html']),
        );
    }

    // ── AC4 — compilateur : exclusive PAR IDENTIFIANT (via le vrai provider) ─

    #[Test]
    public function compiler_keeps_most_specific_maille_per_identifier(): void
    {
        // Même extension .pdf sur DEUX mailles (parc logique + poste) avec des
        // ProgId différents → le POSTE (plus spécifique) gagne POUR CET identifiant.
        $byParc = FileAssociation::factory()->file()->create(['identifier' => '.pdf', 'progid' => 'ParcReader', 'key' => 'pdf_parc']);
        $byWs = FileAssociation::factory()->file()->create(['identifier' => '.pdf', 'progid' => 'WsReader', 'key' => 'pdf_ws']);
        $byParc->workstationGroups()->attach($this->parc->id);
        $byWs->workstations()->attach($this->ws->id);

        $items = $this->compiler()->compile($this->ctx())[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'un seul ProgId par identifiant');
        self::assertSame('WsReader', $items[0]['payload']['progid'], 'le poste bat le parc pour .pdf');
    }

    #[Test]
    public function compiler_accumulates_distinct_identifiers(): void
    {
        $pdf = FileAssociation::factory()->file()->create(['identifier' => '.pdf', 'progid' => 'Reader', 'key' => 'pdf']);
        $http = FileAssociation::factory()->protocol()->create(['identifier' => 'http', 'progid' => 'Browser', 'key' => 'http']);
        $pdf->workstationGroups()->attach($this->parc->id);
        $http->workstationGroups()->attach($this->parc->id);

        $items = $this->compiler()->compile($this->ctx())[StateContract::SCOPE_SESSION];

        self::assertCount(2, $items, 'les identifiants distincts s\'accumulent');
        $ids = collect($items)->pluck('payload.identifier')->sort()->values()->all();
        self::assertSame(['.pdf', 'http'], $ids);
    }

    #[Test]
    public function compiled_item_has_exactly_the_four_contract_keys(): void
    {
        $assoc = FileAssociation::factory()->file()->create(['identifier' => '.pdf', 'progid' => 'Reader']);
        $assoc->workstationGroups()->attach($this->parc->id);

        $items = $this->compiler()->compile($this->ctx())[StateContract::SCOPE_SESSION];

        self::assertSame(['type', 'semantics', 'payload', 'hash'], array_keys($items[0]));
        self::assertSame('associations', $items[0]['type']);
        self::assertSame('exclusive', $items[0]['semantics']);
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [$this->provider()]);
    }

    // ── NFR7 — lecture seule Postgres, zéro AD/APCu/samba ─────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_samba_dependency(): void
    {
        $src = file_get_contents(app_path('Services/Agent/Providers/AssociationsStateProvider.php'));
        // On retire les commentaires/docblocks (NFR7 vise le CODE).
        $codeOnly = preg_replace('#/\*.*?\*/#s', '', $src);
        $codeOnly = preg_replace('#//.*#', '', (string) $codeOnly);

        foreach (['LdapRecord', 'samba-tool', 'Cache::', 'apcu_', 'AssociationsResolver'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                (string) $codeOnly,
                "NFR7 : '{$forbidden}' interdit dans AssociationsStateProvider (canal desired-state Postgres-only)",
            );
        }
    }

    // ── Ciblage multi-maille : poste + groupe user ────────────────────────

    #[Test]
    public function targets_workstation_and_user_group_mailles_too(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $byWs = FileAssociation::factory()->file()->create(['identifier' => '.docx', 'key' => 'docx']);
        $byUg = FileAssociation::factory()->file()->create(['identifier' => '.xlsx', 'key' => 'xlsx']);
        $byWs->workstations()->attach($this->ws->id);
        $byUg->userGroups()->attach($group->id);

        $items = $this->provider()->itemsFor(TargetContext::for($this->ws, $user));
        $mailles = $items->mapWithKeys(fn (StateCandidate $c): array => [$c->payload['identifier'] => $c->maille])->all();

        self::assertSame(StateMaille::Workstation, $mailles['.docx']);
        self::assertSame(StateMaille::UserGroup, $mailles['.xlsx']);
    }
}
