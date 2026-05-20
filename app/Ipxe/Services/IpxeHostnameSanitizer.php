<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

/**
 * Story 3.3 — D6 / AC1.1.
 *
 * Service auxiliaire stateless qui porte natif les transformations
 * `q2a()` (legacy `sambaedu/includes/ipxe_functions.inc.php:48-57`) et
 * `add_hostname_suffix()` (legacy `sambaedu/includes/ldap.inc.php:380-394`)
 * du flow d'enrollment iPXE, plus une validation regex stricte
 * **anti-injection shell** appliquée AVANT tout appel `samba-tool`.
 *
 * **Sécurité critique** : la saisie `new_name` arrive en clair du firmware
 * iPXE — un attaquant LAN peut poster n'importe quoi. {@see isValidHostname()}
 * refuse tout caractère non-ASCII (espaces, `;`, `|`, `&`, `$`, `` ` ``, `\n`,
 * `\r`, `"`, `'`...) → défense en profondeur en complément de la regex
 * stricte côté {@see \App\Ldap\AdMachineManager}.
 *
 * **Stateless** : aucune dépendance, aucun side effect — peut être instancié
 * directement ou résolu via le container Laravel (singleton enregistré dans
 * {@see \App\Providers\IpxeServiceProvider}).
 */
final class IpxeHostnameSanitizer
{
    /**
     * Regex stricte post-sanitization — refuse tout char hors `[a-z0-9_\-\.\$]`
     * et limite à 32 chars.
     *
     * Q4 (review 3.3) : le cap était initialement à 15 (NetBIOS legacy), mais
     * `applyHostnameSuffix()` peut produire `substr($name,0,9) . $suffix` qui
     * dépasse 15 dès que `strlen($suffix) > 6`. Une régression silencieuse
     * apparaissait en prod si `config('sambaedu.legacy_ldap.suffix')` était
     * configuré : le nom sanitizé était rejeté ici sans log explicite côté
     * enrollment. 32 chars couvre les suffixes legacy_ldap connus tout en
     * restant en-dessous de la limite AD (64). La validation char-by-char
     * (`[a-z0-9_\-\.\$]`) reste strict — pas de relâchement anti-injection.
     *
     * Note iso 16.7 `AdMachineManager::MACHINE_REGEX` qui autorise majuscules
     * + 64 chars : ici on impose la contrainte stricte post-`strtolower`.
     */
    private const HOSTNAME_REGEX = '/^[a-z0-9_\-\.\$]{1,32}$/';

    /**
     * Regex de détection des serveurs internes SE4FS/SE4AD (parité iso-legacy
     * `enregistrement.php:39`). Ces noms ne reçoivent PAS de suffix
     * (ils sont des noms canoniques de l'infrastructure SE4).
     */
    private const SERVER_NAME_REGEX = '/^(se4fs|se4ad-[0-9]{7}[a-z])/i';

    /**
     * Port natif de `q2a()` legacy (`ipxe_functions.inc.php:48-57`).
     *
     * Note iso-legacy : la fonction `q2a()` est currently un **no-op** en
     * pratique (le bloc `if (false)` est commenté volontairement — le clavier
     * UEFI Sambaedu n'est pas en azerty translation côté firmware iPXE). On
     * reproduit fidèlement le comportement legacy : pas de translation,
     * juste `strtolower` qui est posé par les call-sites legacy.
     *
     * Ajoute `strtolower` (cohérence avec `add_hostname_suffix()` legacy qui
     * retourne `strtolower($name)` ligne 393).
     *
     * @param  string  $rawName  Nom brut tel que reçu du firmware iPXE
     *                           (potentiellement mixed-case + chars invalides).
     * @param  string  $platform `'legacy'` (default — clavier français
     *                           legacy) ou `'uefi'` (clavier UEFI English,
     *                           translation activable si besoin terrain).
     */
    public function sanitize(string $rawName, string $platform = 'legacy'): string
    {
        // Port iso-legacy `q2a()` : translation azerty↔qwerty désactivée
        // (legacy ligne 51 `if (false) { ... }`). On garde le squelette pour
        // permettre une future activation sans refactor.
        if ($platform === 'uefi' && false) {
            // @phpstan-ignore-next-line dead branch by design — parité iso-legacy.
            $azerty = 'aAzZ01m';
            $qwerty = 'qQwW)!';
            $rawName = strtr($rawName, $qwerty, $azerty);
        }

        return strtolower(trim($rawName));
    }

