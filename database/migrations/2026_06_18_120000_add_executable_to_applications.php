<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 27.11 — Composer d'associations par défaut (extension libre + app par nom).
 *
 * Ajoute `applications.executable` : le chemin (ou le nom) de l'EXÉCUTABLE RUNTIME
 * de l'application WPKG. C'est la SEULE donnée neuve de la story (D-Henri n°8) :
 * `Application` portait déjà `name`/`app_id`/`icon_url`/`installer_filename` mais
 * PAS l'exe runtime.
 *
 * Pourquoi : pour une paire (extension `X`, app `A`) dépourvue de ProgId riche
 * déclaré (`packages.xml`), le {@see \App\Services\Agent\Resolvers\AssociationResolver}
 * fabrique un ProgId GÉNÉRIQUE `Applications\<exe de A>` (« lance cet exe avec le
 * fichier », ce que Windows crée via « Ouvrir avec »). Le SERVEUR n'en consomme que
 * le BASENAME de l'exe (le nom de fichier) pour fabriquer la clé `Applications\<exe>`.
 *
 * Le CHEMIN RUNTIME COMPLET n'est NI transmis au payload (invariant AC7 — le payload
 * reste `{identifier, progid, type}`) NI consommé par l'agent : le compagnon le
 * RE-RÉSOUT sur le poste (App Paths HKCU/HKLM puis PATH) pour écrire
 * `HKCU\Software\Classes\Applications\<exe>\shell\open\command = "<chemin résolu>" "%1"`
 * AVANT d'imposer UserChoice (AC6). Le BASENAME suffit donc au serveur ; le poste
 * fait foi pour le chemin.
 *
 * Nullable : la captation de l'exe est best-effort (à l'import/édition d'app ou
 * dérivée de `packages.xml`). Une app sans `executable` ET sans ProgId riche pour
 * l'extension visée est REFUSÉE à la composition (garde-fou UI, piège n°4) — pas
 * de générique sans exe.
 *
 * Migration IDEMPOTENTE (`Schema::hasColumn` garde) + `down()` symétrique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('applications')) {
            return;
        }

        if (Schema::hasColumn('applications', 'executable')) {
            return;
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->string('executable')
                ->nullable()
                ->after('installer_filename')
                ->comment('Nom de l\'exe runtime de l\'app (le BASENAME suffit ; un chemin complet est toléré mais seul le basename est consommé) — fabrique le ProgId générique Applications\\<exe> du composer (27.11). Le chemin complet n\'est ni transmis au payload ni consommé par l\'agent (le poste le re-résout)');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('applications')) {
            return;
        }

        if (! Schema::hasColumn('applications', 'executable')) {
            return;
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('executable');
        });
    }
};
