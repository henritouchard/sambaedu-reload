<?php

declare(strict_types=1);

namespace App\Services\Agent\Resolvers;

use App\Gpo\Services\PackagesXmlAssociationsReader;
use App\Models\Application;
use App\Models\FileAssociation;
use App\Models\NativeApplication;
use App\Models\WorkstationGroup;
use InvalidArgumentException;

/**
 * Story 27.11 — Service RESOLVER serveur : traduit le choix admin *(extension/
 * protocole X, application A)* en cible technique *(progid, source, wpkg_package)*
 * que l'agent applique DÉJÀ (provider/compilateur/handler/hash de 27.3bis
 * réutilisés tels quels — AC7).
 *
 * **PG-pur (NFR7).** Aucun AD / LdapRecord / APCu / `samba-tool`. La lecture de
 * `packages.xml` via {@see PackagesXmlAssociationsReader} est un geste
 * d'ADMINISTRATION admis (hors chemin desired-state, iso le `FileAssociationSeeder`
 * de 27.3bis) — JAMAIS appelée par `AssociationsStateProvider` (qui reste PG-pur).
 *
 * **Algorithme (D-Henri n°1, AC3) pour `(X, A)` :**
 *   1. **A native curée** déclarant un ProgId POUR X → ProgId canonique,
 *      `source=native`, `wpkg_package=null` (toujours applicable, piège n°7).
 *      Si la native NE déclare PAS X → bascule en générique `Applications\<exe>`
 *      (piège n°2 : un ProgId est par (app × type de contenu)), `source=native`.
 *   2. **A WPKG ayant déclaré un handler POUR X** (`packages.xml`) → ProgId riche
 *      déclaré, `source=wpkg`, `wpkg_package=A.app_id`.
 *   3. **Sinon (générique)** → `progid = Applications\<exe de A>` ; `source=wpkg`
 *      + `wpkg_package=A.app_id` si A est WPKG (check prédictif pertinent),
 *      `native` si A est curée.
 *
 * **Garde-fou exe manquant (piège n°4).** Le générique exige le chemin/nom de
 * l'exe (`Application::$executable` / `NativeApplication::$executable`). Absent ET
 * aucun ProgId riche → {@see InvalidArgumentException} (pas de générique sans exe).
 *
 * Le résultat est UPSERTÉ comme ligne `file_associations` (clé déterministe
 * `catalogKey(identifier, progid)`, iso 27.3bis) puis attaché au parc via le pivot
 * polymorphe `file_association_assignables` — colonnes existantes suffisent, PAS de
 * migration de `file_associations`.
 */
final class AssociationResolver
{
    public function __construct(
        private readonly PackagesXmlAssociationsReader $packagesReader = new PackagesXmlAssociationsReader(),
    ) {}

