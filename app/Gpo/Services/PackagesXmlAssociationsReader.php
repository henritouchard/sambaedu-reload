<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use Illuminate\Support\Facades\Log;

/**
 * Lecteur natif du fichier `packages.xml` WPKG — extrait les éléments
 * `<Association>` enfants de chaque `<package>`.
 *
 * Port natif de la logique DOMDocument de `gpo/associations_out.php:41-66`.
 * Gracieux iso-legacy : fichier absent / DOM mal formé → retour `[]` +
 * log warning channel `daily`. Aucun Fatal — l'endpoint doit pouvoir
 * répondre `{"result": {}}` pour un poste sans WPKG installé.
 *
 * Schéma de retour :
 * ```php
 * [
 *   'firefox' => [
 *     '.html' => ['ProgId' => 'FirefoxHTML', 'type' => 'file'],
 *     'http'  => ['ProgId' => 'FirefoxURL',  'type' => 'protocol'],
 *   ],
 *   'thunderbird' => [...],
 * ]
 * ```
 *
 * Story 16.3c — AC3.5 étape 1-2, AC3.6, AC6.6.
 *
 * @legacy-port path="sambaedu/gpo/associations_out.php:41-66"
 */
class PackagesXmlAssociationsReader
{
    /**
     * Lit le fichier `packages.xml` et retourne le mapping
     * `$packageId => $identifier => ['ProgId', 'type']`.
     *
     * Path par défaut : `config('sambaedu.wpkg.deploy_path').'/packages.xml'`
     * (Story 15.1). Fichier absent / DOM cassé → tableau vide + log.
     *
     * @return array<string, array<string, array{ProgId: string, type: string}>>
     */
    public function read(?string $packagesXmlPath = null): array
    {
        $path = $packagesXmlPath ?? $this->resolvePath();

        if (! is_file($path) || ! is_readable($path)) {
            Log::warning('[PackagesXmlAssociationsReader] packages.xml absent', [
                'path' => $path,
            ]);
            return [];
        }

        // Erreurs DOMDocument capturées en mode interne (parité legacy
        // gracieux silencieux). Restauration de l'état initial en finally.
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $dom = new \DOMDocument();
            $dom->formatOutput = true;
            $dom->preserveWhiteSpace = false;

            // @ pour éviter le warning PHP si XML mal formé (parité legacy
            // `$xml->load($url_packages)` sans check de retour).
            $loaded = @$dom->load($path);

            if ($loaded === false || $dom->documentElement === null) {
                $errors = libxml_get_errors();
                Log::warning('[PackagesXmlAssociationsReader] DOM load failed', [
                    'path' => $path,
                    'libxml_errors' => array_map(
                        static fn(\LibXMLError $e): string => trim($e->message),
                        $errors,
                    ),
                ]);
                return [];
            }

            return $this->extractAssociations($dom);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * Itère sur les `<package>` et extrait leurs `<Association>` enfants.
     *
     * @return array<string, array<string, array{ProgId: string, type: string}>>
     */
    private function extractAssociations(\DOMDocument $dom): array
    {
        if ($dom->documentElement === null) {
            return [];
        }

        $associations = [];
        $packages = $dom->documentElement->getElementsByTagName('package');

        foreach ($packages as $package) {
            if (! $package instanceof \DOMElement) {
                continue;
            }

            $packageId = $package->getAttribute('id');
            if ($packageId === '') {
                continue;
            }

            // @legacy-port iso `associations_out.php:53` —
            // `$package->getElementsByTagName("Association")` retourne TOUS
            // les descendants `<Association>`. Conservé iso pour cohérence.
            $assocNodes = $package->getElementsByTagName('Association');

            foreach ($assocNodes as $assocNode) {
                if (! $assocNode instanceof \DOMElement) {
                    continue;
                }

                $progId = $assocNode->getAttribute('ProgId');
                $identifier = $assocNode->getAttribute('Identifier');

                if ($progId === '' || $identifier === '') {
                    continue;
                }

                // Iso-legacy ligne 56-59 : type défaut 'file' si vide/absent.
                $type = $assocNode->getAttribute('type');
                if ($type === '') {
                    $type = 'file';
                }

                $associations[$packageId][$identifier] = [
                    'ProgId' => $progId,
                    'type' => $type,
                ];
            }
        }

        return $associations;
    }

    /**
     * Path par défaut iso Story 15.1 : `config('sambaedu.wpkg.deploy_path').'/packages.xml'`.
     */
    private function resolvePath(): string
    {
        $deployPath = config('sambaedu.wpkg.deploy_path');
        if (! is_string($deployPath) || $deployPath === '') {
            // Fallback iso-legacy `$url_packages` (cf. config.inc.php legacy).
            return '/var/sambaedu/unattended/install/wpkg/packages.xml';
        }
        return rtrim($deployPath, '/') . '/packages.xml';
    }
}
