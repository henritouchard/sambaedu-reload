<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\AgentResourceStatus;
use App\Enums\ResourceSemantics;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit du contrat v1 figé `se5.desired-state/v1`.
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
    // Le jumeau Go `agent/shared/hasher_test.go::frozenStateHash` doit porter la
    // MÊME valeur : c'est le test croisé qui prouve que les deux
    // canonicalisations (PHP et Go) produisent des octets identiques.
    //
    // Le contrat v1 n'évolue qu'en ADDITIF : un agent plus ancien ignore en
    // silence un type ou un champ de payload inconnu, sans rien remonter au
    // rapport. Une addition ne casse donc rien, mais elle reste sans effet tant
    // que la release agent qui la lit n'est pas publiée : on publie AVANT de
    // retrofiter les données. `report.v1.json` ne bouge pas pour autant — les
    // items de rapport `{type, status, hash[, detail]}` ne portent aucun payload,
    // seul `Rule::in(RESOURCE_TYPES)` d'ingestion s'ouvre au nouveau type.
    //
    // `generated_at` et `ttl_seconds` sont exclus du hash
    // ({@see StateHasher::VOLATILE_STATE_KEYS}) : le TTL dépend du contexte
    // compilé, et un TTL qui change seul ne doit pas invalider l'ETag.
    private const FROZEN_STATE_HASH = '8940e34ff63824c37bad3b2e22d9151016d1661f90a099cc6736977690ac4e7e';

    private StateHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new StateHasher;
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

        // La portée `machine` porte désormais l'item overlay
        // `{kind:"machine", room}` (salle préchargée au logon) : elle n'est plus
        // le « tableau vide » illustratif. Le contrat tolère toujours une portée
        // vide (les trois sont des listes, éventuellement vides — vérifié
        // ci-dessus) ; le golden illustre maintenant les trois portées peuplées.
        $this->assertNotSame([], $state[StateContract::SCOPE_MACHINE]);
        $this->assertSame('overlay', $state[StateContract::SCOPE_MACHINE][0]['type']);
        $this->assertSame('machine', $state[StateContract::SCOPE_MACHINE][0]['payload']['kind']);
    }

    #[Test]
    public function every_state_item_has_the_four_contract_keys_and_valid_enums(): void
    {
        $state = $this->loadGolden('state.v1.json');

        $itemCount = 0;
        foreach (StateContract::scopes() as $scope) {
            foreach ($state[$scope] as $item) {
                $itemCount++;

                // Exactement les 4 clés du contrat, ni plus ni moins : pas de
                // clé `mode`, la convergence est STRICTE et inconditionnelle.
                $this->assertSame(
                    ['type', 'semantics', 'payload', 'hash'],
                    array_keys($item),
                    "item de portée {$scope} : clés non conformes",
                );

                $this->assertIsString($item['type']);
                $this->assertNotNull(ResourceSemantics::tryFrom($item['semantics']));
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
    public function report_golden_file_has_valid_structure_and_three_statuses(): void
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

            // Un statut `error` doit porter un `detail` non vide.
            if ($status === AgentResourceStatus::Error) {
                $this->assertArrayHasKey('detail', $item);
                $this->assertNotSame('', $item['detail']);
            }
        }

        // Les trois statuts sont illustrés ( : `drifted_allowed`
        // retiré → compliant, drift, error).
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
