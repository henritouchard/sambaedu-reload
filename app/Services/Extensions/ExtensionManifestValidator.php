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
 */
class ExtensionManifestValidator
{
    /**
     * Versions de manifest supportées par cette instance SE5 (égalité stricte).
     *
     * @var list<int>
     */
    public const SUPPORTED_MANIFEST_VERSIONS = [1];

    /** Un `id` d'extension est un slug : minuscules, chiffres, `_` et `-`. */
    public const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

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
        $entryUrl = $this->requiredString($manifest, 'entry_url');

        // 5. Visibilité (rôles métier — décision #2).
        $roles = $this->assertVisibilityRoles($manifest);

        return [
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
