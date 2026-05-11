<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Support\GpoActionLog;
use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Service métier d'abstraction `samba-tool gpo` — Epic 16 (Story 16.1).
 *
 * Entrée unique pour toutes les opérations GPO Samba. Délègue l'exécution
 * shell à {@see SambaToolRunner} (le seul autorisé à invoquer le binaire,
 * cf. garde-fou archi `GpoNamespaceTest`).
 *
 * Périmètre Story 16.1 :
 *
 * - **Lecture** : `list`, `get`, `listContainers`, `getLinks`, `getInheritance`
 *   sont **implémentées** (parsing complet de la sortie samba-tool).
 * - **Écriture** : `create`, `delete`, `fetch`, `setLink`, `removeLink`,
 *   `setInheritance` sont des **stubs typés** — signatures stables, logs émis,
 *   mais `RuntimeException` levée. L'implémentation effective est déléguée à
 *   Story 16.4 (CRUD) et Story 16.5 (liaisons).
 *
 * Cette approche fige les signatures côté API et permet aux stories suivantes
 * de s'appuyer dessus sans casser de contrat.
 *
 * Cohabitation : {@see \App\Services\GpoSyncService} (legacy `computer.elevate`)
 * reste vivant et marqué `@deprecated`. Il sera replié dans ce service à
 * partir de Story 16.4+.
 */
class GpoService
{
    public function __construct(
        private readonly SambaToolRunner $runner,
    ) {}

