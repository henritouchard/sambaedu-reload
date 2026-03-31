<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('user_groups')
            ->whereIn('name', ['Eleves', 'Profs', 'Administratifs'])
            ->where('type', 'custom')
            ->update(['type' => 'role']);

        DB::table('user_groups')
            ->whereIn('name', [
                'Direction', 'Secretariat', 'Gestionnaire', 'Medical',
                'VieScol', 'Agent', 'AED', 'Tech', 'Autres',
                'Documentaliste', 'AESH',
            ])
            ->where('type', 'custom')
            ->update(['type' => 'function']);
    }

    public function down(): void
    {
        DB::table('user_groups')
            ->whereIn('type', ['role', 'function'])
            ->update(['type' => 'custom']);
    }
};