    /**
     * Port natif de `add_hostname_suffix()` legacy (`ldap.inc.php:384-394`).
     *
     * Comportement iso-strict :
     *
     *  - **Si `$suffix` est défini et non vide** :
     *      - Si le nom se termine déjà par `$suffix` → retour identité
     *        (`strtolower` appliqué).
     *      - Sinon → tronque `$name` à **9 caractères** puis concatène
     *        `$suffix`. Le résultat peut donc dépasser 15 chars selon la
     *        longueur du suffix — parité legacy stricte (le legacy n'impose
     *        pas de cap 15 dans cette branche).
     *  - **Sinon** :
     *      - Tronque `$name` à **15 caractères**.
     *
     * Retourne toujours `strtolower($name)` final (parité legacy ligne 393).
     *
     * Si `$suffix === null`, lit `config('sambaedu.legacy_ldap.suffix', '')`
     * — c'est la même source utilisée par `AdMachineManager::setOs()` /
     * `AdUserManager`, donc cohérent cross-namespace.
     *
     * @param  string       $name    Nom déjà sanitizé via {@see sanitize()}.
     * @param  string|null  $suffix  Suffix optionnel (défaut = config).
     */
    public function applyHostnameSuffix(string $name, ?string $suffix = null): string
    {
        if ($suffix === null) {
            $suffix = (string) config('sambaedu.legacy_ldap.suffix', '');
        }

        if ($suffix !== '') {
            // Iso-legacy : `if (!preg_match("/$suffix$/i", $name))`.
            // Échappement preg pour suffixes potentiellement regex-sensibles.
            $pattern = '/' . preg_quote($suffix, '/') . '$/i';
            if (preg_match($pattern, $name) === 1) {
                // Déjà suffixé → retour identité (lowercase appliqué).
                return strtolower($name);
            }

            // Tronquage 9 + suffix (parité legacy ligne 388 stricte).
            return strtolower(substr($name, 0, 9) . $suffix);
        }

        // Pas de suffix → tronquage 15 (parité legacy ligne 391).
        return strtolower(substr($name, 0, 15));
    }

    /**
     * Validation regex stricte anti-injection (post-sanitization).
     *
     * **Filtre absolu** : tout caractère hors `[a-z0-9_\-\.\$]` est refusé.
     * Couvre les vecteurs d'injection shell connus : `;`, `|`, `&`, `$`,
     * `` ` ``, `\n`, `\r`, `"`, `'`, espace, slash, etc.
     *
     * **À appeler systématiquement AVANT toute écriture DB ou appel
     * `samba-tool`** — défense en profondeur même si `AdMachineManager` a sa
     * propre regex (qui autorise les majuscules et 64 chars — moins strict).
     */
    public function isValidHostname(string $name): bool
    {
        return $name !== '' && (bool) preg_match(self::HOSTNAME_REGEX, $name);
    }

    /**
     * Détecte les noms de serveurs internes SE4FS/SE4AD (parité iso-legacy
     * `enregistrement.php:39`).
     *
     * Ces noms sont :
     *
     *  - tronqués à 15 chars stricts (pas de suffix appliqué)
     *  - non créés/renommés dans l'AD via le flow iPXE (cf.
     *    `AdMachineManager::check()` qui skip les `se4fs|se4ad`)
     *
     * Pattern matché : `se4fs.*` (n'importe quoi commençant par `se4fs`,
     * case-insensitive) ou `se4ad-NNNNNNNa` (7 digits + 1 lowercase letter
     * — UAI école legacy).
     */
    public function isSpecialServerName(string $name): bool
    {
        return $name !== '' && (bool) preg_match(self::SERVER_NAME_REGEX, $name);
    }

    /**
     * Pour usage dans les sorties text/plain iPXE (templates Blade + value objects).
     *
     * Iso {@see IpxeEnrollmentMenuBuilder::sanitizeAscii()} : tout caractère
     * hors `0x20-0x7E` (et tab) est remplacé par `?`. Bloque newline injection
     * (`\n`/`\r` → `?`), control chars exotiques et ASCII étendu (accents fr).
     *
     * F2 + Opus-1 (review 3.3) : invariant à appliquer dans les factories
     * {@see \App\Ipxe\Support\EnrollNameResult::*} pour garantir que tout
     * `sanitizedName`/`reasonLabel` injecté dans un template Blade est safe.
     */
    public static function sanitizeForIpxeOutput(string $value): string
    {
        // Flag `/u` : remplace chaque char Unicode multi-octets par un seul `?`
        // (sans `/u`, un `é` UTF-8 produit `??` au lieu de `?`).
        // Fallback fail-closed : si `preg_replace` retourne null (UTF-8 invalide
        // → PREG_BAD_UTF8_ERROR), on remplace l'intégralité de la chaîne par des
        // `?` plutôt que de réinjecter du brut non-sanitisé dans le template iPXE.
        $clean = preg_replace('/[^\x20-\x7E\t]/u', '?', $value);
        if ($clean !== null) {
            return $clean;
        }

        // UTF-8 invalide : on retombe en mode bytes pour garantir la sanitization,
        // puis on rebascule en fail-closed `?` si même cette passe échoue.
        $bytewise = preg_replace('/[^\x20-\x7E\t]/', '?', $value);

        return $bytewise ?? str_repeat('?', strlen($value));
    }
}
