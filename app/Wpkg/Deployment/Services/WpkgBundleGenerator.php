<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Config\SambaEduConfig;
use App\Services\AppStore\PackagesXmlService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Story 27.5 (D6/D7/D10) — Génère le bundle WPKG NATIF SE5 pré-substitué dans un
 * sous-dossier PUBLIC servi en STATIQUE par Apache (PAS via Laravel).
 *
 * « Pareil pour tous » (clarif. Henri 2026-06-18) : le bundle ne contient QUE des
 * artefacts identiques pour tout le parc (config d'instance) :
 *   - `wpkg-se4.js`, `wpkg-client.vbs`, `wpkg.cmd` : scripts versionnés
 *     (`resources/wpkg/*`, patchés D8 pour pointer SE5/local), copiés VERBATIM ;
 *   - `packages.xml` : catalogue global, avec `SE4FS_NAME` substitué UNE FOIS à
 *     la génération (source conf serveur — PAS l'AD, iso `packages_xml_out.php`).
 *
 * Story 27.6 (Bug A / SOURCE UNIQUE) : le catalogue `packages.xml` du bundle
 * n'est PLUS sourcé du statique `resources/wpkg/packages.xml` (hand-curated,
 * jamais à jour des apps ajoutées via le module AppStore) mais du CATALOGUE
 * MODULE (`config('sambaedu.wpkg.packages_xml_path')`, régénéré par
 * `PackagesXmlService` à chaque ajout/retrait d'app). Le catalogue module est
 * désormais l'UNIQUE source de vérité ; le bundle en est une projection
 * pré-substituée. Si le catalogue module est absent (jamais régénéré), on le
 * régénère d'abord (D5). La garde structurelle ci-dessous (≠ 1 <packages>)
 * protège ce sourcing : un catalogue module redevenu malformé fait échouer la
 * génération du bundle fort et clair (jamais de faux succès). Les SCRIPTS
 * restent sourcés VERBATIM de `resources/wpkg/` — seul le CATALOGUE change de
 * source.
 *
 * Le SEUL vrai custom par-poste = `profiles.xml`/`hosts.xml`, DÉPOSÉ par l'agent
 * (D9) — JAMAIS dans ce bundle. Régénéré à la pose / au changement de conf
 * (commande `wpkg:bundle`), pas par requête (D7 : zéro charge Laravel sur le
 * gros download — c'est Apache qui sert le statique). Écriture atomique par
 * fichier (tmp + rename).
 */
class WpkgBundleGenerator
{
    /**
     * Scripts « pareil pour tous » copiés VERBATIM depuis `resources/wpkg/`
     * (déjà patchés D8). Les `*-original`/`*.bak-*` sont exclus (références de
     * diff, jamais servies).
     *
     * @var list<string>
     */
    private const VERBATIM_SCRIPTS = [
        'wpkg-se4.js',
        'wpkg-client.vbs',
        'wpkg.cmd',
    ];

    /** Nom du catalogue cible (fichier écrit dans le bundle). */
    private const CATALOG = 'packages.xml';

    public function __construct(
        private readonly SambaEduConfig $config,
        private readonly PackagesXmlService $packagesXml,
    ) {}

    /**
     * Génère le bundle dans le répertoire public configuré
     * (`config('agent.wpkg_bundle_path')`). Idempotent : régénère tout à chaque
     * appel (snapshot de la conf + des scripts versionnés courants).
     *
     * @return array{path: string, files: list<string>, se4fs_name: string}
     */
    public function generate(): array
    {
        $source = $this->sourceDir();
        $target = (string) config('agent.wpkg_bundle_path');
        if ($target === '') {
            throw new RuntimeException('config(agent.wpkg_bundle_path) vide — bundle WPKG non générable.');
        }

        if (! is_dir($target) && ! mkdir($target, 0o755, true) && ! is_dir($target)) {
            throw new RuntimeException("Création du répertoire bundle impossible : {$target}");
        }

        $written = [];

        // 1. Scripts « pareil pour tous » — copie verbatim (déjà patchés D8).
        foreach (self::VERBATIM_SCRIPTS as $script) {
            $src = $source . DIRECTORY_SEPARATOR . $script;
            if (! is_file($src)) {
                throw new RuntimeException("Script source du bundle introuvable : {$src}");
            }
            $raw = file_get_contents($src);
            if ($raw === false) {
                throw new RuntimeException("Lecture du script source en échec : {$src}");
            }
            $this->writeAtomic($target . DIRECTORY_SEPARATOR . $script, $raw);
            $written[] = $script;
        }

        // 2. Catalogue `packages.xml` — SOURCE UNIQUE (Story 27.6 / Bug A) : sourcé
        //    du catalogue MODULE (`config('sambaedu.wpkg.packages_xml_path')`, écrit
        //    par PackagesXmlService), PAS du statique `resources/wpkg/packages.xml`.
        //    SE4FS_NAME substitué à la génération.
        $se4fsName = (string) ($this->config->get('se4fs_name', 'se4fs') ?? 'se4fs');
        $catalog = $this->buildSubstitutedCatalog($this->moduleCatalogPath());
        $this->writeAtomic($target . DIRECTORY_SEPARATOR . self::CATALOG, $catalog);
        $written[] = self::CATALOG;

        Log::channel('wpkg-deploy')->info('[WpkgBundleGenerator] bundle WPKG natif généré', [
            'path' => $target,
            'files' => $written,
            'se4fs_name' => $se4fsName,
        ]);

        return ['path' => $target, 'files' => $written, 'se4fs_name' => $se4fsName];
    }

    /**
     * Chemin du catalogue MODULE = source unique du catalogue du bundle
     * (`config('sambaedu.wpkg.packages_xml_path')`, écrit par `PackagesXmlService`).
     *
     * D5 — si le catalogue module est absent (jamais régénéré, ex. 1er run sur une
     * install neuve), on le régénère d'abord via `PackagesXmlService` : le bundle
     * reste toujours cohérent avec l'état courant des apps installées plutôt que
     * d'échouer au 1er run. La régénération est idempotente (snapshot des
     * `Application::installed()`).
     */
    private function moduleCatalogPath(): string
    {
        $path = (string) config('sambaedu.wpkg.packages_xml_path', '');
        if ($path === '') {
            throw new RuntimeException(
                'config(sambaedu.wpkg.packages_xml_path) vide — catalogue module introuvable, bundle non générable.',
            );
        }

        if (! is_file($path)) {
            Log::channel('wpkg-deploy')->info(
                '[WpkgBundleGenerator] catalogue module absent — régénération avant sourcing du bundle (D5)',
                ['path' => $path],
            );
            $this->packagesXml->regenerate();

            // Contrat D5 : après régénération, le catalogue DOIT exister. Sinon on
            // échoue ICI avec un message explicite plutôt que de laisser
            // buildSubstitutedCatalog lever « Catalogue source introuvable » (trace
            // trompeuse — la cause réelle est une régénération D5 sans écriture).
            if (! is_file($path)) {
                throw new RuntimeException(
                    "Régénération D5 terminée mais catalogue module toujours absent : {$path}",
                );
            }
        }

        return $path;
    }

    /**
     * Substitue les `<variable source="sambaedu">` du catalogue (≥ `SE4FS_NAME`,
     * clé `se4fs_name`) depuis la conf serveur — iso `packages_xml_out.php:46-56`
     * (jamais l'AD). Une clé absente de la conf → `value=""` (parité legacy).
     */
    private function buildSubstitutedCatalog(string $catalogPath): string
    {
        if (! is_file($catalogPath)) {
            throw new RuntimeException("Catalogue source introuvable : {$catalogPath}");
        }

        $raw = file_get_contents($catalogPath);
        if ($raw === false) {
            throw new RuntimeException("Lecture du catalogue en échec : {$catalogPath}");
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($raw);
        libxml_use_internal_errors($prev);
        if (! $ok) {
            throw new RuntimeException("Catalogue packages.xml invalide : {$catalogPath}");
        }

        // Garde structurelle (leçon 2026-06-18) : l'engine WPKG (`wpkg-se4.js`) lit
        // les <package> ENFANTS DIRECTS de l'unique racine <packages>
        // (`getPackages().selectNodes("package")`). Un catalogue bien formé mais MAL
        // STRUCTURÉ — double <packages> imbriqué de l'export SE4, ou racine
        // inattendue — passe `loadXML` mais donne « 0 package entries » côté poste :
        // WPKG n'installe RIEN, en SILENCE. On échoue ICI, fort et clair, plutôt que
        // de servir un catalogue inexploitable (jamais de faux succès).
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'packages') {
            throw new RuntimeException(sprintf(
                'Catalogue packages.xml : racine <%s> inattendue (attendu <packages>) : %s',
                $root?->localName ?? '(vide)',
                $catalogPath,
            ));
        }
        $packagesCount = $dom->getElementsByTagName('packages')->length;
        if ($packagesCount !== 1) {
            throw new RuntimeException(sprintf(
                'Catalogue packages.xml mal structuré : %d éléments <packages> (attendu 1) — '
                .'imbrication détectée, l\'engine WPKG lirait « 0 package entries » : %s',
                $packagesCount,
                $catalogPath,
            ));
        }

        foreach ($dom->getElementsByTagName('variable') as $variable) {
            if (! $variable instanceof \DOMElement) {
                continue;
            }
            if ($variable->getAttribute('source') !== 'sambaedu') {
                continue;
            }
            // `value` porte le NOM de la clé de conf à résoudre (ex. `se4fs_name`).
            $configKey = $variable->getAttribute('value');
            $resolved = $this->config->get($configKey, null);
            // Parité legacy : clé absente → value vide (jamais un placeholder cuit).
            $variable->setAttribute('value', $resolved !== null ? (string) $resolved : '');
        }

        $out = $dom->saveXML();
        if ($out === false) {
            throw new RuntimeException("Sérialisation du catalogue substitué en échec : {$catalogPath}");
        }

        return $out;
    }

    /**
     * Répertoire source des SCRIPTS versionnés (`resources/wpkg/{wpkg-se4.js,
     * wpkg-client.vbs, wpkg.cmd}`). Story 27.6 : ce répertoire ne fournit PLUS le
     * catalogue `packages.xml` (sourcé du catalogue module, cf. `moduleCatalogPath()`)
     * — UNIQUEMENT les scripts copiés VERBATIM.
     * Surchargeable via `config('sambaedu.wpkg.bundle_source_path')` (tests).
     */
    private function sourceDir(): string
    {
        $configured = (string) config('sambaedu.wpkg.bundle_source_path', '');

        return $configured !== '' ? $configured : base_path('resources/wpkg');
    }

    /**
     * Écriture atomique (tmp + rename) — Apache ne sert jamais un fichier à demi
     * écrit. chown www-admin reste une action serveur (convention storage).
     */
    private function writeAtomic(string $path, string $content): void
    {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $content) === false) {
            throw new RuntimeException("Écriture du bundle en échec : {$tmp}");
        }
        if (! rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Rename atomique du bundle en échec : {$tmp} → {$path}");
        }
    }
}
