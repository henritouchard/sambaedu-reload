<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 34.1 — pivot POLYMORPHE d'assignation des répertoires réseau.
 *
 * Calqué sur `shortcut_assignables` (2026_02_09) MAIS 100 % pivot SQL (aucune
 * colonne JSON `ad_*`) : les cibles `User | UserGroup | WorkstationGroup` sont
 * toutes des modèles SQL réels (NFR7, critère Keycloak — zéro AD/LdapRecord).
 *
 *  - `access` (string `ro|rw`, défaut `ro`) : porte le niveau d'accès POSIX
 *    dérivé (rx vs rwx) pour les assignations `User`/`UserGroup`. Une
 *    assignation `WorkstationGroup` est MONTAGE-SEUL (aucune ACL — POSIX ne sait
 *    pas exprimer « les users de la machine X »).
 *  - `unique(network_share_id, assignable_id, assignable_type)` : une cible ne
 *    peut être assignée qu'une fois par répertoire.
 *
 * Domaine `access` non contraint en SQLite (mémoire sqlite_tests_no_varchar) —
 * validé applicativement (`NetworkShareAssignable::ACCESS_*`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_share_assignables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_share_id')
                ->constrained('network_shares')
                ->cascadeOnDelete();
            $table->morphs('assignable'); // assignable_id + assignable_type
            $table->string('access', 8)->default('ro');
            $table->timestamps();

            $table->unique(
                ['network_share_id', 'assignable_id', 'assignable_type'],
                'network_share_assignable_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_share_assignables');
    }
};
