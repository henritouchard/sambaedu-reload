<?php

declare(strict_types=1);

namespace App\Services\Agent\Tools;

use App\Models\AgentTool;

/**
 * Story 25.6 — Résolution du manifest tool/skin servi à l'agent (D6, D8(b)).
 *
 * Endpoint manifest DÉDIÉ (iso `release-manifest` 25.1) — délibérément HORS
 * des `items` desired-state : un outil de rendu N'EST PAS une ressource de
 * configuration (pas de sémantique compliant/drift/hash par item). Le golden
 * overlay/state reste donc INCHANGÉ (le contrat desired-state n'est pas touché).
 *
 * Le manifest expose :
 *  - `tool` : l'outil ACTIF (`enabled = true`) avec son `{key, filename,
 *    sha256, size}` — l'agent y lit le SHA-256 attendu du portable (D6,
 *    remplace la constante Go `RainmeterToolChecksum`). Outil absent ou
 *    désactivé → `tool: null` → no-op gracieux côté agent (D4) ;
 *  - `skin` : la skin d'overlay servie `{filename, sha256}` — l'agent la
 *    télécharge (vérif hash AVANT écriture) puis la convertit UTF-16 LE + BOM.
 *    Skin introuvable/illisible côté serveur → `skin: null`.
 *
 * Lecture seule (`agent_tools` + fichier skin) ; aucune écriture. NFR7 : zéro
 * dépendance AD/LDAP/APCu.
 */
class AgentToolManifestService
{
    public function __construct(
        private readonly OverlaySkinProvisioner $skin,
    ) {
    }

    /**
     * @return array{tool: array{key: string, filename: string, sha256: string, size: int}|null, skin: array{filename: string, sha256: string}|null}
     */
    public function manifest(): array
    {
        return [
            'tool' => $this->activeTool(),
            'skin' => $this->skinEntry(),
        ];
    }

    /**
     * @return array{key: string, filename: string, sha256: string, size: int}|null
     */
    private function activeTool(): ?array
    {
        $tool = AgentTool::query()
            ->where('key', AgentToolService::RAINMETER_KEY)
            ->where('enabled', true)
            ->first();

        if ($tool === null) {
            return null; // absent ou désactivé → no-op gracieux côté agent (D4)
        }

        return [
            'key' => $tool->key,
            'filename' => $tool->filename,
            'sha256' => $tool->sha256,
            'size' => (int) $tool->size,
        ];
    }

    /**
     * @return array{filename: string, sha256: string}|null
     */
    private function skinEntry(): ?array
    {
        $checksum = $this->skin->servedChecksum();
        if ($checksum === null) {
            return null;
        }

        return [
            // Nom de fichier servi par la route skin dédiée (filename FIXE, pas
            // d'input client — anti-traversal). L'agent dérive l'URL.
            'filename' => 'SambaEduOverlay.ini',
            'sha256' => $checksum,
        ];
    }
}
