<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Models\Workstation;

/**
 * Story 3.5 — D5 / AC3.2.
 *
 * Service stateless qui construit le payload de variables Blade rendu par
 * {@see IpxeMenuRenderer::renderInstallationWindowsMenu()} pour le template
 * `resources/views/ipxe/menu/installation-windows.blade.php`.
 *
 * **Architecture** : pattern iso 3.4 `LinuxInstallMenuBuilder` — séparation
 * stricte construction-variables / rendu-Blade pour permettre le test unit
 * isolé du payload sans rendu Blade.
 *
 * **Source de vérité items** : `config('ipxe.windows.menu_items')` (D11 — 7
 * entrées par défaut). L'ordre est préservé dans le menu rendu.
 */
final class WindowsInstallMenuBuilder
{
    /**
     * Construit le payload de variables Blade pour le menu
     * `/ipxe/installation-windows`.
     *
     * @param  Workstation|null  $workstation   Poste résolu (null = inconnu D7).
     * @param  string  $serverBaseUrl           URL de base du SE4FS (ex:
     *                                          `http://192.168.122.50`).
     * @param  string  $ip                      IP du poste appelant.
     * @return array<string, mixed>             Variables Blade.
     */
    public function build(?Workstation $workstation, string $serverBaseUrl, string $ip): array
    {
        $isKnown = $workstation !== null;
        $base = rtrim($serverBaseUrl, '/');

        // Sanitization defense-in-depth du nom du poste (iso 3.3 — un nom AD
        // avec caractères iPXE-special casserait le rendu).
        // Convention SambaEdu : hostnames toujours lowercase.
        $workstationName = $isKnown
            ? strtolower(IpxeHostnameSanitizer::sanitizeForIpxeOutput((string) ($workstation->name ?? 'unknown')))
            : 'unknown';

        return [
            'workstationName' => $workstationName,
            'ip' => $ip,
            'mac' => $isKnown ? (string) ($workstation->mac ?? '') : '',
            'uuid' => $isKnown
                ? IpxeHostnameSanitizer::sanitizeForIpxeOutput((string) ($workstation->uuid ?? ''))
                : '',
            'serverBaseUrl' => $base,
            'installWindowsItems' => $this->loadMenuItems(),
            'menuTimeoutMs' => (int) config('ipxe.windows.menu_timeout_ms', 10000),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.windows.background_png', 'png/windows10.png'),
            'menuDefault' => (string) config('ipxe.windows.default_variant', 'install_win11'),
            'isKnown' => $isKnown,
        ];
    }

    /**
     * Lit les items du menu depuis la config + filtre les entrées
     * incohérentes (clés manquantes, valeurs non scalaires).
     *
     * @return list<array{enum: string, label: string}>
     */
    private function loadMenuItems(): array
    {
        $raw = (array) config('ipxe.windows.menu_items', []);
        $items = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $enum = isset($entry['enum']) && is_string($entry['enum']) ? $entry['enum'] : '';
            $label = isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : '';
            if ($enum === '' || $label === '') {
                continue;
            }
            $items[] = [
                'enum' => $enum,
                'label' => $label,
            ];
        }

        return $items;
    }
}
