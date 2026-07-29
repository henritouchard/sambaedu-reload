<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionSourceSyncStatus;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\InvalidExtensionManifestException;
use App\Models\Extension;
use App\Models\ExtensionSource;
use Illuminate\Support\Facades\Log;

/**
 * Story 54.1 — Service de catalogue du registre d'extensions.
 *
 * Unique point d'entrée des pages `/admin/extensions` (NFR15 : les SFC Livewire
 * ne touchent JAMAIS Eloquent directement) et unique écrivain du registre —
 * pour la source EMBARQUÉE (`syncBundled()`) comme pour les sources DISTANTES
 * (`syncManifestsForSource()`, appelée par {@see RemoteCatalogSyncService}).
 *
 * ## `syncBundled()` — chargement de la source embarquée (AC1/AC2)
 *
 * Parcourt `config('extensions.bundled_path')/<id>/manifest.json`, décode
 * chaque fichier, puis délègue à {@see self::syncManifestsForSource()} qui
 * valide ({@see ExtensionManifestValidator}, pur) et upsert sur la clé
 * naturelle `(extension_source_id, key)`.
 *
 * Invariants :
 *
 *  1. **Un manifest fautif n'en casse aucun autre** : la validation est capturée
 *     PAR FICHIER — l'extension est ignorée avec un `Log::warning` structuré
 *     nommant le champ en cause, la boucle continue.
 *  2. **`status` n'est JAMAIS écrit.** Ni à la création (le défaut DB
 *     `available` s'applique), ni à la mise à jour. Sinon un simple
 *     rechargement de catalogue dé-intégrerait une extension que l'admin a
 *     intégrée (Story 54.2). La colonne est d'ailleurs hors `$fillable`.
 *  3. **Idempotence** : rejouer la synchro ne duplique rien ; les lignes dont le
 *     manifest n'a pas bougé ne changent pas (`isDirty()` ⇒ pas d'écriture).
 *  4. **Prune BORNÉ** : seules les lignes de la source bundled encore
 *     `available` dont le manifest a disparu du disque sont supprimées. Une
 *     extension `integrated` n'est **jamais** retirée silencieusement — on la
 *     signale (`Log::warning`) et on la laisse en place ; c'est à l'admin de la
 *     désinstaller (54.2).
 *  5. **Arbre embarqué introuvable ≠ catalogue vide** : si la racine des
 *     manifests n'existe pas (déploiement incomplet, `EXTENSIONS_BUNDLED_PATH`
 *     mal résolu), la synchro sort en no-op bruyant SANS pruner — sinon un
 *     accident de chemin viderait tout le catalogue.
 *
 * ## Story 56.1 — `syncManifestsForSource()` : le MÊME moteur pour toute source
 *
 * Les invariants #1 à #4 ci-dessus ne sont pas propres à la source embarquée :
 * ils décrivent ce que « charger un lot de manifests dans le registre » doit
 * garantir, d'où que viennent ces manifests. Ils ont donc été extraits
 * VERBATIM dans une méthode publique que {@see RemoteCatalogSyncService}
 * consomme une fois — et une fois SEULEMENT — la signature du catalogue
 * distant vérifiée. Le comportement de `syncBundled()` est inchangé : ce
 * refactor est une extraction, pas une évolution (les tests 54.1 passent
 * inchangés).
 *
 * L'invariant #5 (« rien observé ⇒ aucun prune ») reste, lui, du ressort de
 * l'APPELANT : c'est lui seul qui sait s'il a réellement observé un catalogue
 * complet. `syncBundled()` sort en no-op quand la racine des manifests est
 * introuvable ; `RemoteCatalogSyncService` n'appelle cette méthode que sur le
 * chemin de SUCCÈS vérifié — jamais sur un dépôt injoignable ni sur une
 * signature invalide.
 *
 * ## Lecture
 *
 * `library()` (liste) et `find()` (fiche) renvoient des **tableaux plats**
 * prêts à afficher — aucune entité Eloquent ne remonte dans un composant
 * Livewire.
 *
 * **Story 56.1 — filtrage par l'état de la SOURCE** (dette explicite de
 * l'Epic 54, soldée ici) : une extension `available` n'est proposée que si sa
 * source est ACTIVE et que son dernier catalogue a été VÉRIFIÉ. Une source
 * désactivée par l'admin, ou dont la signature ne se vérifie plus, ne propose
 * plus rien (fail-closed NFR2) — sa fiche répond 404. Une extension
 * **`integrated` reste TOUJOURS listée**, avec les drapeaux qui disent l'état
 * de sa source (`source_enabled`, `source_sync_status`) : on ne dé-intègre
 * jamais silencieusement (invariant #4), c'est l'admin qui désinstalle.
 *
 * Un dépôt momentanément INJOIGNABLE ne masque rien : le registre EST le cache
 * local, ses lignes sont le dernier catalogue *vérifié* (NFR7).
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ». Ce service n'a
 * AUCUN lien avec la sync amont controlHub (isolement NFR14).
 */
