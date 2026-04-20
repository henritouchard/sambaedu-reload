<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 4-2 — correction review #1 (NFR2) & #2 (race condition restart).
 *
 * Table persistante pour suivre l'état des actions power dispatchées en
 * asynchrone via DispatchMachinePowerActionJob. Permet à l'UI Livewire
 * (pages/parc/machines/[id]/index.blade.php) de :
 *
 *  - retourner immédiatement un toast "Action lancée" sans attendre le
 *    Process::run (NFR2 : feedback < 500 ms — review #1 option A),
 *  - suivre la progression via polling (`wire:poll`) en lisant la ligne
 *    `machine_power_action_tasks` correspondante (status=queued/dispatched/
 *    running/completed/failed),
 *  - gérer la machine à états d'un `restart` via `restart_phase`
 *    (waiting-down → waiting-up) et éviter le faux succès "machine déjà up"
 *    détecté à t+3s (review #2 option A).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('machine_power_action_tasks', function (Blueprint $table) {
            $table->id();

            // Référence au poste. Nullable + set null pour préserver l'historique
            // si le Workstation est supprimé pendant que la tâche tourne.
            $table->foreignId('workstation_id')
                ->nullable()
                ->constrained('workstations')
                ->onDelete('set null');

            // Action demandée. On n'utilise pas un enum PG natif pour garder
            // de la flexibilité (ajout d'actions futures sans migration destructive) ;
            // le contrôle de validité est porté par WorkstationService::VALID_ACTIONS.
            $table->string('action', 32)
                ->comment('wake | shutdown | shutdown-force | restart');

            // Cycle de vie de la tâche.
            // queued      : ligne créée, job pas encore pris par le worker
            // dispatched  : job pris par le worker, avant l'appel service
            // running     : service power appelé, en attente de retour
            // completed   : action résolue en succès
            // failed      : action résolue en échec (ou exception)
            $table->string('status', 16)
                ->default('queued')
                ->comment('queued | dispatched | running | completed | failed');

            // Identifiant de l'initiateur (email / nom / id legacy — on reste
            // string pour éviter le couplage dur à users.id tant que la table
            // Laravel users n'est pas source de vérité).
            $table->string('initiated_by', 100)->nullable();

            // Horodatages de cycle de vie. initiated_at est obligatoire
            // (ligne créée = action demandée), les autres se remplissent
            // progressivement via DispatchMachinePowerActionJob.
            $table->timestamp('initiated_at')->useCurrent();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Résultat structuré du service (json libre — contient le code 201/202/203,
            // le message, l'OS détecté le cas échéant, etc.).
            $table->json('result')->nullable();

            // Message d'erreur human-readable. Distinct de result pour pouvoir
            // trier / filtrer facilement côté UI sans parser le JSON.
            $table->text('error_message')->nullable();

            // Machine à états spécifique au restart (review #2).
            // - waiting-down : on attend que la machine cesse de répondre
            // - waiting-up   : la machine a été détectée offline, on attend le retour
            // - null         : non applicable (autres actions)
            $table->string('restart_phase', 16)
                ->nullable()
                ->comment('waiting-down | waiting-up — uniquement pour action=restart');

            $table->timestamps();

            // Index pour les requêtes UI (polling) et le monitoring.
            $table->index(['workstation_id', 'status']);
            $table->index('status');
            $table->index('initiated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_power_action_tasks');
    }
};
