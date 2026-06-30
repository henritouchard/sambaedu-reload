<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Events\ControlHubContractChanged;
use App\Exceptions\ControlHub\InvalidUpstreamContractException;
use App\Exceptions\ControlHub\UnsupportedSchemaVersionException;
use App\Models\ControlHubContract;
use App\Services\ControlHub\ControlHubContractIngestionService;
use App\Services\ControlHub\ControlHubContractSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Story 33.2 — Négociation et rejet gracieux d'une version de schéma d'échange incompatible.
 *
 * Couverture :
 * - #1 version déclarée non supportée → ingestion rejetée (exception dédiée, pas de DTO).
 * - #1/#2 zéro écriture : comptes des 5 tables inchangés + aucun event `ControlHubContractChanged`.
 * - #2 état d'un contrat pré-existant strictement inchangé (rollback total trivial, pré-transaction).
 * - #3 trace : message « reçue vs supportées » + log structuré `{declared, supported}`.
 * - #5 type DÉDIÉ distinct d'`InvalidUpstreamContractException` (rejet VERSION ≠ rejet CONTENU).
 * - #4 chemin heureux 33.1 inchangé (supportée acceptée, absente → version courante).
 * - #7c garde-fou R3 : aucun identifiant/message livré ne contient « central ».
 *
 * ⚠️ Tests sur HÔTE (php8.4 + pdo_sqlite) — JAMAIS sur la VM. RefreshDatabase + CACHE_DRIVER=array.
 */
class UnsupportedSchemaVersionRejectionTest extends TestCase
{
    use RefreshDatabase;

    /** Les 5 tables du contrat amont — leur comptage prouve le « rien écrit » (AC #1/#2). */
    private const CONTRACT_TABLES = [
        'controlhub_contracts',
        'controlhub_contract_items',
        'controlhub_contract_labels',
        'controlhub_contract_imposed_groups',
        'controlhub_contract_catalog_apps',
    ];

    private function service(): ControlHubContractIngestionService
    {
        return new ControlHubContractIngestionService();
    }

