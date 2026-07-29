<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionSourceSyncStatus;
use App\Enums\ExtensionStatus;
use App\Exceptions\ExtensionSourceException;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 56.1 (FR2/FR4/FR36) — Les ACTES d'administration d'une source
 * d'extensions : ajouter, activer, désactiver, retirer, actualiser.
 *
 * Séparé de {@see RemoteCatalogSyncService} (le moteur réseau) et
 * d'{@see ExtensionCatalogService} (le registre) pour la même raison qui a
 * séparé le cycle de vie du catalogue en 54.2 : ce fichier est celui des
 * DÉCISIONS de l'admin et de leurs gardes, il ne parle ni HTTP ni signature.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  LE PIN DE CLÉ (TOFU) — ce qui rend l'ensemble sûr
 *
 *  La clé publique d'une source est **pinnée à l'ajout**, par l'un de deux
 *  chemins et deux seulement :
 *
 *   1. **collée par l'admin** (chemin recommandé : la clé lui a été
 *      communiquée hors bande par l'éditeur) ;
 *   2. **lue UNE FOIS** sur `<url>/source.pub`, et uniquement si l'URL est en
 *      **https** — le TOFU réseau n'a de sens que protégé par TLS.
 *
 *  Une URL en `http://` (miroir LAN, AR9) SANS clé collée est REFUSÉE : sur un
 *  canal en clair, n'importe quel intermédiaire fournirait sa propre clé et
 *  signerait son propre catalogue — la signature ne prouverait plus rien.
 *
 *  Après le pin, la clé n'est **jamais** re-téléchargée ni mise à jour par une
 *  synchro. Un dépôt qui change de clé passe en erreur, et c'est le
 *  comportement voulu : c'est exactement ce qui empêche la compromission
 *  ultérieure d'un dépôt de substituer sa clé. La rotation légitime est un
 *  retrait + un ré-ajout explicites — deux actes journalisés, décidés par un
 *  humain. C'est le modèle `known_hosts` de SSH et le keyring d'apt.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Gardes** :
 *  - la source `bundled` n'est ni désactivable ni retirable (ses manifests font
 *    partie du déploiement) ;
 *  - le retrait est REFUSÉ tant qu'une extension de la source est `integrated` :
 *    la cascade FK emporterait des tuiles en service, et on ne dé-intègre jamais
 *    silencieusement (invariant 54.1 #4). L'admin désinstalle d'abord.
 *
 * **No-op = zéro audit** (discipline 54.2) : désactiver une source déjà
 * désactivée n'écrit rien, ni ligne, ni `updated_at`. Le journal trace des
 * transitions réelles, pas des clics.
 *
 * **Atomicité acte ↔ trace** : chaque mutation et son `ExtensionAuditLog` sont
 * dans la MÊME transaction.
 *
 * NFR15 : toutes les méthodes publiques rendent des **tableaux plats**, et
 * l'acteur est passé en PARAMÈTRE — le service ne lit jamais `auth()`.
 */
class ExtensionSourceService
{
    /** Longueur max du nom saisi. */
    private const NAME_MAX = 120;

    /** Longueur max de l'URL (colonne `url` : 512). */
    private const URL_MAX = 512;

    public function __construct(
        private readonly RemoteCatalogSyncService $sync,
    ) {
    }

    // =====================================================================
    // Lecture
    // =====================================================================

    /**
     * Toutes les sources du registre, prêtes à afficher (NFR15).
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return ExtensionSource::query()
            ->withCount([
                'extensions',
                'extensions as integrated_count' => fn ($query) => $query->where('status', ExtensionStatus::Integrated->value),
            ])
            ->orderByDesc('is_official')
            ->orderBy('name')
            ->get()
            ->map(fn (ExtensionSource $source): array => $this->toRow($source))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(ExtensionSource $source): array
    {
        $syncStatus = $source->syncStatus();

        return [
            'id' => (int) $source->id,
            'key' => (string) $source->key,
            'name' => (string) $source->name,
            'url' => $source->baseUrl(),
            'host' => $source->host(),
            'kind' => $source->kind?->value ?? '',
            'kind_label' => $source->kind?->label() ?? '',
            'is_official' => (bool) $source->is_official,
            'enabled' => (bool) $source->enabled,
            'is_remote' => $source->isRemote(),
            'sync_status' => $syncStatus->value,
            'sync_label' => $syncStatus->label(),
            'sync_badge' => $syncStatus->badgeClass(),
            'sync_icon' => $syncStatus->icon(),
            'last_synced_at' => $source->last_synced_at?->format('d/m/Y H:i') ?? '',
            'last_error' => (string) $source->last_error,
            // Empreinte COURTE de la clé pinnée : l'admin peut la comparer à
            // celle publiée par l'éditeur sans qu'on étale une clé de 44
            // caractères dans un tableau. Une clé publique n'est pas un secret,
            // mais elle n'est pas non plus une information de lecture courante.
            'public_key_preview' => $this->keyPreview((string) $source->public_key),
            'extensions_count' => (int) ($source->extensions_count ?? 0),
            'integrated_count' => (int) ($source->integrated_count ?? 0),
        ];
    }

    private function keyPreview(string $publicKey): string
    {
        $key = trim($publicKey);
        if ($key === '') {
            return '';
        }

        return strlen($key) <= 16 ? $key : substr($key, 0, 8).'…'.substr($key, -8);
    }

    // =====================================================================
    // Actes
    // =====================================================================

    /**
     * Ajoute une source DISTANTE, pinne sa clé, puis lance sa première synchro.
     *
     * La première synchro fait le statut initial : une source dont la signature
     * ne se vérifie pas EXISTE quand même, marquée `error` — c'est ce qui
     * permet à l'admin de comprendre, de corriger la clé (retrait + ré-ajout)
     * ou de retirer la source. Refuser la création laisserait l'admin devant un
     * message sans état à inspecter.
     *
     * @param  string|null  $publicKey  clé base64 collée par l'admin ; `null`/'' ⇒ TOFU https
     * @return array{id: int, key: string, name: string, source: string, status: string, error: string, loaded: int, created: int, updated: int, skipped: int, pruned: int}
     *
     * @throws ExtensionSourceException
     */
    public function add(string $name, string $url, ?string $publicKey, User $actor): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw ExtensionSourceException::invalidName();
        }

        $url = $this->normalizeUrl($url);

        $existing = ExtensionSource::query()->where('url', $url)->first();
        if ($existing !== null) {
            throw ExtensionSourceException::duplicateUrl((string) $existing->name);
        }

        $publicKey = $this->resolvePublicKey($url, $publicKey);

        // La vérification d'unicité ci-dessus (URL) et la génération de clé
        // ci-dessous sont des `SELECT` : entre eux et l'`INSERT`, une seconde
        // requête admin concurrente peut avoir pris la place. La contrainte
        // `ext_sources_key_unique` est l'arbitre — mais sans ce catch elle
        // remonterait en 500 au lieu du toast attendu (review 56.1 #3).
        try {
            $source = DB::transaction(function () use ($name, $url, $publicKey, $actor): ExtensionSource {
                $source = ExtensionSource::create([
                    'key' => $this->uniqueKey($name),
                    'name' => $name,
                    'kind' => ExtensionSourceKind::Remote,
                    'url' => $url,
                    // FR4 — une source ajoutée par un admin n'est JAMAIS
                    // « officielle » : l'officialité désigne la source embarquée
                    // du dépôt SE5, pas la confiance que l'admin accorde. Elle
                    // n'est donc pas un réglage.
                    'is_official' => false,
                    'enabled' => true,
                    'public_key' => $publicKey,
                    // Le statut réel sera écrit par la première synchro, juste après.
                    'sync_status' => ExtensionSourceSyncStatus::Ok,
                    'last_error' => '',
                ]);

                ExtensionAuditLog::logSource(
                    sourceId: $source->id,
                    sourceKey: (string) $source->key,
                    action: ExtensionAuditLog::ACTION_SOURCE_ADD,
                    actorUserId: $actor->id,
                    actorLogin: $actor->login,
                );

                return $source;
            });
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('[Extensions] Création de source refusée par une contrainte d\'unicité', [
                'name' => $name,
                'exception' => $e::class,
            ]);

            throw ExtensionSourceException::concurrentCreation();
        }

        // Hors transaction : la première synchro fait du réseau, elle n'a rien à
        // faire dans la transaction de création (elle la tiendrait ouverte le
        // temps d'un timeout).
        $result = $this->sync->sync($source, $actor);

        return [
            'id' => (int) $source->id,
            'key' => (string) $source->key,
            'name' => (string) $source->name,
        ] + $result;
    }

    /**
     * Réactive une source gelée. No-op propre si elle l'est déjà.
     *
     * @return array{changed: bool, enabled: bool}
     */
    public function enable(int $sourceId, User $actor): array
    {
        return $this->toggle($sourceId, $actor, true, ExtensionAuditLog::ACTION_SOURCE_ENABLE);
    }

    /**
     * Gèle une source : ses `available` disparaissent de la bibliothèque, sa
     * synchro ne s'exécute plus. Ses extensions INTÉGRÉES restent en service et
     * gardent leur tuile — on ne dé-intègre jamais silencieusement.
     *
     * @return array{changed: bool, enabled: bool}
     */
    public function disable(int $sourceId, User $actor): array
    {
        return $this->toggle($sourceId, $actor, false, ExtensionAuditLog::ACTION_SOURCE_DISABLE);
    }

    /**
     * @return array{changed: bool, enabled: bool}
     *
     * @throws ExtensionSourceException
     */
    private function toggle(int $sourceId, User $actor, bool $target, string $action): array
    {
        return DB::transaction(function () use ($sourceId, $actor, $target, $action): array {
            $source = $this->lockedSource($sourceId);
            $this->assertManageable($source);

            // No-op = zéro écriture, zéro audit (discipline 54.2).
            if ((bool) $source->enabled === $target) {
                return ['changed' => false, 'enabled' => $target];
            }

            $source->enabled = $target;
            $source->save();

            ExtensionAuditLog::logSource(
                sourceId: $source->id,
                sourceKey: (string) $source->key,
                action: $action,
                actorUserId: $actor->id,
                actorLogin: $actor->login,
            );

            return ['changed' => true, 'enabled' => $target];
        });
    }

    /**
     * Retire une source et, par cascade FK, ses extensions.
     *
     * REFUSÉ tant qu'une extension de la source est `integrated` : le retrait
     * ferait disparaître des tuiles en service sans que personne ne l'ait
     * décidé. L'admin désinstalle d'abord depuis la bibliothèque.
     *
     * @return array{removed: bool, key: string, name: string}
     *
     * @throws ExtensionSourceException
     */
    public function remove(int $sourceId, User $actor): array
    {
        return DB::transaction(function () use ($sourceId, $actor): array {
            $source = $this->lockedSource($sourceId);
            $this->assertManageable($source);

            $integrated = $source->extensions()
                ->where('status', ExtensionStatus::Integrated->value)
                ->orderBy('name')
                ->pluck('name')
                ->all();

            if ($integrated !== []) {
                throw ExtensionSourceException::integratedExtensionsBlockRemoval(
                    array_values(array_map(static fn ($name): string => (string) $name, $integrated)),
                );
            }

            $key = (string) $source->key;
            $name = (string) $source->name;

            // L'audit est écrit AVANT la suppression, dans la même transaction :
            // la FK `nullOnDelete` videra `extension_source_id`, mais
            // `source_key` garde la trace lisible du dépôt retiré.
            ExtensionAuditLog::logSource(
                sourceId: $source->id,
                sourceKey: $key,
                action: ExtensionAuditLog::ACTION_SOURCE_REMOVE,
                actorUserId: $actor->id,
                actorLogin: $actor->login,
            );

            $source->delete();

            return ['removed' => true, 'key' => $key, 'name' => $name];
        });
    }

    /**
     * Actualise une source distante — le bouton « Actualiser » de la page des
     * sources. Passe par le MÊME moteur que la commande artisan (AR1) : il
     * n'existe pas de second chemin de synchro.
     *
     * @return array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}
     *
     * @throws ExtensionSourceException
     */
    public function refresh(int $sourceId, ?User $actor = null): array
    {
        $source = ExtensionSource::query()->find($sourceId);
        if ($source === null) {
            throw ExtensionSourceException::unknownSource($sourceId);
        }

        if (! $source->isRemote()) {
            throw ExtensionSourceException::notRemote((string) $source->key);
        }

        // Désactiver, c'est GELER : on n'interroge pas un dépôt qu'on a
        // volontairement mis hors circuit.
        if (! $source->enabled) {
            throw ExtensionSourceException::sourceDisabled((string) $source->key);
        }

        return $this->sync->sync($source, $actor);
    }

    // =====================================================================
    // Gardes et normalisations
    // =====================================================================

    /**
     * Relit la source DANS la transaction avec `lockForUpdate()` : deux admins
     * simultanés voient un état cohérent, le second no-op au lieu de produire
     * une double ligne d'audit (neutre sur SQLite de test, effectif sur
     * PostgreSQL en production — patron `ExtensionLifecycleService`).
     *
     * @throws ExtensionSourceException
     */
    private function lockedSource(int $sourceId): ExtensionSource
    {
        /** @var ExtensionSource|null $source */
        $source = ExtensionSource::query()->lockForUpdate()->find($sourceId);
        if ($source === null) {
            throw ExtensionSourceException::unknownSource($sourceId);
        }

        return $source;
    }

    /**
     * La source EMBARQUÉE est intouchable : ni désactivable, ni retirable.
     *
     * La garde porte sur le `kind` ET sur la clé canonique — une source
     * embarquée reste embarquée même si sa clé venait à changer, et la clé
     * `bundled` reste protégée même si son `kind` était corrompu en base.
     *
     * @throws ExtensionSourceException
     */
    private function assertManageable(ExtensionSource $source): void
    {
        if ($source->kind === ExtensionSourceKind::Bundled || $source->key === ExtensionSource::KEY_BUNDLED) {
            throw ExtensionSourceException::bundledIsImmutable();
        }
    }

    /**
     * Normalise et VALIDE l'URL de base d'un dépôt.
     *
     * Refusés : tout schéma hors http(s), une URL sans hôte, une URL porteuse
     * d'identifiants (`user:pass@` — ils finiraient dans des journaux), une
     * query ou un fragment (l'URL sert de BASE à laquelle on concatène
     * `/index.json` : une query produirait une URL cassée), et une URL trop
     * longue pour la colonne.
     *
     * @throws ExtensionSourceException
     */
    private function normalizeUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '' || strlen($url) > self::URL_MAX) {
            throw ExtensionSourceException::invalidUrl();
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            throw ExtensionSourceException::invalidUrl();
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            throw ExtensionSourceException::invalidUrl();
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw ExtensionSourceException::invalidUrl();
        }

        return $url;
    }

    /**
     * Détermine la clé à PINNER : celle collée par l'admin, ou celle lue une
     * seule fois sur `<url>/source.pub` si — et seulement si — l'URL est en
     * https.
     *
     * @throws ExtensionSourceException
     */
    private function resolvePublicKey(string $url, ?string $publicKey): string
    {
        $pasted = trim((string) $publicKey);

        if ($pasted !== '') {
            if (! CatalogSignatureVerifier::isValidPublicKey($pasted)) {
                throw ExtensionSourceException::invalidPublicKey();
            }

            return $pasted;
        }

        if (! Str::startsWith(Str::lower($url), 'https://')) {
            throw ExtensionSourceException::publicKeyRequiredForHttp();
        }

        return $this->sync->fetchPublicKey($url);
    }

    /**
     * Clé naturelle stable dérivée du nom, garantie unique.
     *
     * Un suffixe numérique est ajouté en cas de collision plutôt que de refuser
     * l'ajout : deux académies peuvent légitimement nommer leur dépôt
     * « Extensions », et l'admin n'a pas à deviner qu'un slug existe déjà. La
     * clé `bundled` est protégée par le même mécanisme (elle existe toujours).
     */
    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'source';
        }
        $base = substr($base, 0, 56);

        $candidate = $base;
        $suffix = 2;
        while (ExtensionSource::query()->where('key', $candidate)->exists()) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
