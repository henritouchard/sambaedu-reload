<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Story 56.1 — Sources DISTANTES : clé pinnée, état de synchro, audit de source.
 *
 * Migration **strictement ADDITIVE** : la migration 54.1
 * (`2026_07_28_100000_create_extension_registry_tables.php`) n'est PAS
 * retouchée — elle est passée en review et les instances l'ont déjà jouée. Les
 * colonnes `kind` / `url` / `is_official` / `enabled` posées par anticipation
 * en 54.1 suffisaient au MODÈLE multi-sources ; il manquait ce que la
 * VÉRIFICATION exige.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DÉCISIONS DE CONCEPTION (figées par la story)
 *
 *  1. **`public_key` = la clé PINNÉE de la source** (base64 d'une clé publique
 *     Ed25519 de 32 octets, ~44 caractères — colonne dimensionnée large à 128
 *     pour absorber un éventuel format futur SANS migration de rupture).
 *     Pinnée À L'AJOUT (collée par l'admin, ou lue UNE seule fois sur
 *     `<url>/source.pub` si l'URL est en https), elle n'est **jamais**
 *     re-téléchargée ensuite : c'est le modèle `known_hosts` / keyring apt. Un
 *     dépôt qui change de clé passe en erreur — la rotation légitime est un
 *     retrait + ré-ajout explicites, deux actes journalisés.
 *     `NOT NULL DEFAULT ''` : la source `bundled` n'a pas de clé (ses manifests
 *     sont sur le disque du serveur, aucun transport à authentifier), et une
 *     colonne nullable n'apporte rien qu'une chaîne vide ne dise déjà
 *     (piège #3 de la migration 54.1, reconduit).
 *
 *  2. **`sync_status` = la sémantique du CACHE LOCAL (NFR7).** Le registre EST
 *     le cache : il n'y a pas de fichier de catalogue à côté. Trois états, cast
 *     par {@see \App\Enums\ExtensionSourceSyncStatus} :
 *       - `ok`          : dernier index vérifié — les `available` sont proposées ;
 *       - `unreachable` : réseau/HTTP/3xx — le dernier index VÉRIFIÉ reste
 *                         proposé, rien n'est pruné, les tuiles sont intactes ;
 *       - `error`       : signature ou contenu invalide — fail-closed, les
 *                         `available` de la source sont masquées, les
 *                         `integrated` conservées et signalées, rien n'est pruné.
 *     Aucun `enum()` DB (convention maison) : un `ALTER TYPE` PostgreSQL est un
 *     coût inutile pour trois valeurs.
 *
 *  3. **`last_error` = une CATÉGORIE, jamais un message brut.** Ce que le
 *     service y écrit est une phrase courte et stable, **sans l'URL** : un
 *     message d'exception Guzzle suffixe l'URI complète, et une URL de dépôt
 *     GitLab peut porter `?private_token=…` (piège documenté par la review 39.4
 *     #E11 d'`ArtifactPullService`). Le détail complet reste dans le journal
 *     serveur. Borne 500 caractères, `NOT NULL DEFAULT ''`.
 *
 *  4. **`extension_audit_logs` étendu, PAS de nouvelle table.** `action` est un
 *     string libre EXPRESSÉMENT prévu extensible par le docblock de la
 *     migration 54.2 : `source_add` / `source_enable` / `source_disable` /
 *     `source_remove` / `source_sync_failed` s'y logent sans schéma nouveau. La
 *     FK `extension_source_id` est `nullOnDelete` (la trace du retrait d'une
 *     source doit SURVIVRE à la disparition de la source), doublée de la
 *     colonne dénormalisée `source_key` — exactement le patron
 *     `extension_id` + `extension_key` de 54.2. Sur un événement de SOURCE,
 *     `extension_key` / `extension_name` valent `''`.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Rejouable** : gardes `hasTable` / `hasColumn` partout. La FK est posée en
 * best-effort (SQLite — driver de la suite de tests HÔTE — ne sait pas ajouter
 * une contrainte à une table existante) ; l'échec est journalisé hors SQLite
 * plutôt qu'avalé (patron migration 55.2 `oidc_tokens_user_fk`).
 *
 * Branches driver `timestampTz` / `timestamp` : les tests HÔTE rejouent toutes
 * les migrations sur SQLite (`RefreshDatabase`).
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ── extension_sources : clé pinnée + état de synchro ──────────────
        if (Schema::hasTable('extension_sources')) {
            Schema::table('extension_sources', function (Blueprint $table) use ($driver): void {
                if (! Schema::hasColumn('extension_sources', 'public_key')) {
                    $table->string('public_key', 128)->default('')
                        ->comment('Clé publique Ed25519 base64 PINNÉE à l\'ajout — jamais re-téléchargée (décision #1)');
                }

                if (! Schema::hasColumn('extension_sources', 'sync_status')) {
                    $table->string('sync_status', 16)->default('ok')
                        ->comment('ok | unreachable | error — cast App\\Enums\\ExtensionSourceSyncStatus (décision #2)');
                }

                if (! Schema::hasColumn('extension_sources', 'last_synced_at')) {
                    if ($driver === 'pgsql') {
                        $table->timestampTz('last_synced_at')->nullable()
                            ->comment('Dernière synchro RÉUSSIE et vérifiée (null = jamais)');
                    } else {
                        $table->timestamp('last_synced_at')->nullable()
                            ->comment('Dernière synchro RÉUSSIE et vérifiée (null = jamais)');
                    }
                }

                if (! Schema::hasColumn('extension_sources', 'last_error')) {
                    $table->string('last_error', 500)->default('')
                        ->comment('Catégorie d\'erreur COURTE — jamais l\'URL, jamais un message Guzzle brut (décision #3)');
                }
            });
        }

        // ── extension_audit_logs : événements de SOURCE ───────────────────
        if (Schema::hasTable('extension_audit_logs')) {
            Schema::table('extension_audit_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('extension_audit_logs', 'extension_source_id')) {
                    $table->unsignedBigInteger('extension_source_id')->nullable()
                        ->index('ext_audit_source_idx')
                        ->comment('Source concernée (null si retirée du registre — la trace survit)');
                }

                if (! Schema::hasColumn('extension_audit_logs', 'source_key')) {
                    $table->string('source_key')->default('')
                        ->comment('Clé DÉNORMALISÉE de la source (lisible même après retrait)');
                }
            });

            // FK best-effort : SQLite ne sait pas ajouter une contrainte à une
            // table existante. La colonne indexée nullable suffit
            // fonctionnellement (la lecture du journal ne dépend pas de la FK,
            // `source_key` porte la lisibilité) ; l'intégrité référentielle
            // reste assurée en production (PostgreSQL).
            if (Schema::hasTable('extension_sources') && ! self::foreignKeyExists('ext_audit_source_fk')) {
                try {
                    Schema::table('extension_audit_logs', function (Blueprint $table): void {
                        $table->foreign('extension_source_id', 'ext_audit_source_fk')
                            ->references('id')
                            ->on('extension_sources')
                            ->nullOnDelete();
                    });
                } catch (\Throwable $e) {
                    // Le cas ATTENDU est SQLite. Hors SQLite, un échec est RÉEL
                    // (nom déjà pris, droit ALTER manquant) : on ne fait pas
                    // échouer la migration, mais on laisse une trace exploitable
                    // plutôt qu'une garantie d'intégrité silencieusement fausse.
                    if (DB::getDriverName() !== 'sqlite') {
                        Log::warning('[Extensions] FK ext_audit_source_fk non posée', [
                            'driver' => DB::getDriverName(),
                            'reason' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('extension_audit_logs')) {
            Schema::table('extension_audit_logs', function (Blueprint $table): void {
                try {
                    $table->dropForeign('ext_audit_source_fk');
                } catch (\Throwable) {
                    // Aucune FK posée (SQLite) — on ignore.
                }
            });

            foreach (['extension_source_id', 'source_key'] as $column) {
                if (Schema::hasColumn('extension_audit_logs', $column)) {
                    Schema::table('extension_audit_logs', function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }

        if (Schema::hasTable('extension_sources')) {
            foreach (['public_key', 'sync_status', 'last_synced_at', 'last_error'] as $column) {
                if (Schema::hasColumn('extension_sources', $column)) {
                    Schema::table('extension_sources', function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }

    /** La contrainte existe-t-elle déjà ? (rejouabilité hors SQLite) */
    private static function foreignKeyExists(string $name): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            return DB::table('pg_constraint')->where('conname', $name)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
};