    /**
     * Payload de référence (calque 33.1) ; `$overrides` permet d'ajouter `schema_version`.
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

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (self::CONTRACT_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #1 — Version déclarée non supportée → ingestion rejetée
    // ──────────────────────────────────────────────────────────────────────────

    public function test_unsupported_declared_version_is_rejected(): void
    {
        $this->expectException(UnsupportedSchemaVersionException::class);

        $this->service()->ingest($this->payload(['schema_version' => '2.0']));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #1 / #2 — Rejet n'écrit RIEN (5 tables + aucun event)
    // ──────────────────────────────────────────────────────────────────────────

    public function test_rejection_writes_nothing(): void
    {
        Event::fake();

        $before = $this->tableCounts();

        try {
            $this->service()->ingest($this->payload(['schema_version' => '2.0']));
            $this->fail('Une version non supportée doit lever UnsupportedSchemaVersionException.');
        } catch (UnsupportedSchemaVersionException) {
            // attendu — on vérifie l'absence d'effet de bord ci-dessous.
        }

        $this->assertSame($before, $this->tableCounts(), 'Le rejet ne doit créer/modifier/supprimer aucune ligne.');

        // Toutes les tables sont restées vides (greenfield) : aucune écriture partielle.
        foreach (self::CONTRACT_TABLES as $table) {
            $this->assertSame(0, $this->tableCounts()[$table], "Table {$table} doit rester vide.");
        }

        Event::assertNotDispatched(ControlHubContractChanged::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #2 — Un contrat pré-existant reste strictement inchangé
    // ──────────────────────────────────────────────────────────────────────────

    public function test_rejection_leaves_existing_contract_unchanged(): void
    {
        // 1. Contrat valide pré-existant (chemin heureux 33.1).
        $this->service()->ingest($this->payload(['schema_version' => ControlHubContractSchema::CURRENT_VERSION]));

        $before = ControlHubContract::firstOrFail();
        $schemaVersionBefore = $before->schema_version;
        $receivedAtBefore = $before->received_at->toISOString();
        $updatedAtBefore = $before->updated_at->toISOString();
        $linkStateBefore = $before->link_state->value;
        $countsBefore = $this->tableCounts();
        // Review 33.2 (#3) — snapshot des VALEURS + timestamps enfants (pas seulement les décomptes) :
        // un hypothétique delete+reinsert identique passerait un simple comptage. On fige l'item.
        $itemsBefore = DB::table('controlhub_contract_items')->orderBy('id')->get()->toArray();

        // Avancer le temps : toute écriture changerait les timestamps.
        Carbon::setTestNow(now()->addHour());

        Event::fake();

        // 2. Réception d'une version non supportée → rejet.
        try {
            $this->service()->ingest($this->payload(['schema_version' => '9.9']));
            $this->fail('Une version non supportée doit lever UnsupportedSchemaVersionException.');
        } catch (UnsupportedSchemaVersionException) {
            // attendu.
        }

        // 3. État persisté strictement inchangé.
        $after = ControlHubContract::firstOrFail();
        $this->assertSame($schemaVersionBefore, $after->schema_version);
        $this->assertSame($receivedAtBefore, $after->received_at->toISOString());
        $this->assertSame($updatedAtBefore, $after->updated_at->toISOString());
        $this->assertSame($linkStateBefore, $after->link_state->value);
        $this->assertSame($countsBefore, $this->tableCounts(), 'Les agrégats enfants sont inchangés.');
        // Valeurs + timestamps des enfants strictement identiques (immuabilité, pas seulement cardinalité).
        $this->assertEquals(
            $itemsBefore,
            DB::table('controlhub_contract_items')->orderBy('id')->get()->toArray(),
            'Les valeurs et timestamps des items enfants doivent être strictement inchangés.',
        );

        Event::assertNotDispatched(ControlHubContractChanged::class);

        Carbon::setTestNow();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #3 — Trace : message « reçue vs supportées » + log structuré
    // ──────────────────────────────────────────────────────────────────────────

    public function test_exception_message_names_received_and_supported(): void
    {
        try {
            $this->service()->ingest($this->payload(['schema_version' => '2.0']));
            $this->fail('Attendu : UnsupportedSchemaVersionException.');
        } catch (UnsupportedSchemaVersionException $e) {
            // Nomme la version reçue.
            $this->assertStringContainsString('2.0', $e->getMessage());
            // Nomme les versions supportées.
            foreach (ControlHubContractSchema::SUPPORTED_VERSIONS as $supported) {
                $this->assertStringContainsString($supported, $e->getMessage());
            }
            // Données structurées accessibles.
            $this->assertSame('2.0', $e->declared());
            $this->assertSame(ControlHubContractSchema::SUPPORTED_VERSIONS, $e->supported());
        }
    }

    public function test_rejection_is_logged_with_declared_and_supported(): void
    {
        Log::spy();

        try {
            $this->service()->ingest($this->payload(['schema_version' => '2.0']));
            $this->fail('Une version non supportée doit lever UnsupportedSchemaVersionException.');
        } catch (UnsupportedSchemaVersionException) {
            // attendu.
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'non supportée')
                && ($context['declared'] ?? null) === '2.0'
                && ($context['supported'] ?? null) === ControlHubContractSchema::SUPPORTED_VERSIONS;
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #5 — Type DÉDIÉ, distinct d'InvalidUpstreamContractException
    // ──────────────────────────────────────────────────────────────────────────

    public function test_dedicated_type_distinct_from_invalid_contract(): void
    {
        // L'exception de version n'est PAS une exception de contenu (et réciproquement).
        $this->assertFalse(
            is_a(UnsupportedSchemaVersionException::class, InvalidUpstreamContractException::class, true),
            'Le rejet de VERSION ne doit pas être un sous-type du rejet de CONTENU.',
        );
        $this->assertFalse(
            is_a(InvalidUpstreamContractException::class, UnsupportedSchemaVersionException::class, true),
        );

        // Un payload contenu-invalide (enum hors domaine) lève toujours l'exception de CONTENU,
        // pas celle de version (la version y est supportée).
        try {
            $this->service()->ingest($this->payload([
                'schema_version' => ControlHubContractSchema::CURRENT_VERSION,
                'items' => [
                    ['type' => 'capabilities', 'key' => 'cap_x', 'enforcement_state' => 'WAT', 'target_type' => 'instance'],
                ],
            ]));
            $this->fail('Attendu : InvalidUpstreamContractException (contenu hors domaine).');
        } catch (InvalidUpstreamContractException $e) {
            $this->assertNotInstanceOf(UnsupportedSchemaVersionException::class, $e);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #4 — Chemin heureux 33.1 strictement inchangé
    // ──────────────────────────────────────────────────────────────────────────

    public function test_happy_path_unchanged(): void
    {
        // Version supportée → acceptée + enregistrée.
        $result = $this->service()->ingest($this->payload([
            'schema_version' => ControlHubContractSchema::CURRENT_VERSION,
        ]));
        $this->assertTrue($result->contractCreated);
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, $result->schemaVersion);
        $this->assertSame(
            ControlHubContractSchema::CURRENT_VERSION,
            ControlHubContract::firstOrFail()->schema_version,
        );

        // Version absente → défaut = version courante (négociation directe).
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, ControlHubContractSchema::negotiate(null));
        $this->assertSame(ControlHubContractSchema::CURRENT_VERSION, ControlHubContractSchema::negotiate(''));
        // Version supportée → elle-même (aucun rejet).
        $this->assertSame(
            ControlHubContractSchema::CURRENT_VERSION,
            ControlHubContractSchema::negotiate(ControlHubContractSchema::CURRENT_VERSION),
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AC #7c — Garde-fou R3 : aucun identifiant/message livré ne contient « central »
    // ──────────────────────────────────────────────────────────────────────────

    public function test_r3_no_central_identifier(): void
    {
        $fqcn = UnsupportedSchemaVersionException::class;
        $this->assertStringNotContainsStringIgnoringCase('central', $fqcn);

        $reflection = new \ReflectionClass($fqcn);
        foreach ($reflection->getMethods() as $method) {
            $this->assertStringNotContainsStringIgnoringCase('central', $method->getName());
        }
        foreach ($reflection->getProperties() as $property) {
            $this->assertStringNotContainsStringIgnoringCase('central', $property->getName());
        }

        // Le message livré au rejet ne véhicule pas « central ».
        $message = UnsupportedSchemaVersionException::for('2.0', ControlHubContractSchema::SUPPORTED_VERSIONS)->getMessage();
        $this->assertStringNotContainsStringIgnoringCase('central', $message);

        // Review 33.2 (#7) — le message du Log::warning de negotiate() ne véhicule pas non plus « central ».
        Log::spy();
        try {
            $this->service()->ingest($this->payload(['schema_version' => '2.0']));
        } catch (UnsupportedSchemaVersionException) {
            // attendu.
        }
        Log::shouldHaveReceived('warning')->withArgs(function (string $logMessage): bool {
            return stripos($logMessage, 'central') === false;
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Review 33.2 (#5) — schema_version numérique DÉCLARÉ : pas de fausse acceptation
    // ──────────────────────────────────────────────────────────────────────────

    public function test_numeric_declared_version_is_negotiated_not_silently_accepted(): void
    {
        // Un float JSON (ex. 2.0) ne doit PAS retomber sur la version courante par défaut :
        // coercé en chaîne, il est rejeté comme toute version déclarée non supportée (AC #1).
        try {
            $this->service()->ingest($this->payload(['schema_version' => 2.0]));
            $this->fail('Un schema_version float non supporté doit être rejeté, pas accepté en silence.');
        } catch (UnsupportedSchemaVersionException) {
            // attendu.
        }

        // Un int JSON non supporté est lui aussi rejeté (cohérence du cast).
        try {
            $this->service()->ingest($this->payload(['schema_version' => 2]));
            $this->fail('Un schema_version int non supporté doit être rejeté.');
        } catch (UnsupportedSchemaVersionException) {
            // attendu.
        }

        // Aucune écriture : la fausse acceptation aurait créé un contrat.
        $this->assertSame(0, ControlHubContract::count());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Review 33.2 (#2) — Ordre des causes : la VERSION est validée AVANT le CONTENU
    // ──────────────────────────────────────────────────────────────────────────

    public function test_version_is_validated_before_content(): void
    {
        // Payload DOUBLEMENT invalide : version non supportée ET contenu hors domaine.
        // La cause primaire doit être la VERSION (on ne parse pas un contenu sous les règles v1.0
        // quand la version déclarée est inconnue) → UnsupportedSchemaVersionException, pas
        // InvalidUpstreamContractException (AC#5, diagnostic non trompeur).
        try {
            $this->service()->ingest($this->payload([
                'schema_version' => '2.0',
                'items' => [
                    ['type' => 'capabilities', 'key' => 'cap_x', 'enforcement_state' => 'WAT', 'target_type' => 'instance'],
                ],
            ]));
            $this->fail('Attendu : UnsupportedSchemaVersionException (la version prime sur le contenu).');
        } catch (UnsupportedSchemaVersionException $e) {
            $this->assertNotInstanceOf(InvalidUpstreamContractException::class, $e);
        }

        $this->assertSame(0, ControlHubContract::count());
    }
}
