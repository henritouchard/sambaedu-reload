<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.5 — D1 / AC1.2.
 * Story 3.8 — D2 / AC1.1 — étendu à 8 cases (port complet post-OOBE flows).
 *
 * Whitelist stricte des étapes Windows post-install acceptées par
 * `/ipxe/windows/action`.
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des étapes
 * tracées + dispatchées vers le builder cmd batch. Toute autre valeur
 * (`etape=arbitrary`) → 200 + log warning `ipxe.windows.action.unsupported_step`
 * + body vide (defense in depth couche 2 après `Rule::in` FormRequest).
 *
 * **8 cases en 3.8** :
 *
 *  - `Winpe` (3.5)     — début install WinPE post-boot (`recordWinpeStart`).
 *  - `Oobe`  (3.5)     — fin install (1er logon OOBE) — `os='windows'` +
 *                        `progress=100%` (`recordOobeComplete`).
 *  - `Sysprep`  (3.8)  — préparation clonage avec sysprep (cmd_sysprep — port
 *                        legacy 73-144). Variantes ret={0,1,2} → state machine.
 *  - `Nosysprep` (3.8) — préparation clonage sans sysprep (cmd_nosysprep —
 *                        port legacy 151-192). Q-2 REFACTO CLARTÉ : SE5 utilise
 *                        `etape=nosysprep` distinct (pas `etape=sysprep&ret=2`
 *                        comme legacy).
 *  - `Join` (3.8)      — mise au domaine post-clonage (cmd_join — port legacy
 *                        358-406). Variantes ret={0,1,2} → state machine.
 *  - `Renomme` (3.8)   — renommage poste au domaine (cmd_renomme — port legacy
 *                        317-351). ret=0 → AD rename via AdMachineManager.
 *  - `Post` (3.8)      — post-install manuelle (cmd_post — port legacy 198-231).
 *  - `Wpkg` (3.8)      — lancement wpkg interactif (cmd_wpkg — port legacy
 *                        268-311).
 *
 * **Migration commentaire** : la mention « Hors-scope 3.5 (déférée 3.7) » a
 * été remplacée — les 6 étapes sont maintenant portées par cette story 3.8.
 */
enum WindowsInstallStep: string
{
    case Winpe = 'winpe';
    case Oobe = 'oobe';
    case Sysprep = 'sysprep';
    case Nosysprep = 'nosysprep';
    case Join = 'join';
    case Renomme = 'renomme';
    case Post = 'post';
    case Wpkg = 'wpkg';

    /**
     * Résout une string brute vers le case enum correspondant.
     *
     * @param  string  $raw  Valeur brute reçue par le hook (multipart curl `-F`).
     * @return self|null     Case enum ou `null` si valeur hors whitelist.
     */
    public static function fromString(string $raw): ?self
    {
        // Post-review : anti-injection (cf. WindowsVersion::fromString).
        // Strip whitespace SAFE → check chars printables → tryFrom.
        $stripped = preg_replace('/^\s+|\s+$/u', '', $raw);
        if ($stripped === null) {
            return null;
        }
        if (preg_match('/[^\x20-\x7E]/', $stripped) === 1) {
            return null;
        }

        return self::tryFrom(strtolower($stripped));
    }
}
