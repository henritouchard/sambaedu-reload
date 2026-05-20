<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalise toutes les MAC stockées en lowercase (parité avec
 * {@see \App\Ipxe\Support\MacAddressNormalizer::normalize()} qui retourne
 * toujours du lowercase).
 *
 * Pré-condition de retrait du `whereRaw('lower(mac) = ?', ...)` côté
 * {@see \App\Ipxe\Services\WorkstationLocator} — restaure l'usage de l'index
 * B-tree standard sur `workstations.mac` et garantit le déterminisme du
 * fallback MAC lookup. Un mutator côté modèle Workstation force le lowercase
 * sur tout futur write.
 *
 * Migration irréversible (`down()` no-op) : la casse originale n'est pas
 * conservée — restaurer en mixed-case n'a aucun usage métier (les MACs sont
 * canoniquement lowercase iso firmware iPXE / `samba-tool`).
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('workstations')
            ->whereRaw('mac IS NOT NULL AND mac <> LOWER(mac)')
            ->update(['mac' => DB::raw('LOWER(mac)')]);
    }

    public function down(): void
    {
        // No-op : la casse mixed n'a pas de valeur métier à restaurer.
    }
};
