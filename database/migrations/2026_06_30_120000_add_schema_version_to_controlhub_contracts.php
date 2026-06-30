<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 33.1 — Schéma d'échange versionné : colonne `schema_version` (ADDITIVE) sur
 * `controlhub_contracts`.
 *
 * Enregistre la **version du schéma d'ÉCHANGE** (controlHub ↔ SE5) du dernier payload reçu
 * (Q3=A — attribut du contrat actif, lue par la Story 33.2). Le domaine de version n'est PAS
 * contraint en SQL (jamais de `CHECK` — NFR7, portabilité PG + SQLite) : il est validé en PHP
 * par {@see \App\Services\ControlHub\ControlHubContractSchema::negotiate()}.
 *
 * Colonne **nullable** (additive sur table possiblement peuplée) : un contrat antérieur à 33.1
 * porte `null` jusqu'à sa prochaine réception ; l'ingestion 33.1 résout alors la version courante.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans le nom de colonne, le commentaire ou la contrainte.
 * Vocabulaire imposé : « amont » / `ControlHub*` / `upstream`. [Source: prd-contrat-manage-se5.md#R3]
 *
 * Style : cf. 2026_06_26_100000_create_controlhub_contract_tables.php (garde hasColumn, commentaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('controlhub_contracts')) {
            return;
        }

        if (Schema::hasColumn('controlhub_contracts', 'schema_version')) {
            return;
        }

        Schema::table('controlhub_contracts', function (Blueprint $table): void {
            // Version du schéma d'échange du dernier payload reçu (semver chaîne, ex. '1.0').
            // Domaine validé en PHP (ControlHubContractSchema), JAMAIS par CHECK SQL (NFR7).
            // NB : `after()` est un modificateur MySQL/MariaDB uniquement — ignoré silencieusement
            // sur PostgreSQL (prod) et SQLite (tests) ; purement cosmétique (l'ordre des colonnes
            // n'est pas un invariant — cf. migration 28.1 qui n'en use jamais).
            $table->string('schema_version')->nullable()->after('received_at')
                ->comment('Version du schéma d\'échange du dernier payload reçu de l\'autorité amont (Story 33.1)');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('controlhub_contracts')) {
            return;
        }

        if (! Schema::hasColumn('controlhub_contracts', 'schema_version')) {
            return;
        }

        Schema::table('controlhub_contracts', function (Blueprint $table): void {
            $table->dropColumn('schema_version');
        });
    }
};
