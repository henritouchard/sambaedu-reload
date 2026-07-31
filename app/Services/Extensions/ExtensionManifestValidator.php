<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionType;
use App\Exceptions\InvalidExtensionManifestException;

/**
 * Story 54.1 (AC2) — Validation du **manifest v1**, contrat public du système
 * d'extensions (FR5, NFR11).
 *
 * Service **PUR** : il ne lit ni n'écrit aucune table, aucun fichier. Il reçoit
 * un tableau décodé, le valide, et renvoie une forme NORMALISÉE (défauts
 * appliqués) prête à être persistée. Toute violation lève une
 * {@see InvalidExtensionManifestException} qui NOMME le champ fautif.
 *
 * Manifest v1 :
 *
 * ```json
 * {
 *   "manifest_version": 1,
 *   "id": "doc",
 *   "type": "link",
 *   "name": "Documentation",
 *   "version": "1.0.0",
 *   "entry_url": "/doc",
 *   "icon": "fa-solid fa-book-open",
 *   "publisher": "SambaEdu",
 *   "description": "Documentation publique SambaEdu (guides admin et poste).",
 *   "scopes": [],
 *   "dependencies": [],
 *   "visibility": { "roles": ["admin", "prof", "eleve"] }
 * }
 * ```
 *
 * - **Requis** : `manifest_version`, `id`, `type`, `name`, `version`,
 *   `entry_url`, `visibility.roles`.
 * - **Optionnels avec défauts** : `icon`, `publisher`, `description` (`''`),
 *   `scopes` et `dependencies` (`[]`).
 *
 * DÉCISIONS :
 *
 * 1. **Version STRICTE d'abord.** `manifest_version` est validée AVANT tout
 *    contenu : un manifest émis sous une version future ne doit pas être
 *    interprété selon les règles de la v1 (une erreur de CONTENU masquerait la
 *    vraie cause). Aucun repli tolérant — iso-décision Story 33.2.
 * 2. **`visibility.roles` = rôles MÉTIER** (`admin`/`prof`/`eleve`), jamais des
 *    `SambaPermission` (AR8 : les enums fermées ne sont pas contractuelles). Le
 *    validateur exige un tableau NON VIDE de chaînes non vides, sans imposer de
 *    liste fermée de rôles. 54.1 stocke, 54.3 résout.
 * 3. **`scopes` affichés, jamais consommés** en 54.1 (FR3) — leur seule règle
 *    ici est d'être une liste de chaînes.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  STORY 56.2 — EXTENSION ADDITIVE DU FORMAT v1 (NFR11)
 *
 *  Deux ajouts, tous deux **additifs** : un manifest 54.x/56.1 sans bloc
 *  `install` reste valide VERBATIM, et aucun manifest publié n'est cassé.
 *
 *  1. **Bloc `install` OPTIONNEL** — ce qui rend une `app` installable :
 *
 *  ```json
 *  "install": {
 *    "channel": "deb",
 *    "package": "packages/sambaedu-ext-hello_1.0.0_all.deb",
 *    "sha256": "b1946ac9…",
 *    "redirect_paths": ["/ext/hello/oidc/callback"]
 *  }
 *  ```
 *
 *  - `channel` ∈ {@see self::SUPPORTED_INSTALL_CHANNELS} — STRICT, comme
 *    `manifest_version` : un canal inconnu (`snap`, `oci`…) n'est pas un canal
 *    `deb` dégradé, il est refusé. La liste est extensible sans rupture.
 *  - `package` est un chemin RELATIF à l'URL de base de la source : ni schéma,
 *    ni `..`, ni `//`, ni query, ni fragment, ni `/` initial. Le paquet se
 *    télécharge donc TOUJOURS depuis le même hôte que l'index signé — pas de
 *    dépôt « proxy », pas de SSRF par le manifest.
 *  - `sha256` est le hash du paquet, en 64 hexadécimaux MINUSCULES. Étant porté
 *    par l'index déjà signé Ed25519 (56.1), il est transitivement couvert par
 *    la signature : le vérifier EST la vérification « contre la clé déclarée de
 *    sa source » (NFR2), patron apt `Release` → `Packages` → `.deb`. Aucun
 *    second format de signature n'est inventé.
 *  - `redirect_paths` (optionnel) borne les URI de redirection OIDC au préfixe
 *    `/ext/<id>/` — préfixe RECALCULÉ depuis l'`id` déjà validé, jamais lu du
 *    manifest. Sans cette borne, un manifest hostile ferait enregistrer un
 *    client dont le callback pointe une AUTRE extension (vol de code
 *    d'autorisation).
 *
 *  2. **`type = app` ⇒ `entry_url === '/ext/<id>'`** (AR3). Contrainte posée
 *  MAINTENANT parce qu'aucun manifest `app` n'a jamais été publié : la poser
 *  après publication casserait ses consommateurs (NFR11), exactement comme le
 *  durcissement d'`entry_url` de la review 54.3. C'est elle qui garantit que la
 *  tuile du lanceur pointe l'exposition RÉELLEMENT provisionnée par
 *  l'installation (le fragment Apache `ProxyPass /ext/<key>`) : sans elle, une
 *  `app` installée pourrait afficher une tuile vers n'importe où.
 *
 *  ⚠️ Le bloc `install` n'est PAS exigé pour qu'un manifest `app` soit VALIDE :
 *  le catalogue doit pouvoir AFFICHER une `app` non installable (56.1). C'est
 *  `ext:install` qui refuse fail-closed une `app` sans bloc.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ExtensionManifestValidator
{
    /**
     * Versions de manifest supportées par cette instance SE5 (égalité stricte).
     *
     * @var list<int>
     */
    public const SUPPORTED_MANIFEST_VERSIONS = [1];

    /**
     * Canaux d'installation supportés (Story 56.2). STRICT : un canal inconnu
     * est refusé, jamais dégradé. Extensible par ajout (AR2).
     *
     * @var list<string>
     */
    public const SUPPORTED_INSTALL_CHANNELS = ['deb'];

    /** Un `id` d'extension est un slug : minuscules, chiffres, `_` et `-`. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    /**
     * Un chemin de paquet : segments `[A-Za-z0-9._-]+` séparés par `/`, sans
     * `/` initial ni final. Refuse par CONSTRUCTION tout schéma (`:`), toute
     * URL protocol-relative (`//`), toute query (`?`) et tout fragment (`#`) —
     * aucun de ces caractères n'appartient à la classe. Les segments `.` et
     * `..` sont refusés séparément (ils passent la classe de caractères).
     */
    public const PACKAGE_PATH_PATTERN = '#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#';

    /** Un sha256 : 64 hexadécimaux MINUSCULES (forme canonique, pas de casse mixte). */
    public const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * Valide un manifest décodé et renvoie sa forme normalisée.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{
     *     manifest_version: int,
     *     id: string,
     *     type: ExtensionType,
     *     name: string,
     *     version: string,
     *     entry_url: string,
     *     icon: string,
     *     publisher: string,
     *     description: string,
     *     scopes: list<string>,
     *     dependencies: list<string>,
     *     visibility: array{roles: list<string>},
     *     install?: array{channel: string, package: string, sha256: string, redirect_paths: list<string>},
     * }
     *
     * @throws InvalidExtensionManifestException
     */
    public function validate(array $manifest): array
    {
        // 1. VERSION d'abord (décision #1) — rejet strict, aucun repli.
        $version = $this->assertSupportedVersion($manifest);

        // 2. Identité.
        $id = $this->requiredString($manifest, 'id');
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw InvalidExtensionManifestException::invalidField(
                'id',
                'doit être un slug (minuscules, chiffres, « _ » ou « - », commençant par une lettre ou un chiffre)',
            );
        }

        // 3. Type ∈ enum.
        $rawType = $manifest['type'] ?? null;
        if (! is_string($rawType) || trim($rawType) === '') {
            throw InvalidExtensionManifestException::missingField('type');
        }
        $type = ExtensionType::tryFrom($rawType);
        if ($type === null) {
            $known = implode(', ', array_column(ExtensionType::cases(), 'value'));
            throw InvalidExtensionManifestException::invalidField(
                'type',
                "type inconnu « {$rawType} » ; types connus : {$known}",
            );
        }

        // 4. Champs texte obligatoires restants.
        $name = $this->requiredString($manifest, 'name');
        $extensionVersion = $this->requiredString($manifest, 'version');
        $entryUrl = $this->assertEntryUrl($manifest);

        // 4bis. Story 56.2 (AR3) — une `app` est SERVIE par SE5 sous `/ext/<id>`.
        if ($type === ExtensionType::App && $entryUrl !== self::appEntryUrl($id)) {
            throw InvalidExtensionManifestException::invalidField(
                'entry_url',
                'une extension de type « app » DOIT déclarer exactement « '.self::appEntryUrl($id).' » : '
                .'c\'est le chemin que SE5 provisionne lui-même (ProxyPass vers le port assigné au backend). '
                .'Une tuile pointant ailleurs afficherait autre chose que ce qui a été installé',
            );
        }

        // 5. Visibilité (rôles métier — décision #2).
        $roles = $this->assertVisibilityRoles($manifest);

        // 6. Story 56.2 — bloc `install` OPTIONNEL (additif, NFR11).
        $install = $this->assertInstallBlock($manifest, $id);

        $normalized = [
            'manifest_version' => $version,
            'id' => $id,
            'type' => $type,
            'name' => $name,
            'version' => $extensionVersion,
            'entry_url' => $entryUrl,
            'icon' => $this->optionalString($manifest, 'icon'),
            'publisher' => $this->optionalString($manifest, 'publisher'),
            'description' => $this->optionalString($manifest, 'description'),
            'scopes' => $this->optionalStringList($manifest, 'scopes'),
            'dependencies' => $this->optionalStringList($manifest, 'dependencies'),
            'visibility' => ['roles' => $roles],
        ];

        // Bloc ABSENT ⇒ clé absente : la forme normalisée d'un manifest 54.x
        // reste octet pour octet celle qu'elle était (aucune régression du
        // snapshot persisté, aucun `install: null` parasite en base).
        if ($install !== null) {
            $normalized['install'] = $install;
        }

        return $normalized;
    }

    /**
     * Chemin d'exposition CANONIQUE d'une extension `app` (AR3).
     *
     * Une seule définition pour trois consommateurs : la règle `entry_url`
     * ci-dessus, la borne des `redirect_paths`, et le fragment Apache généré à
     * l'installation. Les faire diverger, c'est provisionner un proxy sur un
     * chemin et afficher une tuile vers un autre.
     */
    public static function appEntryUrl(string $id): string
    {
        return '/ext/'.$id;
    }

    /**
     * Bloc `install` (Story 56.2) — OPTIONNEL, strictement validé s'il est là.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{channel: string, package: string, sha256: string, redirect_paths: list<string>}|null
     *
     * @throws InvalidExtensionManifestException
     */
    private function assertInstallBlock(array $manifest, string $id): ?array
    {
        $install = $manifest['install'] ?? null;
        if ($install === null) {
            return null;
        }

        // Un objet JSON, jamais une liste (`array_is_list`) ni un scalaire —
        // même règle que `visibility` : pas de repli tolérant.
        if (! is_array($install) || array_is_list($install)) {
            throw InvalidExtensionManifestException::invalidField(
                'install',
                'doit être un objet JSON ({"channel":…, "package":…, "sha256":…})',
            );
        }

        // ── channel : liste FERMÉE ────────────────────────────────────────
        $channel = $install['channel'] ?? null;
        if (! is_string($channel) || ! in_array($channel, self::SUPPORTED_INSTALL_CHANNELS, true)) {
            $known = implode(', ', self::SUPPORTED_INSTALL_CHANNELS);
            throw InvalidExtensionManifestException::invalidField(
                'install.channel',
                'canal d\'installation non supporté par cette instance ; canaux connus : '.$known,
            );
        }

        // ── package : chemin RELATIF borné ────────────────────────────────
        $package = $install['package'] ?? null;
        if (! is_string($package)) {
            throw InvalidExtensionManifestException::missingField('install.package');
        }
        $package = trim($package);

        $invalidPath = $package === ''
            || preg_match(self::PACKAGE_PATH_PATTERN, $package) !== 1
            || in_array('.', explode('/', $package), true)
            || in_array('..', explode('/', $package), true);

        if ($invalidPath) {
            throw InvalidExtensionManifestException::invalidField(
                'install.package',
                'doit être un chemin RELATIF à l\'URL de base de la source (« packages/mon-paquet_1.0.0_all.deb ») : '
                .'ni schéma, ni « .. », ni « / » initial, ni paramètre de requête, ni ancre — '
                .'le paquet se télécharge toujours depuis l\'hôte qui a signé l\'index',
            );
        }

        // ── sha256 : forme canonique stricte ──────────────────────────────
        $sha256 = $install['sha256'] ?? null;
        if (! is_string($sha256) || preg_match(self::SHA256_PATTERN, trim($sha256)) !== 1) {
            throw InvalidExtensionManifestException::invalidField(
                'install.sha256',
                'doit être un sha256 de 64 caractères hexadécimaux MINUSCULES',
            );
        }

        return [
            'channel' => $channel,
            'package' => $package,
            'sha256' => trim($sha256),
            'redirect_paths' => $this->assertRedirectPaths($install, $id),
        ];
    }

    /**
     * `install.redirect_paths` : chemins absolus BORNÉS au préfixe `/ext/<id>/`.
     *
     * Le préfixe est RECALCULÉ depuis l'`id` déjà validé — jamais lu du
     * manifest. Une source tierce ne peut donc pas déclarer un callback OIDC
     * pointant hors de SON extension : sinon un manifest hostile enregistrerait
     * un client dont l'URI de redirection cible une autre application de
     * l'instance, et récupérerait ses codes d'autorisation.
     *
     * Absent ou vide ⇒ `[]` : c'est l'appelant (le moteur d'installation) qui
     * applique le défaut conventionnel `/ext/<id>/oidc/callback`.
     *
     * @param  array<string, mixed>  $install
     * @return list<string>
     *
     * @throws InvalidExtensionManifestException
     */
    private function assertRedirectPaths(array $install, string $id): array
    {
        $paths = $install['redirect_paths'] ?? null;
        if ($paths === null) {
            return [];
        }

        if (! is_array($paths) || ! array_is_list($paths)) {
            throw InvalidExtensionManifestException::invalidField(
                'install.redirect_paths',
                'doit être un tableau JSON de chemins — pas un objet',
            );
        }

        $prefix = self::appEntryUrl($id).'/';

        $normalized = [];
        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw InvalidExtensionManifestException::invalidField(
                    'install.redirect_paths',
                    'chaque entrée doit être une chaîne non vide',
                );
            }

            $path = trim($path);

            if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
                throw InvalidExtensionManifestException::invalidField(
                    'install.redirect_paths',
                    'chaque chemin doit commencer par « '.$prefix.' » : une extension ne déclare '
                    .'jamais un callback hors de son propre préfixe',
                );
            }

            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidExtensionManifestException
     */
    private function assertSupportedVersion(array $manifest): int
    {
        if (! array_key_exists('manifest_version', $manifest) || $manifest['manifest_version'] === null) {
            throw InvalidExtensionManifestException::missingField('manifest_version');
        }

        $declared = $manifest['manifest_version'];

        // Un entier, ou une chaîne strictement numérique entière (« 1 ») : rien
        // d'autre. Un « 1.0 » ou « v1 » n'est PAS la version 1 — le laisser
        // passer serait le repli tolérant qu'on refuse.
        $normalized = null;
        if (is_int($declared)) {
            $normalized = $declared;
        } elseif (is_string($declared) && preg_match('/^\d+$/', $declared) === 1) {
            $normalized = (int) $declared;
        }

        if ($normalized === null || ! in_array($normalized, self::SUPPORTED_MANIFEST_VERSIONS, true)) {
            throw InvalidExtensionManifestException::unsupportedVersion(
                $declared,
                self::SUPPORTED_MANIFEST_VERSIONS,
            );
        }

        return $normalized;
    }

    /**
     * `entry_url` : chemin absolu de l'instance (`/doc`) ou URL http(s).
     *
     * Borné depuis la Story 54.3 : c'est elle qui a fait de `entry_url` un
     * `href` CLIQUABLE dans le lanceur, exposé à tous les rôles visés — 54.1 et
     * 54.2 se contentaient de l'afficher dans une fiche. Sans contrainte de
     * schéma, `javascript:…` ou `data:text/html,…` passaient la validation.
     * Blade échappe correctement (pas d'évasion d'attribut possible), donc ce
     * n'était pas une injection HTML : c'est le SCHÉMA d'URL qui n'était pas
     * borné.
     *
     * Durci ici et pas reporté, pour la même raison que `array_is_list()` en
     * review 54.1 : sans effet sur la source embarquée (dépôt contrôlé),
     * décisif dès que des sources DISTANTES fourniront des manifests non
     * contrôlés (Epic 56) — et un contrat public durci APRÈS publication casse
     * ses consommateurs (NFR11).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidExtensionManifestException
     */
    private function assertEntryUrl(array $manifest): string
    {
        $entryUrl = $this->requiredString($manifest, 'entry_url');

        $isAbsolutePath = str_starts_with($entryUrl, '/') && ! str_starts_with($entryUrl, '//');
        $isHttpUrl = preg_match('#^https?://#i', $entryUrl) === 1;

        if (! $isAbsolutePath && ! $isHttpUrl) {
            throw InvalidExtensionManifestException::invalidField(
                'entry_url',
                'doit être un chemin absolu de l\'instance (« /doc ») ou une URL http(s) — '
                .'tout autre schéma (javascript:, data:, file:…) est refusé',
            );
        }

        return $entryUrl;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     *
     * @throws InvalidExtensionManifestException
     */
    private function assertVisibilityRoles(array $manifest): array
    {
        $visibility = $manifest['visibility'] ?? null;
        if (! is_array($visibility) || ! array_key_exists('roles', $visibility)) {
            throw InvalidExtensionManifestException::missingField('visibility.roles');
        }

        $roles = $visibility['roles'];
        // `array_is_list` : un OBJET JSON (`{"a":"admin"}`) décode en tableau
        // ASSOCIATIF PHP. L'accepter reviendrait à le ré-indexer silencieusement
        // en liste — exactement le repli tolérant que la décision #1 refuse. Sans
        // effet sur la source embarquée (dépôt contrôlé), décisif dès qu'une
        // source DISTANTE fournira des manifests non contrôlés (Epic 56).
        if (! is_array($roles) || ! array_is_list($roles) || $roles === []) {
            throw InvalidExtensionManifestException::invalidField(
                'visibility.roles',
                'doit être un tableau JSON non vide de rôles métier (admin, prof, eleve…) — pas un objet',
            );
        }

        $normalized = [];
        foreach ($roles as $role) {
            if (! is_string($role) || trim($role) === '') {
                throw InvalidExtensionManifestException::invalidField(
                    'visibility.roles',
                    'chaque rôle doit être une chaîne non vide',
                );
            }
            $normalized[] = trim($role);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidExtensionManifestException
     */
    private function requiredString(array $manifest, string $field): string
    {
        $value = $manifest[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw InvalidExtensionManifestException::missingField($field);
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws InvalidExtensionManifestException
     */
    private function optionalString(array $manifest, string $field): string
    {
        $value = $manifest[$field] ?? null;
        if ($value === null) {
            return '';
        }
        if (! is_string($value)) {
            throw InvalidExtensionManifestException::invalidField($field, 'doit être une chaîne de caractères');
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     *
     * @throws InvalidExtensionManifestException
     */
    private function optionalStringList(array $manifest, string $field): array
    {
        $value = $manifest[$field] ?? null;
        if ($value === null) {
            return [];
        }
        // Même règle que `visibility.roles` : un objet JSON n'est PAS une liste.
        if (! is_array($value) || ! array_is_list($value)) {
            throw InvalidExtensionManifestException::invalidField(
                $field,
                'doit être un tableau JSON de chaînes — pas un objet',
            );
        }

        $normalized = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw InvalidExtensionManifestException::invalidField(
                    $field,
                    'chaque entrée doit être une chaîne non vide',
                );
            }
            $normalized[] = trim($item);
        }

        return array_values($normalized);
    }
}
