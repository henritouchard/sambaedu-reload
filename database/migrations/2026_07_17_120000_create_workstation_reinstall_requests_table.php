<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 3.11 — D4 — Table dédiée `workstation_reinstall_requests`.
 *
 * Persiste l'intention de réinstallation OS armée par poste depuis l'admin
 * web (poste unique ou fan-out salle/groupe/multi-sélection). **Une ligne =
 * un poste.**
 *
 * Cette table est volontairement SÉPARÉE de `Workstation::programmed_action`
 * (colonne JSON déjà utilisée par le suivi *post-install* Linux/Windows) : la
 * superposer créerait une collision entre « install à armer » et « install
 * terminée à annoncer » (D4).
 *
 * Cycle de vie du `status` :
 *   armed      → requête créée, reboot pas encore déclenché (triggered_at null)
 *   serving    → au moins un PXE boot a été servi l'install (resolveProgrammedAction)
 *   installing → l'installeur a démarré (WinPE / debian-installer)
 *   done       → callback post-install confirmé (LinuxPostInstallTracker /
 *                WindowsPostInstallTracker) — ne sera plus servie
 *   failed     → TTL dépassé ou plafond de serves atteint (garde anti-boucle D5)
 *   canceled   → annulée depuis l'UI, ou poste devenu `protected` (D10 niveau 3)
 *
 * Type PostgreSQL `timestamptz` (fallback `timestamp` sous SQLite) — patron
 * migration 4.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        Schema::create('workstation_reinstall_requests', function (Blueprint $table) use ($driver): void {
            $table->id();

            // Poste ciblé. cascadeOnDelete : si le poste disparaît, sa requête
            // de réinstall n'a plus de sens (contrairement à l'audit power qui
            // survit — ici on ne conserve rien).
            $table->foreignId('workstation_id')
                ->constrained('workstations')
                ->cascadeOnDelete();

            // Valeur enum IpxeAdminAction (ex. `install_win11`, `install_deb_gnome`).
            // varchar(40) couvre largement la plus longue valeur install_* (< 20).
            $table->string('target_action', 40)
                ->comment('Valeur enum IpxeAdminAction (whitelist install-only D9)');

            // Cycle de vie (voir docblock). varchar(16) suffit à `installing` (10).
            $table->string('status', 16)
                ->default('armed')
                ->comment('armed | serving | installing | done | failed | canceled');

            // Garde anti-boucle (D5) : nb de PXE boots pour lesquels l'install a
            // été servie, borné par reinstall.max_boot_serves.
            $table->unsignedInteger('boot_served_count')->default(0);

            // Audit : qui a armé (`user:<id>` pour une action ad hoc, `group:<id>`
            // pour un fan-out salle/groupe). varchar(100) iso machine_power_action_tasks.
            $table->string('initiated_by', 100)->nullable();

            // FK nullable vers l'utilisateur qui a armé (nullOnDelete : préserve
            // la ligne si le compte admin est supprimé).
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            if ($driver === 'pgsql') {
                // `null` = déclenchement immédiat (au prochain tick).
                $table->timestampTz('scheduled_at')->nullable();
                // Reboot PXE forcé déclenché (idempotence du tick D11).
                $table->timestampTz('triggered_at')->nullable();
                // Dernier PXE boot servi (garde anti-boucle D5).
                $table->timestampTz('boot_served_at')->nullable();
                // TTL (garde anti-boucle + libération du slot de concurrence D5/D11).
                $table->timestampTz('expires_at')->nullable();
                $table->timestampsTz();
            } else {
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('triggered_at')->nullable();
                $table->timestamp('boot_served_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            }

            // « La requête active d'un poste » (resolveProgrammedAction, skip doublon).
            $table->index(['workstation_id', 'status']);
            // « Les requêtes planifiées dûes » (tick FIFO `scheduled_at`).
            $table->index(['scheduled_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workstation_reinstall_requests');
    }
};
