<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Wpkg\Deployment\Support\ApplicationXmlReader;
use Illuminate\Support\Facades\Log;

/**
 * @legacy-port path="sambaedu/wpkg/winget_out.php"
 * @see _bmad-output/implementation-artifacts/17-6-portage-endpoints-wpkg-linux-winget.md
 *
 * Story 17.6 / AC2 / D5 — Logique de mapping winget portée fidèlement depuis
 * `winget_out.php:61-194`.
 *
 * `resolve($machine, $localApps)` retourne `['install'=>[], 'upgrade'=>[],
 * 'uninstall'=>[]]` (clés **omises** si vides, parité legacy où
 * `$winget['install'][]` n'est créé que s'il y a au moins une entrée).
 *
 * Étapes (parité stricte, dans l'ordre legacy) :
 *   1. Liste winget demandée pour le poste = `WorkstationPackagesResolver::resolve()`
 *      → noeuds `<windows type="winget">` (via `ApplicationXmlReader`).
 *   2. Merge `add.json` (`/etc/` + `/usr/share/`), priorité légacy au même Id,
 *      puis retrait des Id déjà présents dans la liste XML.
 *   3. install / upgrade (croisement avec `$localApps`, comparaison de version
 *      pinnée).
 *   4. uninstall (merge `remove.json`, croisement avec `$localApps`).
 *
 * **Aucune écriture `/tmp`** (les `file_put_contents("/tmp/winget_*.json")` du
 * legacy étaient du debug, non porté — D5 / AC2.6).
 *
 * Note parité (priorité add/remove) : le code legacy donne la priorité aux
 * entrées `/usr/share/` sur `/etc/` **pour un même Id** (`winget_out.php:115-119`
 * : on `unset` l'entrée `/etc/` quand son Id existe dans `/usr/share/`). La
 * phrase D5 « /etc/ prioritaire » simplifie : on reproduit fidèlement le code
 * legacy (source de vérité), documenté en Completion Notes (écart de formulation).
 */
