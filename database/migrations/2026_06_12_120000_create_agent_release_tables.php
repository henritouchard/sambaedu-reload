<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 25.1 — AC1, AC2, AC3.
 *
 * Distribution des releases de l'agent desired-state (D6, FR24) — deux
 * tables :
 *
 *  - `agent_releases` — une ligne par version publiée du binaire agent
 *    (`agent:release:create`, {@see \App\Services\Agent\Releases\ReleaseCreationService}).
 *    On stocke le `filename` (la donnée stable) — l'`url` du manifest est
 *    une URL absolue calculée à la réponse (décision n° 2 : une URL figée
 *    en DB casserait au premier changement de host/scheme). `is_stable` =
 *    version par défaut des postes sans ring (au plus une ligne à true —
 *    invariant transactionnel dans le service, pas de contrainte partielle
 *    PG : parité SQLite des tests).
 *  - `agent_release_rings` — ciblage par ring : UN ring = UN WorkstationGroup
 *    existant (salle physique OU parc logique, le pivot 4.11 ne distingue
 *    pas), version cible par ring. `workstation_group_id` UNIQUE : un groupe
 *    ne pointe qu'une version à la fois ; l'`updated_at` EST la donnée de
 *    récence (décision n° 4 : conflit multi-rings = la ligne la plus
 *    récemment modifiée gagne). FK cascade des deux côtés : supprimer la
 *    release ou le groupe fait disparaître le ring (le poste retombe sur la
 *    stable — AC3).
 *
 * **Idempotence stricte** : `Schema::hasTable()` avant chaque création
 * (iso `2026_06_11_140000_create_agent_report_tables`). Types simples
 * compatibles SQLite tests ; les longueurs varchar ne sont PAS appliquées
 * par SQLite — les domaines fermés (version, hash, filename) sont validés
 * en code par `ReleaseCreationService` (piège n° 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_releases')) {
            Schema::create('agent_releases', function (Blueprint $table): void {
                $table->id();
                $table->string('version', 32)->unique()
                    ->comment('Version publiée (shared/version.go), domaine fermé validé en code');
                $table->string('hash', 64)
                    ->comment('SHA-256 hex du binaire, VÉRIFIÉ contre le fichier à la création');
                $table->string('filename', 255)->unique()
                    ->comment('Nom du binaire dans storage/agent/releases/ (sambaedu-agent-<version>.exe)');
                $table->boolean('is_stable')->default(false)->index()
                    ->comment('Version par défaut des postes sans ring — au plus une à true (invariant service)');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('agent_release_rings')) {
            Schema::create('agent_release_rings', function (Blueprint $table): void {
                $table->id();
                // UNIQUE : un WorkstationGroup = au plus un ring (une version cible).
                $table->foreignId('workstation_group_id')->unique()
                    ->constrained()->cascadeOnDelete();
                $table->foreignId('agent_release_id')
                    ->constrained('agent_releases')->cascadeOnDelete();
                // updated_at = donnée de récence (décision n° 4) : la ligne la
                // plus récemment modifiée gagne en cas de multi-rings.
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_release_rings');
        Schema::dropIfExists('agent_releases');
    }
};
