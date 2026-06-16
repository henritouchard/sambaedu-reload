<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 25.6 — AC1 (D2).
 *
 * Catalogue des OUTILS DE RENDU posés par l'agent au bootstrap. Table DÉDIÉE
 * simple (PAS de table polymorphe générique « tous assets » — D2) : Rainmeter
 * en est la première et seule entrée envisagée en MVP. Généralise le couple
 * `RainmeterToolChecksum` / `RainmeterToolFilename` figé en dur dans le binaire
 * Go (27.1bis) vers une entrée de catalogue dont le SERVEUR est l'autorité.
 *
 *  - `key`        — identifiant fonctionnel stable de l'outil (`rainmeter`),
 *    unique : un nouvel upload du même outil REMPLACE la version active
 *    (mono-version MVP — D5) ;
 *  - `name`       — libellé d'affichage (UI) ;
 *  - `filename`   — nom du `.zip` rangé sous `config('agent.tools_path')`,
 *    matchant la regex stricte de {@see \App\Http\Controllers\Api\V1\Agent\ToolController}
 *    (`sambaedu-rainmeter-<version>.zip`) — le serving aval réutilise ce nom ;
 *  - `sha256`     — SHA-256 hex CALCULÉ SERVEUR à l'upload (`hash_file`) ;
 *    l'agent le lit depuis le manifest et le vérifie AVANT extraction (D6) ;
 *  - `size`       — taille en octets (bigint) ;
 *  - `enabled`    — toggle GLOBAL (D3) : true → l'outil est exposé actif dans
 *    le manifest et déployé ; false → no-op côté agent, SANS désinstaller (D4) ;
 *  - `uploaded_at`/`uploaded_by` — traçabilité de l'upload (FK users nullable,
 *    `nullOnDelete` : la suppression d'un compte ne casse pas le catalogue).
 *
 * Idempotence stricte (`Schema::hasTable()`, iso création releases). Les
 * longueurs varchar ne sont pas appliquées par SQLite (piège tests) — les
 * domaines fermés (key, filename, hash) sont validés en code par
 * {@see \App\Services\Agent\Tools\AgentToolService}. SEUL écrivain de la table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_tools')) {
            Schema::create('agent_tools', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 32)->unique()
                    ->comment('Identifiant fonctionnel de l\'outil (ex. rainmeter) — mono-version, un upload remplace');
                $table->string('name', 128)
                    ->comment('Libellé d\'affichage (UI)');
                $table->string('filename', 255)
                    ->comment('Nom du .zip dans storage/agent/tools/ (sambaedu-rainmeter-<version>.zip)');
                $table->string('sha256', 64)
                    ->comment('SHA-256 hex CALCULÉ SERVEUR à l\'upload — vérifié côté agent AVANT extraction (D6)');
                $table->unsignedBigInteger('size')
                    ->comment('Taille de l\'archive en octets');
                $table->boolean('enabled')->default(false)->index()
                    ->comment('Toggle GLOBAL (D3) : activé → exposé/déployé ; désactivé → no-op sans désinstaller (D4)');
                $table->timestamp('uploaded_at')->nullable()
                    ->comment('Horodatage du dernier upload validé');
                $table->foreignId('uploaded_by')->nullable()
                    ->constrained('users')->nullOnDelete()
                    ->comment('Admin ayant uploadé (traçabilité ; nullable si compte supprimé)');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tools');
    }
};
