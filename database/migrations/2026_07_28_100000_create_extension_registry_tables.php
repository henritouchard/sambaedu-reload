<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 54.1 — Registre local des EXTENSIONS SE5 (socle de l'Epic 54).
 *
 * Deux tables, une seule migration (une migration par feature) :
 *
 *  - `extension_sources` : d'OÙ viennent les extensions. Le modèle multi-sources
 *    (AR7) est posé DÈS LE SOCLE — l'UI d'ajout de source, les sources distantes
 *    et les signatures arrivent en Epic 56, mais les colonnes existent maintenant
 *    pour ne pas imposer une migration de rupture. Une seule ligne en 54.1 : la
 *    source `bundled` (manifests embarqués dans le dépôt).
 *  - `extensions` : le catalogue proprement dit, une ligne par manifest chargé.
 *
 * DÉCISIONS DE CONCEPTION (figées par la story) :
 *
 *  1. **`manifest` = source de vérité de la fiche.** Le manifest complet est
 *     stocké en JSON (`jsonb` sous PostgreSQL, `json` sous SQLite — patron
 *     `system_settings.value`, migration 2026_04_25_100000). Les colonnes
 *     `name`/`version`/`publisher`/`icon`/`description`/`type` en sont des
 *     DÉNORMALISATIONS destinées à la liste (tri/affichage sans décoder le JSON).
 *     Scopes, dépendances, `entry_url` et `visibility.roles` ne sont PAS
 *     dénormalisés : ils se lisent du manifest.
 *  2. **Clé naturelle d'upsert** = `(extension_source_id, key)` — index unique
 *     `ext_natural_key`. C'est ce qui rend la synchro bundled idempotente : deux
 *     sources peuvent proposer une extension de même `key` sans collision.
 *  3. **`url` NOT NULL DEFAULT ''** (jamais nullable) : une colonne nullable
 *     participant à une clé/contrainte casse l'unicité (NULL distinct de NULL,
 *     en PostgreSQL COMME en SQLite). Vide pour la source bundled. Même règle
 *     appliquée à `publisher`, `icon`, `description`, `version`.
 *  4. **Aucun `enum()` DB** : `kind`, `type` et `status` sont des `string`
 *     castées par des backed enums PHP (`ExtensionSourceKind`, `ExtensionType`,
 *     `ExtensionStatus`) — convention maison, un ALTER TYPE PostgreSQL est un
 *     coût inutile.
 *  5. **`status` a un DÉFAUT DB `'available'`** : la synchro bundled n'écrit
 *     JAMAIS cette colonne (AC2 — une extension intégrée n'est jamais
 *     dé-intégrée par un rechargement de catalogue). Elle n'est mutée qu'en
 *     Story 54.2.
 *  6. **`is_official` ≠ `kind`** : `kind` est le TRANSPORT (embarquée/distante),
 *     `is_official` la CONFIANCE (FR4, consommée en Epic 56). Deux axes
 *     distincts, volontairement séparés.
 *
 * ⚠️ ISOLEMENT (NFR14) : aucune de ces tables n'a de FK, de listener ou de
 * service commun avec les tables `controlhub_contract*`. La sync amont
 * (ingestion → 3 listeners → `ImposedDepotReconciler` → rupture/manifeste) ne
 * doit JAMAIS les toucher — c'est prouvé et verrouillé par
 * `tests/Feature/ControlHub/UpstreamSyncExtensionsBoundaryTest.php`.
 *
 * Branches driver : `jsonb`/`json` et `timestampsTz()`/`timestamps()` — les
 * tests HÔTE rejouent toutes les migrations sur SQLite (`RefreshDatabase`).
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! Schema::hasTable('extension_sources')) {
            Schema::create('extension_sources', function (Blueprint $table) use ($driver): void {
                $table->id();

                // Clé naturelle stable de la source (`bundled` pour l'embarquée).
                // Nom d'index COURT et explicite (PostgreSQL tronque à 63 car.).
                $table->string('key', 64)->unique('ext_sources_key_unique');

                // Libellé affiché (« Embarquée (SambaEdu) »).
                $table->string('name');

                // Transport — cast \App\Enums\ExtensionSourceKind.
                $table->string('kind', 16)->default('bundled');

                // Point d'accès de la source. VIDE pour bundled (les manifests
                // sont sur le disque du serveur). Jamais nullable (piège #3).
                $table->string('url', 512)->default('');

                // Provenance/confiance (FR4) — consommée en Epic 56.
                $table->boolean('is_official')->default(true);

                // Désactivation d'une source sans la supprimer (Epic 56).
                $table->boolean('enabled')->default(true);

                if ($driver === 'pgsql') {
                    $table->timestampsTz();
                } else {
                    $table->timestamps();
                }
            });
        }

        if (! Schema::hasTable('extensions')) {
            Schema::create('extensions', function (Blueprint $table) use ($driver): void {
                $table->id();

                // La disparition d'une source emporte ses extensions : une
                // extension orpheline de sa source n'a aucun sens (on ne saurait
                // plus ni la recharger, ni dire d'où elle vient).
                $table->foreignId('extension_source_id')
                    ->constrained('extension_sources', indexName: 'ext_source_fk')
                    ->cascadeOnDelete();

                // `id` du manifest (ex. `doc`) — slug validé applicativement.
                $table->string('key', 64);

                // Dénormalisations du manifest pour la LISTE (décision #1).
                $table->string('name');
                $table->string('version', 32)->default('');
                $table->string('publisher')->default('');
                $table->string('icon')->default('');
                $table->text('description')->default('');

                // Cast \App\Enums\ExtensionType (link | app).
                $table->string('type', 16);

                // Cast \App\Enums\ExtensionStatus. Défaut DB : la synchro
                // bundled n'écrit jamais cette colonne (décision #5).
                $table->string('status', 16)->default('available');

                // Manifest COMPLET = source de vérité de la fiche (décision #1).
                if ($driver === 'pgsql') {
                    $table->jsonb('manifest');
                } else {
                    $table->json('manifest');
                }

                if ($driver === 'pgsql') {
                    $table->timestampsTz();
                } else {
                    $table->timestamps();
                }

                // Clé d'upsert idempotent de la synchro (décision #2).
                $table->unique(['extension_source_id', 'key'], 'ext_natural_key');
            });
        }
    }

    public function down(): void
    {
        // Ordre INVERSE de la création (FK `extensions` → `extension_sources`).
        Schema::dropIfExists('extensions');
        Schema::dropIfExists('extension_sources');
    }
};
