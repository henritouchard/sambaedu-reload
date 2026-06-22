<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use App\Ipxe\Iso\Exceptions\WindowsIsoValidationException;

/**
 * Story 3.6 — D5 / AC2.* — Validation 2 couches d'une URL Microsoft iso
 * Windows.
 *
 * Couche 1 (côté Livewire) : `rules()` Livewire fait une sanity check
 * regex basique (`required + string + max:2048 + regex https://...`).
 *
 * Couche 2 (ce service) — defense in depth :
 *
 *  1. **Scheme HTTPS strict** — refuse HTTP, FTP, etc.
 *  2. **Allowlist host** — defense vs SSRF. Le `host` parsé via `parse_url`
 *     DOIT être présent dans `config('ipxe.iso_management.allowed_url_hosts')`
 *     (par défaut : `software-static.download.prss.microsoft.com`,
 *     `software-download.microsoft.com`, `download.microsoft.com`). On
 *     accepte aussi les sous-domaines : `host === $allowed` OU
 *     `str_ends_with($host, '.'.$allowed)`.
 *  3. **Path se termine par `Win(10|11).*\.iso`** — extrait `iso_name` du
 *     segment final via regex iso-legacy.
 *  4. **Détection version** — `Win10|Win11` extrait de `iso_name`.
 *  5. **Aucun `userinfo` autorisé** — refuse `https://user@host/...` qui
 *     peut tromper `parse_url` sur certains parsers (defense in depth vs
 *     attaque `https://allowed.com@evil.com/Win11.iso`).
 *  6. **Aucun caractère de contrôle dans l'URL** — refuse `\n`, `\r`,
 *     null byte, etc. (anti-injection iPXE / shell).
 *
 * @return array{url: string, iso_name: string, version: string, version_num: string}
 */
class WindowsIsoUrlValidator
{
    /**
     * Regex iso-legacy — extrait le segment final `Win(10|11)*.iso` du path.
     *
     * Strictement ancrée à la fin (`$`) et au séparateur `/` précédent —
     * empêche les payloads `Win11.iso\nkernel http://evil`.
     *
     * #6 (post-review 2026-05-21) — constante **publique** : référencée par
     * le composant Livewire `pages::admin.ipxe.iso-windows.index` dans ses
     * `rules()` (couche 1 validation) pour éviter le drift entre les 2
     * regex (couche 1 Livewire vs couche 2 service). Source unique.
     */
    public const ISO_NAME_REGEX = '#/(Win(?:10|11)[A-Za-z0-9._\-]*\.iso)$#';

    /**
     * Regex URL complète — utilisée par les `rules()` Livewire (couche 1)
     * pour tightening du contrat URL. Combine HTTPS strict + segment final
     * `Win(10|11)*.iso` — équivalent regex de la couche 2 mais sans
     * allowlist host (la couche 2 fait la vérification host complète).
     *
     * #6 (post-review 2026-05-21) — extraite en constante publique pour
     * éviter la duplication entre composant Livewire et service.
     *
     * 2026-06-22 — autorise une query string / fragment APRÈS `.iso` :
     * `(?:[?#][^\s<>"\']*)?`. Les URLs de téléchargement Microsoft sont des
     * URLs signées (`...Win11.iso?t=<token>&P1=...&P4=...`) — sans cette
     * tolérance, la couche 1 rejetait toute URL réelle. La couche 2 extrait
     * de toute façon l'`iso_name` du PATH seul (la query ne peut pas tromper
     * la regex).
     */
    public const URL_PATH_REGEX = '#^https://[^\s<>"\']+/Win(?:10|11)[A-Za-z0-9._\-]*\.iso(?:[?\#][^\s<>"\']*)?$#';

    /**
     * Regex d'un nom de fichier ISO déposé (upload manuel).
     *
     * Contrairement au flux URL (qui impose la convention Microsoft
     * `Win(10|11)*.iso` car la version y est déduite du nom), le dépôt manuel
     * laisse le choix de la version à l'admin (select). Le nom de fichier est
     * donc plus permissif MAIS strictement borné pour la sécurité :
     *  - charset blanc `[A-Za-z0-9._-]` uniquement → aucun séparateur de chemin
     *    (`/`, `\`), aucun `..` possible (le `.` isolé est ok, `..` non car il
     *    serait suivi/précédé d'autres `.` toujours dans le charset mais le
     *    rejet explicite des `..` ci-dessous le couvre), aucun espace ni
     *    caractère shell.
     *  - extension `.iso` obligatoire (insensible à la casse).
     *  - 1 à 255 caractères (limite filesystem).
     */
    public const UPLOAD_FILENAME_REGEX = '#^[A-Za-z0-9._\-]{1,251}\.iso$#i';

    /**
     * Versions Windows acceptées (D5 + cohérence enum
     * {@see \App\Ipxe\Iso\Enums\WindowsIsoDownloadStatus}).
     *
     * @var list<string>
     */
    private const ALLOWED_VERSIONS = ['10', '11'];

