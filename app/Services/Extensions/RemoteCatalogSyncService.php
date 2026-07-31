<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionSourceKind;
use App\Enums\ExtensionSourceSyncStatus;
use App\Exceptions\ExtensionSourceException;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Story 56.1 (FR2, NFR2, NFR7) — Synchronisation d'une source DISTANTE :
 * récupération bornée, **vérification de signature avant tout usage**, puis
 * chargement du catalogue vérifié dans le registre.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  FORMAT DE CATALOGUE DISTANT v1 — CONTRAT PUBLIC (NFR11)
 *
 *  Un dépôt de source, c'est trois fichiers STATIQUES sous une URL de base
 *  (hébergeable sur GitHub Pages, un raw GitLab, un simple Apache — donc
 *  miroir-able hors ligne, AR9) :
 *
 *      <url>/index.json        le catalogue : méta de source + manifests v1 EMBARQUÉS
 *      <url>/index.json.sig    base64( sodium_crypto_sign_detached(octets exacts d'index.json, sk) )
 *      <url>/source.pub        base64 de la clé publique Ed25519 — lue UNE fois à l'ajout (TOFU)
 *
 *  ```json
 *  {
 *    "index_version": 1,
 *    "name": "Extensions Académie de Grenoble",
 *    "publisher": "DSI ac-grenoble",
 *    "extensions": [ { "manifest_version": 1, "id": "…", "type": "app", … } ]
 *  }
 *  ```
 *
 *  Les manifests sont **embarqués inline**, pas référencés : un seul
 *  téléchargement, et la signature de l'index couvre TRANSITIVEMENT tous les
 *  manifests. Un manifest signé séparément aurait multiplié les fetchs, les
 *  vérifications et les moyens de se tromper.
 *
 *  `index_version` est STRICT (=1), avec les mêmes règles de normalisation que
 *  `manifest_version` : un `"1.0"` ou un `"v1"` n'est pas la version 1. La
 *  Story 56.2 ajoutera un bloc `install` par manifest — ADDITIF, jamais une
 *  rupture.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  ORDRE INVIOLABLE (le cœur de sécurité de cette story)
 *
 *      octets bruts téléchargés
 *        → bornes de TAILLE
 *          → CatalogSignatureVerifier::verify() sur les octets VERBATIM
 *            → alors SEULEMENT json_decode()
 *              → index_version
 *                → manifests
 *
 *  Rien de ce qui n'a pas été vérifié n'est décodé, interprété, journalisé en
 *  contenu ni écrit en base. Un `json_decode` avant la vérification donnerait
 *  déjà à un attaquant réseau une surface d'attaque (le parseur) et pourrait
 *  faire fuir du contenu non authentifié dans les journaux. Un test verrouille
 *  l'ordre : un index à la fois MAL SIGNÉ et MALFORMÉ ne produit aucune erreur
 *  de parsing — la preuve que le parseur n'a jamais été atteint.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  FAIL-CLOSED ET DÉGRADATION (NFR2 / NFR7)
 *
 *  - Réseau, DNS, timeout, 5xx, **3xx** ⇒ `unreachable`. Les redirections ne
 *    sont pas suivies (`allow_redirects => false`) : un dépôt ne peut donc
 *    jamais emmener SE5 vers un autre hôte. Le dernier catalogue VÉRIFIÉ reste
 *    proposé — le registre EST le cache local.
 *  - Signature invalide, taille hors borne, JSON illisible, `index_version`
 *    inconnue, `extensions` mal formé ⇒ `error`. Les `available` de la source
 *    sont masquées de la bibliothèque, les `integrated` conservées.
 *  - **Sur AUCUN de ces chemins d'échec il n'y a d'écriture d'extension ni de
 *    prune.** C'est l'invariant #5 de 54.1 appliqué au réseau : on ne prune que
 *    ce qu'on a réellement OBSERVÉ, et un catalogue non vérifié n'est pas une
 *    observation. Ce projet a déjà vécu un catalogue effacé par une synchro
 *    ratée ; la règle n'est pas négociable.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **`last_error` ne contient JAMAIS l'URL** ni un message d'exception brut :
 * Guzzle suffixe l'URI complète à ses messages, et une URL de dépôt GitLab peut
 * porter `?private_token=…` (piège documenté par la review 39.4 #E11
 * d'`ArtifactPullService`). La colonne reçoit une CATÉGORIE stable et courte,
 * destinée à l'admin ; le détail complet reste dans le journal serveur.
 *
 * **Moteur UNIQUE (AR1)** : le bouton « Actualiser » de la page des sources et
 * la commande `ext:sources:sync` (manuelle ou planifiée) passent tous par ce
 * service. Il n'existe pas de second chemin de synchro.
 *
 * ⚠️ Vocabulaire : « amont » / `Upstream`, jamais « central ». Aucun lien avec
 * la sync amont controlHub (isolement NFR14).
 */
class RemoteCatalogSyncService
{
    /** Les trois fichiers du contrat de format v1, relatifs à l'URL de base. */
    public const INDEX_FILE = 'index.json';
    public const SIGNATURE_FILE = 'index.json.sig';
    public const PUBLIC_KEY_FILE = 'source.pub';

    /** Version d'index supportée par cette instance (égalité STRICTE, iso `manifest_version`). */
    public const SUPPORTED_INDEX_VERSIONS = [1];

    /** Une signature Ed25519 base64 fait 88 caractères : 4 KiB est déjà démesuré. */
    private const SIGNATURE_MAX_BYTES = 4096;

    /** Une clé publique base64 fait 44 caractères. */
    private const PUBLIC_KEY_MAX_BYTES = 1024;

    /** Taille d'un morceau de lecture bornée ({@see self::readBounded()}). */
    private const READ_CHUNK_BYTES = 8192;

    /** Longueur max du message d'erreur persisté (colonne `last_error`). */
    private const ERROR_MAX = 500;

    public function __construct(
        private readonly CatalogSignatureVerifier $verifier,
        private readonly ExtensionCatalogService $catalog,
    ) {
    }

    /**
     * Synchronise UNE source distante. Ne lève jamais d'exception pour un
     * problème de dépôt : elle en fait un statut.
     *
     * @param  User|null  $actor  acteur de l'acte (null ⇒ synchro planifiée,
     *                            journalisée sous l'acteur `system`)
     * @return array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}
     *
     * @throws ExtensionSourceException  si la source n'est pas une source distante
     */
    public function sync(ExtensionSource $source, ?User $actor = null): array
    {
        // Garde de CONTRAT (pas un état de dépôt) : la source embarquée n'a pas
        // d'URL à interroger. C'est une erreur de programmation, elle remonte.
        if ($source->kind !== ExtensionSourceKind::Remote) {
            throw ExtensionSourceException::notRemote((string) $source->key);
        }

        try {
            return $this->doSync($source, $actor);
        } catch (Throwable $e) {
            // Filet de sécurité NFR7 : quoi qu'il arrive dans le moteur, une
            // synchro ratée ne doit jamais faire tomber la page qui l'a
            // déclenchée ni la commande planifiée. On dégrade en `error`.
            Log::error('[Extensions] Synchro distante interrompue par une erreur interne', [
                'source' => $source->key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->markError($source, 'erreur interne de synchronisation', $actor);
        }
    }

    /**
     * Synchronise TOUTES les sources distantes ACTIVES.
     *
     * Une source désactivée est GELÉE : on ne l'interroge pas (désactiver, ce
     * n'est pas seulement masquer). L'échec d'une source n'interrompt jamais
     * les suivantes.
     *
     * @return list<array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}>
     */
    public function syncAll(?User $actor = null): array
    {
        $sources = ExtensionSource::query()
            ->where('kind', ExtensionSourceKind::Remote)
            ->where('enabled', true)
            ->orderBy('key')
            ->get();

        $results = [];
        foreach ($sources as $source) {
            try {
                $results[] = $this->sync($source, $actor);
            } catch (Throwable $e) {
                // `sync()` avale déjà tout sauf la garde de contrat. Ce catch
                // garantit malgré tout qu'une source ne peut pas en bloquer une
                // autre — la boucle est le point de robustesse de la commande
                // planifiée.
                Log::error('[Extensions] Source ignorée pendant la synchro globale', [
                    'source' => $source->key,
                    'exception' => $e::class,
                ]);

                $results[] = $this->emptyResult($source, ExtensionSourceSyncStatus::Error, 'source non synchronisable');
            }
        }

        return $results;
    }

    /**
     * Lit UNE fois `<url>/source.pub` pour pinner la clé d'une nouvelle source
     * (TOFU réseau, réservé aux URL https — voir {@see ExtensionSourceService::add()}).
     *
     * Appelée uniquement à l'AJOUT. Aucune synchro ultérieure ne re-télécharge
     * la clé : c'est ce qui rend la compromission ultérieure du dépôt incapable
     * de substituer sa propre clé (modèle `known_hosts`).
     *
     * @return string  la clé base64, validée
     *
     * @throws ExtensionSourceException  si la clé est illisible ou inexploitable
     */
    public function fetchPublicKey(string $baseUrl): string
    {
        $url = rtrim($baseUrl, '/').'/'.self::PUBLIC_KEY_FILE;

        try {
            $response = $this->client(stream: true)->get($url);
        } catch (Throwable $e) {
            Log::warning('[Extensions] Clé publique de source injoignable', [
                'url' => $url,
                'exception' => $e::class,
            ]);

            throw ExtensionSourceException::publicKeyUnavailable();
        }

        if (! $response->successful()) {
            Log::warning('[Extensions] Clé publique de source indisponible', [
                'url' => $url,
                'status' => $response->status(),
            ]);

            throw ExtensionSourceException::publicKeyUnavailable();
        }

        // Lecture bornée à la source, comme l'index (review 56.1 #2) : une
        // « clé publique » de 2 Go doit coûter un morceau de 8 Kio, pas 2 Go
        // de RAM.
        try {
            $body = $this->readBounded($response, self::PUBLIC_KEY_MAX_BYTES);
        } catch (Throwable $e) {
            Log::warning('[Extensions] Lecture de la clé publique interrompue', [
                'url' => $url,
                'exception' => $e::class,
            ]);

            throw ExtensionSourceException::publicKeyUnavailable();
        }

        if ($body === null || $body === '') {
            throw ExtensionSourceException::publicKeyUnavailable();
        }

        $key = trim($body);
        if (! CatalogSignatureVerifier::isValidPublicKey($key)) {
            throw ExtensionSourceException::invalidPublicKey();
        }

        return $key;
    }

    // =====================================================================
    // Moteur
    // =====================================================================

    /**
     * @return array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}
     */
    private function doSync(ExtensionSource $source, ?User $actor): array
    {
        $base = $source->baseUrl();

        // ── 1. En-têtes — le corps n'est PAS bufferisé (`stream`) ─────────
        try {
            $indexResponse = $this->client(stream: true)->get($base.'/'.self::INDEX_FILE);
            $signatureResponse = $this->client(stream: true)->get($base.'/'.self::SIGNATURE_FILE);
        } catch (Throwable $e) {
            // ConnectionException (DNS, TCP, TLS, timeout) et consorts. On ne
            // persiste QUE la catégorie : le message Guzzle porte l'URI.
            return $this->markUnreachable($source, 'dépôt injoignable ('.class_basename($e).')', $e);
        }

        if (! $indexResponse->successful()) {
            // Inclut les 3xx : les redirections ne sont pas suivies, une
            // redirection EST une indisponibilité de ce point d'accès.
            return $this->markUnreachable($source, $this->httpCategory(self::INDEX_FILE, $indexResponse));
        }

        if (! $signatureResponse->successful()) {
            return $this->markUnreachable($source, $this->httpCategory(self::SIGNATURE_FILE, $signatureResponse));
        }

        // ── 2. Lecture BORNÉE, à la source ────────────────────────────────
        // Ni une signature ni un hash ne bornent une taille (leçon 39.4 #3) —
        // mais une borne vérifiée APRÈS `->body()` ne borne rien non plus : à
        // cet instant le corps est DÉJÀ intégralement en mémoire PHP (review
        // 56.1 #2). Un dépôt hostile — ou dont l'IP a été détournée après le
        // pin — répondrait 2 Go sur `index.json` et ferait tomber la synchro
        // planifiée (`syncAll()` boucle sur toutes les sources) avant même
        // d'atteindre le refus. On lit donc par morceaux et on coupe net.
        $maxIndexBytes = (int) config('extensions.remote.index_max_bytes', 1_048_576);

        try {
            $indexBytes = $this->readBounded($indexResponse, $maxIndexBytes);
            $signature = $this->readBounded($signatureResponse, self::SIGNATURE_MAX_BYTES);
        } catch (Throwable $e) {
            // Coupure en cours de corps : c'est un défaut de transport, pas un
            // refus de contenu — même catégorie que la connexion impossible.
            return $this->markUnreachable($source, 'dépôt injoignable (lecture interrompue : '.class_basename($e).')', $e);
        }

        if ($indexBytes === null) {
            return $this->markError($source, 'catalogue refusé : index.json dépasse la borne de taille autorisée', $actor);
        }
        if ($signature === null) {
            return $this->markError($source, 'catalogue refusé : signature détachée anormalement volumineuse', $actor);
        }
        if ($indexBytes === '') {
            return $this->markError($source, 'catalogue refusé : index.json vide', $actor);
        }

        // ── 3. SIGNATURE, avant tout décodage ─────────────────────────────
        // La clé utilisée est EXCLUSIVEMENT celle pinnée en base : elle n'est
        // jamais re-téléchargée, jamais renégociée. Un dépôt qui change de clé
        // passe ici en erreur — la rotation légitime est un retrait + ré-ajout
        // explicites par l'admin.
        if (! $this->verifier->verify($indexBytes, $signature, (string) $source->public_key)) {
            return $this->markError($source, 'catalogue refusé : signature Ed25519 invalide pour la clé pinnée de la source', $actor);
        }

        // ── 4. Alors seulement : décodage ─────────────────────────────────
        $decoded = json_decode($indexBytes, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->markError($source, 'catalogue refusé : index.json n\'est pas un objet JSON', $actor);
        }

        // ── 5. Version d'index STRICTE ────────────────────────────────────
        $indexVersion = $this->normalizeIndexVersion($decoded['index_version'] ?? null);
        if ($indexVersion === null) {
            return $this->markError($source, 'catalogue refusé : version d\'index non supportée par cette instance', $actor);
        }

        // ── 6. Liste des manifests ────────────────────────────────────────
        $entries = $decoded['extensions'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries)) {
            return $this->markError($source, 'catalogue refusé : « extensions » doit être une liste JSON', $actor);
        }

        // Indexation par ORIGINE lisible : un manifest rejeté est nommé dans le
        // journal par sa position dans l'index, pas par un compteur anonyme.
        $manifests = [];
        foreach ($entries as $position => $entry) {
            $manifests[self::INDEX_FILE.'#'.$position] = $entry;
        }

        // ── 7. Chargement (invariants 54.1 #1-#4) ─────────────────────────
        $stats = $this->catalog->syncManifestsForSource($source, $manifests);

        $this->markOk($source);

        Log::info('[Extensions] Synchro de source distante terminée', $stats + [
            'source' => $source->key,
            'index_version' => $indexVersion,
        ]);

        return [
            'source' => (string) $source->key,
            'status' => ExtensionSourceSyncStatus::Ok->value,
            'error' => '',
        ] + $stats;
    }

    /**
     * Client HTTP BORNÉ, commun aux quatre téléchargements de cette story.
     *
     * `allow_redirects => false` : toute 3xx est traitée comme une
     * indisponibilité. C'est plus simple ET plus sûr qu'une liste blanche
     * d'hôtes — un dépôt ne peut pas rediriger SE5 vers un serveur qu'il
     * choisit (SSRF par redirection).
     *
     * ⚠️ **Aucun filtrage d'hôte** (loopback, RFC1918, lien-local) n'est
     * appliqué, et c'est DÉLIBÉRÉ : AR9 veut explicitement le miroir hors-ligne
     * sur le réseau de l'établissement — interdire les adresses privées
     * interdirait le cas d'usage principal. Ce que cela ouvre reste borné :
     * seul un admin (`server.admin`) déclare une source, aucun octet de la
     * réponse n'est reflété à l'écran (seuls des compteurs et une catégorie
     * d'erreur courte le sont), et rien n'est interprété sans signature valide
     * contre la clé PINNÉE. Le résidu assumé est un sondage de topologie par
     * code HTTP/latence, à la portée d'un compte qui administre déjà le
     * serveur (review 56.1 #4).
     *
     * @param  bool  $stream  ne pas bufferiser le corps : les octets ne sont
     *                        lus qu'à travers {@see self::readBounded()}, qui
     *                        coupe dès la borne franchie.
     */
    private function client(bool $stream = false): \Illuminate\Http\Client\PendingRequest
    {
        $options = ['allow_redirects' => false];
        if ($stream) {
            $options['stream'] = true;
        }

        return Http::connectTimeout((int) config('extensions.remote.connect_timeout', 5))
            ->timeout((int) config('extensions.remote.timeout', 15))
            ->withOptions($options)
            ->withHeaders(['Accept' => 'application/json, text/plain, */*']);
    }

    /**
     * Lit le corps d'une réponse en refusant de dépasser `$maxBytes`.
     *
     * Deux garde-fous, dans cet ordre :
     *
     *  1. `Content-Length` annoncé au-delà de la borne ⇒ on ne lit **rien**.
     *  2. Lecture par morceaux avec coupure : un dépôt qui ment sur sa taille
     *     (ou n'annonce rien, cas du `chunked`) est arrêté au premier morceau
     *     qui franchit la borne, et le flux est refermé — le reste du corps
     *     n'est jamais transféré.
     *
     * @return string|null  les octets lus, ou `null` si la borne est franchie
     *                      (l'appelant traduit ce `null` en refus `error`)
     */
    private function readBounded(Response $response, int $maxBytes): ?string
    {
        $declared = (string) $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            return null;
        }

        $body = $response->toPsrResponse()->getBody();

        $buffer = '';
        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::READ_CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }

                $buffer .= $chunk;

                if (strlen($buffer) > $maxBytes) {
                    return null;
                }
            }
        } finally {
            // Referme la connexion dans TOUS les cas — y compris sur le refus,
            // où l'intérêt est précisément de ne pas tirer la suite du corps.
            $body->close();
        }

        return $buffer;
    }

    /**
     * `index_version` : un entier, ou une chaîne strictement numérique. Rien
     * d'autre — mêmes règles que `manifest_version`
     * ({@see ExtensionManifestValidator::assertSupportedVersion()}) : un
     * « 1.0 » ou un « v1 » n'est PAS la version 1, et le laisser passer serait
     * le repli tolérant que le contrat refuse.
     */
    private function normalizeIndexVersion(mixed $declared): ?int
    {
        $normalized = null;
        if (is_int($declared)) {
            $normalized = $declared;
        } elseif (is_string($declared) && preg_match('/^\d+$/', $declared) === 1) {
            $normalized = (int) $declared;
        }

        if ($normalized === null || ! in_array($normalized, self::SUPPORTED_INDEX_VERSIONS, true)) {
            return null;
        }

        return $normalized;
    }

    /** Catégorie d'erreur HTTP, sans jamais l'URL. */
    private function httpCategory(string $file, Response $response): string
    {
        return 'dépôt injoignable (HTTP '.$response->status().' sur '.$file.')';
    }

    // =====================================================================
    // Transitions d'état de la source (aucune écriture d'extension ici)
    // =====================================================================

    /** Succès : catalogue vérifié et chargé. */
    private function markOk(ExtensionSource $source): void
    {
        $source->sync_status = ExtensionSourceSyncStatus::Ok;
        $source->last_synced_at = now();
        $source->last_error = '';
        $source->save();
    }

    /**
     * Dépôt injoignable (NFR7) : le dernier catalogue vérifié reste en place,
     * les tuiles intégrées restent servies, RIEN n'est pruné ni écrit.
     *
     * Pas d'audit : un incident réseau transitoire n'est pas un acte. Le statut
     * et `last_error` le disent déjà sur la page des sources.
     *
     * @return array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}
     */
    private function markUnreachable(ExtensionSource $source, string $category, ?Throwable $e = null): array
    {
        Log::warning('[Extensions] Source distante injoignable — catalogue local PRÉSERVÉ', [
            'source' => $source->key,
            'url' => $source->baseUrl(),
            'category' => $category,
            'exception' => $e?->getMessage(),
        ]);

        $source->sync_status = ExtensionSourceSyncStatus::Unreachable;
        $source->last_error = Str::limit($category, self::ERROR_MAX, '');
        // `last_synced_at` n'est PAS touché : il date la dernière synchro
        // RÉUSSIE, c'est précisément l'information utile ici.
        $source->save();

        return $this->emptyResult($source, ExtensionSourceSyncStatus::Unreachable, $category);
    }

    /**
     * Catalogue REFUSÉ (NFR2) : signature ou contenu invalide. Fail-closed —
     * les `available` de la source disparaissent de la bibliothèque, les
     * `integrated` sont conservées et signalées, RIEN n'est pruné ni écrit.
     *
     * L'audit `source_sync_failed` n'est consigné qu'à la **transition** vers
     * l'état d'erreur : un re-échec quotidien de la synchro planifiée
     * n'empilerait sinon des lignes identiques et noierait les vraies
     * transitions (même discipline que le no-op de 54.2).
     *
     * @return array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}
     */
    private function markError(ExtensionSource $source, string $category, ?User $actor): array
    {
        Log::error('[Extensions] Catalogue distant REFUSÉ — aucune extension écrite, aucun prune', [
            'source' => $source->key,
            'url' => $source->baseUrl(),
            'category' => $category,
        ]);

        $wasAlreadyInError = $source->syncStatus() === ExtensionSourceSyncStatus::Error;

        DB::transaction(function () use ($source, $category, $actor, $wasAlreadyInError): void {
            $source->sync_status = ExtensionSourceSyncStatus::Error;
            $source->last_error = Str::limit($category, self::ERROR_MAX, '');
            $source->save();

            if ($wasAlreadyInError) {
                return;
            }

            ExtensionAuditLog::logSource(
                sourceId: $source->id,
                sourceKey: (string) $source->key,
                action: ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED,
                actorUserId: $actor?->id,
                actorLogin: $actor?->login ?? ExtensionAuditLog::ACTOR_SYSTEM,
            );
        });

        return $this->emptyResult($source, ExtensionSourceSyncStatus::Error, $category);
    }

    /**
     * Résultat d'un chemin d'échec : tous les compteurs à ZÉRO. C'est
     * l'expression littérale de « rien n'a été écrit, rien n'a été pruné ».
     *
     * @return array{source: string, status: string, loaded: int, created: int, updated: int, skipped: int, pruned: int, error: string}
     */
    private function emptyResult(ExtensionSource $source, ExtensionSourceSyncStatus $status, string $error): array
    {
        return [
            'source' => (string) $source->key,
            'status' => $status->value,
            'loaded' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'pruned' => 0,
            'error' => $error,
        ];
    }
}
