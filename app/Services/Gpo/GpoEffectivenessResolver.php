<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use App\Config\LdapDnHelper;
use LdapRecord\Container;
use Throwable;

/**
 * Effectivité réelle des GPO du domaine sur les périmètres de CETTE instance :
 * l'OU des postes ({@see LdapDnHelper::computersDn()}) pour la configuration
 * MACHINE, et l'OU des comptes ({@see LdapDnHelper::peopleDn()}) pour la
 * configuration UTILISATEUR. STRICTEMENT lecture seule.
 *
 * Les deux moitiés d'une GPO suivent des chemins d'héritage distincts : un
 * blocage posé sur l'OU des postes ne neutralise QUE la moitié machine.
 *
 * Généralise l'algorithme de {@see \App\Services\LegacyGpoNeutralizationInspector}
 * (câblé, lui, sur la seule GPO « applications ») à l'ensemble du domaine, et
 * l'inverse pour le rendre O(profondeur de chaîne) au lieu de O(nombre de GPO) :
 *
 *   - l'Inspector interroge, PAR GPO, `(gPLink=*<guid>*)` sur tout l'annuaire —
 *     soit N scans globaux, coûteux en AD fédéré (~75 collèges) ;
 *   - ici on lit `gPLink` + `gPOptions` sur les quelques nœuds de la chaîne
 *     périmètre → racine (≈2 à 4), plus UNE recherche en sous-arbre du périmètre.
 *     Six opérations LDAP au total, quel que soit le nombre de GPO.
 *
 * Une GPO est EFFECTIVE sur le périmètre si un lien actif (bit 1 = disabled, non
 * armé) existe :
 *   - dans le sous-arbre des postes, ou sur l'OU des postes elle-même ; ou
 *   - sur un ancêtre avec ENFORCED (bit 2) — traverse tous les blocages ; ou
 *   - sur un ancêtre sans qu'aucun nœud strictement en dessous ne bloque
 *     l'héritage (`gPOptions & 1`).
 *
 * ⚠️ Portée assumée : les liens posés sur les OU d'AUTRES établissements ne sont
 * pas consultés — ce n'est ni notre périmètre, ni bon marché en AD fédéré. Une
 * GPO ni liée chez nous ni sur un ancêtre est donc rapportée « hors périmètre »
 * et NON « orpheline » : on ne prétend pas à une vérité domaine.
 *
 * Non couvert (comme l'Inspector) : liens de site (`CN=Sites,CN=Configuration`),
 * security/WMI filtering, `flags` de désactivation partielle du conteneur GPO,
 * et ordre de précédence entre GPO gagnantes.
 */
class GpoEffectivenessResolver
{
    /** Un lien actif atteint le périmètre : la GPO s'applique aux postes. */
    public const STATUS_EFFECTIVE = 'effective';

    /** Liée sur un ancêtre, mais un blocage d'héritage l'arrête avant le périmètre. */
    public const STATUS_NEUTRALIZED = 'neutralized';

    /** Liée sur la chaîne, mais le lien lui-même est désactivé (bit 1). */
    public const STATUS_LINK_DISABLED = 'link_disabled';

    /** Aucun lien sur notre chaîne ni sous notre périmètre (peut vivre ailleurs). */
    public const STATUS_OUT_OF_SCOPE = 'out_of_scope';

    /** Priorité de verdict : un lien effectif l'emporte sur tout le reste. */
    private const PRECEDENCE = [
        self::STATUS_OUT_OF_SCOPE => 0,
        self::STATUS_LINK_DISABLED => 1,
        self::STATUS_NEUTRALIZED => 2,
        self::STATUS_EFFECTIVE => 3,
    ];

    /** Périmètre MACHINE — configuration « Computer Configuration » des GPO. */
    public const PERIMETER_COMPUTERS = 'computers';

    /** Périmètre UTILISATEUR — configuration « User Configuration », appliquée au logon. */
    public const PERIMETER_PEOPLE = 'people';

