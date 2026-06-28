<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Epic 30 — Story 30.2 : Mapping refnum d'un label amont → WorkstationGroup.
 *
 * Ajoute la colonne `controlhub_label` (string nullable indexée) sur
 * `workstation_groups` : elle porte le **nom** d'un label de contrat amont
 * (controlHub) rattaché à ce parc. Au plus 1 label par groupe — l'invariant
 * « 1 max » est une garantie structurelle de la colonne simple nullable (null =
 * aucun label), l'intégrité référentielle étant assurée à l'assignation par
 * {@see \App\Services\ControlHub\WorkstationGroupLabelService} (le label doit
 * être un label `free` du contrat actif), pas par une contrainte DB.
 *
 * ⚠️ DÉCISION DE DESIGN (story 30.2) : rattachement PAR NOM, **pas** de FK dure
 *    vers `controlhub_contract_labels`. Les labels sont réconciliés (prune +
 *    re-create) à chaque ingestion 28.2 et peuvent disparaître à la rupture du
 *    lien (Epic 32) : coupler un parc durable à un label éphémère via FK serait
 *    fragile. Aligné sur le précédent 28.1 `imposed_groups.label_name` (string,
 *    sans FK) et sur la résolution-par-nom de 30.4 (`label:<nom>`).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans le nom de colonne / d'index / le
 *    commentaire. Vocabulaire imposé : « amont » / `controlhub`. [prd#R3]
 * ⚠️ NFR3 : colonne nullable SANS valeur par défaut métier (null = aucun label) —
 *    le comportement standalone reste strictement inchangé.
 *
 * Style : cf. 2026_02_11_130000_add_controlhub_updated_at_to_entities.php (garde
 * hasColumn, after, down dropColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('workstation_groups', 'controlhub_label')) {
            return;
        }

        Schema::table('workstation_groups', function (Blueprint $table): void {
            $table->string('controlhub_label')->nullable()->after('controlhub_version')
                ->comment("Nom du label de contrat amont (free) rattaché à ce parc — au plus 1 par groupe ; rattachement par nom, pas de FK dure (cf. 28.1 imposed_groups.label_name).");

            // Index : support de la résolution-par-nom de 30.4 (« tous les groupes
            // portant ce label »). Nom court explicite (< 63 car. PG).
            $table->index('controlhub_label', 'wg_controlhub_label_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('workstation_groups', 'controlhub_label')) {
            return;
        }

        Schema::table('workstation_groups', function (Blueprint $table): void {
            $table->dropIndex('wg_controlhub_label_idx');
            $table->dropColumn('controlhub_label');
        });
    }
};
