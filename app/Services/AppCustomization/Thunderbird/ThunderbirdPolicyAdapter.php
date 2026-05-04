<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Thunderbird;

use App\Services\AppCustomization\Contracts\AppPolicyAdapter;
use App\Support\AtomicFileWriter;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Thunderbird — parité `tb_import_policy` legacy.
 *
 * Story 4.8 — AC 6. Reproduit `sambaedu/includes/firefox.inc.php` L201-249
 * (fonction `tb_import_policy`). Différences Firefox :
 *   - Pas de `PopupBlocking` (Thunderbird n'a pas de notion de popup browser).
 *   - `HTTPProxy` préfixé `http://` (fidèle L233).
 */
class ThunderbirdPolicyAdapter implements AppPolicyAdapter
{
    /** @var string[] Clés racines `policies.*` éditables via l'UI admin (MVP: Proxy uniquement). */
    private const WHITELISTED_KEYS = [
        'Proxy',
    ];

    public function getTemplate(): array
    {
        $paths = (array) config('app-customizations.template_paths.thunderbird', []);
        if ($paths === []) {
            $paths = [
                '/usr/share/sambaedu/applications/thunderbird/default.json',
                storage_path('app/app-customizations/thunderbird/template.json'),
            ];
        }

        foreach ($paths as $path) {
            if (! is_string($path) || ! is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            Log::warning('[ThunderbirdPolicyAdapter] template invalide', ['path' => $path]);
        }

        return ['policies' => []];
    }

    public function applyAuto(array $template, array $systemConfig): array
    {
        $json = $template;
        $json['policies'] = (array) ($json['policies'] ?? []);

        $proxyType = (string) ($systemConfig['proxy_type'] ?? 'aucun');
        $proxyMap = [
            'manuel' => 'manual',
            'aucun' => 'none',
            'automatique' => 'autoDetect',
        ];
        $proxyMode = $proxyMap[$proxyType] ?? 'none';

        // Proxy (fidèle L220-227 firefox.inc.php :: tb_import_policy)
        $json['policies']['Proxy'] = [
            'Mode' => $proxyMode,
            'Locked' => true,
            'UseHTTPProxyForAllProtocols' => true,
            'Passthrough' => '<local>',
            'AutoLogin' => false,
            'UseProxyForDNS' => false,
        ];

        // DNSOverHTTPS (fidèle L228-231)
        $json['policies']['DNSOverHTTPS'] = [
            'Enabled' => false,
            'Locked' => true,
        ];

        // HTTP Proxy manuel — DIFFÉRENCE Firefox : préfixe `http://` (L232-234)
        $proxyAddress = (string) ($systemConfig['proxy_address'] ?? '');
        $proxyPort = (string) ($systemConfig['proxy_port'] ?? '');
        if ($proxyType === 'manuel' && $proxyAddress !== '' && $proxyPort !== '') {
            $json['policies']['Proxy']['HTTPProxy'] = 'http://' . $proxyAddress . ':' . $proxyPort;
        }

        return $json;
    }

    public function mergeOverrides(array $base, array $overrides): array
    {
        return array_replace_recursive($base, $overrides);
    }

    public function renderFormComponent(): string
    {
        return 'components::organisms.thunderbird.customize-form';
    }

    public function exportToFs(array $policies, string $path): bool
    {
        $json = json_encode($policies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            Log::error('[ThunderbirdPolicyAdapter] json_encode failed', ['path' => $path]);
            return false;
        }

        return AtomicFileWriter::write($path, $json);
    }

    public function validatePolicies(array $policies): array
    {
        $errors = [];
        $maxSize = (int) config('app-customizations.max_policies_size', 262144);

        $encoded = json_encode($policies);
        if ($encoded !== false && strlen($encoded) > $maxSize) {
            $errors['policies_size'] = sprintf(
                'Taille des policies dépassée (%d > %d octets).',
                strlen($encoded),
                $maxSize,
            );
        }

        if (! isset($policies['policies']) || ! is_array($policies['policies'])) {
            return $errors;
        }

        if (isset($policies['policies']['Proxy']) && ! is_array($policies['policies']['Proxy'])) {
            $errors['Proxy'] = 'Le champ Proxy doit être un objet.';
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $policies
     * @return array<string,mixed>
     */
    public function stripNonWhitelistedOverrides(array $policies): array
    {
        $clean = ['policies' => []];
        $source = (array) ($policies['policies'] ?? []);
        foreach (self::WHITELISTED_KEYS as $key) {
            if (array_key_exists($key, $source)) {
                $clean['policies'][$key] = $source[$key];
            }
        }
        return $clean;
    }

    /** @return string[] */
    public static function whitelistedKeys(): array
    {
        return self::WHITELISTED_KEYS;
    }
}