    /**
     * Effectivité sur les DEUX périmètres, machine ET utilisateur.
     *
     * Une GPO porte deux moitiés indépendantes (`Computer Configuration` /
     * `User Configuration`) qui suivent des chemins d'héritage DISTINCTS : la
     * moitié machine descend jusqu'à l'OU des postes, la moitié utilisateur
     * jusqu'à l'OU des comptes. Bloquer l'héritage sur l'OU des postes ne
     * neutralise donc QUE la moitié machine — les stratégies utilisateur
     * (redirections, Bureau, lecteurs réseau…) continuent de s'appliquer à
     * chaque ouverture de session.
     *
     * N'évaluer qu'un seul périmètre produirait un « Neutralisée » faussement
     * rassurant : c'est précisément le contresens que cet écran corrige.
     *
     * @return array{
     *     available: bool,
     *     error: ?string,
     *     perimeters: array<string, array{dn: string, chain: list<string>, blockedNodes: list<string>}>,
     *     gpos: list<array{
     *         guid: string,
     *         displayName: string,
     *         versionNumber: ?int,
     *         statuses: array<string, array{status: string, detail: string, holderDn: ?string, enforced: bool}>,
     *     }>,
     * }
     */
    public function resolve(): array
    {
        $targets = [
            self::PERIMETER_COMPUTERS => LdapDnHelper::computersDn(),
            self::PERIMETER_PEOPLE => LdapDnHelper::peopleDn(),
        ];

        try {
            $baseDn = LdapDnHelper::baseDn();

            $perimeters = [];
            $classifiedBy = [];
            foreach ($targets as $key => $dn) {
                $resolved = $this->resolveFor($dn, $baseDn);
                $perimeters[$key] = [
                    'dn' => $dn,
                    'chain' => $resolved['chain'],
                    'blockedNodes' => $resolved['blockedNodes'],
                ];
                $classifiedBy[$key] = $resolved['gpos'];
            }

            $gpos = [];
            foreach ($this->listDomainGpos($baseDn) as $gpo) {
                $statuses = [];
                foreach (array_keys($targets) as $key) {
                    $verdict = $classifiedBy[$key][$gpo['guid']] ?? null;
                    $statuses[$key] = [
                        'status' => $verdict['status'] ?? self::STATUS_OUT_OF_SCOPE,
                        'detail' => $verdict['detail'] ?? '',
                        'holderDn' => $verdict['holderDn'] ?? null,
                        'enforced' => $verdict['enforced'] ?? false,
                    ];
                }

                $gpos[] = [
                    'guid' => $gpo['guid'],
                    'displayName' => $gpo['displayName'],
                    'versionNumber' => $gpo['versionNumber'],
                    'statuses' => $statuses,
                ];
            }

            // Tri : le pire (= le plus effectif) des deux périmètres en tête.
            usort($gpos, function (array $a, array $b): int {
                $rank = $this->worstRank($b) <=> $this->worstRank($a);

                return $rank !== 0 ? $rank : strcasecmp($a['displayName'], $b['displayName']);
            });

            return [
                'available' => true,
                'error' => null,
                'perimeters' => $perimeters,
                'gpos' => $gpos,
            ];
        } catch (Throwable $e) {
            // Jamais de verdict optimiste sur erreur : l'appelant doit pouvoir
            // afficher « indéterminé » plutôt qu'une liste vide rassurante.
            return [
                'available' => false,
                'error' => 'Annuaire injoignable : ' . $e->getMessage(),
                'perimeters' => [],
                'gpos' => [],
            ];
        }
    }

    /** Rang du périmètre le plus « exposé » d'une GPO (pour le tri). */
    private function worstRank(array $gpo): int
    {
        $ranks = array_map(
            static fn (array $s): int => self::PRECEDENCE[$s['status']] ?? 0,
            $gpo['statuses'],
        );

        return $ranks === [] ? 0 : max($ranks);
    }

    /**
     * Résolution pour UN périmètre donné.
     *
     * @return array{chain: list<string>, blockedNodes: list<string>, gpos: array<string, array<string, mixed>>}
     */
    private function resolveFor(string $perimeterDn, string $baseDn): array
    {
        $chain = $this->chainToBase($perimeterDn, $baseDn);

        $blocks = [];
        $chainLinks = [];
        foreach ($chain as $dn) {
            [$gplink, $gpoptions] = $this->readNode($dn);
            $blocks[$dn] = ($gpoptions & 1) === 1;
            $chainLinks[$dn] = $this->parseGplink($gplink);
        }

        $belowLinks = $this->linksBelowPerimeter($perimeterDn, $chain);

        $gpos = [];
        foreach ($this->listDomainGpos($baseDn) as $gpo) {
            $gpos[$gpo['guid']] = $this->classify($gpo, $chain, $blocks, $chainLinks, $belowLinks);
        }

        return [
            'chain' => $chain,
            'blockedNodes' => array_values(array_keys(array_filter($blocks))),
            'gpos' => $gpos,
        ];
    }

