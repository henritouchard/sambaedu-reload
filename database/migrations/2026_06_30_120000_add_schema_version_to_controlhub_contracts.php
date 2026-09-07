<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schéma d'échange versionné : colonne `schema_version` (ADDITIVE) sur
 * `controlhub_contracts`.
 *
 * Enregistre la **version du schéma d'ÉCHANGE** (controlHub ↔ SE5) du dernier payload reçu
 * (attribut du contrat actif). Le domaine de version n'est PAS
 * contraint en SQL (jamais de `CHECK` — portabilité PG + SQLite) : il est validé en PHP
 * par {@see \App\Services\ControlHub\ControlHubContractSchema::negotiate()}.
 *
 * Colonne **nullable** (additive sur table possiblement peuplée) : un contrat antérieur
 * porte `null` jusqu'à sa prochaine réception ; l'ingestion résout alors la version courante.
 *
 * ⚠️ GARDE-FOU : aucun mot « central » dans le nom de colonne, le commentaire ou la contrainte.
 * Vocabulaire imposé : « amont » / `ControlHub*` / `upstream`.
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
            // Domaine validé en PHP (ControlHubContractSchema), JAMAIS par CHECK SQL :
            // un CHECK casserait la portabilité PG + SQLite.
            // NB : `after()` est un modificateur MySQL/MariaDB uniquement — ignoré silencieusement
            // sur PostgreSQL (prod) et SQLite (tests) ; purement cosmétique (l'ordre des colonnes
            // n'est pas un invariant — cf. migration qui n'en use jamais).
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