    /**
     * Traduit *(extension/protocole X, app A)* → *(progid, source, wpkg_package)*.
     * `$app` est soit une {@see Application} WPKG, soit une {@see NativeApplication}
     * curée. PG-pur (sauf lecture admin de `packages.xml`, hors desired-state).
     *
     * @throws InvalidArgumentException si générique requis sans exe (garde-fou n°4)
     */
    public function resolve(string $identifier, Application|NativeApplication $app): ResolvedAssociation
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            throw new InvalidArgumentException('Identifiant (extension/protocole) vide.');
        }

        if ($app instanceof NativeApplication) {
            return $this->resolveNative($identifier, $app);
        }

        return $this->resolveWpkg($identifier, $app);
    }

    /**
     * App NATIVE curée : ProgId canonique si la native déclare X, sinon générique
     * `Applications\<exe>` (toujours `source=native`, `wpkg_package=null`).
     */
    private function resolveNative(string $identifier, NativeApplication $app): ResolvedAssociation
    {
        if ($app->supportsIdentifier($identifier)) {
            return new ResolvedAssociation(
                progid: (string) $app->progid,
                source: FileAssociation::SOURCE_NATIVE,
                wpkgPackage: null,
                generic: false,
            );
        }

        // La native ne déclare pas X (piège n°2) → générique sur son exe.
        return new ResolvedAssociation(
            progid: $this->genericProgId($identifier, (string) ($app->executable ?? ''), (string) $app->label),
            source: FileAssociation::SOURCE_NATIVE,
            wpkgPackage: null,
            generic: true,
        );
    }

    /**
     * App WPKG : ProgId riche si le paquet déclare un handler POUR X
     * (`packages.xml`), sinon générique `Applications\<exe>` (`source=wpkg`,
     * `wpkg_package=app_id` pour le check prédictif).
     */
    private function resolveWpkg(string $identifier, Application $app): ResolvedAssociation
    {
        $appId = (string) $app->app_id;
        $rich = $this->richProgIdFor($appId, $identifier);

        if ($rich !== null) {
            return new ResolvedAssociation(
                progid: $rich,
                source: FileAssociation::SOURCE_WPKG,
                wpkgPackage: $appId !== '' ? $appId : null,
                generic: false,
            );
        }

        return new ResolvedAssociation(
            progid: $this->genericProgId($identifier, (string) ($app->executable ?? ''), (string) $app->name),
            source: FileAssociation::SOURCE_WPKG,
            wpkgPackage: $appId !== '' ? $appId : null,
            generic: true,
        );
    }

    /**
     * ProgId RICHE déclaré par le paquet `$appId` POUR `$identifier`, ou `null`.
     * Lecture `packages.xml` indexée `packageId → identifier → {ProgId, type}`
     * (= `app_id`, jointure vérifiée 27.3bis). Insensible à la casse sur
     * l'identifiant (Windows l'est sur extensions/protocoles).
     */
    private function richProgIdFor(string $appId, string $identifier): ?string
    {
        if ($appId === '') {
            return null;
        }

        $byPackage = $this->packagesReader->read();
        $assocs = $byPackage[$appId] ?? [];

        // Le reader indexe par identifiant verbatim ; Windows est insensible à la
        // casse → comparer en minuscules sans présumer la casse du packages.xml.
        $needle = strtolower($identifier);
        foreach ($assocs as $declaredIdentifier => $assoc) {
            if (strtolower((string) $declaredIdentifier) !== $needle) {
                continue;
            }
            $progId = (string) ($assoc['ProgId'] ?? '');

            return $progId !== '' ? $progId : null;
        }

        return null;
    }

    /**
     * Fabrique le ProgId GÉNÉRIQUE `Applications\<exe>` (« Ouvrir avec », ce que
     * Windows crée nativement). `<exe>` = le NOM de fichier de l'exe (basename),
     * iso la convention `HKCR\Applications\<exe>`. SEUL le basename est consommé ;
     * le chemin complet (s'il est fourni) n'est ni transmis au payload (AC7) ni
     * consommé par l'agent — le poste re-résout l'exe (App Paths/PATH) pour
     * l'auto-enregistrement (AC6).
     *
     * @throws InvalidArgumentException si l'exe est absent (garde-fou n°4)
     */
    private function genericProgId(string $identifier, string $executable, string $appLabel): string
    {
        $executable = trim($executable);
        if ($executable === '') {
            throw new InvalidArgumentException(sprintf(
                'Impossible de composer une association générique pour « %s » → « %s » : '
                . 'aucun ProgId riche déclaré ET aucun exécutable connu pour cette application '
                . '(piège n°4 : pas de générique sans exe).',
                $identifier,
                $appLabel !== '' ? $appLabel : '?',
            ));
        }

        return 'Applications\\' . $this->exeBasename($executable);
    }

    /**
     * Extrait le nom de fichier (basename) d'un chemin exe, quel que soit le
     * séparateur (`\` Windows ou `/`). Conserve la casse (Windows l'ignore mais le
     * basename canonique vient de la donnée). Ex. `C:\Program Files\…\firefox.exe`
     * → `firefox.exe` ; `firefox.exe` → `firefox.exe`.
     */
    private function exeBasename(string $executable): string
    {
        $normalized = str_replace('\\', '/', $executable);
        $base = substr($normalized, strrpos($normalized, '/') === false ? 0 : (int) strrpos($normalized, '/') + 1);

        return $base !== '' ? $base : $executable;
    }

    /**
     * UPSERTE la ligne `file_associations` correspondant à `(identifier, X) → app`
     * puis l'attache au parc. Clé déterministe `catalogKey(identifier, progid)`
     * (iso 27.3bis : une paire identique upsert au lieu de dupliquer). Retourne le
     * modèle (idempotent, rejouable). PAS de migration de `file_associations`.
     */
    public function compose(
        string $identifier,
        string $assocType,
        Application|NativeApplication $app,
        WorkstationGroup $parc,
    ): FileAssociation {
        $identifier = trim($identifier);
        $assocType = $this->normalizeAssocType($assocType);
        $resolved = $this->resolve($identifier, $app);

        $appLabel = $app instanceof Application ? (string) $app->name : (string) $app->label;

        // `is_active=false` est le KILL-SWITCH GLOBAL d'une paire (filtre du
        // `AssociationsStateProvider`). On le pose à `true` UNIQUEMENT à la création,
        // JAMAIS sur une ligne existante — sinon recomposer cette paire depuis
        // n'importe quel parc la RÉACTIVERAIT pour TOUS les parcs (M1). Les autres
        // champs (label/description/source/wpkg_package) restent rafraîchis comme avant.
        $association = FileAssociation::query()
            ->firstOrNew(['key' => FileAssociation::catalogKey($identifier, $resolved->progid)]);

        $association->fill([
            'label' => $this->labelFor($identifier, $appLabel),
            'description' => $this->descriptionFor($resolved, $appLabel),
            'identifier' => $identifier,
            'assoc_type' => $assocType,
            'progid' => $resolved->progid,
            'source' => $resolved->source,
            'wpkg_package' => $resolved->wpkgPackage,
        ]);

        if (! $association->exists) {
            $association->is_active = true;
        }

        $association->save();

        // Attache au parc (idempotent — ne touche pas les autres assignations).
        $association->workstationGroups()->syncWithoutDetaching([$parc->id]);

        return $association;
    }

    /**
     * Normalise le type d'association : `protocol` si demandé, `file` sinon
     * (défaut sûr iso reader/seeder legacy).
     */
    private function normalizeAssocType(string $assocType): string
    {
        return strtolower(trim($assocType)) === FileAssociation::ASSOC_TYPE_PROTOCOL
            ? FileAssociation::ASSOC_TYPE_PROTOCOL
            : FileAssociation::ASSOC_TYPE_FILE;
    }

    private function labelFor(string $identifier, string $appLabel): string
    {
        return $identifier . ' → ' . ($appLabel !== '' ? $appLabel : '?');
    }

    private function descriptionFor(ResolvedAssociation $resolved, string $appLabel): string
    {
        if ($resolved->isNative()) {
            return 'Association vers l\'application native « ' . $appLabel . ' »'
                . ($resolved->generic ? ' (ouverture générique via l\'exécutable).' : '.');
        }

        return $resolved->generic
            ? 'Association générique vers « ' . $appLabel . ' » (ouverture via l\'exécutable, paquet WPKG '
                . ($resolved->wpkgPackage ?? '?') . ').'
            : 'Association vers « ' . $appLabel . ' » fournie par le paquet WPKG « '
                . ($resolved->wpkgPackage ?? '?') . ' ».';
    }
}