final class WingetPackagesResolver
{
    public function __construct(
        private readonly WorkstationPackagesResolver $packagesResolver,
        private readonly ApplicationXmlReader $xmlReader,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $localApps  Liste `Get-WinGetPackage`
     *                                                  envoyée par `install.ps1`
     *                                                  (chaque entrée porte Id,
     *                                                  InstalledVersion,
     *                                                  IsUpdateAvailable,
     *                                                  AvailableVersions, Source).
     * @return array{install?: list<array<string, mixed>>, upgrade?: list<array<string, mixed>>, uninstall?: list<array<string, mixed>>}
     */
    public function resolve(string $machine, array $localApps): array
    {
        $winget = [];

        // Étape 1 — liste winget demandée pour le poste (parité :61-100).
        $appIds = $this->packagesResolver->resolve($machine);
        $applications = $this->xmlReader->loadByAppIds($appIds);

        /** @var list<array<string, string>> $liste */
        $liste = [];
        foreach ($applications as $application) {
            foreach ($this->xmlReader->wingetEntriesFor($application) as $entry) {
                $liste[] = $entry;
            }
        }

        // Étape 2 — merge add.json (parité :103-130).
        $liste = $this->mergeAdd($liste);

        // Étape 3 — install / upgrade (parité :133-159).
        foreach ($liste as $l) {
            $k = $this->findIndexById($localApps, (string) ($l['Id'] ?? ''));

            if ($k === null) {
                // Absent localement → install.
                $winget['install'][] = $l;

                continue;
            }

            // Déjà installé : on vérifie la version (parité :135-155).
            if (! ($localApps[$k]['IsUpdateAvailable'] ?? false)) {
                continue;
            }

            $upgradeEntry = $this->buildUpgradeEntry($l, $localApps[$k]);
            if ($upgradeEntry !== null) {
                $winget['upgrade'][] = $upgradeEntry;
            }
        }

        // Étape 4 — uninstall (parité :164-189).
        $poubelle = $this->mergeRemove();
        foreach ($localApps as $l) {
            if (! is_array($l)) {
                continue;
            }
            $k = $this->findIndexById($poubelle, (string) ($l['Id'] ?? ''));
            if ($k !== null) {
                $winget['uninstall'][] = $l;
            }
        }

        return $winget;
    }

    /**
     * Merge `add.json` `/etc/` + `/usr/share/` puis retire les Id déjà présents
     * dans `$liste` (XML), puis `array_merge($add, $liste)` (parité :103-130).
     *
     * @param  list<array<string, string>>  $liste
     * @return list<array<string, string>>
     */
    private function mergeAdd(array $liste): array
    {
        $lAdd = $this->readCatalog(
            (string) config('sambaedu.wpkg.winget_catalog_add_local', '/etc/sambaedu/applications/winget/add.json')
        );
        $add = $this->readCatalog(
            (string) config('sambaedu.wpkg.winget_catalog_add_default', '/usr/share/sambaedu/applications/winget/add.json')
        );

        // Parité :115-119 — pour un même Id, l'entrée /usr/share l'emporte :
        // on retire de $lAdd (/etc/) les Id présents dans $add (/usr/share/).
        $lAdd = $this->removeByIds($lAdd, $this->idsOf($add));

        // Parité :121 — $add = array_merge($add, $l_add).
        $add = array_merge($add, $lAdd);

        // Parité :123-128 — on retire de $add les Id déjà présents dans $liste.
        $add = $this->removeByIds($add, $this->idsOf($liste));

        // Parité :129 — $liste = array_merge($add, $liste).
        return array_merge($add, $liste);
    }

    /**
     * Merge `remove.json` `/etc/` + `/usr/share/` (parité :164-182).
     *
     * @return list<array<string, string>>
     */
    private function mergeRemove(): array
    {
        $lPoubelle = $this->readCatalog(
            (string) config('sambaedu.wpkg.winget_catalog_remove_local', '/etc/sambaedu/applications/winget/remove.json')
        );
        $poubelle = $this->readCatalog(
            (string) config('sambaedu.wpkg.winget_catalog_remove_default', '/usr/share/sambaedu/applications/winget/remove.json')
        );

        // Parité :176-180 — on retire de $l_poubelle (/etc/) les Id présents
        // dans $poubelle (/usr/share/).
        $lPoubelle = $this->removeByIds($lPoubelle, $this->idsOf($poubelle));

        // Parité :182 — $poubelle = array_merge($poubelle, $l_poubelle).
        return array_merge($poubelle, $lPoubelle);
    }

    /**
     * Construit l'entrée `upgrade` pour une app déjà installée avec MAJ dispo
     * (parité :137-154). Retourne `null` si aucune version cible n'est retenue
     * (parité :152 — `if (isset($l['AvailableVersion']))`).
     *
     * @param  array<string, string>  $entry      Entrée winget (XML/add).
     * @param  array<string, mixed>   $localApp   Entrée `Get-WinGetPackage`.
     * @return array<string, mixed>|null
     */
    private function buildUpgradeEntry(array $entry, array $localApp): ?array
    {
        $l = $entry;
        unset($l['AvailableVersion']);

        $installedVersion = (string) ($localApp['InstalledVersion'] ?? '');
        $availableVersions = $localApp['AvailableVersions'] ?? [];
        if (! is_array($availableVersions)) {
            $availableVersions = [];
        }

        if (isset($l['Version'])) {
            // Version pinnée dans le XML (parité :139-147) : si la version
            // pinnée est > installée, on choisit la plus haute version dispo
            // <= pin (le foreach break dès qu'on dépasse la pin).
            if (version_compare((string) $l['Version'], $installedVersion, 'gt')) {
                foreach ($availableVersions as $v) {
                    if (version_compare((string) $l['Version'], (string) $v, 'gt')) {
                        break;
                    }
                    $l['AvailableVersion'] = $v;
                }
            }
        } else {
            // Pas de pin (parité :149) : on prend la plus récente dispo.
            if (array_key_exists(0, $availableVersions)) {
                $l['AvailableVersion'] = $availableVersions[0];
            }
        }

        // Parité :151 — $l['Version'] = version installée.
        $l['Version'] = $installedVersion;

        // Parité :152-154 — l'entrée upgrade n'est retenue que si une
        // AvailableVersion a été déterminée.
        if (! isset($l['AvailableVersion'])) {
            return null;
        }

        return $l;
    }

    /**
     * Lit un catalogue JSON (`add.json` / `remove.json`). Fichier absent ou
     * JSON invalide → `[]` (parité :104-114, garde fichier-absent).
     *
     * @return list<array<string, string>>
     */
    private function readCatalog(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Log::channel('wpkg-deploy')->warning(
                '[WingetPackagesResolver] catalogue JSON invalide, ignoré',
                ['path' => $path]
            );

            return [];
        }

        // On ne garde que les entrées tableau (robustesse).
        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * Retire de `$entries` toutes les entrées dont l'Id ∈ `$ids` (parité
     * `array_search` + `unset` legacy, en réindexant).
     *
     * @param  list<array<string, string>>  $entries
     * @param  list<string>                 $ids
     * @return list<array<string, string>>
     */
    private function removeByIds(array $entries, array $ids): array
    {
        if ($ids === []) {
            return array_values($entries);
        }

        $lookup = array_flip($ids);

        return array_values(array_filter(
            $entries,
            static fn (array $e): bool => ! isset($lookup[(string) ($e['Id'] ?? '')])
        ));
    }

    /**
     * Retourne la liste des Id d'un ensemble d'entrées.
     *
     * @param  list<array<string, string>>  $entries
     * @return list<string>
     */
    private function idsOf(array $entries): array
    {
        return array_values(array_map(
            static fn (array $e): string => (string) ($e['Id'] ?? ''),
            $entries
        ));
    }

    /**
     * Index de la 1re entrée dont l'Id == `$id` (parité `array_search` legacy),
     * ou `null` si absent.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function findIndexById(array $entries, string $id): ?int
    {
        if ($id === '') {
            return null;
        }

        foreach ($entries as $k => $entry) {
            if (is_array($entry) && (string) ($entry['Id'] ?? '') === $id) {
                return (int) $k;
            }
        }

        return null;
    }
}
