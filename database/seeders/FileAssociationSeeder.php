<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Gpo\Services\PackagesXmlAssociationsReader;
use App\Models\FileAssociation;
use App\Models\WorkstationGroup;
use Illuminate\Database\Seeder;

/**
 * Story 27.3bis (D-Henri n°4 + n°7) — Seeder de REPRODUCTION de l'existant legacy,
 * TAGUÉ par source (`native` vs `wpkg`).
 *
 * But : à la bascule du canal legacy vers l'agent, les défauts d'associations
 * actuels sont DÉJÀ présents en base (zéro régression fonctionnelle). Le seeder
 * est IDEMPOTENT (upsert par clé DÉTERMINISTE `(identifier, progid)`, attach
 * `syncWithoutDetaching`) et REJOUABLE — câblé dans `DatabaseSeeder` (iso
 * `ShortcutSeeder`) et exposable via le flux de refresh.
 *
 * **Deux sources peuplées (D-Henri n°7) :**
 *   1. **Natives** — `default.xml` legacy ({@see self::LEGACY_DEFAULT_XML_PATH},
 *      sous `/usr/share/sambaedu/applications/associations/`) : les built-ins
 *      Windows → tag `source=native`, `wpkg_package=null` (toujours applicable).
 *   2. **WPKG** — `packages.xml` via {@see PackagesXmlAssociationsReader::read()}
 *      (`packageId → identifier → {ProgId, type}`) : les associations fournies par
 *      les paquets → tag `source=wpkg`, `wpkg_package=<packageId>`. Le `<package id>`
 *      = `Application::$app_id` (cf. `PackagesXmlService::regenerate()` qui émet le
 *      `$app->xml` dont la racine `<package id>` vaut `app_id`) → clé de jointure
 *      avec le déploiement par parc (validation prédictive UI).
 *
 * **Repli hôte/CI** : ni `default.xml` ni `packages.xml` lisibles → baseline FIGÉE
 * TAGUÉE (Firefox = `wpkg` paquet `firefox` ; `.jpg`/`.txt` = `native`), iso la
 * migration de seed `…_140200`.
 *
 * **Préférence native** : si une même paire `(identifier, progid)` arrive des deux
 * sources, `native` GAGNE (built-in toujours disponible → applicable partout).
 *
 * ⚠️ Pourquoi le reader legacy (`App\Gpo`) ici et pas dans le provider ? Le seeder
 * est un geste d'ADMINISTRATION ponctuel (peuplement du catalogue), pas le chemin
 * critique desired-state — y lire `packages.xml` est acceptable. Le PROVIDER, lui,
 * reste PG-pur (NFR7) : il lit le catalogue déjà peuplé, jamais le reader. Aucune
 * source NON-legacy équivalente n'existe : `App\Services\AppStore\PackagesXmlService`
 * ÉCRIT `packages.xml` (regenerate) mais n'expose PAS les associations par paquet.
 *
 * Le hash UserChoice n'est JAMAIS seedé : il est calculé côté agent (piège n° 2).
 *
 * ASSIGNATION par défaut : les associations reproduites sont attachées à TOUS les
 * parcs actifs (`WorkstationGroup`), reproduisant la portée legacy « all ». NB : si
 * aucun parc actif n'existe encore (1er déploiement), le catalogue est peuplé mais
 * non assigné — rejouer le seeder après création des parcs.
 */
class FileAssociationSeeder extends Seeder
{
    /**
     * `default.xml` legacy (sous `/usr/share/sambaedu/applications/associations/`)
     * — source des associations NATIVES quand elle est lisible (VM/prod). Constante
     * LOCALE volontaire : le seeder ne dépend PAS du namespace legacy `App\Gpo`
     * (qui meurt en 27.6) pour un simple chemin de fichier.
     */
    private const LEGACY_DEFAULT_XML_PATH = '/usr/share/sambaedu/applications/associations/default.xml';