    /**
     * Liste toutes les GPOs du domaine.
     *
     * Wrapper de `samba-tool gpo listall`. Parse la sortie texte et retourne
     * une `Collection<GpoSummary>`.
     *
     * @return Collection<int, GpoSummary>
     */
    public function list(): Collection
    {
        $log = GpoLogger::action('gpo.list');

        try {
            $result = $this->runner->run(['gpo', 'listall'], $log);

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'samba-tool gpo listall failed (exit=%d): %s',
                    $result->exitCode() ?? -1,
                    $result->errorOutput(),
                ));
            }

            $gpos = $this->parseListAll($result->output());
            $log->success(['count' => $gpos->count()]);

            return $gpos;
        } catch (\Throwable $e) {
            $log->failure($e);

            throw $e;
        }
    }

    /**
     * Récupère le détail d'une GPO par son GUID (`samba-tool gpo show`).
     *
     * @param  string  $name  GUID de la GPO (format `{XXXX...XXXX}`).
     */
    public function get(string $name): ?GpoSummary
    {
        $log = GpoLogger::action('gpo.show', context: ['gpo_name' => $name]);

        try {
            $result = $this->runner->run(['gpo', 'show', $name], $log);

            if (! $result->successful()) {
                // GPO inexistante → null (pas une erreur métier).
                $log->success(['found' => false]);

                return null;
            }

            $summary = $this->parseShow($name, $result->output());
            $log->success(['found' => $summary !== null]);

            return $summary;
        } catch (\Throwable $e) {
            $log->failure($e);

            throw $e;
        }
    }

    /**
     * Liste les containers (OU, Site, Domain) sur lesquels une GPO est liée.
     *
     * Wrapper de `samba-tool gpo listcontainers`.
     *
     * @param  string  $name  GUID de la GPO.
     * @return list<string>   Liste de DNs de containers.
     */
    public function listContainers(string $name): array
    {
        $log = GpoLogger::action('gpo.containers.list', context: ['gpo_name' => $name]);

        try {
            $result = $this->runner->run(['gpo', 'listcontainers', $name], $log);

            if (! $result->successful()) {
                $log->success(['containers' => 0]);

                return [];
            }

            $containers = $this->parseListContainers($result->output());
            $log->success(['containers' => count($containers)]);

            return $containers;
        } catch (\Throwable $e) {
            $log->failure($e);

            throw $e;
        }
    }

    /**
     * Retourne les GPOs liées à un container AD donné.
     *
     * Wrapper de `samba-tool gpo getlink`.
     *
     * @param  string  $containerDn  DN du container (OU, Site, Domain).
     * @return list<GpoLink>
     */
    public function getLinks(string $containerDn): array
    {
        $log = GpoLogger::action('gpo.link.get', context: ['target_dn' => $containerDn]);

        try {
            $result = $this->runner->run(['gpo', 'getlink', $containerDn], $log);

            if (! $result->successful()) {
                $log->success(['links' => 0]);

                return [];
            }

            $links = $this->parseGetLink($containerDn, $result->output(), $log);
            $log->success(['links' => count($links)]);

            return $links;
        } catch (\Throwable $e) {
            $log->failure($e);

            throw $e;
        }
    }

    /**
     * Retourne l'état d'héritage GPO d'un container AD (`inherit` ou `block`).
     *
     * Wrapper de `samba-tool gpo getinheritance`.
     */
    public function getInheritance(string $containerDn): bool
    {
        $log = GpoLogger::action('gpo.inheritance.get', context: ['target_dn' => $containerDn]);

        try {
            $result = $this->runner->run(['gpo', 'getinheritance', $containerDn], $log);

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'samba-tool gpo getinheritance failed (exit=%d): %s',
                    $result->exitCode() ?? -1,
                    $result->errorOutput(),
                ));
            }

            $inherit = $this->parseGetInheritance($result->output());
            $log->success(['inherit' => $inherit]);

            return $inherit;
        } catch (\Throwable $e) {
            $log->failure($e);

            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // STUBS Story 16.4 (écriture) — signatures stables, implémentation TBD.
    // -----------------------------------------------------------------------

    /**
     * Crée une nouvelle GPO (`samba-tool gpo create`).
     *
     * @throws RuntimeException  Stub : implémentation déléguée à Story 16.4.
     */
    public function create(string $displayName): GpoSummary
    {
        $log = GpoLogger::action('gpo.create', context: ['display_name' => $displayName]);
        $log->step('stub — implementation pending Story 16.4');
        $e = new RuntimeException('GpoService::create() — not implemented yet, see Story 16.4');
        $log->failure($e);

        throw $e;
    }

    /**
     * Supprime une GPO (`samba-tool gpo del`).
     *
     * @throws RuntimeException  Stub : implémentation déléguée à Story 16.4.
     */
    public function delete(string $name): bool
    {
        $log = GpoLogger::action('gpo.delete', context: ['gpo_name' => $name]);
        $log->step('stub — implementation pending Story 16.4');
        $e = new RuntimeException('GpoService::delete() — not implemented yet, see Story 16.4');
        $log->failure($e);

        throw $e;
    }

    /**
     * Récupère localement une GPO depuis SYSVOL (`samba-tool gpo fetch`).
     *
     * @throws RuntimeException  Stub : implémentation déléguée à Story 16.3
     *                            (lecture sections nécessite fetch local).
     */
    public function fetch(string $name, string $destDir): bool
    {
        $log = GpoLogger::action('gpo.fetch', context: ['gpo_name' => $name, 'dest_dir' => $destDir]);
        $log->step('stub — implementation pending Story 16.3/16.4');
        $e = new RuntimeException('GpoService::fetch() — not implemented yet, see Story 16.3/16.4');
        $log->failure($e);

        throw $e;
    }

    /**
     * Lie une GPO à un container AD (`samba-tool gpo setlink`).
     *
     * @throws RuntimeException  Stub : implémentation déléguée à Story 16.5.
     */
    public function setLink(string $containerDn, string $gpoName, bool $enforce = false, bool $disable = false): bool
    {
        $log = GpoLogger::action('gpo.link.set', context: [
            'target_dn' => $containerDn,
            'gpo_name' => $gpoName,
            'enforce' => $enforce,
            'disable' => $disable,
        ]);
        $log->step('stub — implementation pending Story 16.5');
        $e = new RuntimeException('GpoService::setLink() — not implemented yet, see Story 16.5');
        $log->failure($e);

        throw $e;
    }

    /**
     * Supprime une liaison GPO ↔ container (`samba-tool gpo dellink`).
     *
     * @throws RuntimeException  Stub : implémentation déléguée à Story 16.5.
     */
    public function removeLink(string $containerDn, string $gpoName): bool
    {
        $log = GpoLogger::action('gpo.link.remove', context: [
            'target_dn' => $containerDn,
            'gpo_name' => $gpoName,
        ]);
        $log->step('stub — implementation pending Story 16.5');
        $e = new RuntimeException('GpoService::removeLink() — not implemented yet, see Story 16.5');
        $log->failure($e);

        throw $e;
    }

    /**
     * Définit l'héritage GPO sur un container (`samba-tool gpo setinheritance`).
     *
     * @throws RuntimeException  Stub : implémentation déléguée à Story 16.5.
     */
    public function setInheritance(string $containerDn, bool $enabled): bool
    {
        $log = GpoLogger::action('gpo.inheritance.set', context: [
            'target_dn' => $containerDn,
            'enabled' => $enabled,
        ]);
        $log->step('stub — implementation pending Story 16.5');
        $e = new RuntimeException('GpoService::setInheritance() — not implemented yet, see Story 16.5');
        $log->failure($e);

        throw $e;
    }

    // -----------------------------------------------------------------------
    // Parsers de sortie samba-tool — privés, testés via GpoServiceTest.
    // -----------------------------------------------------------------------

    /**
     * Parse la sortie texte de `samba-tool gpo listall`.
     *
     * Format typique (lignes par GPO, séparées par lignes vides) :
     *
     *     GPO          : {12345678-1234-1234-1234-123456789012}
     *     display name : MaGPO
     *     path         : \\dc.example.org\sysvol\example.org\Policies\{...}
     *     dn           : CN={...},CN=Policies,CN=System,DC=example,DC=org
     *     version      : 12
     *
     * @return Collection<int, GpoSummary>
     */
    private function parseListAll(string $output): Collection
    {
        $blocks = preg_split("/\r?\n\r?\n/", trim($output)) ?: [];
        $result = collect();

        foreach ($blocks as $block) {
            $fields = $this->parseKeyValueBlock($block);

            $name = $fields['GPO'] ?? $fields['gpo'] ?? null;
            $displayName = $fields['display name'] ?? null;

            if ($name === null || $displayName === null) {
                continue;
            }

            $result->push(new GpoSummary(
                name: $name,
                displayName: $displayName,
                versionNumber: isset($fields['version']) ? (int) $fields['version'] : null,
                dn: $fields['dn'] ?? null,
                path: $fields['path'] ?? null,
            ));
        }

        return $result;
    }

    /**
     * Parse la sortie texte de `samba-tool gpo show <GUID>`.
     */
    private function parseShow(string $expectedName, string $output): ?GpoSummary
    {
        $fields = $this->parseKeyValueBlock($output);

        $displayName = $fields['display name'] ?? null;
        if ($displayName === null) {
            return null;
        }

        return new GpoSummary(
            name: $fields['GPO'] ?? $expectedName,
            displayName: $displayName,
            versionNumber: isset($fields['version']) ? (int) $fields['version'] : null,
            dn: $fields['dn'] ?? null,
            path: $fields['path'] ?? null,
        );
    }

    /**
     * Parse la sortie texte de `samba-tool gpo listcontainers`.
     *
     * Format typique : lignes `dn: DN=...,DC=...,DC=...`.
     *
     * @return list<string>
     */
    private function parseListContainers(string $output): array
    {
        $containers = [];
        foreach (preg_split("/\r?\n/", $output) ?: [] as $line) {
            if (preg_match('/^\s*dn\s*:\s*(.+)$/i', $line, $m) === 1) {
                $containers[] = trim($m[1]);
            }
        }

        return $containers;
    }

    /**
     * Parse la sortie texte de `samba-tool gpo getlink`.
     *
     * Format typique (par lien, séparés par lignes vides) :
     *
     *     GPO     : {GUID}
     *     Name    : MaGPO
     *     Options : 0
     *
     * Robustesse : on splitte sur les lignes vides (bloc par bloc) plutôt
     * que d'accumuler ligne par ligne — ça permet de détecter les blocs
     * incomplets (clé manquante) au lieu de les écraser silencieusement.
     *
     * @return list<GpoLink>
     */
    private function parseGetLink(string $containerDn, string $output, ?GpoActionLog $log = null): array
    {
        $links = [];
        // Split bloc par bloc sur lignes vides (avec normalisation \r\n).
        $blocks = preg_split('/\R\s*\R+/', trim($output)) ?: [];

        foreach ($blocks as $index => $block) {
            $current = ['gpo' => null, 'name' => null, 'options' => null];

            foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
                if (preg_match('/^\s*(GPO|Name|Options)\s*:\s*(.+)$/i', $line, $m) === 1) {
                    $key = strtolower($m[1]);
                    $current[$key] = trim($m[2]);
                }
            }

            // Bloc complet ? on instancie le lien. Sinon, on logge un step
            // (pour traçabilité Epic 16) et on poursuit — pas d'écrasement silencieux.
            if ($current['gpo'] !== null && $current['name'] !== null && $current['options'] !== null) {
                $optionsInt = (int) $current['options'];
                $links[] = new GpoLink(
                    containerDn: $containerDn,
                    gpoName: $current['gpo'],
                    gpoDisplayName: $current['name'],
                    enforced: ($optionsInt & 2) === 2,
                    disabled: ($optionsInt & 1) === 1,
                    optionsRaw: $optionsInt,
                );
            } elseif ($block !== '') {
                $log?->step('parseGetLink: bloc incomplet ignoré', [
                    'block_index' => $index,
                    'fields_present' => array_keys(array_filter($current, fn ($v) => $v !== null)),
                ]);
            }
        }

        return $links;
    }

    /**
     * Parse la sortie texte de `samba-tool gpo getinheritance`.
     *
     * Retourne `true` si l'héritage est actif (`GPO_INHERIT`), `false` si
     * bloqué (`GPO_BLOCK_INHERITANCE`). Lève une exception si la sortie ne
     * matche aucun des deux marqueurs (sécurité : éviter qu'une décision
     * admin AD soit prise sur une ambiguïté silencieuse).
     */
    private function parseGetInheritance(string $output): bool
    {
        // On vérifie GPO_BLOCK_INHERITANCE en premier (sinon /GPO_INHERIT/
        // matche le préfixe de GPO_BLOCK_INHERITANCE).
        if (preg_match('/GPO_BLOCK_INHERITANCE/i', $output) === 1) {
            return false;
        }
        if (preg_match('/GPO_INHERIT/i', $output) === 1) {
            return true;
        }

        throw new RuntimeException(sprintf(
            'samba-tool gpo getinheritance: sortie inattendue (ni GPO_INHERIT ni GPO_BLOCK_INHERITANCE). Output: %s',
            substr(trim($output), 0, 200),
        ));
    }

    /**
     * Parse un bloc texte "clef: valeur" (un par ligne) en tableau associatif.
     *
     * @return array<string,string>
     */
    private function parseKeyValueBlock(string $block): array
    {
        $fields = [];
        foreach (preg_split("/\r?\n/", $block) ?: [] as $line) {
            if (preg_match('/^\s*([^:]+?)\s*:\s*(.*)$/', $line, $m) === 1) {
                $key = strtolower(trim($m[1]));
                $fields[$key] = trim($m[2]);
                // Conservation du label original (GPO) pour cohérence parseListAll.
                if ($key === 'gpo') {
                    $fields['GPO'] = trim($m[2]);
                }
            }
        }

        return $fields;
    }
}
