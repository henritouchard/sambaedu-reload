<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppression de `app_profiles.is_active` : un profil applicatif existe, ou
 * bien il est supprimé.
 *
 * Le drapeau promettait un gel du déploiement qu'il ne tenait pas.
 * {@see \App\Wpkg\Deployment\Services\WorkstationPackagesResolver}, qui décide
 * ce qui part dans `profiles.xml` pour un poste, ne filtre que sur
 * `archived_at` — jamais sur `is_active`. Désactiver un profil déjà rattaché à
 * un parc ne retirait donc RIEN des postes : les applications continuaient
 * d'être installées à l'identique. Le seul effet observable était cosmétique
 * (badge, filtre) plus un masquage dans les pickers d'attachement, ce qu'aucun
 * admin ne peut deviner derrière le mot « inactif ».
 *
 * Le besoin « ce profil ne doit plus rien produire » est déjà couvert par
 * `archived_at`, qui lui EST honoré par le resolver. Le besoin « ce profil ne
 * sert plus » se traite par une suppression définitive, désormais proposée
 * dans le catalogue et sur la fiche du profil.
 *
 * Rien à reprendre avant suppression : la colonne ne conditionnait aucun état
 * métier reconstituable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('app_profiles', 'is_active')) {
            return;
        }

        // L'index posé par le schéma unifié doit tomber AVANT la colonne :
        // SQLite refuse le `drop column` tant qu'un index le référence.
        // Conditionnel : les schémas montés à la main par certains tests
        // créent la colonne sans son index.
        if (Schema::hasIndex('app_profiles', ['is_active'])) {
            Schema::table('app_profiles', function (Blueprint $table): void {
                $table->dropIndex(['is_active']);
            });
        }

        Schema::table('app_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('app_profiles', 'is_active')) {
            return;
        }

        Schema::table('app_profiles', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
            $table->index('is_active');
        });
    }
};
