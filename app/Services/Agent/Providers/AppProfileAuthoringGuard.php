<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

/**
 * Story 36.5 (AC1/AC4) — garde-fou d'AUTHORING des projections
 * `windows/app_profile` : refuse à la SOURCE les entrées de catalogue que
 * l'agent refuserait ou qui provoqueraient une collision avec le canal
 * `legacy_cleanup` (défense en profondeur — le serveur peut avoir tort mais ne
 * doit JAMAIS produire un catalogue dangereux). Service PUR (projections en
 * entrée, violations NOMMÉES en sortie, zéro requête/écriture) — patron
 * {@see FsAclAuthoringGuard}.
 *
 * **Ce qu'il refuse** :
 *   1. champs minimaux vides (`app`, `link`, `server`, `profile_name`) ;
 *   2. `link`/`server`/`cache_local` NON relatifs (chemin absolu Windows
 *      `<lettre>:\…` ou UNC `\\…`, segment `..`, `cache_local` avec séparateur) —
 *      `link` est relatif au profil Windows, `server` au home réseau ;
 *   3. **radical `sambaedu`** dans `profile_name`/`link`/`server` (piège n°1,
 *      AC4) : un nom bâti sur `sambaedu` collisionnerait avec la garde
 *      `referencesSambaeduProfile()` du mécanisme `legacy_cleanup` (38.3), qui
 *      supprimerait à chaque logon ce que la redirection vient d'écrire.
 *      « Ne pas poser la mine » ;
 *   4. **incohérence `profile_name`** : le dernier segment de `link` DOIT être
 *      `profile_name` (c'est le `Path=` de `profiles.ini`, relatif au dossier
 *      des ini = parent de `link`) — sinon Firefox ouvre un profil vide ;
 *   5. `install_hash` présent mais non hexadécimal (8–16 hex — sinon Firefox
 *      ignore la section Install) ;
 *   6. `enabled` présent mais NON booléen STRICT (Story 36.7, AC2) — le champ
 *      d'activation par entrée doit être un vrai booléen JSON (`true`/`false`),
 *      jamais `"on"`/`0`/`1`/`null` : une valeur ambiguë ferait diverger le
 *      filtre du provider (`enabled === false`) de l'intention de l'admin.
 *
 * Conçu pour être RÉUTILISÉ TEL QUEL par le formulaire de catalogue de la
 * Story 36.7 (mêmes messages FR, mêmes constantes publiques).
 */
final class AppProfileAuthoringGuard
{
    /**
     * Radical interdit dans les identifiants de profil (piège n°1, AC4) —
     * insensible à la casse. Le mécanisme `legacy_cleanup` supprime toute paire
     * `profiles.ini`/`installs.ini` référençant `sambaedu.default` ; un nom natif
     * bâti sur ce radical se ferait effacer à chaque logon.
     */
    public const FORBIDDEN_RADICAL = 'sambaedu';

