<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verdict d'application par item, pour que le canal ③ cesse d'affirmer.
 *
 * Le rapport de conformité dérivait son statut du seul `enforcement_state` :
 * `locked` valait `applied`, sans qu'aucune vérification n'ait eu lieu. Un item que
 * SE5 n'avait pas pu poser remontait donc « appliqué » à l'amont. Les
 * réconciliateurs connaissent le verdict au moment où ils passent ; cette colonne
 * le garde jusqu'à l'émission.
 *
 * `null` = aucun réconciliateur ne revendique ce type d'item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_items', function (Blueprint $table): void {
            $table->string('apply_status', 16)->nullable()->after('pull_error')
                ->comment('Verdict d\'application rapporté au canal ③ (applied|pending|error) ; null = type non revendiqué');
            $table->text('apply_detail')->nullable()->after('apply_status')
                ->comment('Motif lisible du verdict, transmis tel quel à l\'amont');
            $table->index('apply_status');
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_items', function (Blueprint $table): void {
            $table->dropIndex(['apply_status']);
            $table->dropColumn(['apply_status', 'apply_detail']);
        });
    }
};
