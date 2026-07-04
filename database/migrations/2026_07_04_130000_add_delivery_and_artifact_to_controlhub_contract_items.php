<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 39.4 — Canal ④ : ingestion + pull des binaires imposés par le contrat amont
 * (controlHub). Étend `controlhub_contract_items` pour porter le mode de livraison et
 * l'IDENTITÉ STABLE d'un binaire `artifact` (significatif pour `type ∈ {wallpapers, agent_tools}`).
 *
 * Migration ADDITIVE (jamais une réécriture de
 * `2026_06_26_100000_create_controlhub_contract_tables.php`) — patron
 * `2026_06_29_100000_add_source_to_controlhub_catalog_apps.php`. Toutes colonnes NULLABLES
 * (rétrocompatibilité NFR-A4 : un payload sans `artifact`/`delivery_mode` reste accepté à
 * l'identique). La CLÉ NATURELLE `(controlhub_contract_id, type, key, target_type, target_label)`
 * est INCHANGÉE (idempotence 28.2 préservée).
 *
 * ⚠️ `artifact_url` n'est VOLONTAIREMENT PAS une colonne (AC2/AC5, piège d'idempotence) : les URL
 * signées sont régénérées à chaque émission du contrat — les persister ferait basculer le calcul
 * générique `wasChanged()` de `reconcileChildren()` à `true` à chaque re-diffusion (mutation
 * parasite + re-pull inutile), cassant le no-op NFR-A2. L'URL ne vit que dans les arguments du
 * job de pull dispatché en mémoire ({@see \App\Jobs\ControlHub\PullContractArtifactJob}).
 *
 *  - `delivery_mode`    (string nullable)  — mode de livraison amont (capturé, non arbitré, AC6) ;
 *  - `artifact_checksum`(string nullable)  — sha256 hex = identité stable du binaire (base du no-op) ;
 *  - `artifact_filename`(string nullable)  — nom informatif (JAMAIS utilisé pour le nommage disque) ;
 *  - `artifact_size`    (bigint nullable)  — taille attendue en octets ;
 *  - `pull_status`      (string nullable)  — `ControlHubArtifactPullStatus` (pending|downloaded|error) ;
 *  - `pull_error`       (text nullable)    — message court d'échec (jamais l'URL signée en clair, NFR-A3).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('controlhub_contract_items', 'delivery_mode')) {
                $table->string('delivery_mode')->nullable()->after('value')
                    ->comment('Mode de livraison amont (capturé, non arbitré — Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_items', 'artifact_checksum')) {
                $table->string('artifact_checksum')->nullable()->after('delivery_mode')
                    ->comment('sha256 hex = identité stable du binaire imposé (base du no-op — Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_items', 'artifact_filename')) {
                $table->string('artifact_filename')->nullable()->after('artifact_checksum')
                    ->comment('Nom informatif du binaire (jamais utilisé pour le nommage disque — Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_items', 'artifact_size')) {
                $table->bigInteger('artifact_size')->nullable()->after('artifact_filename')
                    ->comment('Taille attendue du binaire en octets (Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_items', 'pull_status')) {
                $table->string('pull_status')->nullable()->after('artifact_size')
                    ->comment('État du pull : pending|downloaded|error (ControlHubArtifactPullStatus, Story 39.4)');
            }
            if (! Schema::hasColumn('controlhub_contract_items', 'pull_error')) {
                $table->text('pull_error')->nullable()->after('pull_status')
                    ->comment('Message court d\'échec du pull (jamais l\'URL signée en clair — NFR-A3, Story 39.4)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_items', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_mode',
                'artifact_checksum',
                'artifact_filename',
                'artifact_size',
                'pull_status',
                'pull_error',
            ]);
        });
    }
};
