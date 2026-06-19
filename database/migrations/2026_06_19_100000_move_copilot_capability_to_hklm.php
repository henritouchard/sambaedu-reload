<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix `windows_copilot_off` : déplacement HKCU → HKLM.
 *
 * La projection seedée (lot iso 100300) ciblait
 * `HKCU\Software\Policies\Microsoft\Windows\WindowsCopilot\TurnOffWindowsCopilot`.
 * Or `HKCU\Software\Policies\*` est en LECTURE SEULE pour l'utilisateur standard
 * (ACL Windows, anti-contournement GPO) → le companion de session (contexte user)
 * échoue avec « Accès refusé » (constaté dans les logs companion 2026-06-18).
 *
 * Copilot dispose d'un équivalent MACHINE supporté :
 *   HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsCopilot\TurnOffWindowsCopilot = 1
 * On bascule donc la projection en HKLM (scope machine, écrite par le service
 * SYSTEM comme `uac_enabled` / `windows_store_disabled`). Conséquence assumée :
 * la désactivation devient machine-wide (cohérent avec la gestion par parc).
 *
 * Map SYMÉTRIQUE conservée {on:1, off:0} (27.12). IDEMPOTENT : updateOrInsert de
 * la projection (capability_id, os, mechanism).
 *
 * Règle générale : toute clé `…\Software\Policies\*` doit être projetée en HKLM
 * (ou écrite par SYSTEM dans HKU\<SID>), jamais via le companion user.
 */
return new class extends Migration
{
    private const KEY = 'windows_copilot_off';

    public function up(): void
    {
        $this->setHive('HKLM', 'SOFTWARE\\Policies\\Microsoft\\Windows\\WindowsCopilot');
    }

    public function down(): void
    {
        // Retour à la projection d'origine (HKCU) — état seedé par le lot iso.
        $this->setHive('HKCU', 'Software\\Policies\\Microsoft\\Windows\\WindowsCopilot');
    }

    private function setHive(string $hive, string $path): void
    {
        if (! Schema::hasTable('capabilities') || ! Schema::hasTable('capability_projections')) {
            return;
        }

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
                        ['hive' => $hive, 'path' => $path, 'name' => 'TurnOffWindowsCopilot', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ],
        );
    }
};
