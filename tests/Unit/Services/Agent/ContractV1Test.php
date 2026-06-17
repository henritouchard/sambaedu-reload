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
    //
    // Re-bumpé SCIEMMENT par la Story 27.7 (évolution MINEURE du contrat, §9) :
    // le payload `shortcuts` du golden gagne `{icon_asset, icon_checksum}`
    // (icône UPLOADÉE content-addressed, AC2/AC6) ET illustre une icône uploadée
    // (nom nu `icon`). Champs AJOUTÉS = forward-compatible, pas un major. Le
    // jumeau Go (hasher_test.go::frozenStateHash) est bumpé à la même valeur
    // (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 27.8 (§9) : la clé `mode` est RETIRÉE de
    // chaque item d'état (item 5 clés → 4 : type/semantics/payload/hash —
    // convergence STRICT inconditionnelle). Le hash de chaque item ET le hash
    // d'état changent. Bumpé à l'IDENTIQUE côté Go (hasher_test.go::frozenStateHash).
    //
    // Re-bumpé SCIEMMENT par la Story 27.10 (§9) : la SALLE passe de la portée
    // session (ancien item identity `{kind, login, fullname, room}`) à la portée
    // MACHINE — nouvel item overlay `{kind:"machine", room}` (cache persistant,
    // préchargement poste+salle au logon). L'item identity session perd `room`.
    //
    // Re-bumpé SCIEMMENT par la Story 27.3 (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `registry` en portée `session` — payload v1 réel
    // `{hive, path, name, type, value}` owné par les providers registry
    // (RegistryMachineStateProvider HKLM / RegistryUserStateProvider HKCU). Type
    // DÉJÀ figé §7 ; payload ajouté = forward-compatible, pas un major.
    //
    // Rebase 27.3 sur main (27.10 inclus) : le golden combine désormais l'item
    // overlay machine-scope (room) ET l'item registry session → 7 items, hash
    // d'état RECALCULÉ. Le jumeau Go (hasher_test.go::frozenStateHash) porte la
    // même valeur (test croisé NFR13 — canonicalisation équivalente PHP↔Go).
    //
    // Re-bumpé SCIEMMENT par la Story 27.3bis (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `associations` en portée `session` — payload v1 réel
    // `{identifier, progid, type}` owné par AssociationsStateProvider. Le hash
    // UserChoice anti-tamper N'EST JAMAIS au payload (calculé 100 % côté agent à
    // partir du SID/temps/experience du poste). Type DÉJÀ figé §7 ; payload
    // ajouté = forward-compatible, pas un major → 8 items, hash d'état RECALCULÉ.
    // Le jumeau Go porte la même valeur (test croisé NFR13).
    //
    // Re-bumpé SCIEMMENT par la Story 27.4 (évolution MINEURE du contrat, §9) :
    // ajout d'UN item `app_config` (aggregate) — payload v1 réel
    // `{app_kind, policies}` owné par AppConfigStateProvider (projection des
    // policies résolues `policies.json` Firefox/Thunderbird, story 4.8). Les
    // policies sont CONCRÈTES (jamais un id de scope/customization), sans float
    // (§4.1). Type DÉJÀ figé §7 ; payload ajouté = forward-compatible, pas un
    // major → 9 items, hash d'état RECALCULÉ. Le jumeau Go
    // (hasher_test.go::frozenStateHash) porte la même valeur (test croisé NFR13).
    //
    // Correctif post-review 2026-06-17 (review #1) : l'item `app_config` passe de
    // la portée `session` à la portée `machine` — `policies.json` est
    // machine-wide (admin-write, écrit par le service SYSTEM), résolu PAR PARC
    // (niveaux 1-4, `$user = null`). Le par-user de Firefox = le profil
    // (Mécanisme B / roaming, hors 27.4). Le déplacement de portée RECALCULE le
    // hash d'état (machine = 2 items, session = 6) ; le jumeau Go porte la même
    // valeur (test croisé NFR13).
    private const FROZEN_STATE_HASH = '6f0ff33e8ea114d28f67094042bea656a68d6cfdafa01ee6ad9f9537dff377fb';

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

        // Story 27.10 — la portée `machine` porte désormais l'item overlay
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

                // AC1 — exactement les 4 clés du contrat, ni plus ni moins
                // (Story 27.8 : la clé `mode` est retirée — STRICT inconditionnel).
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

            // AC3 — un statut `error` doit porter un `detail` non vide.
            if ($status === AgentResourceStatus::Error) {
                $this->assertArrayHasKey('detail', $item);
                $this->assertNotSame('', $item['detail']);
            }
        }

        // AC3 — les trois statuts sont illustrés (Story 27.8 : `drifted_allowed`
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
