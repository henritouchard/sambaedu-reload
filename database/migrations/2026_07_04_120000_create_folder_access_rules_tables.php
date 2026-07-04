<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 36.4 — Règles d'accès aux dossiers (feature à formulaire, D8).
 *
 * SECONDE surface d'authoring du mécanisme `fs_acl` (36.1) : le référent
 * numérique crée des règles « interdire/autoriser CE dossier à CE groupe » via un
 * formulaire 100 % métier. Chaque règle active se PROJETTE en items `fs_acl`
 * IDENTIQUES à ceux d'une capacité (aucune nouvelle notion côté agent/contrat) —
 * calque STRUCTUREL des lecteurs réseau (34.1 : `network_shares` +
 * `network_share_assignables`, canal `drives` bi-alimenté).
 *
 * Deux tables :
 *  - `folder_access_rules` : la règle {path, user_group_id (VRAI picker SQL,
 *    cascadeOnDelete — un groupe supprimé emporte ses règles, fenêtre d'orphelin
 *    documentée piège #3), ace_type, rights, applies_to, label, is_active,
 *    created_by_user_id}. Domaines validés APPLICATIVEMENT (constantes du guard
 *    `FsAclAuthoringGuard` — SQLite n'applique pas les varchar/checks, mémoire
 *    `sqlite_tests_no_varchar_enforcement`).
 *  - `folder_access_rule_assignables` : pivot POLYMORPHE par parc, calque
 *    byte-près de `network_share_assignables` (FK cascade, `morphs`, unique)
 *    SANS colonne `access` (une règle ne porte pas de niveau d'accès POSIX — le
 *    niveau est dans `rights`). v1 : `WorkstationGroup` seul autorisé
 *    (`FolderAccessRule::ALLOWED_ASSIGNABLE_TYPES`), extensible SANS migration.
 *
 * Retrait propre (piège #3 36.1) : désactiver une règle (`is_active=false`)
 * n'éteint PAS son émission — elle émet ses items avec `ensure:'absent'` (off
 * réel). La suppression d'une règle ACTIVE est refusée côté service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_access_rules', function (Blueprint $table) {
            $table->id();
            // Chemin Windows absolu (validé applicativement `^[A-Za-z]:\` — miroir
            // du guard). Le trustee n'est PAS stocké : il est DÉRIVÉ du groupe
            // (D9, CN de `ad_dn`) à l'émission.
            $table->string('path');
            // VRAI picker de groupe SQL (PAS un jeton) — cascadeOnDelete : un
            // groupe supprimé emporte ses règles (fenêtre d'orphelin piège #3).
            $table->foreignId('user_group_id')
                ->constrained('user_groups')
                ->cascadeOnDelete();
            $table->string('ace_type');      // allow | deny
            $table->string('rights');        // list_folder | read | write | modify
            $table->string('applies_to');    // folder_only | folder_subfolders_files
            $table->string('label');         // libellé admin (requis)
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('user_group_id');
            $table->index('is_active');
        });

        Schema::create('folder_access_rule_assignables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_access_rule_id')
                ->constrained('folder_access_rules')
                ->cascadeOnDelete();
            $table->morphs('assignable'); // assignable_id + assignable_type
            $table->timestamps();

            $table->unique(
                ['folder_access_rule_id', 'assignable_id', 'assignable_type'],
                'folder_access_rule_assignable_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_access_rule_assignables');
        Schema::dropIfExists('folder_access_rules');
    }
};