    /**
     * Valide un ensemble de projections d'authoring `app_profile`.
     *
     * @param  list<array{capability:string, warning:?string, spec:mixed}>  $projections
     * @return list<string> violations lisibles (vide = authoring valide)
     */
    public function violations(array $projections): array
    {
        $violations = [];

        foreach ($projections as $projection) {
            $capability = (string) ($projection['capability'] ?? '?');

            // Doublons INTRA-projection (C3, post-review) : deux entrées partageant
            // le même `app` OU le même `link` écriraient dans le même profiles.ini
            // (parent commun du lien) — l'agent poserait A puis B, jamais compliant,
            // réécriture à chaque logon. Refusés à la source. `app` insensible à la
            // casse (identifiant) ; `link` insensible à la casse (chemins Windows).
            $seenApps = [];
            $seenLinks = [];

            foreach ($this->apps($projection['spec'] ?? null) as $app) {
                $appId = trim((string) ($app['app'] ?? ''));
                $link = trim((string) ($app['link'] ?? ''));
                $server = trim((string) ($app['server'] ?? ''));
                $profileName = trim((string) ($app['profile_name'] ?? ''));
                $installHash = trim((string) ($app['install_hash'] ?? ''));
                $cacheLocal = trim((string) ($app['cache_local'] ?? ''));

                $label = $appId !== '' ? $appId : '?';

                // (1) Champs minimaux non vides.
                if ($appId === '') {
                    $violations[] = sprintf("app_profile [%s] : identifiant `app` vide.", $capability);
                }
                if ($link === '') {
                    $violations[] = sprintf("app_profile [%s] app '%s' : `link` vide.", $capability, $label);
                }
                if ($server === '') {
                    $violations[] = sprintf("app_profile [%s] app '%s' : `server` vide.", $capability, $label);
                }
                if ($profileName === '') {
                    $violations[] = sprintf("app_profile [%s] app '%s' : `profile_name` vide.", $capability, $label);
                }

                // (2) Relativité des chemins.
                if ($link !== '' && ! $this->isRelativePath($link)) {
                    $violations[] = sprintf(
                        "app_profile [%s] app '%s' : `link` '%s' doit être RELATIF au profil Windows (ni chemin absolu <lettre>:\\, ni UNC \\\\, ni segment `..`).",
                        $capability, $label, $link,
                    );
                }
                if ($server !== '' && ! $this->isRelativePath($server)) {
                    $violations[] = sprintf(
                        "app_profile [%s] app '%s' : `server` '%s' doit être RELATIF au home réseau (ni chemin absolu, ni UNC, ni segment `..`).",
                        $capability, $label, $server,
                    );
                }
                if ($cacheLocal !== '' && ($this->hasSeparator($cacheLocal) || $this->hasParentSegment($cacheLocal))) {
                    $violations[] = sprintf(
                        "app_profile [%s] app '%s' : `cache_local` '%s' doit être un NOM de dossier simple sous %%LOCALAPPDATA%% (sans séparateur ni `..`).",
                        $capability, $label, $cacheLocal,
                    );
                }

                // (3) Radical `sambaedu` interdit (piège n°1, AC4).
                foreach (['profile_name' => $profileName, 'link' => $link, 'server' => $server] as $field => $value) {
                    if ($value !== '' && $this->containsForbiddenRadical($value)) {
                        $violations[] = sprintf(
                            "app_profile [%s] app '%s' : `%s` '%s' contient le radical interdit « %s » — collision avec le nettoyage legacy_cleanup (le profil serait effacé à chaque logon). Utiliser un nom neuf (ex. « managed.default »).",
                            $capability, $label, $field, $value, self::FORBIDDEN_RADICAL,
                        );
                    }
                }

                // (4) Cohérence profile_name = dernier segment de link.
                if ($link !== '' && $profileName !== '') {
                    $last = $this->lastSegment($link);
                    if (strcasecmp($last, $profileName) !== 0) {
                        $violations[] = sprintf(
                            "app_profile [%s] app '%s' : `profile_name` '%s' ≠ dernier segment de `link` ('%s') — profiles.ini pointerait un profil vide.",
                            $capability, $label, $profileName, $last,
                        );
                    }
                }

                // (5) install_hash hexadécimal si présent.
                if ($installHash !== '' && ! preg_match('/^[0-9A-Fa-f]{8,16}$/', $installHash)) {
                    $violations[] = sprintf(
                        "app_profile [%s] app '%s' : `install_hash` '%s' non hexadécimal (8 à 16 chiffres hex attendus).",
                        $capability, $label, $installHash,
                    );
                }

                // (7) `enabled` booléen STRICT si présent (Story 36.7, AC2). ABSENT
                // = vaut `true` (défaut — comportement 36.5 préservé, le provider ne
                // filtre QUE `enabled === false`). Présent mais non-booléen refusé :
                // `array_key_exists` distingue « clé absente » (toléré) de « clé
                // présente à une valeur ambiguë » (refusé).
                if (array_key_exists('enabled', $app) && ! is_bool($app['enabled'])) {
                    $violations[] = sprintf(
                        "app_profile [%s] app '%s' : `enabled` doit être un booléen strict (true/false), pas '%s'.",
                        $capability, $label, is_scalar($app['enabled']) ? (string) $app['enabled'] : gettype($app['enabled']),
                    );
                }

                // (6) Doublons INTRA-projection : même `app` ou même `link` (C3).
                if ($appId !== '') {
                    $appKey = strtolower($appId);
                    if (isset($seenApps[$appKey])) {
                        $violations[] = sprintf(
                            "app_profile [%s] : `app` '%s' en DOUBLON dans la même projection — deux entrées pour la même application se battraient à chaque logon (jamais compliant).",
                            $capability, $appId,
                        );
                    }
                    $seenApps[$appKey] = true;
                }
                if ($link !== '') {
                    $linkKey = strtolower($link); // chemins Windows : casse ignorée.
                    if (isset($seenLinks[$linkKey])) {
                        $violations[] = sprintf(
                            "app_profile [%s] : `link` '%s' en DOUBLON dans la même projection (comparaison insensible à la casse) — deux entrées écrivant le même profiles.ini se battraient à chaque logon (jamais compliant).",
                            $capability, $link,
                        );
                    }
                    $seenLinks[$linkKey] = true;
                }
            }
        }

        return $violations;
    }

    /**
     * Entrées `apps[]` d'une `spec` (défensif : spec inattendue = liste vide).
     *
     * @return list<array<string,mixed>>
     */
    private function apps(mixed $spec): array
    {
        if (! is_array($spec) || ! isset($spec['apps']) || ! is_array($spec['apps'])) {
            return [];
        }

        return array_values(array_filter($spec['apps'], 'is_array'));
    }

    /**
     * Un chemin RELATIF : pas de préfixe de lecteur (`C:\`), pas de préfixe UNC
     * (`\\`), pas de racine (`\` initial), aucun segment `..`.
     */
    private function isRelativePath(string $path): bool
    {
        $p = str_replace('/', '\\', $path);
        if (str_starts_with($p, '\\')) {
            return false; // racine ou UNC.
        }
        if (preg_match('/^[A-Za-z]:/', $p)) {
            return false; // lecteur absolu.
        }

        return ! $this->hasParentSegment($p);
    }

    private function hasParentSegment(string $path): bool
    {
        foreach (preg_split('#[\\\\/]#', $path) ?: [] as $segment) {
            if (trim($segment) === '..') {
                return true;
            }
        }

        return false;
    }

    private function hasSeparator(string $value): bool
    {
        return str_contains($value, '\\') || str_contains($value, '/');
    }

    private function containsForbiddenRadical(string $value): bool
    {
        return str_contains(strtolower($value), self::FORBIDDEN_RADICAL);
    }

    /** Dernier segment d'un chemin (séparateurs `\` ou `/`). */
    private function lastSegment(string $path): string
    {
        $parts = preg_split('#[\\\\/]#', rtrim($path, '\\/')) ?: [];

        return (string) end($parts);
    }
}
