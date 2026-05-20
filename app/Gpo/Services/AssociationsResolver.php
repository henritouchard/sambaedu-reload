<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Dto\AppCustomization\AppContext;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Support\Facades\Log;

/**
 * Service métier — Résolveur des associations d'extensions de fichiers/protocoles
 * à appliquer sur un poste Windows au logon, port natif du flux logique de
 * `gpo/associations_out.php:31-167`.
 *
 * Logique métier (cf. story 16.3c AC3.5 étapes 1-8) :
 *
 * 1. Lit `packages.xml` via `PackagesXmlAssociationsReader` → map
 *    `$packageId => $identifier => [ProgId, type]`.
 * 2. Intersecte avec les apps réellement installées sur le poste via
 *    `WorkstationPackagesResolver::resolve($machineName)` (Story 15.2 — pendant
 *    natif de `info_poste_applications`).
 * 3. Charge `/usr/share/sambaedu/applications/associations/default.xml`
 *    (assoc défauts iso-legacy, gracieux si absent).
 * 4. Charge les JSON associations système (`/usr/share/...`) + local
 *    (`/etc/...`) — listes de groupes user/parc autorisés par app.
 * 5. Filtre les JSON pour ne garder que les apps WPKG installées
 *    (intersection avec étape 2).
 * 6. Construit la liste `[all, ...groupes_user_inverses..., force]` iso-legacy
 *    `associations_out.php:142-144`.
 * 7. Itère 2 passes (système puis local) pour fusionner les associations
 *    matchant les groupes du contexte. **Local prime sur système** (parité
 *    legacy : la boucle local est en 2ᵉ → `array_merge` écrase).
 * 8. Calcule le delta vs `$localAssocs` (input POST du poste) — supprime du
 *    résultat les associations identiques côté poste.
 *
 * Iso-bytes obligatoire (parité legacy parc-wide). Tests fixture comparison.
 *
 * Story 16.3c — AC3.5, AC6.5.
 *
 * @legacy-port path="sambaedu/gpo/associations_out.php:1-173"
 */
class AssociationsResolver
{
    /**
     * Paths iso-legacy. **Hardcodés** (pas paramètres user — défense path
     * traversal AC5.3). Override possible via constructor pour les tests.
     */
    public const DEFAULT_XML_PATH = '/usr/share/sambaedu/applications/associations/default.xml';
    public const ASSOCIATIONS_SYSTEM_JSON_PATH = '/usr/share/sambaedu/applications/associations/associations.json';
    public const ASSOCIATIONS_LOCAL_JSON_PATH = '/etc/sambaedu/applications/associations/associations.json';

    public function __construct(
        private readonly PackagesXmlAssociationsReader $reader,
        private readonly WorkstationPackagesResolver $packagesResolver,
        private readonly ?string $defaultXmlPath = null,
        private readonly ?string $systemJsonPath = null,
        private readonly ?string $localJsonPath = null,
    ) {}

