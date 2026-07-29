<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 56.2 — Installation d'une extension `app` : ce que l'INSTANCE sait de
 * son installation, et la catégorie d'échec du journal d'audit.
 *
 * Migration **strictement ADDITIVE** : ni 54.1, ni 54.2, ni 56.1 ne sont
 * retouchées — elles sont passées en review et les instances les ont déjà
 * jouées.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DÉCISIONS DE CONCEPTION (figées par la story)
 *
 *  1. **`installed_version` ≠ `version`.** `extensions.version` est la version
 *     PUBLIÉE par le catalogue (elle bouge à chaque synchro de la source) ;
 *     `installed_version` est celle réellement POSÉE sur cette instance. Les
 *     confondre rendrait impossible la détection d'une mise à jour disponible
 *     (Story 56.3 : `version` ≠ `installed_version` ⇒ mise à jour proposée),
 *     et une re-synchro de catalogue effacerait silencieusement la trace de ce
 *     qui tourne vraiment. `NOT NULL DEFAULT ''` : une extension non installée
 *     n'a pas de version installée, et une chaîne vide le dit aussi bien qu'un
 *     `NULL` (piège #3 de la migration 54.1, reconduit).
 *
 *  2. **`installed_port` est assigné par SE5, jamais déclaré par le manifest.**
 *     Un éditeur tiers ne choisit pas un port de l'hôte : les collisions
 *     inter-éditeurs seraient garanties, et un manifest pourrait squatter un
 *     port système. La colonne EST le registre d'allocation
 *     ({@see \App\Services\Extensions\ExtensionInstallService::allocatePort()}
 *     prend le premier libre de `config('extensions.install.port_range')` sous
 *     le verrou global). Nullable : `null` ⇒ aucun port réservé.
 *
 *  3. **`installed_*` reste HORS `$fillable`** d'{@see \App\Models\Extension}
 *     — même doctrine que `status` (54.1/54.2) : le `fill()` de l'upsert de
 *     catalogue ({@see \App\Services\Extensions\ExtensionCatalogService::syncManifestsForSource()})
 *     ne doit JAMAIS pouvoir toucher ces colonnes, même si un manifest tiers
 *     porte une clé `installed_port` parasite. La mutation se fait par
 *     assignation de propriété explicite dans
 *     {@see \App\Services\Extensions\ExtensionLifecycleService}, dont la
 *     promesse « seul écrivain de `status` » s'étend à ces trois colonnes.
 *
 *  4. **`extension_audit_logs.details` = une CATÉGORIE, jamais un message
 *     brut.** Ce que le moteur y écrit est une étiquette courte et stable
 *     (`sha256 non concordant`, `échec à l'étape apt_install`…), **sans l'URL
 *     du dépôt et sans le moindre secret** — même règle que `last_error` de
 *     56.1 (piège Guzzle review 39.4 #E11 : le message d'exception suffixe
 *     l'URI complète, et une URL de dépôt peut porter `?private_token=…`). Le
 *     détail complet reste dans le journal serveur. Borne 500,
 *     `NOT NULL DEFAULT ''` : la très grande majorité des lignes d'audit
 *     (`integrate`, `uninstall`, `install`, `remove`) n'ont rien à détailler.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Rejouable** : gardes `hasTable` / `hasColumn` partout. Branches driver
 * `timestampTz` / `timestamp` : les tests HÔTE rejouent toutes les migrations
 * sur SQLite (`RefreshDatabase`), qui ne connaît pas `timestamptz`.
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ── extensions : ce qui est réellement INSTALLÉ sur cette instance ──
        if (Schema::hasTable('extensions')) {
            Schema::table('extensions', function (Blueprint $table) use ($driver): void {
                if (! Schema::hasColumn('extensions', 'installed_version')) {
                    $table->string('installed_version', 32)->default('')
                        ->comment('Version RÉELLEMENT posée sur l\'instance — jamais celle du catalogue (décision #1)');
                }

                if (! Schema::hasColumn('extensions', 'installed_port')) {
                    $table->integer('installed_port')->nullable()
                        ->comment('Port backend ASSIGNÉ par SE5 dans extensions.install.port_range (décision #2)');
                }

                if (! Schema::hasColumn('extensions', 'installed_at')) {
                    if ($driver === 'pgsql') {
                        $table->timestampTz('installed_at')->nullable()
                            ->comment('Horodatage de l\'installation réussie (null = non installée)');
                    } else {
                        $table->timestamp('installed_at')->nullable()
                            ->comment('Horodatage de l\'installation réussie (null = non installée)');
                    }
                }
            });
        }

        // ── extension_audit_logs : catégorie d'échec ────────────────────────
        if (Schema::hasTable('extension_audit_logs')) {
            Schema::table('extension_audit_logs', function (Blueprint $table): void {
                if (! Schema::hasColumn('extension_audit_logs', 'details')) {
                    $table->string('details', 500)->default('')
                        ->comment('Catégorie COURTE d\'échec — jamais l\'URL, jamais un secret (décision #4)');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('extension_audit_logs') && Schema::hasColumn('extension_audit_logs', 'details')) {
            Schema::table('extension_audit_logs', function (Blueprint $table): void {
                $table->dropColumn('details');
            });
        }

        if (Schema::hasTable('extensions')) {
            foreach (['installed_version', 'installed_port', 'installed_at'] as $column) {
                if (Schema::hasColumn('extensions', $column)) {
                    Schema::table('extensions', function (Blueprint $table) use ($column): void {
                        $table->dropColumn($column);
                    });
                }
            }
        }
    }
};
