<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Support\GpoActionLog;
use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Collection;
use InvalidArgumentException;
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
     * Story 16.5 — AC1.1. Implémentation native via `SambaToolRunner` mode array
     * (pas de concat shell). Inputs validés par regex stricte AVANT side effect.
     *
     * Idempotence : si la liaison existe déjà (`samba-tool` retourne exit != 0
     * avec message « already »), on considère l'opération comme succès. Cela
     * couvre la race-condition multi-admin et permet à `reorderLinks` de
     * recréer un lien sans se soucier de l'état initial.
     *
     * @param  string  $containerDn  DN du container AD cible.
     * @param  string  $gpoName      GUID de la GPO au format `{XXXX-...-XXXX}`.
     * @param  bool    $enforce      Liaison `enforced` (héritage obligatoire).
     * @param  bool    $disable      Liaison `disabled` (la GPO ne s'applique pas).
     *
     * @throws InvalidArgumentException Si $containerDn ou $gpoName n'a pas un
     *                                   format valide (regex stricte AVANT exec).
     * @throws RuntimeException          Si l'exécution `samba-tool` échoue pour
     *                                   une raison autre qu'« already exists ».
     */
    public function setLink(string $containerDn, string $gpoName, bool $enforce = false, bool $disable = false): bool
    {
        self::assertValidGuid($gpoName);
        self::assertValidContainerDn($containerDn);

        $log = GpoLogger::action('gpo.link.add', context: [
            'target_dn' => $containerDn,
            'gpo_name' => $gpoName,
            'enforce' => $enforce,
            'disable' => $disable,
        ]);

        try {
            $args = ['gpo', 'setlink', $containerDn, $gpoName];
            if ($enforce) {
                $args[] = '--enforce';
            }
            if ($disable) {
                $args[] = '--disable';
            }

            $log->step('samba-tool gpo setlink invoked');
            $result = $this->runner->run($args, $log);

            if ($result->successful()) {
                $log->success();
                return true;
            }

            // Idempotence : already exists / already linked → succès silencieux
            $stderr = (string) $result->errorOutput();
            if (self::looksLikeIdempotentLinkError($stderr)) {
                $log->step('idempotent: link déjà présent — succès silencieux', ['stderr_excerpt' => substr($stderr, 0, 200)]);
                $log->success(['idempotent' => true]);
                return true;
            }

            throw new RuntimeException(sprintf(
                'samba-tool gpo setlink failed (exit=%d): %s',
                $result->exitCode() ?? -1,
                $stderr,
            ));
        } catch (\Throwable $e) {
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Supprime une liaison GPO ↔ container (`samba-tool gpo dellink`).
     *
     * Story 16.5 — AC1.2. Idempotent : si le lien n'existe pas, l'opération
     * retourne `true` (parité avec le legacy `gpodellink` qui ne signale pas
     * cette condition au caller).
     *
     * @throws InvalidArgumentException Inputs invalides (validation AVANT exec).
     * @throws RuntimeException          Échec exec autre qu'« does not exist ».
     */
    public function removeLink(string $containerDn, string $gpoName): bool
    {
        self::assertValidGuid($gpoName);
        self::assertValidContainerDn($containerDn);

        $log = GpoLogger::action('gpo.link.remove', context: [
            'target_dn' => $containerDn,
            'gpo_name' => $gpoName,
        ]);

        try {
            $log->step('samba-tool gpo dellink invoked');
            $result = $this->runner->run(['gpo', 'dellink', $containerDn, $gpoName], $log);

            if ($result->successful()) {
                $log->success();
                return true;
            }

            $stderr = (string) $result->errorOutput();
            if (self::looksLikeIdempotentUnlinkError($stderr)) {
                $log->step('idempotent: lien absent — succès silencieux', ['stderr_excerpt' => substr($stderr, 0, 200)]);
                $log->success(['idempotent' => true]);
                return true;
            }

            throw new RuntimeException(sprintf(
                'samba-tool gpo dellink failed (exit=%d): %s',
                $result->exitCode() ?? -1,
                $stderr,
            ));
        } catch (\Throwable $e) {
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Définit l'héritage GPO sur un container (`samba-tool gpo setinheritance`).
     *
     * Story 16.5 — AC1.3. **NE PAS reproduire le bug legacy
     * `samba-tool.inc.php:1027-1030`** (qui concaténait `inherit` sur la string
     * `$message` au lieu de `$command`). Notre mode array bypass naturellement
     * le bug : le 4ème argument est typé et passé au binaire intact.
     *
     * @throws InvalidArgumentException Input DN invalide.
     * @throws RuntimeException          Échec exec.
     */
    public function setInheritance(string $containerDn, bool $enabled): bool
    {
        self::assertValidContainerDn($containerDn);

        $log = GpoLogger::action('gpo.inheritance.set', context: [
            'target_dn' => $containerDn,
            'enabled' => $enabled,
        ]);

        try {
            $arg = $enabled ? 'inherit' : 'block';
            $log->step('samba-tool gpo setinheritance invoked', ['mode' => $arg]);
            $result = $this->runner->run(['gpo', 'setinheritance', $containerDn, $arg], $log);

            if (! $result->successful()) {
                throw new RuntimeException(sprintf(
                    'samba-tool gpo setinheritance failed (exit=%d): %s',
                    $result->exitCode() ?? -1,
                    $result->errorOutput(),
                ));
            }

            $log->success();
            return true;
        } catch (\Throwable $e) {
            $log->failure($e);
            throw $e;
        }
    }

    /**
     * Réordonne les liaisons GPO d'un container AD (Story 16.5 — AC1.4 / D3).
     *
     * `samba-tool gpo setlink` ne supporte pas le réordonnancement natif —
     * on simule donc une transaction logique :
     *   1. Lit l'état initial (`getLinks`) et le mémorise pour rollback.
     *   2. Supprime tous les liens existants (`removeLink` séquentiel).
     *   3. Ré-applique les liens dans l'ordre voulu (`setLink` séquentiel,
     *      en préservant les flags enforced/disabled de l'état initial).
     *
     * **Non-atomique** : si une étape échoue à mi-parcours, on tente un
     * rollback best effort (re-setLink avec l'ordre initial). Si le rollback
     * lui-même échoue, on lève `RuntimeException` — l'état AD est alors
     * potentiellement incohérent (cf. TD-16.5-1 dans `docs/tech-debt-gpo.md`).
     *
     * @param  string        $containerDn       DN du container AD.
     * @param  list<string>  $orderedGpoNames   Liste ordonnée des GUIDs (la première position = plus prioritaire / appliquée en dernier).
     *
     * @throws InvalidArgumentException Input invalide ou GPO de la liste non liée actuellement.
     * @throws RuntimeException          Si rollback échoué — état AD potentiellement incohérent.
     */
    public function reorderLinks(string $containerDn, array $orderedGpoNames): bool
    {
        self::assertValidContainerDn($containerDn);
        foreach ($orderedGpoNames as $g) {
            self::assertValidGuid($g);
        }

        $log = GpoLogger::action('gpo.link.order.update', context: [
            'target_dn' => $containerDn,
            'order_target' => $orderedGpoNames,
        ]);

        try {
            // 1. Lecture état initial (pour rollback éventuel + flags enforce/disable).
            $log->step('reading current links for rollback');
            $initial = $this->getLinks($containerDn);

            // Indexer par GUID pour préserver flags + valider que tous les GUIDs
            // demandés sont actuellement liés.
            /** @var array<string, GpoLink> $byGuid */
            $byGuid = [];
            foreach ($initial as $link) {
                $byGuid[$link->gpoName] = $link;
            }

            foreach ($orderedGpoNames as $guid) {
                if (! isset($byGuid[$guid])) {
                    throw new InvalidArgumentException(sprintf(
                        'reorderLinks: GPO %s n\'est pas actuellement liée à %s — impossible de la réordonner.',
                        $guid,
                        $containerDn,
                    ));
                }
            }

            // Story 16.5 review #S3 : exiger une PERMUTATION COMPLÈTE de l'état
            // initial. Une liste tronquée provoquerait une suppression silencieuse
            // de liens (remove all → re-setlink uniquement les listés).
            // Combiné à #7 (Locked) = défense en profondeur côté service.
            $initialGuids = array_keys($byGuid);
            sort($initialGuids);
            $orderedSorted = $orderedGpoNames;
            sort($orderedSorted);
            if ($initialGuids !== $orderedSorted) {
                throw new InvalidArgumentException(sprintf(
                    'reorderLinks: la liste passée doit contenir exactement tous les liens actuels (permutation complète requise). Attendu %d GUIDs, reçu %d.',
                    count($initialGuids),
                    count($orderedGpoNames),
                ));
            }

            $orderBefore = array_map(fn (GpoLink $l) => $l->gpoName, $initial);
            $log->diff('order', $orderBefore, $orderedGpoNames);

            // 2. Supprime tous les liens existants.
            $log->step('removing existing links', ['count' => count($initial)]);
            $removed = [];
            foreach ($initial as $link) {
                $this->removeLinkUnaudited($containerDn, $link->gpoName);
                $removed[] = $link;
            }

            // 3. Re-applique dans l'ordre cible (chaque GUID conservant ses flags initiaux).
            $log->step('adding links in target order', ['count' => count($orderedGpoNames)]);
            $applied = [];
            try {
                foreach ($orderedGpoNames as $guid) {
                    $link = $byGuid[$guid];
                    $this->setLinkUnaudited($containerDn, $guid, $link->enforced, $link->disabled);
                    $applied[] = $link;
                }
            } catch (\Throwable $applyError) {
                // Rollback best effort : recréer les liens initiaux dans leur ordre originel.
                $log->step('apply phase failed — initiating rollback', [
                    'applied_count' => count($applied),
                    'error' => $applyError->getMessage(),
                ]);

                try {
                    // D'abord nettoyer ce qui a été ré-appliqué (sinon doublons).
                    foreach ($applied as $link) {
                        $this->removeLinkUnaudited($containerDn, $link->gpoName);
                    }
                    // Puis recréer l'ordre initial.
                    foreach ($initial as $link) {
                        $this->setLinkUnaudited($containerDn, $link->gpoName, $link->enforced, $link->disabled);
                    }
                    $log->step('rollback succeeded — état initial restauré');
                    $log->failure($applyError);
                    return false;
                } catch (\Throwable $rollbackError) {
                    $log->step('rollback FAILED — état AD potentiellement incohérent', [
                        'rollback_error' => $rollbackError->getMessage(),
                    ]);
                    $combined = new RuntimeException(sprintf(
                        'reorderLinks: échec apply (%s) ET rollback (%s) — état AD incohérent sur %s.',
                        $applyError->getMessage(),
                        $rollbackError->getMessage(),
                        $containerDn,
                    ), 0, $applyError);
                    $log->failure($combined);
                    throw $combined;
                }
            }

            $log->success(['count' => count($applied)]);
            return true;
        } catch (\Throwable $e) {
            $log->failure($e);
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Helpers internes Story 16.5 — validation + helpers samba-tool sans
    // log haut niveau (utilisés par reorderLinks pour éviter de poluer le
    // catalogue avec N actions `gpo.link.add` quand on réordonne).
    // -----------------------------------------------------------------------

    /**
     * Valide qu'une string est un GUID GPO au format Microsoft strict
     * avec accolades. Lève `InvalidArgumentException` sinon.
     */
    public static function assertValidGuid(string $gpoName): void
    {
        $pattern = '/^\{[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}\}$/';
        if (preg_match($pattern, $gpoName) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'GUID GPO invalide : %s — attendu format Microsoft {XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}.',
                substr($gpoName, 0, 80),
            ));
        }
    }

    /**
     * Valide qu'un DN container AD a un format plausible (commence par
     * `XX=...` où XX est OU/CN/DC). Garde-fou défensif — `SambaToolRunner`
     * mode array protège déjà contre l'injection shell.
     */
    public static function assertValidContainerDn(string $dn): void
    {
        if ($dn === '' || strlen($dn) > 1024) {
            throw new InvalidArgumentException('DN container vide ou trop long.');
        }
        if (preg_match('/^[A-Za-z]+=[^,]+(,[A-Za-z]+=[^,]+)*$/', $dn) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'DN container invalide : %s — attendu format LDAP `OU=...,DC=...,DC=...`.',
                substr($dn, 0, 200),
            ));
        }
    }

    /**
     * Heuristique stderr legacy : détecte « already exists / linked » pour
     * traiter l'erreur comme idempotente (succès silencieux).
     *
     * Story 16.5 review #3 : `'object class violation'` retiré — c'est une
     * erreur LDAP générique de schéma, pas un signe de lien déjà existant.
     * Cf. TD-16.5-4 pour la fragilité résiduelle des heuristiques stderr.
     */
    private static function looksLikeIdempotentLinkError(string $stderr): bool
    {
        $lower = strtolower($stderr);
        return str_contains($lower, 'already')
            || str_contains($lower, 'gplink already');
    }

    /**
     * Heuristique stderr legacy : détecte « does not exist / not linked » pour
     * traiter le dellink comme idempotent.
     *
     * Story 16.5 review #3 : `'no such'` (générique) restreint à
     * `'no such gp link'` — le match large interceptait `'no such attribute'`
     * (vraie erreur LDAP). Cf. TD-16.5-4.
     */
    private static function looksLikeIdempotentUnlinkError(string $stderr): bool
    {
        $lower = strtolower($stderr);
        return str_contains($lower, 'no such gp link')
            || str_contains($lower, 'does not exist')
            || str_contains($lower, 'not linked')
            || str_contains($lower, 'no link')
            || str_contains($lower, 'no entry');
    }

    /**
     * Variant `setLink` qui ne crée pas sa propre action `gpo.link.add` —
     * utilisé par `reorderLinks` pour ne pas spammer le catalogue.
     *
     * Lève RuntimeException sur échec (gère idempotence comme setLink).
     */
    private function setLinkUnaudited(string $containerDn, string $gpoName, bool $enforce, bool $disable): void
    {
        $args = ['gpo', 'setlink', $containerDn, $gpoName];
        if ($enforce) {
            $args[] = '--enforce';
        }
        if ($disable) {
            $args[] = '--disable';
        }
        $result = $this->runner->run($args);
        if ($result->successful()) {
            return;
        }
        $stderr = (string) $result->errorOutput();
        if (self::looksLikeIdempotentLinkError($stderr)) {
            return;
        }
        throw new RuntimeException(sprintf(
            'reorderLinks: setlink %s échoué (exit=%d): %s',
            $gpoName,
            $result->exitCode() ?? -1,
            $stderr,
        ));
    }

    /**
     * Variant `removeLink` sans log d'action propre.
     */
    private function removeLinkUnaudited(string $containerDn, string $gpoName): void
    {
        $result = $this->runner->run(['gpo', 'dellink', $containerDn, $gpoName]);
        if ($result->successful()) {
            return;
        }
        $stderr = (string) $result->errorOutput();
        if (self::looksLikeIdempotentUnlinkError($stderr)) {
            return;
        }
        throw new RuntimeException(sprintf(
            'reorderLinks: dellink %s échoué (exit=%d): %s',
            $gpoName,
            $result->exitCode() ?? -1,
            $stderr,
        ));
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
