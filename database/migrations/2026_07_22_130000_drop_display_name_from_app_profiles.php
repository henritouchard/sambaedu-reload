<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un profil applicatif n'a plus qu'un `name` et une `description`.
 *
 * `display_name` existait pour séparer l'identifiant technique du libellé
 * lisible, par symétrie avec `workstation_groups` / `user_groups` dont le
 * `name` est contraint (AD, longueur, charset). Pour `app_profiles` cette
 * séparation ne portait plus rien : partout où un profil est créé côté SE5
 * (observer de parc, formulaires) `display_name` valait déjà `name`, et le
 * seul canal qui l'alimentait différemment était l'import AD, qui y recopiait
 * la `description` de l'OU=Parcs. Deux champs pour une seule information.
 *
 * Reprise des données avant suppression : si un profil porte un
 * `display_name` distinct de son `name` et n'a pas de `description`, le
 * libellé devient la description — c'est la seule information qui aurait été
 * perdue (typiquement les profils issus de l'import AD antérieurs au moment
 * où `description` a été renseignée en propre).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('app_profiles', 'display_name')) {
            return;
        }

        DB::table('app_profiles')
            ->whereNotNull('display_name')
            ->whereRaw('display_name <> name')
            ->where(function ($query): void {
                $query->whereNull('description')->orWhere('description', '');
            })
            ->update(['description' => DB::raw('display_name')]);

        Schema::table('app_profiles', function (Blueprint $table): void {
            $table->dropColumn('display_name');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('app_profiles', 'display_name')) {
            return;
        }

        Schema::table('app_profiles', function (Blueprint $table): void {
            $table->string('display_name', 255)->nullable()->after('name');
        });
    }
};