    /**
     * Résout les associations à appliquer côté poste, retourne le delta vs
     * l'input local.
     *
     * @param AppContext $context Contexte APCu posé par `applications.php` (4.8 + 15.2).
     *                            Utilise `$context->machineName` (= `machine.cn`) pour résoudre
     *                            les apps WPKG, et `$context->raw['list']` pour les groupes user/parc.
     * @param array<string, array{ProgId: string, type: string}> $localAssocs
     *        Associations locales actuelles du poste (POST `list` parsé). Format après
     *        `parseLocalAssocs()`: `$identifier => ['ProgId' => ..., 'type' => ...]`.
     *
     * @return array<string, array{ProgId: string, type: string}>
     *         Delta à appliquer côté poste (associations à modifier).
     */
    public function resolve(AppContext $context, array $localAssocs): array
    {
        // Étape 1 — Lecture packages.xml (intégral, on filtrera ensuite).
        $packagesAssociations = $this->reader->read();

        // Étape 2 — Apps WPKG installées sur le poste (Eloquent, Story 15.2).
        // Iso-legacy : `info_poste_applications($config, $machine)`.
        $installedPackages = $this->packagesResolver
            ->resolve($context->machineName)
            ->map(fn(string $v): string => strtolower($v))
            ->all();

        // Iso-legacy `array_search(strtolower($package->getAttribute('id')), array_map("strtolower", $liste_applications))` :
        // on conserve uniquement les associations des apps installées.
        $filteredAssociations = [];
        foreach ($packagesAssociations as $packageId => $assocs) {
            if (in_array(strtolower($packageId), $installedPackages, true)) {
                $filteredAssociations[$packageId] = $assocs;
            }
        }

        // Étape 3 — Charger default.xml (assoc préinstallées Windows, gracieux).
        $default = $this->loadDefaultXml($this->defaultXmlPath ?? self::DEFAULT_XML_PATH);

        // Étape 4 — Charger JSON système + local (listes de groupes par app).
        // Iso-legacy : `$add` = système, `$l_add` = local.
        $systemAddRaw = $this->loadJsonOrEmpty($this->systemJsonPath ?? self::ASSOCIATIONS_SYSTEM_JSON_PATH);
        $localAddRaw = $this->loadJsonOrEmpty($this->localJsonPath ?? self::ASSOCIATIONS_LOCAL_JSON_PATH);

        // Étape 5 — Filtrer pour ne garder que les apps installées (filteredAssociations).
        $systemAdd = $this->filterAppsByInstalled($systemAddRaw, $filteredAssociations);
        $localAdd = $this->filterAppsByInstalled($localAddRaw, $filteredAssociations);

        // Étape 6 — Construire la liste de contextes user/parc inversée + ['all', ..., 'force'].
        // Iso-legacy `associations_out.php:141-144` :
        //   $list = array_reverse($id['list']);
        //   array_unshift($list, "all");
        //   array_push($list, "force");
        $rawList = (array) ($context->raw['list'] ?? []);
        $rawList = array_values(array_filter($rawList, static fn($v): bool => is_string($v)));
        $list = array_reverse($rawList);
        array_unshift($list, 'all');
        array_push($list, 'force');

        // Étape 7 — Fusion 2 passes : système puis local (local prime).
        $result = $default;

        foreach ($list as $contextKey) {
            foreach ($systemAdd as $app => $groups) {
                if (in_array($contextKey, $groups, true) && isset($filteredAssociations[$app])) {
                    $result = array_merge($result, $filteredAssociations[$app]);
                }
            }
        }
        foreach ($list as $contextKey) {
            foreach ($localAdd as $app => $groups) {
                if (in_array($contextKey, $groups, true) && isset($filteredAssociations[$app])) {
                    $result = array_merge($result, $filteredAssociations[$app]);
                }
            }
        }

        // Étape 8 — Calcul du delta vs localAssocs (associations identiques retirées).
        // Iso-legacy ligne 162-166 : `if (isset($local_assoc[$i]) && empty(array_diff($local_assoc[$i], $a))) { unset($result[$i]); }`.
        foreach ($result as $identifier => $assoc) {
            if (isset($localAssocs[$identifier])
                && empty(array_diff($localAssocs[$identifier], $assoc))
            ) {
                unset($result[$identifier]);
            }
        }

        return $result;
    }

