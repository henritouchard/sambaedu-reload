<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\LdapDnHelper;
use LdapRecord\Container;
use Throwable;

/**
 * Story 38.6 — Vérifie que la GPO de domaine « applications » est neutralisée
 * pour le périmètre de l'instance (l'OU des postes, `LdapDnHelper::computersDn()`).
 *
 * La GPO est hébergée AU-DESSUS de l'ensemble des collèges (AD fédéré, liée à
 * la racine) : on ne la vide/délie/supprime JAMAIS — les collèges encore en
 * SE4 la consomment. Le mécanisme SE5 constaté (dev + lab1) est le blocage
 * d'héritage côté collège : `gPOptions=1` sur l'OU des postes. Cet inspecteur
 * est STRICTEMENT lecture seule.
 *
 * Une GPO « applications » s'applique encore au périmètre si un lien actif
 * (non-disabled) existe : soit DANS le sous-arbre des postes, soit sur un
 * ancêtre avec `enforced` (traverse les blocages), soit sur un ancêtre sans
 * qu'aucun nœud intermédiaire (OU des postes incluse) ne bloque l'héritage.
 */
class LegacyGpoNeutralizationInspector
{
    public const STATUS_NEUTRALIZED = 'neutralized';

    public const STATUS_APPLIES = 'applies';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_UNAVAILABLE = 'unavailable';

    /**
     * @return array{status: string, detail: string}
     */
    public function inspect(): array
    {
        try {
            $connection = Container::getDefaultConnection();
            $baseDn = LdapDnHelper::baseDn();
            $computersDn = LdapDnHelper::computersDn();

            $gpos = $connection->query()
                ->in('CN=Policies,CN=System,' . $baseDn)
                ->rawFilter('(&(objectClass=groupPolicyContainer)(displayName=applications))')
                ->select(['cn'])
                ->get();

            if (count($gpos) === 0) {
                return [
                    'status' => self::STATUS_ABSENT,
                    'detail' => 'Aucune GPO « applications » dans le domaine.',
                ];
            }

            $chain = $this->chainToBase($computersDn, $baseDn);
            $blocks = $this->inheritanceBlocks($chain);

            foreach ($gpos as $gpo) {
                $guid = (string) ($gpo['cn'][0] ?? '');
                if ($guid === '') {
                    continue;
                }

                $verdict = $this->gpoAppliesToPerimeter($guid, $chain, $blocks, $computersDn);
                if ($verdict !== null) {
                    return ['status' => self::STATUS_APPLIES, 'detail' => $verdict];
                }
            }

            return [
                'status' => self::STATUS_NEUTRALIZED,
                'detail' => sprintf(
                    'GPO « applications » sans lien effectif sur %s (héritage bloqué côté collège ou liens inactifs).',
                    $computersDn,
                ),
            ];
        } catch (Throwable $e) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'detail' => 'Annuaire injoignable : ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Chaîne de DN de l'OU des postes (incluse) jusqu'au DN de base (inclus),
     * en composants normalisés minuscules.
     *
     * @return list<string>
     */
    private function chainToBase(string $computersDn, string $baseDn): array
    {
        $target = $this->normalizeDn($computersDn);
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

    /**
     * `gPOptions & 1` (héritage bloqué) par nœud de la chaîne.
     *
     * @param  list<string>  $chain
     * @return array<string, bool>
     */
    private function inheritanceBlocks(array $chain): array
    {
        $connection = Container::getDefaultConnection();
        $blocks = [];

        foreach ($chain as $dn) {
            $entry = $connection->query()
                ->in($dn)
                ->rawFilter('(objectClass=*)')
                ->select(['gpoptions'])
                ->read()
                ->get()[0] ?? null;

            $blocks[$dn] = (((int) ($entry['gpoptions'][0] ?? 0)) & 1) === 1;
        }

        return $blocks;
    }

    /**
     * Null si la GPO ne s'applique pas au périmètre, sinon le motif.
     *
     * @param  list<string>  $chain
     * @param  array<string, bool>  $blocks
     */
    private function gpoAppliesToPerimeter(string $guid, array $chain, array $blocks, string $computersDn): ?string
    {
        $connection = Container::getDefaultConnection();
        $target = $this->normalizeDn($computersDn);

        $holders = $connection->query()
            ->rawFilter(sprintf('(gPLink=*%s*)', $guid))
            ->select(['distinguishedname', 'gplink'])
            ->get();

        foreach ($holders as $holder) {
            $holderDn = $this->normalizeDn((string) ($holder['distinguishedname'][0] ?? ''));
            $flag = $this->linkFlag((string) ($holder['gplink'][0] ?? ''), $guid);

            if ($flag === null || ($flag & 1) === 1) {
                continue; // pas de lien réel, ou lien désactivé
            }

            if (str_ends_with($holderDn, ',' . $target)) {
                return sprintf('%s liée DANS le périmètre des postes (%s).', $guid, $holderDn);
            }

            $index = array_search($holderDn, $chain, true);
            if ($index === false) {
                continue; // lié ailleurs (autre collège) — hors périmètre
            }

            if (($flag & 2) === 2) {
                return sprintf('%s liée sur %s en ENFORCED (traverse les blocages d\'héritage).', $guid, $holderDn);
            }

            $blockedBelow = false;
            for ($i = 0; $i < $index; $i++) {
                if ($blocks[$chain[$i]] ?? false) {
                    $blockedBelow = true;
                    break;
                }
            }

            if (! $blockedBelow) {
                return sprintf(
                    '%s liée sur %s sans blocage d\'héritage en dessous (poser gPOptions=1 sur l\'OU des postes — JAMAIS toucher la GPO partagée).',
                    $guid,
                    $holderDn,
                );
            }
        }

        return null;
    }

    /**
     * Flag du lien pour CE guid dans une valeur gPLink
     * (`[LDAP://cn={GUID},…;FLAGS]` — 1 = disabled, 2 = enforced).
     */
    private function linkFlag(string $gplink, string $guid): ?int
    {
        if (preg_match('/\[LDAP:\/\/[^;\]]*' . preg_quote($guid, '/') . '[^;\]]*;(\d+)\]/i', $gplink, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    private function normalizeDn(string $dn): string
    {
        return strtolower((string) preg_replace('/,\s+/', ',', trim($dn)));
    }
}
