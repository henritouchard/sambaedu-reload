<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Events\ControlHubContractChanged;
use App\Exceptions\ControlHub\UnsupportedSchemaVersionException;
use App\Models\ControlHubContract;
use App\Services\ControlHub\ControlHubContractIngestionService;
use App\Services\ControlHub\ControlHubContractSchema;
use App\Services\ControlHub\Data\ContractIngestionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Story 33.1 — Schéma d'ÉCHANGE versionné du contrat amont (controlHub ↔ SE5).
 *
 * Couverture :
 * - #1/#2 payload conforme (version supportée) → accepté + version enregistrée (modèle + DTO).
 * - #3 payload SANS version → accepté, version par défaut = version courante (rétro-compat 28.2).
 * - #4 réception identique (même version) → no-op total : aucune écriture, aucun event (NFR4).
 * - #5 changement de version supportée → mutation (event 1×) — conditionnel ≥ 2 versions supportées.
 * - #7d garde-fou R3 : aucun identifiant livré par 33.1 ne contient « central ».
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM (sans pdo_sqlite).
 * ⚠️ Idempotence mesurée par comptage de lignes + `mutated` + dispatch d'event + timestamps
 *    (PAS par contrainte de chaîne / longueur varchar — non appliquée par SQLite).
 */
class ControlHubContractSchemaVersionTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ControlHubContractIngestionService
    {
        return new ControlHubContractIngestionService();
    }

    /**
     * Payload de référence (calque 28.2) ; `$overrides` permet d'ajouter/retirer `schema_version`.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'items' => [
                ['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
            ],
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
            'catalog_apps' => [
                ['app_key' => 'firefox', 'display_name' => 'Firefox'],
            ],
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #1 / #2 — Version conforme acceptée + enregistrée (modèle + DTO)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_conforming_version_accepted_and_recorded(): void
    {
        $result = $this->service()->ingest($this->payload([
            'schema_version' => ControlHubContractSchema::CURRENT_VERSION,
        ]));

        // Persistance 28.2 inchangée.
        $this->assertTrue($result->contractCreated);
        $this->assertTrue($result->mutated);
        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertDatabaseCount('controlhub_contract_items', 1);

        // AC #2(b) — version lisible sur le DTO.
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, $result->schemaVersion);

        // AC #2(a) — version lisible sur le modèle (colonne + property).
        $contract = ControlHubContract::firstOrFail();
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, $contract->schema_version);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #3 — Version absente → défaut = version courante (rétro-compat 28.2)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_absent_version_defaults_to_current(): void
    {
        // Payload 28.2 « historique » : aucune clé schema_version.
        $payload = $this->payload();
        $this->assertArrayNotHasKey('schema_version', $payload);

        $result = $this->service()->ingest($payload);

        $this->assertTrue($result->mutated);
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, $result->schemaVersion);
        $this->assertSame(
            ControlHubContractSchema::CURRENT_VERSION,
            ControlHubContract::firstOrFail()->schema_version,
        );
    }

    public function test_blank_version_defaults_to_current(): void
    {
        // Une chaîne vide est traitée comme absente (négociation tolérante 33.1).
        $result = $this->service()->ingest($this->payload(['schema_version' => '']));

        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, $result->schemaVersion);
        $this->assertSame(
            ControlHubContractSchema::CURRENT_VERSION,
            ControlHubContract::firstOrFail()->schema_version,
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #4 — Réception identique (même version) = no-op total (NFR4)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_identical_reception_is_noop_with_version(): void
    {
        $payload = $this->payload(['schema_version' => ControlHubContractSchema::CURRENT_VERSION]);

        $this->service()->ingest($payload);

        $before = ControlHubContract::firstOrFail();
        $receivedAtBefore = $before->received_at->toISOString();
        $updatedAtBefore = $before->updated_at->toISOString();
        $versionBefore = $before->schema_version;

        // Avancer le temps : toute écriture changerait les timestamps.
        Carbon::setTestNow(now()->addHour());

        Event::fake();
        $result = $this->service()->ingest($payload);

        // No-op fonctionnel : enregistrer la version ne doit PAS transformer une réception
        // identique en mutation (le cœur du risque de la story — NFR4).
        $this->assertFalse($result->mutated, 'Une réception identique (même version) doit rester un no-op.');
        Event::assertNotDispatched(ControlHubContractChanged::class);

        // Le DTO expose toujours la version négociée (observabilité), même sur no-op.
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, $result->schemaVersion);

        // Aucune écriture : timestamps + version inchangés.
        $after = ControlHubContract::firstOrFail();
        $this->assertSame($receivedAtBefore, $after->received_at->toISOString());
        $this->assertSame($updatedAtBefore, $after->updated_at->toISOString());
        $this->assertSame($versionBefore, $after->schema_version);

        Carbon::setTestNow();
    }

    public function test_identical_reception_without_version_then_with_current_is_noop(): void
    {
        // 1re réception SANS version (enregistre la version courante par défaut).
        $this->service()->ingest($this->payload());

        $before = ControlHubContract::firstOrFail();
        $receivedAtBefore = $before->received_at->toISOString();
        $updatedAtBefore = $before->updated_at->toISOString();

        Carbon::setTestNow(now()->addHour());

        // 2e réception déclarant EXPLICITEMENT la version courante : sémantiquement identique
        // à la version enregistrée par défaut ⇒ toujours un no-op (pas de fausse mutation).
        Event::fake();
        $result = $this->service()->ingest($this->payload([
            'schema_version' => ControlHubContractSchema::CURRENT_VERSION,
        ]));

        $this->assertFalse($result->mutated);
        Event::assertNotDispatched(ControlHubContractChanged::class);

        // Aucune écriture : received_at ET updated_at inchangés (symétrie avec le no-op explicite).
        $after = ControlHubContract::firstOrFail();
        $this->assertSame($receivedAtBefore, $after->received_at->toISOString());
        $this->assertSame($updatedAtBefore, $after->updated_at->toISOString());

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #5 — Changement de version supportée = mutation (event 1×)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_version_change_is_mutation(): void
    {
        // CONDITIONNEL : ce scénario n'est observable que s'il existe ≥ 2 versions supportées.
        // En 33.1, SUPPORTED_VERSIONS ne contient que la version courante (Q2 fige une version
        // unique). On NE fabrique PAS de 2ᵉ version factice (consigne story). La LOGIQUE de
        // « changement de version = mutation » est néanmoins vérifiée :
        //   - directement, ci-dessous, dès qu'une 2ᵉ version existe ;
        //   - par construction sinon (le gating $mutated intègre `schema_version !== négociée`,
        //     calqué sur received_at/link_state — voir ingest(), et test no-op AC #4 qui prouve
        //     l'autre branche : version identique ⇒ aucune mutation).
        $others = array_values(array_filter(
            ControlHubContractSchema::SUPPORTED_VERSIONS,
            static fn (string $v): bool => $v !== ControlHubContractSchema::CURRENT_VERSION,
        ));

        if ($others === []) {
            $this->assertCount(
                1,
                ControlHubContractSchema::SUPPORTED_VERSIONS,
                'En 33.1, une seule version est supportée ; le changement de version est couvert par construction (gating $mutated) et par revue de code.',
            );

            return;
        }

        // ≥ 2 versions supportées : le changement de version (contenu sinon identique) mute.
        $this->service()->ingest($this->payload([
            'schema_version' => ControlHubContractSchema::CURRENT_VERSION,
        ]));

        Event::fake();
        $result = $this->service()->ingest($this->payload([
            'schema_version' => $others[0],
        ]));

        $this->assertTrue($result->mutated, 'Un changement de version supportée doit être une mutation.');
        $this->assertFalse($result->contractCreated, 'Le contrat actif est réutilisé (singleton).');
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);
        $this->assertSame($others[0], ControlHubContract::firstOrFail()->schema_version);
    }

    public function test_version_change_from_legacy_null_is_mutation(): void
    {
        // Exerce DIRECTEMENT la branche `$versionChanged = true` du service SANS fabriquer de 2ᵉ
        // version supportée : on simule un contrat « legacy » antérieur à 33.1 (colonne nullable
        // ⇒ schema_version = NULL en base), puis on ré-ingère un contenu STRICTEMENT identique.
        // Seule la version diffère (null → version courante) ⇒ mutation légitime (AC #5) + event 1×.
        // Couvre aussi le 1er passage post-migration d'un contrat 28.2 (acquisition de sa version).
        $payload = $this->payload(['schema_version' => ControlHubContractSchema::CURRENT_VERSION]);
        $this->service()->ingest($payload);

        // Rétrograder silencieusement la version persistée à NULL (état d'un contrat legacy 28.2).
        // Mass-update via query builder : ne bump PAS updated_at (pas de fausse mutation injectée).
        ControlHubContract::query()->update(['schema_version' => null]);
        $this->assertNull(ControlHubContract::firstOrFail()->schema_version);

        Carbon::setTestNow(now()->addHour());

        Event::fake();
        $result = $this->service()->ingest($payload);

        // Le changement de version (null → courante) — contenu par ailleurs identique — est une mutation.
        $this->assertTrue($result->mutated, 'Une version qui change (legacy null → courante) doit muter.');
        $this->assertFalse($result->contractCreated, 'Le contrat actif est réutilisé (singleton), pas recréé.');
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, ControlHubContract::firstOrFail()->schema_version);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Référentiel de version — négociation (conforme / absente)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_negotiate_resolves_conforming_and_absent(): void
    {
        // Absente / vide → version courante (Q1=A).
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, ControlHubContractSchema::negotiate(null));
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, ControlHubContractSchema::negotiate(''));

        // Supportée → elle-même.
        $this->assertSame(
            ControlHubContractSchema::CURRENT_VERSION,
            ControlHubContractSchema::negotiate(ControlHubContractSchema::CURRENT_VERSION),
        );

        // La version courante est, par construction, supportée.
        $this->assertTrue(ControlHubContractSchema::isSupported(ControlHubContractSchema::CURRENT_VERSION));
    }

    public function test_unsupported_version_is_rejected_and_logged(): void
    {
        // Story 33.2 — bascule du repli au REJET strict : une version DÉCLARÉE non supportée
        // n'est plus tolérée (plus de retour CURRENT_VERSION). negotiate() trace l'écart puis
        // lève l'exception dédiée. (Le no-op/écriture est couvert dans UnsupportedSchemaVersionRejectionTest.)
        Log::spy();

        try {
            ControlHubContractSchema::negotiate('99.0');
            $this->fail('Une version déclarée non supportée doit être rejetée.');
        } catch (UnsupportedSchemaVersionException $e) {
            $this->assertSame('99.0', $e->declared());
            $this->assertSame(ControlHubContractSchema::SUPPORTED_VERSIONS, $e->supported());
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'non supportée')
                && ($context['declared'] ?? null) === '99.0'
                && ($context['supported'] ?? null) === ControlHubContractSchema::SUPPORTED_VERSIONS;
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #7d — Garde-fou R3 : aucun identifiant livré ne contient « central »
    // ──────────────────────────────────────────────────────────────────────────

    public function test_r3_no_central_identifier(): void
    {
        $deliveredFqcns = [
            ControlHubContractSchema::class,
            ControlHubContractIngestionService::class,
            ContractIngestionResult::class,
            ControlHubContract::class,
        ];

        foreach ($deliveredFqcns as $fqcn) {
            $this->assertStringNotContainsStringIgnoringCase('central', $fqcn, "FQCN « {$fqcn} » ne doit pas contenir « central ».");

            $reflection = new \ReflectionClass($fqcn);
            foreach ($reflection->getMethods() as $method) {
                $this->assertStringNotContainsStringIgnoringCase('central', $method->getName(), "Méthode {$fqcn}::{$method->getName()} ne doit pas contenir « central ».");
            }
            foreach ($reflection->getProperties() as $property) {
                $this->assertStringNotContainsStringIgnoringCase('central', $property->getName());
            }
            foreach ($reflection->getConstants() as $constName => $constValue) {
                $this->assertStringNotContainsStringIgnoringCase('central', (string) $constName);
                // La VALEUR des constantes livrées (ex. CURRENT_VERSION, SUPPORTED_VERSIONS) ne doit
                // pas non plus véhiculer « central » (R3 vise aussi les valeurs, pas que les noms).
                foreach (\Illuminate\Support\Arr::flatten([$constValue]) as $constLeaf) {
                    if (is_string($constLeaf)) {
                        $this->assertStringNotContainsStringIgnoringCase('central', $constLeaf, "Valeur de {$fqcn}::{$constName} ne doit pas contenir « central ».");
                    }
                }
            }
        }

        // La colonne livrée elle-même ne doit pas contenir « central ».
        $this->assertStringNotContainsStringIgnoringCase('central', 'schema_version');
    }
}
