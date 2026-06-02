<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Story 20.2 — D-7 (migration additive uniquement).
     *
     * Enrichit `external_identities` (livrée minimale par 20.1) avec les
     * colonnes du cycle de vie & de la rétention RGPD :
     *
     *  - `anonymized_at` (timestamp nullable) : horodatage de l'anonymisation
     *    de fin de rétention. Non-null ⇒ état « anonymisée » (PII purgée,
     *    `external_sub` réécrit en `anon:<sha256>` — D-5). Sert de garde
     *    d'idempotence (réexécution de la purge = no-op).
     *  - `deactivated_reason` (string nullable) : motif tracé d'une
     *    désactivation administrative (`is_active=false`) sans suppression.
     *  - `deleted_reason` (string nullable) : motif tracé d'un soft-delete.
     *
     * AUCUNE colonne supprimée / renommée / table créée : compatible avec le
     * schéma 20.1 déjà déployé. `external_sub` n'est PAS modifié structurellement
     * (la réécriture `anon:<sha256>` est une mise à jour de valeur, pas de schéma).
     */
    public function up(): void
    {
        if (! Schema::hasTable('external_identities')) {
            return;
        }

        Schema::table('external_identities', function (Blueprint $table): void {
            if (! Schema::hasColumn('external_identities', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable()->after('last_login_at');
            }
            if (! Schema::hasColumn('external_identities', 'deactivated_reason')) {
                $table->string('deactivated_reason')->nullable()->after('anonymized_at');
            }
            if (! Schema::hasColumn('external_identities', 'deleted_reason')) {
                $table->string('deleted_reason')->nullable()->after('deactivated_reason');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('external_identities')) {
            return;
        }

        Schema::table('external_identities', function (Blueprint $table): void {
            foreach (['deleted_reason', 'deactivated_reason', 'anonymized_at'] as $column) {
                if (Schema::hasColumn('external_identities', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
