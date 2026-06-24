<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.17 — colonne `is_parc_default` sur `applications`.
 *
 * Équivalent applicatif du `is_default` du wallpaper : marque qu'une application
 * doit être appliquée PAR DÉFAUT à TOUS les postes (couche Broadcast). Lue par
 * {@see \App\Services\Agent\Providers\ApplicationsStateProvider} qui émet ces
 * apps en candidats `StateMaille::Broadcast` EN PLUS de l'ensemble résolu par
 * poste/groupe/profil — SANS modifier la précédence (`StateCompiler` intact ;
 * `applications` est un type `aggregate`, l'union ne crée jamais de conflit).
 *
 * Booléen non nullable, défaut `false` : les apps existantes restent
 * inchangées (aucune n'est diffusée par défaut tant que l'admin ne l'active pas
 * via /admin/settings/parc-defaults, onglet « Applications »).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->boolean('is_parc_default')
                ->default(false)
                ->index()
                ->after('app_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropIndex(['is_parc_default']);
            $table->dropColumn('is_parc_default');
        });
    }
};
