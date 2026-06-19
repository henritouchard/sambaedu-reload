<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Capacité « Désactiver le Microsoft Store » — ajout au lot iso (modèle
 * capability-first 27.12). Capacité NEUVE (aucune source GPO legacy `se4_*`).
 *
 * IDEMPOTENT : même pattern que le seed du lot iso (2026_06_18_100300) —
 * `updateOrInsert` par `key` puis par `(capability_id, os, mechanism)`.
 *
 * Mécanisme : policy GPO « Désactiver l'application Store » =
 *   HKLM\SOFTWARE\Policies\Microsoft\WindowsStore\RemoveWindowsStore (REG_DWORD)
 *   1 = Store bloqué · 0 = Store accessible (défaut Windows).
 * Scope MACHINE (HKLM) → seul `RegistryMachineCapabilityProvider` l'émet.
 *
 * Map SYMÉTRIQUE {on:1, off:0} (règle 27.12 : si l'UI propose « off », off doit
 * réécrire une vraie valeur — ici 0 = réactivation, défaut Windows).
 *
 * Défaut diffusé = `on` (Store bloqué sur tout le parc sans override ; les rares
 * postes qui doivent garder le Store reçoivent un override `off` par maille).
 *
 * ⚠️ `RemoveWindowsStore` n'est pleinement effectif que sur Windows
 * Education/Enterprise ; sur Pro/Home la policy peut être ignorée par le poste
 * (clé bien écrite côté agent, mais effet non garanti). Mention en description.
 */
return new class extends Migration
{
    private const KEY = 'windows_store_disabled';

    public function up(): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

        $now = now();

        DB::table('capabilities')->updateOrInsert(
            ['key' => self::KEY],
            [
                'label' => 'Désactiver le Microsoft Store',
                'description' => 'Bloque l\'accès au Microsoft Store (policy RemoveWindowsStore). '
                    . 'Réglage machine ; redémarrage requis. '
                    . 'Pleinement effectif uniquement sur les éditions Windows Education/Enterprise '
                    . '(sur Pro/Home la policy peut être ignorée).',
                'category' => 'Optimisations',
                'value_type' => 'toggle',
                'options' => json_encode([
                    ['value' => 'on', 'label' => 'Activé'],
                    ['value' => 'off', 'label' => 'Désactivé'],
                ], JSON_UNESCAPED_UNICODE),
                'default_value' => 'on',
                'warning' => null,
                'applies_to_os' => json_encode(['windows'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'overrides_locked' => false,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $capabilityId = DB::table('capabilities')->where('key', self::KEY)->value('id');
        if ($capabilityId === null) {
            return;
        }

        DB::table('capability_projections')->updateOrInsert(
            [
                'capability_id' => $capabilityId,
                'os' => 'windows',
                'mechanism' => 'registry',
            ],
            [
                'spec' => json_encode([
                    'keys' => [
                        // symétrique : on (blocage actif) = 1, off = 0 (Store accessible, défaut Windows).
                        ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\WindowsStore', 'name' => 'RemoveWindowsStore', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('capabilities')) {
            return;
        }

        // FK cascadeOnDelete : supprimer la capacité retire sa projection + overrides.
        DB::table('capabilities')->where('key', self::KEY)->delete();
    }
};