    public function run(): void
    {
        $catalog = $this->loadCatalog();

        // Upsert par CLÉ DÉTERMINISTE (identifier, progid) : la baseline figée, le
        // seed-migration ET les parses default.xml/packages.xml convergent sur la
        // même clé pour une paire identique → zéro doublon catalogue (idempotent).
        $associations = [];
        foreach ($catalog as $row) {
            $associations[] = FileAssociation::query()->updateOrCreate(
                ['key' => FileAssociation::catalogKey($row['identifier'], $row['progid'])],
                [
                    'label' => $row['label'],
                    'description' => $row['description'] ?? null,
                    'identifier' => $row['identifier'],
                    'assoc_type' => $row['assoc_type'],
                    'progid' => $row['progid'],
                    'source' => $row['source'],
                    'wpkg_package' => $row['wpkg_package'] ?? null,
                    'is_active' => true,
                ],
            );
        }

        // Reproduction de la portée legacy « all » : attache les défauts à tous
        // les parcs actifs (idempotent via syncWithoutDetaching).
        $parcIds = WorkstationGroup::query()
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        if ($parcIds !== []) {
            foreach ($associations as $association) {
                $association->workstationGroups()->syncWithoutDetaching($parcIds);
            }
        }

        if ($this->command !== null) {
            $this->command->info(
                count($catalog) . ' associations de fichiers seedées (reproduction legacy native+wpkg), '
                . count($parcIds) . ' parc(s) ciblé(s).',
            );
        }
    }

    /**
     * Charge le catalogue d'associations à reproduire, fusion de DEUX sources
     * taguées (native depuis `default.xml`, wpkg depuis `packages.xml`). Si AUCUNE
     * des deux n'est lisible → baseline figée taguée (hôte/CI). Préférence native.
     *
     * @return list<array{label:string,description?:string,identifier:string,assoc_type:string,progid:string,source:string,wpkg_package:?string}>
     */
    private function loadCatalog(): array
    {
        $native = $this->parseDefaultXml(self::LEGACY_DEFAULT_XML_PATH);
        $wpkg = $this->readWpkgAssociations();

        if ($native === [] && $wpkg === []) {
            return $this->frozenBaseline();
        }

        return self::mergeCatalogs($native, $wpkg);
    }

    /**
     * Fusionne les deux sources par identité `(identifier, progid)` avec
     * **préférence NATIVE** (D-Henri n°7) : on insère d'abord les `wpkg`, puis les
     * `native` écrasent une clé identique → un built-in toujours disponible bat un
     * paquet. Static/protected pour être testable sans I/O.
     *
     * @param  list<array{identifier:string,progid:string,source:string,wpkg_package:?string}>  $native
     * @param  list<array{identifier:string,progid:string,source:string,wpkg_package:?string}>  $wpkg
     * @return list<array<string,mixed>>
     */
    protected static function mergeCatalogs(array $native, array $wpkg): array
    {
        $merged = [];
        foreach ($wpkg as $row) {
            $merged[$row['identifier'] . '|' . $row['progid']] = $row;
        }
        foreach ($native as $row) {
            $merged[$row['identifier'] . '|' . $row['progid']] = $row;
        }

        return array_values($merged);
    }

