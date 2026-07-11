<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Workstation;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\Contracts\KeyedExclusiveProvider;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use App\Services\ControlHub\Resolution\RegistryUpstreamAdapter;
use App\Services\ControlHub\Resolution\UpstreamAwareProvider;
use App\Services\ControlHub\Resolution\UpstreamContractSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.5 (NFR2) — PREUVE par construction : un item amont `locked` est DÉJÀ
 * soumis au drift STRICT inconditionnel (livré en 27.8), SANS aucun câblage
 * d'enforcement ajouté par 29.5.
 *
 * Ce test verrouille la chaîne : un item amont `registry`/`locked`/`instance`
 * matchant une projection de capacité (même clé qu'un réglage local) est injecté
 * à la maille `StateMaille::Upstream` (rang -1, inbattable — 28.3/29.3), compilé en
 * item de desired-state à **exactement 4 clés** `{type, semantics, payload, hash}`
 * portant la **valeur amont**, et **ne contient AUCUN** marqueur `mode` / `drift` /
 * `drift_policy`. C'est ce qui prouve que l'item verrouillé entre dans le pipeline
 * de réapplication STRICT côté agent (moteur Go `provision.Reconcile`, réapplique
 * sur toute divergence de hash).
 *
 * NFR2 (AC#2) : aucune modification du moteur agent ni du contrat n'est introduite
 * par 29.5 ; la couverture STRICT côté Go (`agent/shared/handler_*_test.go`, 27.8)
 * est citée comme preuve de la réapplication (un item compilé est source-agnostique
 * — un item amont est, après compilation, de forme identique à un item local).
 * AUCUN nouveau test Go n'est requis.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». [Source: prd-contrat-manage-se5.md#R3]
 */
class UpstreamLockedDriftStrictTest extends TestCase
{
    use RefreshDatabase;

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
    }

    #[Test]
    public function locked_upstream_item_compiles_to_strict_four_key_item_without_drift_marker(): void
    {
        // Contrat actif imposant un item `registry`/`locked`/`instance` sur une clé
        // qui correspond aussi à une projection de capacité (même {hive,path,name}).
        $contract = ControlHubContract::factory()->create(); // link_state = active
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKCU|Software\\Cap|EnableLUA|REG_DWORD',
            'value' => '1',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);

        // Réglage LOCAL sur la MÊME clé, valeur DIVERGENTE (dérive simulée).
        $local = $this->keyedExclusiveProvider('registry', StateScope::Session, [
            new StateCandidate(
                StateMaille::LogicalGroup,
                $this->regPayload('HKCU', 'Software\\Cap', 'EnableLUA', 0),
                now(),
                1,
            ),
        ]);

        $items = $this->compileDecorated([$local])[StateContract::SCOPE_SESSION];

        self::assertCount(1, $items, 'une seule valeur par clé (exclusive)');

        $item = $items[0];

        // AC#1 : EXACTEMENT 4 clés, dans l'ordre du contrat 27.8.
        self::assertSame(
            ['type', 'semantics', 'payload', 'hash'],
            array_keys($item),
            'item de desired-state à 4 clés (STRICT implicite — 27.8)',
        );

        // AC#1 : AUCUN marqueur de mode/drift (réintroduire un marqueur = régression 27.8).
        self::assertArrayNotHasKey('mode', $item);
        self::assertArrayNotHasKey('drift', $item);
        self::assertArrayNotHasKey('drift_policy', $item);

        // AC#1 : l'item porte la VALEUR AMONT (la maille Upstream gagne, inbattable).
        self::assertSame(1, $item['payload']['value'], 'la valeur amont gagne (maille Upstream rang -1)');

        // Le hash est bien recalculé sur l'item à 4 clés (cohérence StateHasher).
        self::assertSame($this->hasher->hashItem($item), $item['hash']);
    }

    // ── Helpers (calque UpstreamContractResolutionTest) ───────────────────

    /**
     * @param  list<StateProvider>  $providers
     * @return array<string,mixed>
     */
    private function compileDecorated(array $providers): array
    {
        $source = new UpstreamContractSource([new RegistryUpstreamAdapter()]);
        $decorated = array_map(
            fn (StateProvider $p): StateProvider => UpstreamAwareProvider::wrap($p, $source),
            $providers,
        );

        return (new StateCompiler($this->hasher, $decorated, new AgentTtlResolver()))->compile($this->machineOnlyContext());
    }

    private function machineOnlyContext(): TargetContext
    {
        return TargetContext::for(Workstation::factory()->create(), null);
    }

    /**
     * @return array<string,mixed>
     */
    private function regPayload(string $hive, string $path, string $name, int $value): array
    {
        return [
            'hive' => $hive,
            'path' => $path,
            'name' => $name,
            'type' => 'REG_DWORD',
            'value' => $value,
        ];
    }

    /**
     * @param  list<StateCandidate>  $candidates
     */
    private function keyedExclusiveProvider(
        string $type,
        StateScope $scope,
        array $candidates,
    ): StateProvider {
        return new class($type, $scope, $candidates) implements KeyedExclusiveProvider, StateProvider
        {
            /** @param list<StateCandidate> $candidates */
            public function __construct(
                private readonly string $type,
                private readonly StateScope $scope,
                private readonly array $candidates,
            ) {}

            public function type(): string
            {
                return $this->type;
            }

            public function semantics(): ResourceSemantics
            {
                return ResourceSemantics::Exclusive;
            }

            public function scope(): StateScope
            {
                return $this->scope;
            }

            public function exclusiveKey(array $payload): string
            {
                return strtolower(($payload['hive'] ?? '').'|'.($payload['path'] ?? '').'|'.($payload['name'] ?? ''));
            }

            public function itemsFor(TargetContext $ctx): Collection
            {
                return collect($this->candidates);
            }
        };
    }
}
