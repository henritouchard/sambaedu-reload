<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Firefox;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Résolution d'un addon Firefox via l'API publique addons.mozilla.org.
 *
 * Story 4.8 — alternative au téléchargement XPI : l'admin colle l'URL
 * de la page AMO (ex. `https://addons.mozilla.org/fr/firefox/addon/clearurls/`),
 * on extrait le slug, on appelle `GET /api/v5/addons/addon/{slug}/` et on
 * récupère :
 *   - `guid`                              → l'ID Gecko
 *   - `current_version.file.url`          → URL XPI officielle signée
 *   - `current_version.file.hash`         → hash SHA-256 pour intégrité
 *   - `current_version.version`           → numéro de version courant
 *   - `name.en-US` ou locale applicable   → nom affichable
 *
 * Avantages vs `FirefoxExtensionResolver` :
 *   - Pas de download XPI (~5 Ko JSON vs jusqu'à 10 Mo XPI)
 *   - Pas de `ZipArchive` requis
 *   - Surface SSRF nulle (URL fixe API Mozilla)
 *   - Infos enrichies (version, nom, hash d'intégrité)
 *
 * Le `FirefoxExtensionResolver` reste disponible pour les addons custom
 * hébergés hors AMO (déploiements maison).
 */
class FirefoxAddonResolver
{
    private const API_BASE = 'https://addons.mozilla.org/api/v5/addons/addon/';

    public function __construct(
        private readonly ?Client $client = null,
    ) {}

    /**
     * Détecte si une URL pointe sur une page addon AMO (format éligible).
     */
    public static function isAddonPageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        if (strtolower($parts['host']) !== 'addons.mozilla.org') {
            return false;
        }
        return self::extractSlug($parts['path']) !== null;
    }

    /**
     * Extrait le slug AMO depuis le path de l'URL.
     *
     * Formats acceptés :
     *   /fr/firefox/addon/clearurls/
     *   /firefox/addon/ublock-origin/
     *   /fr/firefox/addon/clearurls/?utm_*                 (querystring ignorée)
     *   /en-US/firefox/addon/privacy-badger17/versions/    (sous-path ignoré)
     */
    public static function extractSlug(string $path): ?string
    {
        if (preg_match('#/addon/([a-z0-9][a-z0-9_\-]*)/?#i', $path, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * @return array{gecko_id: string, install_url: ?string, hash: ?string, name: ?string, version: ?string}|null
     *
     * @throws \InvalidArgumentException  URL invalide / hors AMO / slug introuvable
     * @throws \RuntimeException          Erreur réseau/TLS/API (feedback distinct pour admin)
     */
    public function resolveFromUrl(string $url): ?array
    {
        if (! self::isAddonPageUrl($url)) {
            throw new \InvalidArgumentException('URL AMO invalide — attendu : https://addons.mozilla.org/<locale>/firefox/addon/<slug>/');
        }

        $slug = self::extractSlug((string) parse_url($url, PHP_URL_PATH));
        if ($slug === null) {
            throw new \InvalidArgumentException('Impossible d\'extraire le slug de l\'URL.');
        }

        $timeout = (int) config('app-customizations.firefox.addon_resolver.timeout', 5);
        $connectTimeout = (int) config('app-customizations.firefox.addon_resolver.connect_timeout', 3);

        $client = $this->client ?? new Client([
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'allow_redirects' => ['max' => 2, 'strict' => true, 'protocols' => ['https']],
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'SambaEdu/4.8 (+https://sambaedu.org)',
            ],
        ]);

        try {
            $response = $client->request('GET', self::API_BASE . rawurlencode($slug) . '/');
        } catch (ConnectException $e) {
            Log::warning('[FirefoxAddonResolver] connect error', ['slug' => $slug, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Erreur connexion/TLS AMO : ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : null;
            Log::warning('[FirefoxAddonResolver] request error', ['slug' => $slug, 'status' => $status, 'error' => $e->getMessage()]);
            if ($status === 404) {
                throw new \InvalidArgumentException('Addon introuvable sur AMO : ' . $slug);
            }
            throw new \RuntimeException('Erreur API AMO : ' . $e->getMessage(), 0, $e);
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        if (! is_array($data)) {
            throw new \RuntimeException('Réponse AMO invalide (JSON malformé).');
        }

        $guid = $data['guid'] ?? null;
        if (! is_string($guid) || $guid === '') {
            return null;
        }

        $currentVersion = $data['current_version'] ?? [];
        $file = is_array($currentVersion) ? ($currentVersion['file'] ?? []) : [];

        return [
            'gecko_id' => $guid,
            'install_url' => (is_array($file) && isset($file['url'])) ? (string) $file['url'] : null,
            'hash' => (is_array($file) && isset($file['hash'])) ? (string) $file['hash'] : null,
            'name' => $this->extractLocalizedName($data['name'] ?? null),
            'version' => is_array($currentVersion) ? ($currentVersion['version'] ?? null) : null,
        ];
    }

    /**
     * L'API AMO retourne `name` sous forme d'objet `{ "fr": "...", "en-US": "..." }`.
     * On tente la locale courante puis fallback en-US puis première valeur.
     */
    private function extractLocalizedName(mixed $name): ?string
    {
        if (is_string($name)) {
            return $name;
        }
        if (! is_array($name) || $name === []) {
            return null;
        }
        $locale = str_replace('_', '-', (string) app()->getLocale());
        foreach ([$locale, substr($locale, 0, 2), 'fr', 'en-US', 'en'] as $candidate) {
            if (isset($name[$candidate]) && is_string($name[$candidate])) {
                return $name[$candidate];
            }
        }
        $first = reset($name);
        return is_string($first) ? $first : null;
    }
}
