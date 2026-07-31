<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 56.3 — Ce qu'il faut pour METTRE À JOUR une extension `app` depuis
 * l'UI, et pour que la progression d'une opération survive à un rechargement de
 * page.
 *
 * Migration **strictement ADDITIVE** : 54.1, 54.2, 56.1 et 56.2 ne sont pas
 * retouchées — elles sont passées en review et les instances les ont jouées.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DÉCISIONS DE CONCEPTION (figées par la story)
 *
 *  1. **`extensions.installed_sha256` est le GAGE DE ROLLBACK de la mise à
 *     jour.** Le staging des paquets est content-addressed
 *     (`<staging>/<key>/<sha256>.deb`) et conservé après installation
 *     (décision 56.2 #6). Sans cette colonne, impossible de désigner, parmi les
 *     fichiers présents, celui de la version RÉELLEMENT installée : le rollback
 *     d'un update raté deviendrait une espérance au lieu d'une précondition
 *     vérifiable AVANT d'agir. `NOT NULL DEFAULT ''` (même doctrine que
 *     `installed_version`) : une extension non installée n'a pas de paquet
 *     posé, et la chaîne vide le dit aussi bien qu'un `NULL`.
 *
 *     ⚠️ Conséquence assumée : une extension installée AVANT cette migration
 *     porte `''` — sa mise à jour est refusée fail-closed
 *     (`rollback_package_missing`), le chemin de secours étant désinstaller
 *     puis réinstaller. On préfère un refus explicite à un update dont on ne
 *     sait pas revenir.
 *
 *  2. **`extension_install_runs` porte l'ÉTAT D'UNE OPÉRATION, pas un journal
 *     d'audit.** Les deux coexistent sans se recouvrir :
 *     `extension_audit_logs` répond à « qui a décidé quoi » (append-only,
 *     conservé), cette table-ci répond à « où en est-on » (mutable, lue par la
 *     page pendant quelques minutes). La persister EST ce qui rend la
 *     progression indolore à un rechargement, visible d'un second admin, et
 *     consultable après coup — trois exigences qu'un état porté par le
 *     composant Livewire ou par le cache ne couvre pas.
 *
 *     `queue_task_runs` (tracking générique des workers) n'est PAS le bon
 *     support : c'est de la supervision d'infrastructure (log_lines, purge à
 *     14 j), pas un état métier par-extension. Le Job y apparaîtra
 *     gratuitement via les hooks `Queue::before/after` — on n'y écrit rien.
 *
 *  3. **`error` est une CATÉGORIE COURTE, jamais un message brut.** Troisième
 *     déclinaison de la règle `last_error` (56.1, puis `details` d'audit en
 *     56.2) : la valeur est lue par une UI, et un message d'exception Guzzle
 *     suffixe l'URI complète — qui peut porter un jeton. Borne 191 (index-safe
 *     MySQL, sans objet ici mais convention du projet), `NOT NULL DEFAULT ''`.
 *
 *  4. **`requested_by_login` est dénormalisé** à côté de la FK
 *     `nullOnDelete` : la trace d'une opération doit rester lisible après le
 *     départ de l'admin qui l'a lancée (patron des tables d'audit du projet).
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Rejouable** : gardes `hasTable` / `hasColumn` partout. Branches driver
 * `timestampTz` / `timestamp` : les tests HÔTE rejouent toutes les migrations
 * sur SQLite (`RefreshDatabase`), qui ne connaît pas `timestamptz`.
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ».
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // ── extensions : le gage de rollback de la mise à jour ──────────────
        if (Schema::hasTable('extensions') && ! Schema::hasColumn('extensions', 'installed_sha256')) {
            Schema::table('extensions', function (Blueprint $table): void {
                $table->string('installed_sha256', 64)->default('')
                    ->comment('sha256 du .deb RÉELLEMENT installé — désigne le paquet de rollback en staging (décision #1)');
            });
        }

        // ── extension_install_runs : l'état d'une opération en cours ────────
        if (! Schema::hasTable('extension_install_runs')) {
            Schema::create('extension_install_runs', function (Blueprint $table) use ($driver): void {
                $table->id();

                $table->foreignId('extension_id')
                    ->constrained('extensions')
                    ->cascadeOnDelete();

                $table->string('operation', 16)
                    ->comment('install | update | remove');

                $table->string('status', 16)->default('pending')
                    ->comment('pending | running | success | failed');

                $table->string('current_step', 32)->default('')
                    ->comment('Constante STEP_* du moteur — libellé résolu à l\'affichage');

                $table->json('steps')->nullable()
                    ->comment('Étapes ACCOMPLIES, dans l\'ordre (liste de constantes STEP_*)');

                $table->string('error', 191)->default('')
                    ->comment('Catégorie COURTE — jamais d\'URL, jamais de secret (décision #3)');

                // Review 56.3 #3 — un run peut réussir SANS avoir rien fait :
                // l'écran de l'admin était périmé et l'état demandé était déjà
                // en place (déjà installée, déjà à jour, déjà retirée). AC5
                // exige alors un toast INFO, pas un « terminée » qui laisse
                // croire que ce clic-ci a agi. Sans cette colonne, l'UI ne
                // pouvait pas faire la différence.
                $table->boolean('changed')->default(true)
                    ->comment('L\'opération a-t-elle réellement changé l\'état ? false = no-op sur écran périmé');

                $table->foreignId('requested_by_user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->string('requested_by_login', 191)->default('')
                    ->comment('Dénormalisé : la trace survit au départ de l\'admin (décision #4)');

                if ($driver === 'pgsql') {
                    $table->timestampTz('started_at')->nullable();
                    $table->timestampTz('finished_at')->nullable();
                    $table->timestampsTz();
                } else {
                    $table->timestamp('started_at')->nullable();
                    $table->timestamp('finished_at')->nullable();
                    $table->timestamps();
                }

                // La lecture chaude de la bibliothèque est « le dernier run par
                // extension » et « existe-t-il un run actif » : les deux
                // passent par ces deux colonnes.
                $table->index(['extension_id', 'id'], 'ext_install_runs_ext_id_idx');
                $table->index('status', 'ext_install_runs_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('extension_install_runs')) {
            Schema::drop('extension_install_runs');
        }

        if (Schema::hasTable('extensions') && Schema::hasColumn('extensions', 'installed_sha256')) {
            Schema::table('extensions', function (Blueprint $table): void {
                $table->dropColumn('installed_sha256');
            });
        }
    }
};