class ExtensionCatalogService
{
    public function __construct(
        private readonly ExtensionManifestValidator $validator,
        private readonly ExtensionScopeService $scopes,
    ) {
    }

    // =====================================================================
    // Synchronisation de la source EMBARQUÉE
    // =====================================================================

    /**
     * Garantit l'existence de la source embarquée (clé `bundled`).
     *
     * `firstOrCreate` et non `updateOrCreate` : le service ne réécrit pas le
     * libellé d'une source déjà en base (la baseline canonique est posée par
     * {@see \Database\Seeders\BundledExtensionSeeder}).
     */
    public function ensureBundledSource(): ExtensionSource
    {
        return ExtensionSource::firstOrCreate(
            ['key' => ExtensionSource::KEY_BUNDLED],
            [
                'name' => ExtensionSource::NAME_BUNDLED,
                'kind' => ExtensionSourceKind::Bundled,
                'url' => '',
                'is_official' => true,
                'enabled' => true,
            ],
        );
    }

    /**
     * Charge (ou recharge) les manifests embarqués dans le registre.
     *
     * @return array{loaded: int, created: int, updated: int, skipped: int, pruned: int}
     */
    public function syncBundled(): array
    {
        $stats = ['loaded' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'pruned' => 0];

        $source = $this->ensureBundledSource();

        $paths = $this->discoverBundledManifestPaths();

        // ⚠️ ARBRE EMBARQUÉ INTROUVABLE ≠ CATALOGUE VIDE (invariant #5).
        // `null` = la racine des manifests n'existe pas / n'est pas lisible
        // (déploiement incomplet, `EXTENSIONS_BUNDLED_PATH` mal résolu, config
        // mise en cache avec un chemin d'une autre machine). Dans ce cas on
        // n'a RIEN observé — on ne peut donc rien conclure sur ce qui a
        // « disparu ». Poursuivre jusqu'au prune supprimerait TOUT le catalogue
        // embarqué sur un simple accident de chemin : c'est exactement le
        // sinistre déjà vécu par ce projet sur le catalogue applicatif local.
        // On sort en no-op bruyant. Une racine PRÉSENTE mais vide, elle, reste
        // une observation légitime : le prune s'applique normalement.
        if ($paths === null) {
            Log::warning('[Extensions] Racine des manifests embarqués introuvable — synchro ignorée (catalogue PRÉSERVÉ)', [
                'bundled_path' => (string) config('extensions.bundled_path'),
                'source' => $source->key,
            ]);

            return $stats;
        }

        // Décodage des FICHIERS (spécifique à la source embarquée : un manifest
        // illisible ou au JSON invalide est ignoré comme un manifest fautif).
        // Les manifests décodés sont indexés par leur chemin, qui sert d'ORIGINE
        // dans les journaux de la boucle de validation.
        $decodedManifests = [];
        $unreadable = 0;
        foreach ($paths as $path) {
            $decoded = $this->decodeManifestFile($path);
            if ($decoded === null) {
                $unreadable++;

                continue;
            }

            $decodedManifests[$path] = $decoded;
        }

        $stats = $this->syncManifestsForSource($source, $decodedManifests);
        $stats['skipped'] += $unreadable;

        Log::info('[Extensions] Synchro de la source embarquée terminée', $stats + ['source' => $source->key]);

        return $stats;
    }

