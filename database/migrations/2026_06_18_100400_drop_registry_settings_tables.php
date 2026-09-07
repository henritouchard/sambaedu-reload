<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DROP de l'ancien modèle registre (bascule capability-first).
 *
 * Le registre n'est plus une table d'authoring : c'est une PROJECTION de capacité
 * ({@see capability_projections} `mechanism = registry`). Les tables `registry_settings`
 * + `registry_setting_assignables` sont SUPERSEDED. Zéro prod
 * (mémoire `project_zero_prod_publish_is_test`) → on peut dropper après bascule
 * (le seed migre les 3 réglages vers des capacités).
 *
 * On NE supprime PAS les migrations historiques (2026_06_16_1300* / 2026_06_17_0900*)
 * de l'arbre : cette migration de DROP est additive et idempotente
 * (`Schema::hasTable`). `down()` RECRÉE les deux tables (schéma final :
 * catalogue avec options/warning/overrides_locked + pivot avec value) pour rester
 * symétrique et permettre un rollback propre du jeu de migrations.
 *
 * Ordre des drops : le pivot AVANT le catalogue (FK `registry_setting_id` cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pivot d'abord (FK vers le catalogue).
        Schema::dropIfExists('registry_setting_assignables');
        Schema::dropIfExists('registry_settings');
    }

    public function down(): void
    {
        // Recrée le CATALOGUE dans son schéma final (idempotent).
        if (! Schema::hasTable('registry_settings')) {
            Schema::create('registry_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('label');
                $table->string('description')->nullable();
                $table->string('hive', 16);
                $table->string('path');
                $table->string('name');
                $table->string('type', 16);
                $table->text('value');
                // Colonnes (add_options_and_warning / add_overrides_locked).
                $table->json('options')->nullable();
                $table->text('warning')->nullable();
                $table->boolean('overrides_locked')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Recrée le PIVOT (schéma final : colonne `value` nullable).
        if (! Schema::hasTable('registry_setting_assignables')) {
            Schema::create('registry_setting_assignables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('registry_setting_id')
                    ->constrained('registry_settings')
                    ->cascadeOnDelete();
                $table->morphs('assignable');
                // Colonne (add_value_to_registry_setting_assignables).
                $table->text('value')->nullable();
                $table->timestamps();

                $table->unique(
                    ['registry_setting_id', 'assignable_id', 'assignable_type'],
                    'registry_setting_assignable_unique',
                );
            });
        }
    }
};
