<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('irundo_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();             // ID local SambaEdu
            $table->uuid('irundo_task_id');            // ID MassActionInstance côté IRUNDO (pour callback)
            $table->string('name');                    // Nom donné par l'utilisateur (transmis par IRUNDO)
            $table->string('type');                    // 'greetme', etc. (voir TaskType)
            $table->json('payload')->nullable();       // Données de la tâche
            $table->enum('status', [
                'received',     // Reçue
                'queued',       // En file d'attente
                'in_progress',  // En cours d'exécution
                'success',      // Terminée avec succès
                'failed'        // Échec
            ])->default('received');
            $table->json('result')->nullable();        // Résultat de l'exécution
            $table->text('error_message')->nullable(); // Message d'erreur
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Callback tracking (pour retry si échec réseau)
            $table->boolean('callback_sent')->default(false);   // Résultat envoyé à IRUNDO ?
            $table->timestamp('callback_sent_at')->nullable();  // Quand le callback a réussi
            $table->timestamps();

            $table->index('irundo_task_id');
            $table->index('status');
            $table->index('callback_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('irundo_tasks');
    }
};
