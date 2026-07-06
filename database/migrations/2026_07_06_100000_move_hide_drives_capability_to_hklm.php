<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix `hide_drives` (NoDrives) : déplacement HKCU → HKLM.
 *
 * La projection seedée (lot CD95 100000) ciblait
 * `HKCU\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer\NoDrives`,
 * sur l'hypothèse que ce sous-arbre `…\CurrentVersion\Policies` était écrivable
 * par le companion de session. Constaté FAUX en runtime (log companion
 * 2026-07-06 : « création/ouverture de HKCU\…\Policies\Explorer : Accès refusé ») :
 * sur machine jointe au domaine, Windows durcit l'ACL de TOUT le sous-arbre
 * `…\Policies` sous HKCU (pas seulement `HKCU\Software\Policies\*`) → le companion
 * (contexte user standard) échoue. Même maladie que `windows_copilot_off`.
 *
 * NoDrives dispose d'un équivalent MACHINE supporté par l'Explorateur :
 *   HKLM\Software\Microsoft\Windows\CurrentVersion\Policies\Explorer\NoDrives
 * On bascule donc la projection en HKLM (scope machine, écrite par le service
 * SYSTEM). Cohérent : `hide_drives` est une capacité « contextuel par parc »
 * (scope machine/parc) — HKCU par-utilisateur était le mauvais hive dès le départ.
 * Conséquence assumée : masquage machine-wide.
 *
 * Bitmask conservé {none:0, c:4, cl:2052}. IDEMPOTENT : updateOrInsert de la
 * projection (capability_id, os, mechanism).
 *
 * Règle générale : toute clé sous `…\Policies\*` (Software\Policies OU
 * CurrentVersion\Policies) doit être projetée en HKLM (ou écrite par SYSTEM dans
 * HKU\<SID>), jamais via le companion user.
 */
return new class extends Migration
{
    private const KEY = 'hide_drives';

    private const PATH = 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer';

    public function up(): void
    {
        $this->setHive('HKLM');
    }

    public function down(): void
    {
        // Retour à la projection d'origine (HKCU) — état seedé par le lot CD95.
        $this->setHive('HKCU');
    }

    private function setHive(string $hive): void
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
                        ['hive' => $hive, 'path' => self::PATH, 'name' => 'NoDrives', 'type' => 'REG_DWORD', 'value' => ['none' => 0, 'c' => 4, 'cl' => 2052]],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ],
        );
    }
};