    /**
     * Valide un dépôt manuel d'ISO : nom de fichier + version choisie.
     *
     * @param  string  $filename  Nom de fichier brut fourni par le client (jamais un chemin).
     * @param  string  $version   'Win10' | 'Win11' (select admin).
     * @return array{iso_name: string, version: string, version_num: string}
     *
     * @throws WindowsIsoValidationException
     */
    public function validateUploadFilename(string $filename, string $version): array
    {
        // Anti-caractère de contrôle / null byte / newline.
        if (preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new WindowsIsoValidationException(
                "Nom de fichier invalide : caractères de contrôle interdits.",
            );
        }

        // Defense in depth anti path-traversal — refus explicite de `..` et de
        // tout séparateur de chemin avant même la regex de charset.
        if (str_contains($filename, '..')
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
        ) {
            throw new WindowsIsoValidationException(
                "Nom de fichier invalide : séparateurs de chemin et `..` interdits.",
            );
        }

        if (preg_match(self::UPLOAD_FILENAME_REGEX, $filename) !== 1) {
            throw new WindowsIsoValidationException(
                "Nom de fichier invalide : seuls les caractères [A-Za-z0-9._-] sont autorisés, "
                . "extension `.iso` obligatoire (255 caractères max).",
            );
        }

        // Version issue d'un select admin — whitelist stricte.
        $versionNum = str_replace('Win', '', $version);
        if (! in_array($versionNum, self::ALLOWED_VERSIONS, true)) {
            throw new WindowsIsoValidationException(
                "Version Windows '" . $version . "' non supportée (Win10 ou Win11 uniquement).",
            );
        }

        return [
            'iso_name'    => $filename,
            'version'     => 'Win' . $versionNum,
            'version_num' => $versionNum,
        ];
    }

    /**
     * @return array{url: string, iso_name: string, version: string, version_num: string}
     *
     * @throws WindowsIsoValidationException
     */
    public function validate(string $url): array
    {
        // Garde-fou anti-caractère de contrôle / null byte / newline avant tout.
        // parse_url() lève des warnings discrets sur certains payloads — on
        // refuse explicitement.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new WindowsIsoValidationException(
                "URL invalide : caractères de contrôle interdits.",
            );
        }

        if ($url === '' || strlen($url) > 2048) {
            throw new WindowsIsoValidationException(
                "URL invalide : la longueur doit être comprise entre 1 et 2048 caractères.",
            );
        }

        $parsed = parse_url($url);
        if ($parsed === false || ! is_array($parsed)) {
            throw new WindowsIsoValidationException("URL invalide : parsing échoué.");
        }

        // 1) Scheme HTTPS strict.
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new WindowsIsoValidationException(
                "Scheme HTTPS obligatoire (reçu : '" . ($scheme === '' ? '<vide>' : $scheme) . "').",
            );
        }

        // 5) Aucun userinfo. `parse_url` met le user dans `user`/`pass`.
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            throw new WindowsIsoValidationException(
                "URL invalide : informations d'authentification interdites dans l'URL.",
            );
        }

        // 2) Host allowlist (defense in depth vs SSRF).
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            throw new WindowsIsoValidationException("URL invalide : host absent.");
        }

        $allowedHosts = (array) config('ipxe.iso_management.allowed_url_hosts', [
            // `download.prss.microsoft.com` couvre via str_ends_with() les
            // sous-domaines `software.` ET `software-static.` (Microsoft a
            // basculé de l'un à l'autre) + les futurs.
            'download.prss.microsoft.com',
            'software-download.microsoft.com',
            'download.microsoft.com',
        ]);

        $hostOk = false;
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                $hostOk = true;
                break;
            }
        }
        if (! $hostOk) {
            throw new WindowsIsoValidationException(
                "Host '" . $host . "' non autorisé (allowlist Microsoft uniquement).",
            );
        }

        // 3) Refus défensif des sequences `..` dans le path (anti path-traversal).
        // Bien que la regex finale ancrée à `$` empêche tout segment final
        // hors `Win(10|11)*.iso`, on rejette explicitement les paths qui
        // contiennent `..` ou des séquences encodées (`%2e`) — defense in
        // depth + message d'erreur explicite.
        $path = (string) ($parsed['path'] ?? '');
        $pathDecoded = strtolower(rawurldecode($path));
        if (str_contains($pathDecoded, '..') || str_contains($pathDecoded, '%2e')) {
            throw new WindowsIsoValidationException(
                "URL invalide : le path contient une séquence interdite (..) — Win(10|11)…\\.iso seul accepté.",
            );
        }

        // 4) Extract iso_name via regex iso-legacy.
        // On match contre le path uniquement — pas l'URL complète — pour
        // empêcher les query strings de tromper la regex (`?fake=Win11.iso`).
        if (! preg_match(self::ISO_NAME_REGEX, $path, $matches)) {
            throw new WindowsIsoValidationException(
                "URL invalide : le segment final du path doit matcher 'Win(10|11)…\\.iso' "
                . "(parité legacy win_iso.php).",
            );
        }
        $isoName = $matches[1];

        // 4) Détection version (Win10|Win11 — Win7/Win8 explicitement rejetés).
        if (! preg_match('/^Win(10|11)/i', $isoName, $m)) {
            throw new WindowsIsoValidationException(
                "Impossible de déterminer la version Windows (Win10 ou Win11) dans '" . $isoName . "'.",
            );
        }
        $versionNum = $m[1];
        if (! in_array($versionNum, self::ALLOWED_VERSIONS, true)) {
            // Defense in depth — la regex en amont garantit déjà '10|11'.
            throw new WindowsIsoValidationException(
                "Version Windows '" . $versionNum . "' hors whitelist (Win10|Win11 seuls).",
            );
        }

        return [
            'url'         => $url,
            'iso_name'    => $isoName,
            'version'     => 'Win' . $versionNum,
            'version_num' => $versionNum,
        ];
    }
}
