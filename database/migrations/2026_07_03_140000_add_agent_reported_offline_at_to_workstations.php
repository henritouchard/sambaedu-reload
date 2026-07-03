<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Détection d'extinction du poste : l'agent signale son arrêt (best-effort,
 * `POST /v1/agent/shutdown` au shutdown Windows). Le timestamp est comparé à
 * `agent_last_checkin_at` par {@see \App\Models\Workstation::agentPresence()} :
 * signal >= dernier check-in → poste « éteint » immédiatement, sans attendre
 * le seuil de silence (2 × ttl). Jamais remis à null : un check-in ultérieur
 * plus récent le rend simplement inopérant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->timestamp('agent_reported_offline_at')->nullable()->after('agent_last_checkin_at');
        });
    }

    public function down(): void
    {
        Schema::table('workstations', function (Blueprint $table) {
            $table->dropColumn('agent_reported_offline_at');
        });
    }
};
