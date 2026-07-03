<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Story 35.5 (flip d'activation, intégration ultradev vague 2) — lève le GATE
 * D'HONNÊTETÉ posé par le seed `2026_07_03_130000` : la capacité
 * `photo_viewer_restored` était seedée `is_active = false` parce que l'agent
 * rejetait `name: ""` (valeur par défaut d'une clé — les 2 commandes open/print
 * de la visionneuse). La Story 35.2 a livré et PROUVÉ le support (`parseRegistrySpec`
 * accepte `name` présent-et-vide, Ops Windows Get/Set/DeleteValue("") = default
 * value, tests Go dédiés, agent 2.4.0) → le gate n'a plus de raison d'être.
 *
 * Fait AUSSI ce que le seed exigeait de sa migration de flip (review 35.5 #3) :
 * réécrit `description` pour retirer la phrase « Inactive tant que… » — sinon le
 * tooltip UI mentirait après activation.
 *
 * IDEMPOTENTE (update ciblé par key, rejouable) ; `down()` restaure le gate
 * (inactive + phrase de gate), inverse exact.
 *
 * ⚠️ Effet au poste conditionné à la PUBLICATION de l'agent ≥ 2.4.0 (un binaire
 * antérieur rend {status: error} isolé sur les 2 items name="" — update.sh ne
 * publie jamais seul).
 */
return new class extends Migration
{
    private const KEY = 'photo_viewer_restored';

    private const DESCRIPTION_ACTIVE = 'Réenregistre la Visionneuse de photos Windows (commandes open/print + DropTarget) '
        .'pour la session — iso-GPO CD95 « Ajustement_Photo ». Ne choisit pas l\'application par extension '
        .'(voir le composer d\'associations).';

    private const DESCRIPTION_GATED = 'Réenregistre la Visionneuse de photos Windows (commandes open/print + DropTarget) '
        .'pour la session — iso-GPO CD95 « Ajustement_Photo ». Inactive tant que l\'agent ne sait '
        .'pas écrire la valeur par défaut d\'une clé.';

    public function up(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        DB::table('capabilities')->where('key', self::KEY)->update([
            'is_active' => true,
            'description' => self::DESCRIPTION_ACTIVE,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        DB::table('capabilities')->where('key', self::KEY)->update([
            'is_active' => false,
            'description' => self::DESCRIPTION_GATED,
            'updated_at' => now(),
        ]);
    }
};