    /**
     * Cœur PUR du calcul d'effectivité — aucune I/O, tout est passé en argument.
     *
     * Public à dessein : c'est une décision de sécurité (une GPO s'applique-t-elle
     * à un poste ?) dont l'erreur est silencieuse. Elle doit être testable
     * exhaustivement sans annuaire.
     *
     * @param  array{guid: string, displayName: string, versionNumber: ?int}  $gpo
     * @param  list<string>  $chain  périmètre (index 0) → racine, DN normalisés
     * @param  array<string, bool>  $blocks  DN → héritage bloqué (`gPOptions & 1`)
     * @param  array<string, array<string, int>>  $chainLinks  DN de la chaîne → (GUID → flags)
     * @param  array<string, array<string, int>>  $belowLinks  DN sous le périmètre → (GUID → flags)
     * @return array{guid: string, displayName: string, versionNumber: ?int, status: string, detail: string, holderDn: ?string, enforced: bool}
     */
    public function classify(array $gpo, array $chain, array $blocks, array $chainLinks, array $belowLinks): array
    {
        $guid = $gpo['guid'];
        $best = self::STATUS_OUT_OF_SCOPE;
        $detail = sprintf('Aucun lien sur %s ni sur ses ancêtres (liée sur un autre périmètre, ou non liée).', $chain[0] ?? '—');
        $holderDn = null;
        $enforced = false;

        $consider = function (string $status, string $why, string $dn, bool $isEnforced) use (&$best, &$detail, &$holderDn, &$enforced): void {
            if (self::PRECEDENCE[$status] <= self::PRECEDENCE[$best]) {
                return;
            }
            $best = $status;
            $detail = $why;
            $holderDn = $dn;
            $enforced = $isEnforced;
        };

        // 1. Liens posés STRICTEMENT sous le périmètre : ils s'appliquent
        //    d'office (aucun blocage d'héritage ne peut les intercepter).
        foreach ($belowLinks as $dn => $links) {
            $flag = $links[$guid] ?? null;
            if ($flag === null) {
                continue;
            }
            if (($flag & 1) === 1) {
                $consider(self::STATUS_LINK_DISABLED, sprintf('Lien désactivé sur %s (dans le périmètre).', $dn), $dn, false);

                continue;
            }
            $consider(self::STATUS_EFFECTIVE, sprintf('Liée dans le périmètre des postes (%s).', $dn), $dn, ($flag & 2) === 2);
        }

        // 2. Liens sur la chaîne périmètre → racine.
        foreach ($chain as $index => $dn) {
            $flag = $chainLinks[$dn][$guid] ?? null;
            if ($flag === null) {
                continue;
            }

            if (($flag & 1) === 1) {
                $consider(self::STATUS_LINK_DISABLED, sprintf('Lien désactivé sur %s.', $dn), $dn, false);

                continue;
            }

            if (($flag & 2) === 2) {
                $consider(
                    self::STATUS_EFFECTIVE,
                    sprintf('Liée sur %s en ENFORCED — traverse les blocages d\'héritage.', $dn),
                    $dn,
                    true,
                );

                continue;
            }

            // Un blocage sur un nœud STRICTEMENT en dessous du porteur arrête
            // la descente. Le périmètre lui-même (index 0) compte : c'est là
            // que SE5 pose gPOptions=1 pour neutraliser les GPO de domaine.
            $blockedBy = null;
            for ($i = 0; $i < $index; $i++) {
                if ($blocks[$chain[$i]] ?? false) {
                    $blockedBy = $chain[$i];
                    break;
                }
            }

            if ($blockedBy !== null) {
                $consider(
                    self::STATUS_NEUTRALIZED,
                    sprintf('Liée sur %s, mais l\'héritage est bloqué sur %s.', $dn, $blockedBy),
                    $dn,
                    false,
                );

                continue;
            }

            $consider(
                self::STATUS_EFFECTIVE,
                sprintf('Liée sur %s, héritage non bloqué jusqu\'au périmètre.', $dn),
                $dn,
                false,
            );
        }

        return [
            'guid' => $guid,
            'displayName' => $gpo['displayName'],
            'versionNumber' => $gpo['versionNumber'],
            'status' => $best,
            'detail' => $detail,
            'holderDn' => $holderDn,
            'enforced' => $enforced,
        ];
    }

