<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 8.1 — Création de la table `dhcp_reservations` (FR20/FR22).
 *
 * Modèle de données dédié (décision SM #1) : ne pas stocker la réservation
 * sur `workstations` (`ip` / `mac` y existent déjà mais représentent l'état
 * observé, pas l'intention de réservation). Découplage :
 *  - une réservation peut exister sans `Workstation` (import CSV depuis un
 *    autre serveur, machine non encore enregistrée) ;
 *  - la suppression d'une machine n'efface pas la réservation
 *    (`workstation_id` ON DELETE SET NULL) ;
 *  - aucune dépendance écriture AD (NFR « rien d'AD en chemin critique »).
 *
 * Choix `string`(45) pour `ip` (vs `inet` PostgreSQL) — portabilité driver
 * test (SQLite) + IPv6 future. La validation IPv4 reste applicative
 * (`DhcpService::validateIp`).
 *
 * Index :
 *  - `mac` UNIQUE (clé d'unicité métier — empêche deux réservations pour
 *    la même carte réseau) ;
 *  - `ip` UNIQUE (une seule réservation par IP) ;
 *  - `name` UNIQUE (cohérent legacy `cn` AD machine, unique par convention) ;
 *  - `workstation_id` index FK (`SET NULL` on delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dhcp_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 63);
            $table->string('mac', 17);  // xx:xx:xx:xx:xx:xx → 17 chars
            $table->string('ip', 45);   // IPv4 (15) ou IPv6 future (45)
            $table->foreignId('workstation_id')
                ->nullable()
                ->constrained('workstations')
                ->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->unique('name');
            $table->unique('mac');
            $table->unique('ip');
            $table->index('workstation_id');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dhcp_reservations');
    }
};