    /**
     * Story 56.1 — Charge un lot de manifests DÉCODÉS dans le registre, pour
     * une source quelconque : valide, upsert, puis prune borné.
     *
     * **Extraction verbatim des invariants #1 à #4** de `syncBundled()` — voir
     * le docblock de classe. Le contenu du lot n'est PAS de la responsabilité
     * de cette méthode : pour une source distante, l'appelant a déjà vérifié la
     * signature du catalogue AVANT de le décoder, et ne l'appelle QUE sur le
     * chemin de succès (invariant #5 : on ne prune jamais ce qu'on n'a pas
     * observé).
     *
     * ⚠️ Cette méthode n'écrit JAMAIS `extensions.status` (invariant #2) : le
     * seul écrivain de cette colonne reste {@see ExtensionLifecycleService}.
     *
     * @param  array<string, mixed>  $decodedManifests  manifests décodés, indexés
     *                                                  par une ORIGINE lisible
     *                                                  (chemin de fichier, ou
     *                                                  `index.json#3`) reportée
     *                                                  telle quelle dans les
     *                                                  journaux de rejet
     * @return array{loaded: int, created: int, updated: int, skipped: int, pruned: int}
     */
    public function syncManifestsForSource(ExtensionSource $source, array $decodedManifests): array
    {
        $stats = ['loaded' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'pruned' => 0];
        $seenKeys = [];

        foreach ($decodedManifests as $origin => $decoded) {
            // Une entrée qui n'est même pas un objet JSON (nombre, chaîne,
            // `null`) : même traitement qu'un manifest fautif — ignorée seule.
            if (! is_array($decoded)) {
                Log::warning('[Extensions] Entrée de catalogue non exploitable — extension ignorée', [
                    'origin' => (string) $origin,
                    'source' => $source->key,
                    'type' => get_debug_type($decoded),
                ]);
                $stats['skipped']++;

                continue;
            }

            try {
                $normalized = $this->validator->validate($decoded);
            } catch (InvalidExtensionManifestException $e) {
                // Invariant #1 : on ignore CE manifest, jamais les autres.
                Log::warning('[Extensions] Manifest rejeté — extension ignorée', [
                    'origin' => (string) $origin,
                    'source' => $source->key,
                    'field' => $e->field,
                    'reason' => $e->reason,
                    'message' => $e->getMessage(),
                ]);
                $stats['skipped']++;

                continue;
            }

            $seenKeys[] = $normalized['id'];
            $this->upsert($source, $normalized, $stats);
            $stats['loaded']++;
        }

        $stats['pruned'] = $this->pruneDisappeared($source, $seenKeys);

        return $stats;
    }

    /**
     * Manifests embarqués détectés : `<bundled_path>/<id>/manifest.json`.
     *
     * @return list<string>|null  `null` ⇒ racine INTROUVABLE (rien n'a pu être
     *                            observé) ; `[]` ⇒ racine présente mais SANS
     *                            manifest (observation légitime). La distinction
     *                            décide si le prune peut s'appliquer.
     */
    private function discoverBundledManifestPaths(): ?array
    {
        $root = rtrim((string) config('extensions.bundled_path', base_path('resources/extensions')), '/\\');
        if ($root === '' || ! is_dir($root)) {
            return null;
        }

        $matches = glob($root.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'manifest.json') ?: [];
        sort($matches);

        return array_values(array_map(static fn ($p): string => (string) $p, $matches));
    }

