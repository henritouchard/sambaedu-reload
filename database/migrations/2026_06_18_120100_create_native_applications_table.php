<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table NATIVE CURÉE des applications built-in Windows.
 *
 * Source 2 du dropdown du composer (à côté de la table `applications` WPKG) : un
 * référentiel CURÉ MANUELLEMENT des programmes Win32 livrés avec Windows
 * (Bloc-notes, Paint, WordPad, Visionneuse de photos…) dont le ProgId canonique est
 * CONNU et TOUJOURS présent sur le poste → `source=native`, toujours applicable
 * (aucune dépendance de paquet WPKG). **UWP modernes EXCLUES** (ProgId `AppX…`
 * ingérables) — ne pas confondre « native curée », au ProgId connu, et le
 * « générique » fabriqué `Applications\<exe>`.
 *
 * Colonnes :
 *   - `key`         : clé technique unique (slug) — identité d'upsert idempotent ;
 *   - `label`       : libellé affichable dans le dropdown ;
 *  - `progid` : ProgId canonique built-in (ex. `txtfile`, `Paint.Picture`)
 *                     ce que le resolver émet en `source=native` ;
 *   - `executable`  : chemin/nom de l'exe runtime (ex. `%SystemRoot%\system32\notepad.exe`)
 *                     — fallback générique si jamais le built-in n'expose pas de
 *                     ProgId pour une extension visée ;
 *   - `assoc_types` : JSON, liste des identifiants (extensions/protocoles) que ce
 *                     built-in sait gérer nativement (`['.txt']`, `['.bmp','.png']`…)
 *                     — borne le ProgId canonique à ses extensions déclarées
 *                     (un ProgId est propre à un couple app × type de contenu) ;
 *   - `icon_url`    : optionnel (icône du dropdown ; null = icône générique UI).
 *
 * IDEMPOTENTE (`Schema::hasTable` garde) + `down()` symétrique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('native_applications')) {
            return;
        }

        Schema::create('native_applications', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('Clé technique unique (slug) — identité d\'upsert idempotent (27.11)');
            $table->string('label')->comment('Libellé affichable dans le dropdown du composer (27.11)');
            $table->string('progid')->comment('ProgId canonique built-in Windows (ex. txtfile, Paint.Picture) — émis source=native (27.11)');
            $table->string('executable')->comment('Chemin/nom de l\'exe runtime du built-in (ex. %SystemRoot%\\system32\\notepad.exe) — fallback générique (27.11)');
            $table->json('assoc_types')->comment('Liste JSON des identifiants (extensions/protocoles) gérés nativement par ce built-in (27.11)');
            $table->string('icon_url')->nullable()->comment('Icône optionnelle du dropdown ; null = icône générique UI (27.11)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_applications');
    }
};