    /**
     * Toutes les GPO du domaine (conteneurs `groupPolicyContainer`).
     *
     * @return list<array{guid: string, displayName: string, versionNumber: ?int}>
     */
    private function listDomainGpos(string $baseDn): array
    {
        $entries = Container::getDefaultConnection()->query()
            ->in('CN=Policies,CN=System,' . $baseDn)
            ->rawFilter('(objectClass=groupPolicyContainer)')
            ->select(['cn', 'displayname', 'versionnumber'])
            ->get();

        $gpos = [];
        foreach ($entries as $entry) {
            $guid = $this->normalizeGuid((string) ($entry['cn'][0] ?? ''));
            if ($guid === '') {
                continue;
            }

            $version = $entry['versionnumber'][0] ?? null;

            $gpos[] = [
                'guid' => $guid,
                'displayName' => (string) ($entry['displayname'][0] ?? $guid),
                'versionNumber' => $version === null ? null : (int) $version,
            ];
        }

        return $gpos;
    }

    /**
     * Porteurs de lien situés STRICTEMENT sous le périmètre (sous-arbre), le
     * nœud périmètre lui-même étant déjà couvert par la chaîne.
     *
     * @param  list<string>  $chain
     * @return array<string, array<string, int>>
     */
    private function linksBelowPerimeter(string $perimeterDn, array $chain): array
    {
        $holders = Container::getDefaultConnection()->query()
            ->in($perimeterDn)
            ->rawFilter('(gPLink=*)')
            ->select(['distinguishedname', 'gplink'])
            ->get();

        $result = [];
        foreach ($holders as $holder) {
            $dn = $this->normalizeDn((string) ($holder['distinguishedname'][0] ?? ''));
            if ($dn === '' || in_array($dn, $chain, true)) {
                continue;
            }

            $result[$dn] = $this->parseGplink((string) ($holder['gplink'][0] ?? ''));
        }

        return $result;
    }

    /**
     * `gPLink` + `gPOptions` bruts d'un nœud.
     *
     * @return array{0: string, 1: int}
     */
    private function readNode(string $dn): array
    {
        $entry = Container::getDefaultConnection()->query()
            ->in($dn)
            ->rawFilter('(objectClass=*)')
            ->select(['gplink', 'gpoptions'])
            ->read()
            ->get()[0] ?? null;

        return [
            (string) ($entry['gplink'][0] ?? ''),
            (int) ($entry['gpoptions'][0] ?? 0),
        ];
    }

    /**
     * Décode une valeur `gPLink` en `GUID normalisé => flags`.
     *
     * Format : `[LDAP://CN={GUID},CN=Policies,…;FLAGS][LDAP://…;FLAGS]`
     * FLAGS — bit 1 = lien désactivé, bit 2 = ENFORCED.
     *
     * @return array<string, int>
     */
    public function parseGplink(string $gplink): array
    {
        if ($gplink === '' || preg_match_all('/\[LDAP:\/\/([^;\]]+);(\d+)\]/i', $gplink, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $links = [];
        foreach ($matches as $match) {
            if (preg_match('/\{([0-9A-Fa-f-]{36})\}/', $match[1], $guidMatch) !== 1) {
                continue;
            }

            $links[$this->normalizeGuid($guidMatch[0])] = (int) $match[2];
        }

        return $links;
    }

    /**
     * Chaîne de DN du périmètre (inclus) jusqu'au DN de base (inclus),
     * en composants normalisés minuscules.
     *
     * @return list<string>
     */
    public function chainToBase(string $perimeterDn, string $baseDn): array
    {
        $target = $this->normalizeDn($perimeterDn);
        $base = $this->normalizeDn($baseDn);
        $components = explode(',', $target);

        $chain = [];
        for ($i = 0; $i < count($components); $i++) {
            $dn = implode(',', array_slice($components, $i));
            $chain[] = $dn;
            if ($dn === $base) {
                break;
            }
        }

        return $chain;
    }

    /** GUID en majuscules AVEC accolades — forme canonique des routes GPO. */
    private function normalizeGuid(string $guid): string
    {
        $trimmed = strtoupper(trim($guid));

        if (preg_match('/\{?([0-9A-F-]{36})\}?/', $trimmed, $m) !== 1) {
            return '';
        }

        return '{' . $m[1] . '}';
    }

    private function normalizeDn(string $dn): string
    {
        return strtolower((string) preg_replace('/,\s+/', ',', trim($dn)));
    }
}
