<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 3.6 — D9 / AC1.1.
 *
 * Table `windows_iso_downloads` — trace par tentative des téléchargements
 * d'ISO Windows (Win10/Win11) lancés depuis la page admin web SE5
 * `/admin/ipxe/iso-windows`.
 *
 * Audit complet par opération admin serveur :
 *  - `version`         : 'Win10' | 'Win11' (CHECK applicatif via enum).
 *  - `iso_name`        : 'Win11_24H2.iso' (validé regex iso-legacy).
 *  - `source_url`      : URL Microsoft saisie par l'admin (publique).
 *  - `status`          : pending|downloading|extracting|success|failed|cancelled.
 *  - `started_at`      : début du `curl` (transition pending → downloading).
 *  - `completed_at`    : fin (success | failed | cancelled).
 *  - `exit_code`       : exit code du Process Symfony (null tant que running).
 *  - `error`           : stderr abrégé (≤ 2000 chars applicatif — text en DB).
 *  - `initiated_by_user_id` : FK users (Q2 Henri 2026-05-21 = nullOnDelete +
 *    nullable — conserve l'audit trail si l'admin est supprimé. La row n'est
 *    pas effacée, seul le pointeur user devient `null`).
 *  - `host_ip`         : IP de l'admin (IPv4/IPv6, validée FILTER_VALIDATE_IP
 *    côté orchestrator — Opus-D — nullable car non garanti si requête atypique).
 *
 * Pas de FK vers `windows_iso_downloads` côté Workstation/MachineBootLog
 * (D12 — cible = opération serveur, pas machine).
 *
 * Indexes (UI = historique desc + filtre par status) :
 *  - (status, created_at) — bandeau "en cours" + listing récents.
 *  - (version, status)    — filtre Win10/Win11 + status.
 *  - (created_at)         — Opus-G — tri historique desc sans filtre status
 *    (la requête `orderByDesc('created_at')->take(10)` est full-scan + sort
 *    sans cet index).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('windows_iso_downloads', function (Blueprint $t): void {
            $t->id();
            $t->string('version', 10);            // 'Win10' | 'Win11'
            $t->string('iso_name', 255);          // 'Win11_24H2.iso'
            $t->string('source_url', 2048);       // URL Microsoft saisie
            $t->string('status', 20)->default('pending'); // enum applicatif
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->integer('exit_code')->nullable();
            $t->text('error')->nullable();        // stderr abrégé (≤ 2000 chars applicatif)
            // Q2 Henri 2026-05-21 : `nullOnDelete + nullable` — préserve
            // l'historique d'audit si l'admin est supprimé (ne pas perdre la
            // trace « qui a déclenché quel download »).
            $t->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('host_ip', 45)->nullable(); // IPv4 / IPv6
            $t->timestamps();

            $t->index(['status', 'created_at'], 'wid_status_created_idx');
            $t->index(['version', 'status'], 'wid_version_status_idx');
            // Opus-G — index sur created_at seul pour le tri historique desc.
            $t->index('created_at', 'wid_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('windows_iso_downloads');
    }
};
