<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nature du parc qu'un groupe imposé demande à SE5 de garantir.
 *
 * Nullable, et non `false` par défaut : une valeur absente signifie que l'autorité
 * amont ne se prononce pas (les contrats émis avant ce champ), là où `false`
 * signifie qu'elle réclame explicitement un parc logique. La distinction porte la
 * rétro-compatibilité — sans elle, un contrat muet imposerait « logique » au lieu
 * de laisser SE5 appliquer son défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controlhub_contract_imposed_groups', function (Blueprint $table): void {
            $table->boolean('is_physical')->nullable()->after('label_name')
                ->comment('Nature du parc réclamée par l\'amont ; null = non déclarée (défaut SE5 : logique)');
        });
    }

    public function down(): void
    {
        Schema::table('controlhub_contract_imposed_groups', function (Blueprint $table): void {
            $table->dropColumn('is_physical');
        });
    }
};
