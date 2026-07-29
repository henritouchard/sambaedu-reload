<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionSourceKind;
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
 * ne touchent JAMAIS Eloquent directement) et unique écrivain du registre pour
 * la source EMBARQUÉE.
 *
 * ## `syncBundled()` — chargement de la source embarquée (AC1/AC2)
 *
 * Parcourt `config('extensions.bundled_path')/<id>/manifest.json`, valide chaque
 * manifest ({@see ExtensionManifestValidator}, pur) et l'upsert sur la clé
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
 * ## Lecture
 *
 * `library()` (liste) et `find()` (fiche) renvoient des **tableaux plats**
 * prêts à afficher — aucune entité Eloquent ne remonte dans un composant
 * Livewire.
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ». Ce service n'a
 * AUCUN lien avec la sync amont controlHub (isolement NFR14).
 */
class ExtensionCatalogService
{
    public function __construct(
        private readonly ExtensionManifestValidator $validator,
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
        $seenKeys = [];

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

        foreach ($paths as $path) {
            $decoded = $this->decodeManifestFile($path);
            if ($decoded === null) {
                $stats['skipped']++;

                continue;
            }

            try {
                $normalized = $this->validator->validate($decoded);
            } catch (InvalidExtensionManifestException $e) {
                // Invariant #1 : on ignore CE manifest, jamais les autres.
                Log::warning('[Extensions] Manifest rejeté — extension ignorée', [
                    'path' => $path,
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

        Log::info('[Extensions] Synchro de la source embarquée terminée', $stats + ['source' => $source->key]);

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
     * Supprime les extensions bundled `available` dont le manifest a disparu.
     *
     * Invariant #4 : une extension `integrated` n'est jamais supprimée — elle
     * est signalée et conservée.
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
     * La bibliothèque : toutes les extensions du registre, prêtes à afficher.
     *
     * @return list<array<string, mixed>>
     */
    public function library(): array
    {
        return Extension::query()
            ->with('source')
            ->orderBy('name')
            ->get()
            ->map(fn (Extension $extension): array => $this->toListRow($extension))
            ->all();
    }

    /**
     * La fiche d'une extension, ou `null` si l'identifiant est inconnu.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $extension = Extension::query()->with('source')->find($id);

        return $extension === null ? null : $this->toDetail($extension);
    }

    /**
     * Ligne de LISTE (dénormalisations + libellés).
     *
     * @return array<string, mixed>
     */
    private function toListRow(Extension $extension): array
    {
        $source = $extension->source;

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
        ];
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
            'dependencies' => $extension->dependencies(),
            'visibility_roles' => $extension->visibilityRoles(),
            'manifest_version' => (int) ($extension->manifest['manifest_version'] ?? 0),
            'source_kind_label' => $source?->kind?->label() ?? '',
            'source_is_official' => (bool) ($source?->is_official ?? false),
        ];
    }
}
