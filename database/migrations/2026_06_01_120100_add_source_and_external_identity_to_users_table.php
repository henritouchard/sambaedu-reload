<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Story 20.1 — D-4.
     *
     * Marque l'origine d'un User Eloquent et le relie éventuellement à une
     * identité externe.
     *
     *  - `source` : 'ad' (défaut, iso-existant) ou 'federated'. Un User
     *    `source='federated'` n'est JAMAIS synchronisé AD (pas de `dn`/
     *    `ad_guid`), et le guard de session saute la vérif LDAP pour lui (D-5).
     *  - `external_identity_id` : FK nullable vers `external_identities`.
     *    Nullable car la quasi-totalité des users sont AD (FK NULL).
     *
     * On réutilise le même `App\Models\User` comme principal de session (les
     * Policies/Gates Spatie type-hint `User`) — aucune réécriture de
     * l'autorisation.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'source')) {
                // 'ad' = origine Active Directory (défaut, comportement actuel).
                // 'federated' = provisionné via login fédéré (Epic 20).
                $table->string('source', 16)->default('ad')->index();
            }

            if (! Schema::hasColumn('users', 'external_identity_id')) {
                $table->unsignedBigInteger('external_identity_id')->nullable()->index();
            }
        });

        // FK ajoutée séparément (table cible créée par la migration précédente).
        // Encapsulée : sqlite :memory: en test ne supporte pas toujours l'ALTER
        // ADD CONSTRAINT ; on garde la FK best-effort sans casser la migration.
        if (Schema::hasTable('external_identities')) {
            try {
                Schema::table('users', function (Blueprint $table): void {
                    $table->foreign('external_identity_id')
                        ->references('id')
                        ->on('external_identities')
                        ->nullOnDelete();
                });
            } catch (\Throwable) {
                // sqlite / drivers sans ALTER ADD CONSTRAINT : la colonne
                // indexée nullable suffit fonctionnellement. La contrainte
                // d'intégrité reste assurée en prod (pgsql).
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'external_identity_id')) {
                try {
                    $table->dropForeign(['external_identity_id']);
                } catch (\Throwable) {
                    // pas de FK posée (sqlite) — on ignore.
                }
                $table->dropColumn('external_identity_id');
            }
            if (Schema::hasColumn('users', 'source')) {
                $table->dropColumn('source');
            }
        });
    }
};
