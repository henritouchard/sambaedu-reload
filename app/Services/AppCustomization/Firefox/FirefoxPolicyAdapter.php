<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Firefox;

use App\Services\AppCustomization\Contracts\AppPolicyAdapter;
use App\Services\AppCustomization\Support\AtomicFileWriter;
use Illuminate\Support\Facades\Log;

/**
 * Adapter Firefox — parité `ff_import_policy` + `ff_export_policy` legacy.
 *
 * Story 4.8 — AC 5. Reproduit exactement la logique de
 * `sambaedu/includes/firefox.inc.php` L7-87 (fonctions `ff_import_policy` et
 * `ff_export_policy`). `ff_form_policy` est remplacé par le composant Livewire
 * `components::organisms.firefox.customize-form`.
 */
class FirefoxPolicyAdapter implements AppPolicyAdapter
{
    /** @var string[] Clés racines `policies.*` éditables via l'UI admin. */
    private const WHITELISTED_KEYS = [
        'Homepage',
        'Bookmarks',
        'ExtensionSettings',
    ];

    public function getTemplate(): array
    {
        $paths = (array) config('app-customizations.template_paths.firefox', []);
        if ($paths === []) {
            $paths = [
                '/usr/share/sambaedu/applications/firefox/default.json',
                storage_path('app/app-customizations/firefox/template.json'),
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
            Log::warning('[FirefoxPolicyAdapter] template invalide', ['path' => $path]);
        }

        // Fallback minimal pour environnements dev/CI sans fichiers système.
        return ['policies' => []];
    }

    public function applyAuto(array $template, array $systemConfig): array
    {
        $json = $template;
        $json['policies'] = (array) ($json['policies'] ?? []);

        $se4Url = (string) ($systemConfig['se4_url'] ?? '');
        $se4FsName = (string) ($systemConfig['se4fs_name'] ?? '');

        // PopupBlocking (fidèle L21-33 firefox.inc.php)
        if ('http://' . $se4FsName === $se4Url) {
            $allow = [$se4Url];
        } else {
            $allow = [$se4Url, 'http://' . $se4FsName];
        }
        $json['policies']['PopupBlocking'] = [
            'Allow' => $allow,
        ];

        // Proxy (fidèle L34-47)
        $proxyType = (string) ($systemConfig['proxy_type'] ?? 'aucun');
        $proxyMap = [
            'manuel' => 'manual',
            'aucun' => 'none',
            'automatique' => 'autoDetect',
        ];
        $proxyMode = $proxyMap[$proxyType] ?? 'none';

        $json['policies']['Proxy'] = [
            'Mode' => $proxyMode,
            'Locked' => true,
            'UseHTTPProxyForAllProtocols' => true,
            'Passthrough' => '<local>',
            'AutoLogin' => false,
            'UseProxyForDNS' => false,
        ];

        // DNSOverHTTPS (fidèle L48-51)
        $json['policies']['DNSOverHTTPS'] = [
            'Enabled' => false,
            'Locked' => true,
        ];

        // Preferences OCSP stapling (fidèle L52)
        $json['policies']['Preferences'] = (array) ($json['policies']['Preferences'] ?? []);
        $json['policies']['Preferences']['security.ssl.enable_ocsp_stapling'] = true;

        $proxyAddress = (string) ($systemConfig['proxy_address'] ?? '');
        $proxyPort = (string) ($systemConfig['proxy_port'] ?? '');
        $proxyUrl = (string) ($systemConfig['proxy_url'] ?? '');

        // HTTP Proxy manuel (fidèle L56-58) — Firefox sans préfixe `http://`
        if ($proxyType === 'manuel' && $proxyAddress !== '' && $proxyPort !== '') {
            $json['policies']['Proxy']['HTTPProxy'] = $proxyAddress . ':' . $proxyPort;
        }

        // AutoConfig URL (fidèle L59-62)
        if ($proxyType === 'automatique' && $proxyUrl !== '') {
            $json['policies']['Proxy']['AutoConfigURL'] = $proxyUrl;
            $json['policies']['Proxy']['Mode'] = 'autoConfig';
        }

        return $json;
    }

    public function mergeOverrides(array $base, array $overrides): array
    {
        return array_replace_recursive($base, $overrides);
    }

    public function renderFormComponent(): string
    {
        return 'components::organisms.firefox.customize-form';
    }

    public function exportToFs(array $policies, string $path): bool
    {
        $json = json_encode($policies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            Log::error('[FirefoxPolicyAdapter] json_encode failed', ['path' => $path]);
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
            // Structure minimale acceptée : `policies.*` vide. Pas une erreur.
            return $errors;
        }

        // Les clés hors whitelist sont droppées silencieusement par
        // `stripNonWhitelisted` (cf. Service). Ici on valide juste la forme
        // des clés présentes.
        $allowed = self::WHITELISTED_KEYS;

        foreach (array_keys($policies['policies']) as $key) {
            if (! is_string($key)) {
                $errors['policies_key_type'] = 'Clé policies invalide (non-string).';
                break;
            }
        }

        if (isset($policies['policies']['Bookmarks']) && ! is_array($policies['policies']['Bookmarks'])) {
            $errors['Bookmarks'] = 'Le champ Bookmarks doit être une liste.';
        }

        if (isset($policies['policies']['Homepage'])) {
            $homepage = $policies['policies']['Homepage'];
            if (! is_array($homepage) || ! isset($homepage['URL'])) {
                $errors['Homepage'] = 'Le champ Homepage doit être un objet avec `URL`.';
            } elseif (! is_string($homepage['URL']) || ! filter_var($homepage['URL'], FILTER_VALIDATE_URL)) {
                $errors['Homepage.URL'] = 'URL de page d\'accueil invalide.';
            }
        }

        if (isset($policies['policies']['ExtensionSettings']) && ! is_array($policies['policies']['ExtensionSettings'])) {
            $errors['ExtensionSettings'] = 'Le champ ExtensionSettings doit être un objet.';
        }

        // Note : les clés hors whitelist ne sont PAS une erreur (comportement
        // legacy — le merge auto peut injecter Proxy/DNSOverHTTPS après la
        // validation). Le Service est responsable de filtrer via
        // `stripNonWhitelistedOverrides`.
        unset($allowed);

        return $errors;
    }

    /**
     * Strip des clés hors whitelist : retourne les policies sanitized qu'on
     * peut sauver en base (seules les clés éditables par l'UI survivent).
     *
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
