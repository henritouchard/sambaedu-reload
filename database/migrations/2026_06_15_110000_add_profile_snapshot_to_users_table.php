<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 26.3 — Snapshot quotidien des tailles de profils itinérants.
 *
 * Ajoute une colonne `profile_snapshot` sur la table `users`. Cette colonne
 * contient un document JSON par utilisateur, alimenté quotidiennement à 04h30
 * par la commande `profiles:snapshot` (parsing de
 * `du --max-depth=1 -b /home/profiles` en une passe).
 *
 * Calque exactement le pattern `quota_snapshot` (story 5.1b) : un job nocturne
 * écrit la colonne, l'UI (tableau /app/users) lit la colonne sans aucun
 * shellout par ligne rendue. CONTRAINTE PERF non négociable : `du`/scan FS =
 * job nocturne UNIQUEMENT.
 *
 * Structure du document (cf. ProfilesSnapshotCommand) :
 *   {
 *     "size_mb": 124.5,
 *     "size_bytes": 130560000,
 *     "dir": "alice.V6",
 *     "captured_at": "2026-06-15T04:30:05+02:00"
 *   }
 *
 * Branch pgsql → JSONB (indexable, natif) ; sinon (sqlite tests) → JSON.
 * La colonne est nullable : un user sans profil itinérant (ou pas encore
 * scanné) n'a pas de snapshot — l'UI n'affiche alors aucun badge.
 *
 * Les profils ORPHELINS (dossier `/home/profiles/<x>` sans compte user) ne
 * peuvent pas être stockés ici (pas de ligne user) : ils sont persistés
 * séparément dans `SystemSetting` clé `profiles.orphans`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'profile_snapshot')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users ADD COLUMN profile_snapshot JSONB NULL');
        } else {
            // SQLite (tests) et autres — JSON générique.
            Schema::table('users', function (Blueprint $table) {
                $table->json('profile_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'profile_snapshot')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_snapshot');
        });
    }
};
