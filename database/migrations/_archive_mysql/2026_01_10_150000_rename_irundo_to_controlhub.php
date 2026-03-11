<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Renomme toutes les références "irundo" vers "controlhub"
     */
    public function up(): void
    {
        // 1. Renommer la table irundo_tasks -> controlhub_tasks
        if (Schema::hasTable('irundo_tasks') && !Schema::hasTable('controlhub_tasks')) {
            Schema::rename('irundo_tasks', 'controlhub_tasks');
        }

        // 2. Renommer la colonne irundo_task_id -> controlhub_task_id dans controlhub_tasks
        if (Schema::hasTable('controlhub_tasks') && Schema::hasColumn('controlhub_tasks', 'irundo_task_id')) {
            Schema::table('controlhub_tasks', function (Blueprint $table) {
                $table->renameColumn('irundo_task_id', 'controlhub_task_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. Renommer la colonne controlhub_task_id -> irundo_task_id
        if (Schema::hasTable('controlhub_tasks') && Schema::hasColumn('controlhub_tasks', 'controlhub_task_id')) {
            Schema::table('controlhub_tasks', function (Blueprint $table) {
                $table->renameColumn('controlhub_task_id', 'irundo_task_id');
            });
        }

        // 2. Renommer la table controlhub_tasks -> irundo_tasks
        if (Schema::hasTable('controlhub_tasks') && !Schema::hasTable('irundo_tasks')) {
            Schema::rename('controlhub_tasks', 'irundo_tasks');
        }
    }
};
