<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.3bis — Catalogue des associations de fichiers/protocoles par défaut
 * (premier type `associations`, table DÉDIÉE iso 27.3 = D1 architecture, JAMAIS
 * une table polymorphe générique de règles).
 *
 * Chaque ligne est une association PRÉDÉTERMINÉE que l'admin d'établissement
 * active par parc (`.pdf` → Acrobat, `http` → Firefox…). L'association SE COMPILE
 * côté serveur ({@see \App\Services\Agent\Providers\AssociationsStateProvider}) en
 * un item de contrat CONCRET `{identifier, progid, type}` — le `key`/`id` du
 * catalogue ne fuite JAMAIS au payload (invariant central, iso 27.3).
 *
 * **Le hash UserChoice n'est JAMAIS porté ici** : il dépend du SID de l'utilisateur,
 * d'un timestamp et du GUID « user experience » lus sur le poste — il est calculé
 * 100 % côté AGENT (compagnon, HKCU). Le catalogue ne porte que la cible logique.
 *
 * Colonnes signifiantes :
 *   - `identifier` : l'extension (`.pdf`) ou le protocole (`http`) ;
 *   - `assoc_type` : `file` | `protocol` (discrimine FileExts vs UrlAssociations) ;
 *   - `progid`     : le ProgId Windows cible (ex. `Acrobat.Document.DC`,
 *                    `FirefoxURL`) — celui que l'agent inscrit sous UserChoice.
 *
 * **Extension WPKG-aware (D-Henri n°7, 2026-06-17).** Chaque entrée porte une
 * SOURCE qui dit d'où vient le ProgId, et donc s'il sera applicable sur un parc :
 *   - `source`       : `native` (built-in Windows, ex. `.txt → txtfile` Notepad —
 *                      toujours présent → toujours applicable) | `wpkg` (le ProgId
 *                      est fourni par un paquet WPKG, applicable SEULEMENT si le
 *                      paquet est déployé sur le parc) ;
 *   - `wpkg_package` : pour `source=wpkg`, le `<package id>` WPKG d'origine
 *                      (= `Application::$app_id`) ; `null` pour `native`.
 * Ces colonnes sont SERVEUR-only : elles alimentent la validation PRÉDICTIVE de
 * l'UI (« Firefox non déployé sur ce parc → cette association échouera ici »),
 * mais NE fuient JAMAIS au payload contrat (qui reste `{identifier, progid, type}`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('file_associations')) {
            return;
        }

        Schema::create('file_associations', function (Blueprint $table) {
            $table->id();
            // Clé technique unique de l'association (snake/kebab) — identifiant de
            // CATALOGUE, JAMAIS émis au payload (invariant central 27.3bis).
            $table->string('key')->unique()->comment('Clé technique unique de l\'association de catalogue (27.3bis) — jamais émise au payload contrat');
            $table->string('label')->comment('Libellé affichable UI (27.3bis)');
            $table->string('description')->nullable()->comment('Aide courte affichée dans l\'UI (27.3bis)');

            // Association CONCRÈTE compilée au payload.
            $table->string('identifier')->comment('Extension (.pdf) ou protocole (http) — émis au payload sous `identifier` (27.3bis)');
            $table->string('assoc_type', 16)->comment('Type : file (FileExts) | protocol (UrlAssociations) — émis au payload sous `type` (27.3bis)');
            $table->string('progid')->comment('ProgId Windows cible inscrit sous UserChoice (ex. Acrobat.Document.DC, FirefoxURL) (27.3bis)');

            // Source du ProgId (D-Henri n°7) : SERVEUR-only, jamais émis au payload.
            // Pilote la validation prédictive UI (applicable vs indisponible par parc).
            $table->string('source', 16)->default('native')->comment('Source du ProgId : native (built-in Windows, toujours applicable) | wpkg (fourni par un paquet, applicable si déployé) (27.3bis D-Henri n°7)');
            $table->string('wpkg_package')->nullable()->comment('Pour source=wpkg : le <package id> WPKG d\'origine (= Application::app_id) ; null pour native (27.3bis D-Henri n°7)');

            // Visibilité/activation de l'association dans le catalogue.
            $table->boolean('is_active')->default(true)->comment('Association proposée dans le catalogue (27.3bis)');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_associations');
    }
};
