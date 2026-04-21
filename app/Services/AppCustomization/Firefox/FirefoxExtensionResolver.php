<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Firefox;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Résolution de l'ID Gecko d'une extension Firefox depuis son URL XPI.
 *
 * Story 4.8 — AC 14. Durcissement vs `get_ff_ext_id` legacy :
 *   - Allowlist de domaines (config `app-customizations.firefox.extension_resolver.allowed_domains`)
 *   - Scheme HTTPS strict (pas de http://, file://, ftp://)
 *   - Timeout court (5s)
 *   - Taille max (10 Mo)
 *   - Sandbox dans `storage/app/tmp/` (pas `/tmp`)
 *   - Extraction ZIP via `getFromName('manifest.json')` uniquement (pas d'`extractTo`)
 *   - `unlink()` systématique en `finally`
 *
 * Surface SSRF confinée à l'UI admin (gate `app.customize`).
 */
class FirefoxExtensionResolver
{
    public function __construct(
        private readonly ?Client $client = null,
    ) {}

    /**
     * Extrait l'ID Gecko d'une extension depuis une URL XPI (allowlist).
     *
     * @return string|null  ID Gecko ou null si manifest absent/invalide.
     *
     * @throws \InvalidArgumentException  URL invalide / hors allowlist / scheme non-HTTPS / trop grosse / DNS rebinding.
     * @throws \RuntimeException          Erreur réseau/TLS (feedback distinct pour admin).
     */
    public function resolveFromUrl(string $url): ?string
    {
        $this->assertValidUrl($url);

        $timeout = (int) config('app-customizations.firefox.extension_resolver.timeout', 5);
        $connectTimeout = (int) config('app-customizations.firefox.extension_resolver.connect_timeout', 3);
        $maxSize = (int) config('app-customizations.firefox.extension_resolver.max_size', 10_485_760);

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $tmpFile = $tmpDir . '/xpi-' . bin2hex(random_bytes(8)) . '.xpi';

        $client = $this->client ?? new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'allow_redirects' => false,
        ]);

        try {
            // HEAD préalable pour Content-Length guard avant de télécharger.
            try {
                $headResponse = $client->request('HEAD', $url, [
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                ]);
                $contentLength = (int) ($headResponse->getHeaderLine('Content-Length') ?: 0);
                if ($contentLength > 0 && $contentLength > $maxSize) {
                    throw new \InvalidArgumentException(sprintf(
                        'Taille du XPI dépassée (%d > %d octets).',
                        $contentLength,
                        $maxSize,
                    ));
                }
            } catch (GuzzleException $e) {
                // HEAD non supporté — on poursuit avec le GET mais taille surveillée via ftell.
            }

            $fh = @fopen($tmpFile, 'wb');
            if ($fh === false) {
                throw new \RuntimeException('Impossible de créer le fichier temporaire.');
            }

            try {
                $response = $client->request('GET', $url, [
                    'sink' => $fh,
                    'timeout' => $timeout,
                    'connect_timeout' => $connectTimeout,
                ]);
            } finally {
                @fclose($fh);
            }

            $size = is_file($tmpFile) ? (int) filesize($tmpFile) : 0;
            if ($size > $maxSize) {
                throw new \InvalidArgumentException(sprintf(
                    'Taille du XPI dépassée après téléchargement (%d > %d octets).',
                    $size,
                    $maxSize,
                ));
            }

            if (! class_exists('ZipArchive')) {
                Log::warning('[FirefoxExtensionResolver] ZipArchive indisponible');
                return null;
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                return null;
            }
            try {
                $manifestRaw = $zip->getFromName('manifest.json');
                if ($manifestRaw === false) {
                    return null;
                }
                $manifest = json_decode($manifestRaw, true);
                if (! is_array($manifest)) {
                    return null;
                }

                $id = $manifest['applications']['gecko']['id']
                    ?? $manifest['browser_specific_settings']['gecko']['id']
                    ?? null;

                return is_string($id) && $id !== '' ? $id : null;
            } finally {
                $zip->close();
            }
        } catch (ConnectException $e) {
            // TLS handshake / connect timeout / DNS failure → feedback distinct admin.
            Log::warning('[FirefoxExtensionResolver] connect error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Erreur connexion/TLS : ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            Log::warning('[FirefoxExtensionResolver] request error', [
                'url' => $url,
                'status' => $e->hasResponse() ? $e->getResponse()->getStatusCode() : null,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Erreur HTTP : ' . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            Log::warning('[FirefoxExtensionResolver] HTTP error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            if (is_file($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertValidUrl(string $url): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL invalide.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('URL malformée.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('Scheme non autorisé, HTTPS requis.');
        }

        /** @var string[] $allowed */
        $allowed = (array) config('app-customizations.firefox.extension_resolver.allowed_domains', []);
        if ($allowed === []) {
            $allowed = ['addons.mozilla.org'];
        }

        $host = strtolower((string) $parts['host']);
        $matched = false;
        foreach ($allowed as $candidate) {
            $candidate = strtolower((string) $candidate);
            if ($host === $candidate || str_ends_with($host, '.' . $candidate)) {
                $matched = true;
                break;
            }
        }

        if (! $matched) {
            throw new \InvalidArgumentException('Domaine non autorisé : ' . $host);
        }

        $this->assertNotPrivateIp($host);
    }

    /**
     * DNS rebinding guard : résout le hostname et refuse si l'IP est loopback
     * ou dans un range RFC1918/link-local. Garde activable via config —
     * désactiver uniquement en dev/CI si besoin d'un mock HTTP local.
     *
     * @throws \InvalidArgumentException
     */
    private function assertNotPrivateIp(string $host): void
    {
        if (! (bool) config('app-customizations.firefox.extension_resolver.dns_rebinding_guard', true)) {
            return;
        }

        // Si l'host est déjà une IP littérale, on la check directement.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $resolved = @gethostbynamel($host);
            if ($resolved === false || $resolved === []) {
                // DNS injoignable → mieux vaut échouer qu'ouvrir une faille
                throw new \InvalidArgumentException('Résolution DNS impossible pour ' . $host);
            }
            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                throw new \InvalidArgumentException(
                    'IP privée/loopback/réservée refusée pour ' . $host . ' → ' . $ip,
                );
            }
        }
    }
}
