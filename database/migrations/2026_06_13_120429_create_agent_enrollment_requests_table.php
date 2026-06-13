<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 25.3 — Porte 2 de l'enrôlement (FR16, gap architecture n° 3).
 *
 * `agent_enrollment_requests` — une ligne par demande d'enrôlement d'un poste
 * MIGRÉ (existant, agent posé par la GPO-dispatcher 25.4) qui rejoue son
 * `POST /v1/agent/enrollment` SANS ticket. La demande précède le rapprochement
 * et peut viser un poste inconnu en DB → table dédiée, PAS de colonnes sur
 * `workstations` (la demande n'est pas un poste).
 *
 * Faisceau de preuves (décision n° 1) : `mac` (ancre fiable, normalisée
 * lowercase `:` iso {@see \App\Ipxe\Support\MacAddressNormalizer}), `hostname`
 * (corroborant), `uuid` SMBIOS (corroborant faible — peu fiable, jamais
 * suffisant seul). Toutes nullable : une demande où aucune preuve ne porte est
 * tout de même tracée, mais jamais auto-approuvable.
 *
 * `matched_workstation_id` — le poste connu rapproché par le faisceau, null si
 * inconnu (`nullOnDelete` : la suppression du poste ne supprime pas l'audit de
 * la demande, elle la dé-rapproche). `status` (`pending`|`approved`|`rejected`)
 * = domaine fermé VALIDÉ EN CODE (varchar non appliqué par SQLite — piège
 * n° 5 / 25.1 piège n° 9). `auto_approved` distingue l'auto-approbation de
 * campagne du clic admin. `last_seen_at` = récence (le poste rejoue à chaque
 * check-in tant qu'il n'est pas approuvé — idempotence). `resolved_at` /
 * `resolved_by` = audit de la résolution manuelle.
 *
 * **Pas de contrainte unique partielle** : l'unicité métier (une seule demande
 * vivante par faisceau) est portée en code par `updateOrCreate` sur la clé du
 * faisceau (parité SQLite des tests, piège n° 5). Idempotence stricte de la
 * migration via `Schema::hasTable()` (iso `2026_06_12_120000`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_enrollment_requests')) {
            return;
        }

        Schema::create('agent_enrollment_requests', function (Blueprint $table): void {
            $table->id();
            // Faisceau de preuves — toutes nullable, aucune suffisante seule.
            $table->string('mac', 17)->nullable()
                ->comment('MAC normalisée lowercase `:` (ancre fiable) — clé du faisceau');
            $table->string('hostname', 255)->nullable()
                ->comment('Hostname court présenté par le poste (corroborant)');
            $table->string('uuid', 64)->nullable()
                ->comment('UUID SMBIOS (corroborant faible — jamais suffisant seul, gap 3)');
            // Poste connu rapproché — null si inconnu. nullOnDelete : on garde
            // l'audit de la demande même si le poste disparaît.
            $table->foreignId('matched_workstation_id')->nullable()
                ->constrained('workstations')->nullOnDelete();
            // Domaine fermé validé en code (pending|approved|rejected).
            $table->string('status', 16)->default('pending')->index()
                ->comment('pending|approved|rejected — domaine fermé validé en code');
            $table->boolean('auto_approved')->default(false)
                ->comment('Auto-approbation de campagne (true) vs clic admin (false)');
            $table->timestamp('last_seen_at')->nullable()
                ->comment('Récence — rafraîchie à chaque re-POST du même faisceau (idempotence)');
            $table->timestamp('resolved_at')->nullable()
                ->comment('Horodatage de la résolution (approbation/rejet)');
            $table->unsignedBigInteger('resolved_by')->nullable()
                ->comment('Id de l\'admin qui a résolu manuellement (audit) — null si auto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_enrollment_requests');
    }
};
