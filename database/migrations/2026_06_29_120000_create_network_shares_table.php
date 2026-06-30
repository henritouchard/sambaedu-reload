<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 34.1 — Fondations des lecteurs réseau gérés.
 *
 * Table `network_shares` : le RÉPERTOIRE RÉSEAU nommé (un « lecteur » côté
 * client). C'est le chaînon « MVP-B » délibérément reporté en 27.2 (table +
 * pivot d'assignation configurable). Le provisioning FS/ACL est porté par
 * {@see App\Services\Filesystem\NetworkShareService} (racine dédiée
 * `/var/sambaedu/Partages`) ; la projection agent par
 * {@see App\Services\Agent\Providers\DrivesStateProvider} (type `drives` figé).
 *
 *  - `name`           : libellé affiché (admin).
 *  - `directory_name` : segment FS sûr UNIQUE (validé applicativement
 *                       `^[A-Za-z0-9_-][A-Za-z0-9_.-]*$`, calqué `bareClassName`).
 *  - `label`          : libellé du lecteur dans l'explorateur (null → `name`).
 *  - `letter`         : lettre forcée (ex. `P:`) ou null → auto-assignée par le
 *                       provider (pool `M..Z`, déterministe).
 *  - `created_by_user_id` : auteur (FK nullable, `nullOnDelete`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_shares', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('directory_name')->unique();
            $table->string('label')->nullable();
            // Lettre courte (ex. `P:`) — nullable : le provider auto-assigne une
            // lettre libre du pool M..Z si absente.
            $table->string('letter', 8)->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_shares');
    }
};
