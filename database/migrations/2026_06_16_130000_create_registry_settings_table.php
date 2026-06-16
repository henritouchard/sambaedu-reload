<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3 — Catalogue de réglages de registre (premier type `registry` SANS
 * table métier existante : D1 architecture L250-260 = table DÉDIÉE, JAMAIS une
 * table polymorphe générique de règles).
 *
 * Chaque ligne est un réglage PRÉDÉTERMINÉ que l'admin d'établissement
 * active/configure par parc (pas d'édition de chemin de registre à la main en
 * v1). Le réglage SE COMPILE côté serveur ({@see RegistryStateProvider}) en un
 * item de contrat CONCRET `{hive, path, name, type, value}` — le `key`/`id` du
 * catalogue ne fuite JAMAIS au payload (invariant central de la story).
 *
 * Sérialisation de `value` (texte) : la cible est portée telle quelle. Pour les
 * types non-string, convention figée serveur (le provider produit la valeur
 * typée au payload, contrat §4.1 zéro float) :
 *   - REG_DWORD       : entier en DÉCIMAL (ex. "0", "1") ;
 *   - REG_QWORD       : entier en décimal ;
 *   - REG_SZ/EXPAND_SZ: la chaîne littérale ;
 *   - REG_MULTI_SZ    : JSON array de chaînes (ex. ["a","b"]).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('registry_settings')) {
            return;
        }

        Schema::create('registry_settings', function (Blueprint $table) {
            $table->id();
            // Clé technique unique du réglage (snake/kebab) — identifiant de
            // CATALOGUE, JAMAIS émis au payload (invariant central 27.3).
            $table->string('key')->unique()->comment('Clé technique unique du réglage de catalogue (27.3) — jamais émise au payload contrat');
            $table->string('label')->comment('Libellé affichable UI (27.3)');
            $table->string('description')->nullable()->comment('Aide courte affichée dans l\'UI (27.3)');

            // Item de registre CONCRET compilé au payload.
            $table->string('hive', 16)->comment('Ruche : HKLM (machine/SYSTEM) | HKCU (session/compagnon) (27.3)');
            $table->string('path')->comment('Chemin de clé sous la ruche, ex. SOFTWARE\\... (27.3)');
            $table->string('name')->comment('Nom de la valeur de registre (27.3)');
            $table->string('type', 16)->comment('Type REG_* : REG_SZ|REG_DWORD|REG_EXPAND_SZ|REG_MULTI_SZ|REG_QWORD (27.3)');
            $table->text('value')->comment('Valeur cible sérialisée en texte (DWORD décimal, MULTI_SZ JSON) — cf. provider (27.3)');

            // Visibilité/activation du réglage dans le catalogue.
            $table->boolean('is_active')->default(true)->comment('Réglage proposé dans le catalogue (27.3)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registry_settings');
    }
};