    /**
     * Parse l'input POST `list` legacy : JSON décodable `{type: [string,...]}`
     * → format normalisé `$identifier => ['ProgId' => ..., 'type' => ...]`.
     *
     * Iso-legacy `associations_out.php:32-40` :
     * ```php
     * foreach (json_decode($list, true) as $type => $apps) {
     *     foreach ($apps as $l) {
     *         preg_match("/^\s*(.*)\s*,\s*(.*)$/", $l, $m);
     *         $local_assoc[$m[1]] = ['ProgId' => $m[2], 'type' => $type];
     *     }
     * }
     * ```
     *
     * @param mixed $listInput Soit string JSON, soit array déjà décodé.
     * @return array<string, array{ProgId: string, type: string}>
     */
    public function parseLocalAssocs(mixed $listInput): array
    {
        if (is_string($listInput)) {
            $decoded = json_decode($listInput, true);
        } elseif (is_array($listInput)) {
            $decoded = $listInput;
        } else {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $localAssoc = [];
        foreach ($decoded as $type => $apps) {
            if (! is_string($type) || ! is_array($apps)) {
                continue;
            }
            foreach ($apps as $entry) {
                if (! is_string($entry)) {
                    continue;
                }
                $matches = [];
                // Iso-legacy `associations_out.php:36` : regex **greedy** (`.*` pas `.*?`).
                // Sur input `".html,Foo,Bar"` : capture `(".html,Foo", "Bar")`.
                // En pratique extensions/progIds Windows n'ont pas de virgule,
                // mais on conserve la sémantique legacy stricte (iso-bytes).
                if (preg_match('/^\s*(.*)\s*,\s*(.*)$/', $entry, $matches) === 1) {
                    $identifier = $matches[1];
                    $progId = $matches[2];
                    if ($identifier !== '') {
                        $localAssoc[$identifier] = [
                            'ProgId' => $progId,
                            'type' => $type,
                        ];
                    }
                }
            }
        }

        return $localAssoc;
    }

    /**
     * Filtre un dict `{app => [groupes]}` pour ne garder que les apps qui sont
     * dans le set des packages WPKG filtrés (étape 5 iso-legacy).
     *
     * @param array<string, mixed> $appsMap     Dict `{app => [groupes]}`.
     * @param array<string, array<string, array{ProgId: string, type: string}>> $filteredAssociations
     * @return array<string, list<string>>
     */
    private function filterAppsByInstalled(array $appsMap, array $filteredAssociations): array
    {
        $installed = array_keys($filteredAssociations);
        $result = [];
        foreach ($appsMap as $app => $groups) {
            if (! in_array($app, $installed, true)) {
                continue;
            }
            if (! is_array($groups)) {
                continue;
            }
            $result[$app] = array_values(array_filter(
                $groups,
                static fn($g): bool => is_string($g),
            ));
        }
        return $result;
    }

    /**
     * Lecture gracieuse d'un fichier JSON — retourne `[]` si absent/illisible.
     *
     * @return array<mixed>
     */
    private function loadJsonOrEmpty(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lecture gracieuse de `default.xml` — assoc préinstallées Windows.
     *
     * Iso-legacy `associations_out.php:78-97` : récupère **tous** les éléments
     * `<Association ProgId="..." Identifier="..." type="...">` du document, à
     * n'importe quelle profondeur (`DOMDocument::getElementsByTagName` est
     * récursif — le legacy fait pareil via `->getElementsByTagName('Association')`).
     *
     * En pratique le fichier `default.xml` est plat (`<root><Association/>…`)
     * mais une éventuelle imbrication serait absorbée silencieusement, iso-legacy.
     *
     * @return array<string, array{ProgId: string, type: string}>
     */
    private function loadDefaultXml(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->formatOutput = true;
            $dom->preserveWhiteSpace = false;
            $loaded = @$dom->load($path);
            if ($loaded === false) {
                Log::warning('[AssociationsResolver] default.xml load failed', ['path' => $path]);
                return [];
            }

            $default = [];
            $assocNodes = $dom->getElementsByTagName('Association');
            foreach ($assocNodes as $assocNode) {
                if (! $assocNode instanceof \DOMElement) {
                    continue;
                }
                $progId = $assocNode->getAttribute('ProgId');
                $identifier = $assocNode->getAttribute('Identifier');
                if ($progId === '' || $identifier === '') {
                    continue;
                }
                $type = $assocNode->getAttribute('type');
                if ($type === '') {
                    $type = 'file';
                }
                $default[$identifier] = [
                    'ProgId' => $progId,
                    'type' => $type,
                ];
            }

            return $default;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }
}
