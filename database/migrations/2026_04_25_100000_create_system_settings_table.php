<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 5.1c — Table K/V pour les réglages système globaux.
 *
 * Permet de persister des paramètres applicatifs (JSON) sans multiplier les
 * colonnes spécialisées sur d'autres tables. Première utilisation : onglet
 * "Quotas & FS" de la page /admin/settings (defaults par profil + TTL trash
 * + toggle purge automatique). Futures extensions possibles (DHCP, CUPS, etc.).
 *
 * Conventions de clés (point-séparé, namespace logique) :
 *   - quota.defaults                  → defaults par profil + partition
 *   - quota.trash                     → { ttl_days, purge_auto }
 *   - quota.toast.show_on_login       → bool (futur kill-switch éventuel)
 *
 * Branch pgsql → JSONB (indexable, compact) ; sinon (sqlite tests) → JSON.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 191)->unique();

            // JSONB sur Postgres, JSON sur SQLite — le cast 'array' du modèle
            // normalise des deux côtés (cf. pattern delegation_history 7.1 +
            // add_quota_snapshot 5.1b).
            if (DB::getDriverName() === 'pgsql') {
                $table->jsonb('value')->nullable();
            } else {
                $table->json('value')->nullable();
            }

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
