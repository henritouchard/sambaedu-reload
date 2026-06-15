<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\AgentResourceStatus;
use App\Enums\ResourceSemantics;
use App\Enums\StateMode;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit du contrat v1 figé `se5.desired-state/v1` — Story 23.1 (AC1, AC2,
 * AC3, AC5).
 *
 * Garde-fous de régression sur les **golden files** normatifs
 * `tests/Fixtures/Agent/{state,report}.v1.json` : structure, énumérations,
 * cohérence des hashes et hash d'état figé. Toute dérive de canonicalisation ou
 * d'invariant de contrat casse ces tests (effet recherché — le wire format est
 * un irréversible).
 */
class ContractV1Test extends TestCase
{
    /**
     * Hash d'état figé du golden file `state.v1.json` (calculé par
     * {@see StateHasher::hashState}). Garde-fou : toute évolution du golden
     * file ou de la canonicalisation doit mettre cette valeur à jour
     * sciemment (+ bump de version, cf. règle d'évolution du contrat).
     */
    // Bumpé SCIEMMENT par la Story 27.1 (évolution MINEURE du contrat, §9) :
    // le payload `shortcuts` du golden est passé du squelette illustratif
    // (`{name, target, location}`) au payload v1 RÉEL owné par
    // `ShortcutsStateProvider` (`{name, target, args, icon, place,
    // desktop_path}`). Champ/payload ajouté = forward-compatible, pas un major.
    //
    // Re-bumpé SCIEMMENT (mode debug du poste, §9) : ajout du champ d'enveloppe
    // `debug` (bool) à côté de `ttl_seconds`. Champ ajouté = forward-compatible
    // (l'agent ignore les champs d'enveloppe inconnus) ; il entre dans le hash
    // pour que le toggle franchisse le cache 304.
    //
    // Re-bumpé SCIEMMENT par la Story 27.2 (évolution MINEURE du contrat, §9) :
    // ajout de DEUX items réels en portée `session` — `printers` (payload v1
    // `{cups_name, connection, description, location, is_default}` owné par
    // PrintersStateProvider) et `drives` (payload v1 `{letter, unc, label}` owné
    // par DrivesStateProvider). Types DÉJÀ figés §7 ; payloads ajoutés =
    // forward-compatible, pas un major. Le jumeau Go (hasher_test.go) est bumpé
    // à la même valeur (test croisé NFR13).
    private const FROZEN_STATE_HASH = 'fe4cb1216da04ab7ad02215e6958251b72174d62923d545013b54487619c174e';

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher();
    }

    #[Test]
    public function state_golden_file_has_valid_envelope_and_scopes(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $this->assertSame(StateContract::SCHEMA, $state['schema']);
        $this->assertArrayHasKey('generated_at', $state);
        $this->assertArrayHasKey('ttl_seconds', $state);
        $this->assertIsInt($state['ttl_seconds']);

        // Mode debug du poste — champ d'enveloppe (bool), à côté de ttl_seconds.
        $this->assertArrayHasKey('debug', $state);
        $this->assertIsBool($state['debug']);

        // Les trois portées sont présentes et sont des listes ordonnées
        // (une map changerait l'ordre canonique via le tri des clés).
        foreach (StateContract::scopes() as $scope) {
            $this->assertArrayHasKey($scope, $state, "portée manquante: {$scope}");
            $this->assertIsArray($state[$scope]);
            $this->assertTrue(array_is_list($state[$scope]), "portée {$scope} : doit être une liste, pas une map");
        }

        // AC1 — une portée illustre le « tableau vide » (rien à faire).
        $this->assertSame([], $state[StateContract::SCOPE_MACHINE]);
    }

    #[Test]
    public function every_state_item_has_the_five_contract_keys_and_valid_enums(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $itemCount = 0;
        foreach (StateContract::scopes() as $scope) {
            foreach ($state[$scope] as $item) {
                $itemCount++;

                // AC1 — exactement les 5 clés du contrat, ni plus ni moins.
                $this->assertSame(
                    ['type', 'semantics', 'mode', 'payload', 'hash'],
                    array_keys($item),
                    "item de portée {$scope} : clés non conformes",
                );

                $this->assertIsString($item['type']);
                $this->assertNotNull(ResourceSemantics::tryFrom($item['semantics']));
                $this->assertNotNull(StateMode::tryFrom($item['mode']));
                $this->assertIsArray($item['payload']);
            }
        }

        $this->assertGreaterThan(0, $itemCount, 'le golden state doit porter des items');
    }

    #[Test]
    public function each_state_item_hash_matches_state_hasher(): void
    {
        $state = $this->loadGolden('state.v1.json');

        foreach (StateContract::scopes() as $scope) {
            foreach ($state[$scope] as $item) {
                $this->assertSame(
                    $this->hasher->hashItem($item),
                    $item['hash'],
                    "hash incohérent pour l'item {$item['type']} (portée {$scope})",
                );
            }
        }
    }

    #[Test]
    public function state_hash_is_frozen_regression_guard(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $this->assertSame(
            self::FROZEN_STATE_HASH,
            $this->hasher->hashState($state),
            'Le hash du golden state a changé : dérive de canonicalisation ou '
            .'évolution de contrat non versionnée.',
        );
    }

    #[Test]
    public function report_golden_file_has_valid_structure_and_four_statuses(): void
    {
        $report = $this->loadGolden('report.v1.json');

        $this->assertSame(StateContract::SCHEMA, $report['schema']);
        $this->assertArrayHasKey('generated_at', $report);
        $this->assertArrayHasKey('agent_version', $report);
        $this->assertIsString($report['agent_version']);

        // Identité du poste (gap 2) : hostname + uuid (contrat §6).
        $this->assertArrayHasKey('workstation', $report);
        $this->assertArrayHasKey('hostname', $report['workstation']);
        $this->assertArrayHasKey('uuid', $report['workstation']);

        $this->assertIsArray($report['items']);

        $statuses = [];
        foreach ($report['items'] as $item) {
            $this->assertArrayHasKey('type', $item);
            $this->assertArrayHasKey('status', $item);
            $this->assertArrayHasKey('hash', $item);

            $status = AgentResourceStatus::tryFrom($item['status']);
            $this->assertNotNull($status, "statut inconnu: {$item['status']}");
            $statuses[$item['status']] = true;

            // AC3 — un statut `error` doit porter un `detail` non vide.
            if ($status === AgentResourceStatus::Error) {
                $this->assertArrayHasKey('detail', $item);
                $this->assertNotSame('', $item['detail']);
            }
        }

        // AC3 — les quatre statuts sont illustrés.
        foreach (AgentResourceStatus::cases() as $case) {
            $this->assertArrayHasKey(
                $case->value,
                $statuses,
                "statut non illustré dans le golden report: {$case->value}",
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function loadGolden(string $name): array
    {
        $path = base_path("tests/Fixtures/Agent/{$name}");

        return json_decode(
            file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