    /**
     * Parse le `default.xml` legacy (`<Association ProgId Identifier type/>`) en
     * catalogue NATIF (`source=native`, `wpkg_package=null`). Gracieux : fichier
     * absent/illisible/mal formé → liste vide.
     *
     * @return list<array{label:string,description?:string,identifier:string,assoc_type:string,progid:string,source:string,wpkg_package:null}>
     */
    private function parseDefaultXml(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            $dom->preserveWhiteSpace = false;
            if (@$dom->load($path) === false) {
                return [];
            }

            $catalog = [];
            foreach ($dom->getElementsByTagName('Association') as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                $progId = $node->getAttribute('ProgId');
                $identifier = $node->getAttribute('Identifier');
                if ($progId === '' || $identifier === '') {
                    continue;
                }
                $assocType = $node->getAttribute('type');
                if ($assocType === '') {
                    // Iso `AssociationsResolver::loadDefaultXml` : type vide = file.
                    $assocType = FileAssociation::ASSOC_TYPE_FILE;
                }

                // Dédoublonnage par identité (identifier, progid) dès le parse.
                $catalog[$identifier . '|' . $progId] = [
                    'label' => $identifier . ' → ' . $progId,
                    'description' => 'Association native par défaut reprise de default.xml legacy.',
                    'identifier' => $identifier,
                    'assoc_type' => $assocType,
                    'progid' => $progId,
                    'source' => FileAssociation::SOURCE_NATIVE,
                    'wpkg_package' => null,
                ];
            }

            return array_values($catalog);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    /**
     * Lit les associations fournies par les paquets WPKG via le reader natif
     * (`packageId → identifier → {ProgId, type}`) et les tague `source=wpkg`,
     * `wpkg_package=<packageId>`. Gracieux : `packages.xml` absent → liste vide.
     *
     * @return list<array{label:string,description:string,identifier:string,assoc_type:string,progid:string,source:string,wpkg_package:string}>
     */
    private function readWpkgAssociations(): array
    {
        $byPackage = (new PackagesXmlAssociationsReader())->read();

        $catalog = [];
        foreach ($byPackage as $packageId => $assocs) {
            foreach ($assocs as $identifier => $assoc) {
                $progId = (string) ($assoc['ProgId'] ?? '');
                if ($progId === '' || $identifier === '') {
                    continue;
                }
                $assocType = (string) ($assoc['type'] ?? FileAssociation::ASSOC_TYPE_FILE);
                if ($assocType === '') {
                    $assocType = FileAssociation::ASSOC_TYPE_FILE;
                }

                // Dédoublonnage par identité (identifier, progid). Si deux paquets
                // fournissent la même paire, la dernière gagne (sans incidence : la
                // cible logique est identique).
                $catalog[$identifier . '|' . $progId] = [
                    'label' => $identifier . ' → ' . $progId,
                    'description' => 'Association fournie par le paquet WPKG « ' . $packageId . ' ».',
                    'identifier' => (string) $identifier,
                    'assoc_type' => $assocType,
                    'progid' => $progId,
                    'source' => FileAssociation::SOURCE_WPKG,
                    'wpkg_package' => (string) $packageId,
                ];
            }
        }

        return array_values($catalog);
    }

    /**
     * Baseline FIGÉE TAGUÉE (cas hôte/CI sans `default.xml`/`packages.xml`). Iso la
     * migration de seed `…_140200` (mêmes paires `(identifier, progid)` → mêmes
     * clés `catalogKey`, mêmes tags `source`/`wpkg_package` → zéro doublon).
     *   - Firefox (`.html/.htm/http/https`) = `wpkg`, paquet `firefox` ;
     *   - `.jpg → WindowsPhotoViewer` = `native` ;
     *   - `.txt → txtfile` = `native` (le cas de Henri).
     *
     * @return list<array{label:string,description:string,identifier:string,assoc_type:string,progid:string,source:string,wpkg_package:?string}>
     */
    private function frozenBaseline(): array
    {
        return [
            ['label' => 'Pages HTML → Firefox', 'description' => 'Ouvre les fichiers .html avec Mozilla Firefox.', 'identifier' => '.html', 'assoc_type' => 'file', 'progid' => 'FirefoxHTML', 'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox'],
            ['label' => 'Pages HTM → Firefox', 'description' => 'Ouvre les fichiers .htm avec Mozilla Firefox.', 'identifier' => '.htm', 'assoc_type' => 'file', 'progid' => 'FirefoxHTML', 'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox'],
            ['label' => 'Protocole HTTP → Firefox', 'description' => 'Ouvre les liens http:// avec Mozilla Firefox.', 'identifier' => 'http', 'assoc_type' => 'protocol', 'progid' => 'FirefoxURL', 'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox'],
            ['label' => 'Protocole HTTPS → Firefox', 'description' => 'Ouvre les liens https:// avec Mozilla Firefox.', 'identifier' => 'https', 'assoc_type' => 'protocol', 'progid' => 'FirefoxURL', 'source' => FileAssociation::SOURCE_WPKG, 'wpkg_package' => 'firefox'],
            ['label' => 'Images JPG → Visionneuse de photos', 'description' => 'Ouvre les fichiers .jpg avec la visionneuse de photos Windows.', 'identifier' => '.jpg', 'assoc_type' => 'file', 'progid' => 'WindowsPhotoViewer', 'source' => FileAssociation::SOURCE_NATIVE, 'wpkg_package' => null],
            ['label' => 'Fichiers texte → Bloc-notes', 'description' => 'Ouvre les fichiers .txt avec le Bloc-notes Windows (built-in).', 'identifier' => '.txt', 'assoc_type' => 'file', 'progid' => 'txtfile', 'source' => FileAssociation::SOURCE_NATIVE, 'wpkg_package' => null],
        ];
    }
}
