<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 5.1b — Snapshot quotas quotidien.
 *
 * Ajoute une colonne `quota_snapshot` sur la table `users`. Cette colonne
 * contient un document JSON par utilisateur, alimenté quotidiennement à 03h00
 * par la commande `quota:snapshot` (parsing de `xfs_quota -x -c 'report -a -N'`
 * en une passe pour toutes les partitions XFS).
 *
 * Structure du document (cf. QuotaSnapshotCommand) :
 *   {
 *     "home": {
 *       "used_kb": ..., "soft_kb": ..., "hard_kb": ...,
 *       "used_mb": ..., "soft_mb": ..., "hard_mb": ...,
 *       "percent": ..., "is_over_soft": bool, "is_over_hard": bool,
 *       "grace_days": int|null
 *     },
 *     "sambaedu": { ...idem },
 *     "captured_at": "2026-04-23T03:00:05+02:00"
 *   }
 *
 * Branch pgsql → JSONB (indexable, natif) ; sinon (sqlite tests) → JSON.
 * La colonne est nullable : un user créé entre deux runs 03h00 n'a pas de
 * snapshot — l'UI affiche "—" et le bouton Refresh manuel reste disponible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // JSONB pour l'indexabilité et la compacité (~30% plus petit que JSON).
            DB::statement('ALTER TABLE users ADD COLUMN quota_snapshot JSONB NULL');
        } else {
            // SQLite (tests) et autres — JSON générique.
            Schema::table('users', function (Blueprint $table) {
                $table->json('quota_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'quota_snapshot')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('quota_snapshot');
        });
    }
};