    /**
     * Lit et décode un fichier manifest. `null` ⇒ illisible / JSON invalide
     * (déjà journalisé) : l'appelant doit sauter ce manifest.
     *
     * @return array<string, mixed>|null
     */
    private function decodeManifestFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            Log::warning('[Extensions] Manifest illisible — extension ignorée', ['path' => $path]);

            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Log::warning('[Extensions] Manifest JSON invalide — extension ignorée', [
                'path' => $path,
                'json_error' => json_last_error_msg(),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * Upsert d'une extension sur la clé naturelle `(source, key)`.
     *
     * ⚠️ `status` n'est PAS dans les attributs écrits (invariant #2).
     *
     * @param  array<string, mixed>  $normalized  manifest normalisé par le validateur
     * @param  array{loaded:int,created:int,updated:int,skipped:int,pruned:int}  $stats
     */
    private function upsert(ExtensionSource $source, array $normalized, array &$stats): void
    {
        /** @var ExtensionType $type */
        $type = $normalized['type'];

        // Le manifest PERSISTÉ est la forme NORMALISÉE (défauts appliqués) :
        // la fiche lit toujours les mêmes clés, jamais un `??` défensif de plus.
        $manifest = $normalized;
        $manifest['type'] = $type->value;

        $extension = Extension::firstOrNew([
            'extension_source_id' => $source->id,
            'key' => $normalized['id'],
        ]);

        $isNew = ! $extension->exists;

        $extension->fill([
            'name' => $normalized['name'],
            'version' => $normalized['version'],
            'publisher' => $normalized['publisher'],
            'icon' => $normalized['icon'],
            'description' => $normalized['description'],
            'type' => $type,
            'manifest' => $manifest,
        ]);

        if ($isNew) {
            $extension->save();
            $stats['created']++;

            return;
        }

        // Idempotence (invariant #3) : rien de sale ⇒ aucune écriture, pas même
        // un `updated_at` qui ferait mentir le snapshot d'un test de frontière.
        if ($extension->isDirty()) {
            $extension->save();
            $stats['updated']++;
        }
    }

    /**
     * Supprime les extensions `available` DE CETTE SOURCE dont le manifest a
     * disparu du lot observé.
     *
     * Invariant #4 : une extension `integrated` n'est jamais supprimée — elle
     * est signalée et conservée. Le prune est BORNÉ à la source passée en
     * paramètre : la synchro d'un dépôt ne touche jamais le catalogue d'un
     * autre.
     *
     * @param  list<string>  $seenKeys
     */
    private function pruneDisappeared(ExtensionSource $source, array $seenKeys): int
    {
        $disappeared = Extension::query()
            ->where('extension_source_id', $source->id)
            ->when($seenKeys !== [], fn ($q) => $q->whereNotIn('key', $seenKeys))
            ->get();

        $pruned = 0;
        foreach ($disappeared as $extension) {
            if ($extension->status !== ExtensionStatus::Available) {
                Log::warning('[Extensions] Manifest disparu pour une extension INTÉGRÉE — conservée', [
                    'source' => $source->key,
                    'key' => $extension->key,
                    'status' => $extension->status?->value,
                ]);

                continue;
            }

            $extension->delete();
            $pruned++;
            Log::info('[Extensions] Extension retirée du catalogue (manifest disparu)', [
                'source' => $source->key,
                'key' => $extension->key,
            ]);
        }

        return $pruned;
    }

    // =====================================================================
    // Lecture (pages admin)
    // =====================================================================

    /**
     * La bibliothèque : les extensions du registre PROPOSABLES, prêtes à
     * afficher.
     *
     * Story 56.1 — les extensions `available` d'une source désactivée ou dont
     * le dernier catalogue a été refusé sont MASQUÉES (voir
     * {@see self::isProposable()}). Les `integrated` restent toutes listées,
     * quel que soit l'état de leur source.
     *
     * @return list<array<string, mixed>>
     */
    public function library(): array
    {
        return Extension::query()
            ->with('source')
            ->orderBy('name')
            ->get()
            ->filter(fn (Extension $extension): bool => $this->isProposable($extension))
            ->map(fn (Extension $extension): array => $this->toListRow($extension))
            ->values()
            ->all();
    }

    /**
     * La fiche d'une extension, ou `null` si l'identifiant est inconnu — ou si
     * l'extension n'est plus proposable (source désactivée / catalogue refusé) :
     * la fiche répond alors 404, exactement comme si la ligne n'existait pas.
     * Une extension masquée ne doit pas rester intégrable par son URL directe.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $extension = Extension::query()->with('source')->find($id);

        if ($extension === null || ! $this->isProposable($extension)) {
            return null;
        }

        return $this->toDetail($extension);
    }

    /**
     * Story 56.1 — Cette extension a-t-elle sa place dans la bibliothèque ?
     *
     * - Une extension **`integrated`** : TOUJOURS. La désactivation d'une
     *   source gèle ce qu'elle propose, elle ne dé-intègre pas ce qui a été
     *   installé (invariant #4, doctrine « rupture = figer l'état »). La carte
     *   porte des drapeaux qui disent l'état de sa source ; c'est l'admin qui
     *   décide de désinstaller.
     * - Une extension **`available`** : seulement si sa source est ACTIVE et
     *   que son dernier catalogue a été VÉRIFIÉ. Une source en `error`
     *   (signature invalide) ne propose plus rien — fail-closed NFR2. Une
     *   source `unreachable` continue, elle, de proposer son dernier catalogue
     *   vérifié : le registre EST le cache local (NFR7).
     * - Source manquante (ligne orpheline — la FK `cascadeOnDelete` rend le cas
     *   théorique) : on masque. On ne propose pas une extension dont on ne peut
     *   plus dire d'où elle vient.
     */
    private function isProposable(Extension $extension): bool
    {
        if (($extension->status ?? ExtensionStatus::Available) !== ExtensionStatus::Available) {
            return true;
        }

        $source = $extension->source;
        if ($source === null) {
            return false;
        }

        // Règle unique, portée par le modèle : ce qui s'affiche et ce qui
        // s'intègre doivent dire la même chose (review 56.1 #1).
        return $source->offersAvailableExtensions();
    }

    /**
     * Ligne de LISTE (dénormalisations + libellés).
     *
     * Story 56.1 — la ligne porte désormais la PROVENANCE complète
     * (`source_is_official`, `source_host`) et l'état de la source
     * (`source_enabled`, `source_sync_status`) : la carte doit pouvoir afficher
     * un badge « Tierce » et un avertissement sans jamais retoucher la base, et
     * la modale d'avertissement doit nommer l'hôte réel du dépôt (FR4/UX-DR4).
     *
     * @return array<string, mixed>
     */
    private function toListRow(Extension $extension): array
    {
        $source = $extension->source;
        $syncStatus = $source?->syncStatus() ?? ExtensionSourceSyncStatus::Ok;

        return [
            'id' => (int) $extension->id,
            'key' => (string) $extension->key,
            'name' => (string) $extension->name,
            'icon' => (string) $extension->icon,
            'version' => (string) $extension->version,
            'publisher' => (string) $extension->publisher,
            'description' => (string) $extension->description,
            'type' => $extension->type?->value ?? '',
            'type_label' => $extension->type?->label() ?? '',
            'type_icon' => $extension->type?->icon() ?? '',
            'status' => $extension->status?->value ?? ExtensionStatus::Available->value,
            'status_label' => ($extension->status ?? ExtensionStatus::Available)->label(),
            'status_badge' => ($extension->status ?? ExtensionStatus::Available)->badgeClass(),
            'source_key' => (string) ($source?->key ?? ''),
            'source_name' => (string) ($source?->name ?? ''),
            'source_is_official' => (bool) ($source?->is_official ?? false),
            'source_host' => $source?->host() ?? '',
            'source_enabled' => (bool) ($source?->enabled ?? false),
            'source_sync_status' => $syncStatus->value,
            'source_sync_label' => $syncStatus->label(),
            'source_sync_badge' => $syncStatus->badgeClass(),

            // ── Story 56.3 — cycle `app` ────────────────────────────────────
            // `installed_version` est ce qui TOURNE, `version` ce que la source
            // PUBLIE : deux faits différents, et leur écart EST la détection de
            // mise à jour.
            'installed_version' => (string) $extension->installed_version,
            'update_available' => $this->hasUpdateAvailable($extension),
            'installable' => $this->isAppInstallable($extension),
            // ⚠️ `installed_sha256` n'a RIEN à faire ici : c'est une donnée
            // interne de rollback, sans aucune valeur pour l'admin.
        ];
    }

    /**
     * Story 56.3 (AC3) — Une mise à jour est-elle PROPOSABLE pour cette
     * extension ?
     *
     * ⚠️ Règle à UN SEUL énoncé, calculée dans {@see self::toListRow()} : la
     * fiche hérite par construction ({@see self::toDetail()} = `toListRow()` +
     * des champs de manifest), donc la liste et la fiche ne peuvent pas
     * diverger. La dupliquer dans la vue ou dans un composant rouvrirait
     * exactement le défaut de la review 56.1 #3.
     *
     * La règle est un **ÉCART**, jamais un ORDRE. `version` est une chaîne
     * LIBRE du manifest — le validateur ne lui impose aucun format — donc un
     * tri sémantique mentirait sur un « 2024-annexe-b ». Une republication
     * ANTÉRIEURE est donc proposée comme un changement : c'est voulu (la source
     * est l'autorité de sa propre fraîcheur, modèle apt), et c'est pourquoi le
     * helper root pose `--allow-downgrades`.
     *
     * `installed_version` non vide est nécessaire : une `app` intégrée sans
     * version installée n'a pas été posée par le moteur, et l'écart n'aurait
     * aucun sens.
     */
    private function hasUpdateAvailable(Extension $extension): bool
    {
        if ($extension->type !== ExtensionType::App) {
            return false;
        }

        if (($extension->status ?? ExtensionStatus::Available) !== ExtensionStatus::Integrated) {
            return false;
        }

        $installed = (string) $extension->installed_version;

        if ($installed === '' || $installed === (string) $extension->version) {
            return false;
        }

        // Une source gelée ou dont le catalogue n'a pas pu être VÉRIFIÉ ne
        // propose plus rien — y compris une nouvelle version. Même règle
        // UNIQUE que l'affichage et que l'intégration (review 56.1 #1), portée
        // par le modèle.
        return $extension->source?->offersAvailableExtensions() === true;
    }

    /**
     * Story 56.3 (AC1) — Le moteur accepterait-il d'installer cette extension ?
     *
     * L'UI ne doit JAMAIS proposer ce que le moteur refusera. Les deux
     * conditions sont exactement celles de
     * {@see ExtensionInstallService::install()} avant tout effet : un bloc
     * `install` exploitable (règle unique portée par le modèle,
     * {@see Extension::hasSupportedInstallBlock()}) et une source qui propose
     * encore ({@see \App\Models\ExtensionSource::offersAvailableExtensions()}).
     *
     * Ce drapeau ne remplace aucune garde : le moteur revalide tout. Il évite
     * seulement d'offrir un bouton qui ne peut que décevoir.
     */
    private function isAppInstallable(Extension $extension): bool
    {
        if ($extension->type !== ExtensionType::App) {
            return false;
        }

        if (! $extension->hasSupportedInstallBlock()) {
            return false;
        }

        return $extension->source?->offersAvailableExtensions() === true;
    }

    /**
     * Fiche DÉTAIL : la liste + ce qui se lit du manifest (FR3).
     *
     * @return array<string, mixed>
     */
    private function toDetail(Extension $extension): array
    {
        $source = $extension->source;

        return $this->toListRow($extension) + [
            'entry_url' => $extension->entryUrl(),
            'scopes' => $extension->requestedScopes(),
            // Story 56.4 — les scopes RÉELLEMENT ACCORDÉS (`null` = aucun
            // client OIDC actif : la fiche n'affiche alors pas de volet).
            // Résolu ICI, par le service : une vue ne requête pas la base.
            'granted_scopes' => $this->scopes->grantedScopesFor($extension),
            'dependencies' => $extension->dependencies(),
            'visibility_roles' => $extension->visibilityRoles(),
            'manifest_version' => (int) ($extension->manifest['manifest_version'] ?? 0),
            'source_kind_label' => $source?->kind?->label() ?? '',
            'source_url' => (string) ($source?->baseUrl() ?? ''),
            // `source_is_official` / `source_host` viennent déjà de `toListRow()`.
        ];
    }
}
