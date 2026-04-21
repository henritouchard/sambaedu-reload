<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Firefox;

/**
 * Dispatcher — route une URL d'extension Firefox vers le bon resolver.
 *
 * Story 4.8 — préserve la compatibilité avec les XPI "maison" (hors AMO)
 * tout en proposant un chemin optimal pour les addons publics AMO :
 *
 *   Input URL                                              → Resolver
 *   ────────────────────────────────────────────────────────────────
 *   https://addons.mozilla.org/<locale>/firefox/addon/…   → FirefoxAddonResolver (API JSON)
 *   https://…/*.xpi  (tout autre domaine dans l'allowlist) → FirefoxExtensionResolver (XPI download)
 *
 * Le mode AMO est privilégié car plus simple, plus sûr (pas de SSRF,
 * pas de ZipArchive), plus riche (version, nom, hash officiel).
 *
 * Le retour unifié a toujours la même forme que `FirefoxAddonResolver` :
 *   [gecko_id, install_url, hash?, name?, version?, source: 'amo'|'xpi']
 *
 * `install_url` peut être null si le resolver XPI ne peut le déduire
 * (dans ce cas l'URL fournie par l'admin EST déjà l'install_url).
 */
class FirefoxAddonDiscovery
{
    public function __construct(
        private readonly FirefoxAddonResolver $addonResolver,
        private readonly FirefoxExtensionResolver $extensionResolver,
    ) {}

    /**
     * @return array{gecko_id: string, install_url: ?string, hash: ?string, name: ?string, version: ?string, source: string}|null
     *
     * @throws \InvalidArgumentException  URL invalide / hors allowlist / DNS privé / slug AMO introuvable
     * @throws \RuntimeException          Erreur réseau/TLS distincte
     */
    public function resolveFromUrl(string $url): ?array
    {
        if (FirefoxAddonResolver::isAddonPageUrl($url)) {
            $result = $this->addonResolver->resolveFromUrl($url);
            return $result !== null ? [...$result, 'source' => 'amo'] : null;
        }

        // Fallback XPI custom (addon maison). L'allowlist config
        // `extension_resolver.allowed_domains` doit inclure les domaines
        // de déploiement interne si l'étab en utilise.
        $geckoId = $this->extensionResolver->resolveFromUrl($url);
        if ($geckoId === null) {
            return null;
        }

        return [
            'gecko_id' => $geckoId,
            'install_url' => $url,  // l'URL fournie EST l'install_url en mode XPI custom
            'hash' => null,
            'name' => null,
            'version' => null,
            'source' => 'xpi',
        ];
    }
}
