<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Acl;

/**
 * Utilitaire PUR (sans I/O) de normalisation et de parsing des ACL POSIX,
 * partagé entre :
 *  - {@see \App\Services\Filesystem\NetworkShareService::computeDrift()} (audit
 *    désiré-vs-effectif) ;
 *  - {@see AclInspectionService} (inspection / import depuis le legacy).
 *
 * **Pourquoi ce point est piégeux.** Les builders d'ACL (`buildAcls`,
 * `ShareService::buildXxxAcls`) émettent les modes en RACCOURCI `setfacl`
 * (`rwx`, `rx`, `---`, `rw`) — la forme d'ENTRÉE de `setfacl -m`. Mais `getfacl`
 * SORT la forme canonique fixe à 3 caractères (`rwx`, `r-x`, `---`, `rw-`).
 * Comparer un set désiré (`group:x:rx`) à la sortie disque (`group:x:r-x`)
 * échouerait à tort. {@see normalizeMode()} ramène les deux à la forme
 * canonique `r-x` avant toute comparaison, de sorte que l'égalité ensembliste
 * reflète l'égalité SÉMANTIQUE des ACL.
 */
final class AclFormat
{
    /**
     * Normalise un mode de permission (le champ après le dernier `:`) vers la
     * forme canonique `rwx` à 3 positions. Accepte le raccourci setfacl
     * (`rx`, `rw`, `r`, `x`, `-`) comme la sortie getfacl (`r-x`, `rw-`, `---`).
     *
     * Exemples : `rx` → `r-x`, `rw` → `rw-`, `---` → `---`, `rwx` → `rwx`,
     * `x` → `--x`, `-` → `---`.
     */
    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        // Toute position `-` compte comme absence ; on ne teste que la présence
        // des lettres r/w/x, indépendamment de leur position ou des `-`.
        $r = str_contains($mode, 'r') ? 'r' : '-';
        $w = str_contains($mode, 'w') ? 'w' : '-';
        $x = str_contains($mode, 'x') ? 'x' : '-';

        return $r . $w . $x;
    }

    /**
     * Normalise une ligne d'ACL complète (sujet + mode) en canonisant SEULEMENT
     * le mode (dernier champ). Le sujet (préfixe `default:`, `user`/`group`,
     * qualifier éventuel avec espaces échappés `\040`) est préservé tel quel.
     *
     * Renvoie `''` pour une ligne vide / commentaire (`# file:` …) — filtrable
     * par l'appelant.
     */
    public static function normalizeLine(string $line): string
    {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            return '';
        }

        $pos = strrpos($line, ':');
        if ($pos === false) {
            return $line;
        }

        $subject = substr($line, 0, $pos);
        $mode = substr($line, $pos + 1);

        return $subject . ':' . self::normalizeMode($mode);
    }

    /**
     * Normalise + trie un set d'ACL (liste de lignes) pour comparaison
     * ensembliste stable. Élimine les vides/commentaires.
     *
     * @param  iterable<string>  $lines
     * @return list<string>
     */
    public static function normalizeSet(iterable $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $n = self::normalizeLine($line);
            if ($n !== '') {
                $out[] = $n;
            }
        }
        sort($out);

        return array_values($out);
    }

    /**
     * Parse la sortie brute de `getfacl -c -E` en entrées structurées.
     *
     * @return list<array{default: bool, type: string, qualifier: ?string, mode: string, raw: string}>
     *         `type` ∈ `user|group|other|mask`. `qualifier` = login/groupe pour
     *         les entrées nommées, `null` pour `user::`/`group::`/`other`/`mask`.
     *         `mode` est canonisé (3 chars).
     */
    public static function parseEntries(string $raw): array
    {
        $entries = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $default = false;
            if (str_starts_with($line, 'default:')) {
                $default = true;
                $line = substr($line, strlen('default:'));
            }

            // `type:qualifier:mode` — qualifier peut être vide.
            $pos = strrpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $mode = self::normalizeMode(substr($line, $pos + 1));
            $head = substr($line, 0, $pos); // `type` ou `type:qualifier`

            $colon = strpos($head, ':');
            if ($colon === false) {
                // Pas de qualifier explicite (ne devrait pas arriver) — ignore.
                continue;
            }
            $type = substr($head, 0, $colon);
            $qualifier = substr($head, $colon + 1);

            $entries[] = [
                'default' => $default,
                'type' => $type,
                'qualifier' => $qualifier === '' ? null : $qualifier,
                'mode' => $mode,
                'raw' => trim(($default ? 'default:' : '') . $head . ':' . $mode),
            ];
        }

        return $entries;
    }

    /**
     * Dé-échappe un qualifier getfacl : `\040` (octal de l'espace) → ' '.
     * getfacl échappe les espaces des noms d'utilisateur/groupe ; on restitue la
     * forme humaine pour la résolution en base (ex. `domain\040admins`).
     */
    public static function unescape(string $qualifier): string
    {
        return str_replace('\\040', ' ', $qualifier);
    }

    /**
     * Traduit un mode canonique en niveau d'accès métier du modèle managé :
     *  - contient `w` → `rw` ;
     *  - contient `r` (sans `w`) → `ro` ;
     *  - ni `r` ni `w` (`---`, `--x`) → `null` (non représentable : soit aucun
     *    accès effectif, soit exécution seule — l'appelant décide de skipper ou
     *    de signaler la perte de fidélité).
     */
    public static function modeToAccess(string $mode): ?string
    {
        $mode = self::normalizeMode($mode);
        if (str_contains($mode, 'w')) {
            return 'rw';
        }
        if (str_contains($mode, 'r')) {
            return 'ro';
        }

        return null;
    }
}
