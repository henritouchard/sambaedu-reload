<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Auth\Oidc\Services\OidcClientRegistry;
use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\ExtensionInstallException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\OidcClient;
use App\Models\User;
use App\Services\Extensions\Contracts\ExtensionHelperRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Story 56.2 (FR7/FR8/FR10, NFR2/NFR3/NFR8) — LE moteur d'installation et de
 * désinstallation d'une extension de type `app`.
 *
 * `php artisan ext:install <key>` et `ext:remove <key>` ne sont que des
 * façades sur ce service, et l'UI de la Story 56.3 n'en sera qu'une autre
 * (AR1 : le canal existe, scriptable et auditable, AVANT toute interface).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CHAÎNE DE CONFIANCE — AUCUN SECOND FORMAT DE SIGNATURE
 *
 *  La Story 56.1 signe l'INDEX du dépôt (Ed25519, clé PINNÉE par source). Le
 *  manifest embarqué dans cet index porte `install.sha256` : le hash du paquet
 *  est donc **transitivement couvert par la signature de l'index**. Vérifier
 *  `hash_file('sha256', $deb) === $manifest['install']['sha256']` EST la
 *  vérification « contre la clé déclarée de sa source » exigée par NFR2 —
 *  exactement le modèle apt (`Release` signé → `Packages` → `.deb` par hash).
 *  Inventer une signature détachée par-paquet serait un second vérificateur,
 *  pour la même clé, à zéro gain, avec un coût pour chaque éditeur.
 *
 *  « AVANT toute exécution » (FR7) a ici un sens PRÉCIS : la première
 *  exécution de code tiers, c'est le maintainer script d'apt (`preinst` /
 *  `postinst`, exécutés root). Le sha256 est vérifié avant le PREMIER appel au
 *  helper — donc apt n'est jamais invoqué sur un artefact non conforme. Le
 *  test AC3 l'affirme littéralement : zéro appel au runner.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CONTRAT DE PAQUET `sambaedu-ext-*` (contrat PUBLIC — Epics 57/58)
 *
 *  | Élément        | Contrat                                                     |
 *  |----------------|-------------------------------------------------------------|
 *  | Nom de paquet  | `sambaedu-ext-<key>` (`<key>` = `id` du manifest)            |
 *  | Unité systemd  | `sambaedu-ext-<key>.service`, livrée PAR le paquet           |
 *  | Environnement  | `EnvironmentFile=/etc/sambaedu/extensions/<key>.env`         |
 *  | Écoute         | `127.0.0.1:${SE5_EXT_PORT}` EXCLUSIVEMENT (jamais 0.0.0.0)   |
 *  | Base path      | l'app se sert sous `${SE5_EXT_BASE_PATH}` (= `/ext/<key>`)  |
 *  | Démarrage      | le paquet n'enable/start JAMAIS son unité — SE5 le fait      |
 *  | Utilisateur    | dédié (`DynamicUser=yes`), jamais root, jamais www-admin     |
 *
 *  Variables du fichier d'environnement (0600 root:root — systemd le lit en
 *  root AVANT le drop de privilèges, donc l'utilisateur de service n'a pas
 *  besoin d'y accéder, et www-admin ne peut pas le relire) :
 *  `SE5_EXT_KEY`, `SE5_EXT_BASE_PATH`, `SE5_EXT_PORT`, `SE5_OIDC_ISSUER`,
 *  `SE5_OIDC_CLIENT_ID`, `SE5_OIDC_CLIENT_SECRET`, `SE5_OIDC_REDIRECT_URI`.
 *
 *  C'est ce fichier qui répond à la friction n° 3 relevée en clôture de
 *  l'Epic 55 (« rien ne dit comment une extension apprend son issuer ») : elle
 *  l'apprend ici, par son environnement, et par aucun autre canal.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  ORDRE D'INSTALLATION — CHAQUE ÉTAPE RÉVERSIBLE (NFR8)
 *
 *      étape (do)                                     compensation (undo)
 *   0. verrou fichier global                          release (finally)
 *   1. résolution + gardes (type, source, unicité)     — (lecture seule)
 *   2. bloc `install` + allocation de port             — (lecture seule)
 *   3. téléchargement borné + sha256                   unlink du .tmp fautif
 *      ───── frontière fail-closed : rien au-delà sans sha256 conforme ─────
 *   4. OidcClientRegistry::register()                  revoke(client_id)
 *   5. helper write-env (stdin : env + secret)         helper remove-env
 *   6. helper install-package (apt → 1ʳᵉ exécution)    helper remove-package
 *   7. helper enable-service                           helper disable-service
 *   8. helper write-fragment + reload-apache           helper remove-fragment + reload
 *   9. DB : markAppInstalled + audit `install`         — (dernière étape)
 *
 *  Rationale : le réversible-et-local (fichiers SE5, registre OIDC) avant le
 *  privilégié ; l'env AVANT le paquet (l'unité référence le fichier — un start
 *  prématuré échouerait) ; le fragment Apache en DERNIER geste système (on
 *  n'expose `/ext/<key>` qu'une fois le backend démarré : jamais de 502
 *  provisionné) ; la base en TOUT dernier (si elle échoue, les compensations
 *  8→4 ramènent l'état propre ; l'inverse — base posée, système en vrac —
 *  serait l'installation zombie que NFR8 interdit).
 *
 *  Échec à l'étape N ⇒ undo N-1…4 en ordre INVERSE, chaque compensation dans
 *  son propre try/catch (une compensation qui échoue est journalisée et
 *  n'empêche pas les suivantes — best effort explicite), puis audit
 *  `install_failed` nommant l'étape. La relance repart de 1 : l'état est
 *  propre, et le `.deb` VÉRIFIÉ conservé en staging content-addressed évite le
 *  re-téléchargement.
 *
 *  `remove()` est strictement l'ordre inverse, chaque étape tolérante à
 *  l'absent — c'est ce qui en fait à la fois la désinstallation nominale ET
 *  l'outil de nettoyage d'un état dégradé imprévu.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Aucun appel système ici** (NFR15) : tous les effets privilégiés passent par
 * {@see ExtensionHelperRunner}, seul seam root du domaine. Le secret OIDC ne
 * quitte ce service que par le `stdin` de `write-env` : jamais en argument,
 * jamais journalisé, jamais dans `details`, jamais dans un retour de méthode.
 *
 * **Ce service ne mute JAMAIS `status` ni `installed_*` directement** : la
 * transaction finale passe par {@see ExtensionLifecycleService::markAppInstalled()}
 * / {@see ExtensionLifecycleService::markAppRemoved()}, qui restent seuls
 * écrivains de ces colonnes.
 */
class ExtensionInstallService
{
    /**
     * Verrou GLOBAL du moteur (décision 56.2 #2) — pas un verrou par clé : les
     * installations sont des actes d'administration rares, et l'unicité globale
     * des clés comme l'allocation de port deviennent triviales et sans course.
     *
     * ⚠️ `Cache::store('file')` OBLIGATOIRE : le store par défaut du projet est
     * APCu, qui n'a AUCUN support de `lock()` (fiche mémoire projet) —
     * `Cache::lock()` y serait un verrou qui ne verrouille rien.
     */
    private const LOCK_KEY = 'extensions:install-engine';

    /** Durée de rétention du verrou (une installation apt peut être longue). */
    private const LOCK_SECONDS = 600;

    /** Taille d'un morceau de lecture du paquet (borne appliquée À LA LECTURE). */
    private const READ_CHUNK_BYTES = 65536;

    // ── Sous-commandes du helper root (contrat `sambaedu-ext-helper.sh`) ────
    public const HELPER_WRITE_ENV = 'write-env';
    public const HELPER_REMOVE_ENV = 'remove-env';
    public const HELPER_INSTALL_PACKAGE = 'install-package';
    public const HELPER_REMOVE_PACKAGE = 'remove-package';
    public const HELPER_ENABLE_SERVICE = 'enable-service';
    public const HELPER_DISABLE_SERVICE = 'disable-service';
    public const HELPER_WRITE_FRAGMENT = 'write-fragment';
    public const HELPER_REMOVE_FRAGMENT = 'remove-fragment';
    public const HELPER_RELOAD_APACHE = 'reload-apache';

    // ── Étiquettes d'étapes (rapport console + `details` d'audit) ───────────
    public const STEP_PACKAGE = 'package';
    public const STEP_OIDC = 'oidc_client';
    public const STEP_ENV = 'env_file';
    public const STEP_APT = 'apt_install';
    public const STEP_SERVICE = 'service';
    public const STEP_APACHE = 'apache';
    public const STEP_REGISTRY = 'registry';

    public function __construct(
        private readonly ExtensionHelperRunner $runner,
        private readonly OidcClientRegistry $registry,
        private readonly ExtensionLifecycleService $lifecycle,
    ) {
    }

    // =====================================================================
    // API publique
    // =====================================================================

    /**
     * Installe une extension `app` de bout en bout.
     *
     * @param  string       $key        clé (`id` du manifest) de l'extension
     * @param  string|null  $sourceKey  source à privilégier si la clé est publiée par plusieurs
     * @param  User|null    $actor      `null` ⇒ acte CLI, audité sous `system`
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     *
     * @throws ExtensionInstallException  refus de CONTRAT (clé inconnue/ambiguë, type `link`, moteur occupé)
     */
    public function install(string $key, ?string $sourceKey = null, ?User $actor = null): array
    {
        return $this->underLock(fn (): array => $this->doInstall($key, $sourceKey, $actor));
    }

    /**
     * Désinstalle une extension `app` : ordre INVERSE de l'installation, chaque
     * étape tolérante à l'absent.
     *
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     *
     * @throws ExtensionInstallException
     */
    public function remove(string $key, ?User $actor = null): array
    {
        return $this->underLock(fn (): array => $this->doRemove($key, $actor));
    }

    /**
     * Contenu du fichier d'environnement d'une extension — méthode PURE
     * (aucun accès disque, aucun accès base), donc testable telle quelle.
     *
     * ⚠️ Cette chaîne PORTE LE SECRET du client OIDC. Elle n'est transmise qu'à
     * `write-env` par stdin, et n'est ni retournée à un appelant, ni
     * journalisée, ni stockée.
     */
    public function buildEnvFile(string $key, int $port, string $clientId, string $clientSecret, string $redirectUri): string
    {
        $issuer = rtrim((string) config('oidc.issuer', ''), '/');

        $lines = [
            'SE5_EXT_KEY='.$key,
            'SE5_EXT_BASE_PATH='.ExtensionManifestValidator::appEntryUrl($key),
            'SE5_EXT_PORT='.$port,
            'SE5_OIDC_ISSUER='.$issuer,
            'SE5_OIDC_CLIENT_ID='.$clientId,
            'SE5_OIDC_CLIENT_SECRET='.$clientSecret,
            'SE5_OIDC_REDIRECT_URI='.$redirectUri,
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * URI de redirection OIDC de l'extension : celles du manifest (bornées à
     * `/ext/<key>/` par le validateur) ou le défaut conventionnel.
     *
     * Le défaut EST le contrat que le SDK (Epic 58) implémentera : une
     * extension qui ne déclare rien est servie sur
     * `/ext/<key>/oidc/callback`.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function redirectUrisFor(string $key, array $declared): array
    {
        if ($declared !== []) {
            return array_values($declared);
        }

        return [ExtensionManifestValidator::appEntryUrl($key).'/oidc/callback'];
    }

    // =====================================================================
    // Installation
    // =====================================================================

    /**
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     */
    private function doInstall(string $key, ?string $sourceKey, ?User $actor): array
    {
        // ── 1. Résolution + gardes de CONTRAT (elles lèvent) ───────────────
        $extension = $this->resolve($key, $sourceKey);

        if ($extension->type !== ExtensionType::App) {
            throw ExtensionInstallException::linkNotSupported($key);
        }

        // NFR8 — déjà installée depuis CETTE ligne : no-op signalé, zéro appel
        // helper, zéro ligne d'audit. Une installation rejouée n'est pas un
        // acte, c'est une confirmation.
        if (($extension->status ?? ExtensionStatus::Available) === ExtensionStatus::Integrated) {
            return $this->result(false, $extension, [], (int) $extension->installed_port, '');
        }

        $source = $extension->source;

        // ── 1b. Gardes d'ÉTAT (elles auditent `install_failed`) ────────────
        if ($source === null) {
            return $this->fail($extension, 'source introuvable pour cette extension', $actor);
        }

        // Une source gelée ou dont le dernier catalogue n'a pas pu être VÉRIFIÉ
        // ne propose plus rien (règle UNIQUE portée par le modèle, review 56.1
        // #1). `unreachable` reste acceptable : le registre EST le dernier
        // catalogue vérifié (NFR7) — et le paquet, lui, sera de toute façon
        // vérifié par son sha256.
        if (! $source->offersAvailableExtensions()) {
            return $this->fail($extension, 'source désactivée ou catalogue non vérifié', $actor);
        }

        // ── 1c. Unicité GLOBALE des clés `app` (dette léguée par 56.1) ─────
        //
        // Ce qui doit être unique, c'est ce que la clé RÉSERVE sur le système :
        // le paquet `sambaedu-ext-<key>`, l'unité systemd homonyme, le fragment
        // Apache et le préfixe `/ext/<key>`. Seules les `app` occupent ces
        // noms — une `link` intégrée ne pose aucun composant (54.2). Sans le
        // filtre de type (review 56.2 #3), une `link` nommée `doc` bloquait
        // l'installation d'une `app` `doc` d'une autre source, avec un message
        // exact à la lettre mais trompeur, et pour un conflit qui n'existe pas.
        $conflict = Extension::query()
            ->where('key', $extension->key)
            ->where('type', ExtensionType::App)
            ->where('status', ExtensionStatus::Integrated)
            ->where('id', '!=', $extension->id)
            ->with('source')
            ->first();

        if ($conflict !== null) {
            return $this->fail(
                $extension,
                'clé déjà installée depuis la source « '.((string) ($conflict->source?->key ?? '?')).' »',
                $actor,
            );
        }

        // ── 2. Bloc `install` + allocation de port ─────────────────────────
        $install = $extension->installBlock();
        if ($install === null) {
            return $this->fail($extension, 'bloc install absent du manifest', $actor);
        }

        if (! in_array($install['channel'], ExtensionManifestValidator::SUPPORTED_INSTALL_CHANNELS, true)) {
            return $this->fail($extension, 'canal d\'installation non supporté', $actor);
        }

        // Défense en profondeur : le validateur a déjà borné `redirect_paths`
        // au préfixe de l'extension à la synchro, mais c'est ICI qu'un URI de
        // redirection devient un client OIDC réel. Un manifest persisté avant
        // cette règle, une ligne modifiée à la main, un futur chemin d'écriture
        // qui contournerait le validateur : la garde ne doit pas dépendre de
        // qui a écrit la ligne. Fail-closed plutôt que filtrage silencieux —
        // laisser tomber une URI en douce donnerait une installation qui ne
        // fonctionne pas pour une raison invisible.
        $prefix = ExtensionManifestValidator::appEntryUrl((string) $extension->key).'/';
        foreach ($install['redirect_paths'] as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
                return $this->fail($extension, 'URI de redirection hors du préfixe de l\'extension', $actor);
            }
        }

        $port = $this->allocatePort();
        if ($port === null) {
            return $this->fail($extension, 'plage de ports épuisée', $actor);
        }

        // ── 3. Téléchargement borné + sha256 (FRONTIÈRE FAIL-CLOSED) ───────
        $package = $this->ensurePackage($extension, $source, $install);
        if ($package['path'] === null) {
            return $this->fail($extension, $package['error'], $actor);
        }

        // ═══ Au-delà de cette ligne, et pas avant, du code tiers s'exécute ═══

        $steps = [self::STEP_PACKAGE];
        /** @var list<callable(): void> $undo */
        $undo = [];
        $currentStep = self::STEP_OIDC;

        try {
            // ── 4. Client OIDC ─────────────────────────────────────────────
            $redirectUris = $this->redirectUrisFor((string) $extension->key, $install['redirect_paths']);
            $registration = $this->registry->register((string) $extension->name, $redirectUris, (string) $extension->key);
            $clientId = (string) $registration['client_id'];
            $undo[] = function () use ($clientId): void {
                $this->registry->revoke($clientId);
            };
            $steps[] = self::STEP_OIDC;

            // ── 5. Fichier d'environnement (LE secret part par stdin) ───────
            $currentStep = self::STEP_ENV;
            $env = $this->buildEnvFile(
                (string) $extension->key,
                $port,
                $clientId,
                (string) $registration['client_secret'],
                $redirectUris[0],
            );
            $this->callHelper([self::HELPER_WRITE_ENV, (string) $extension->key], $env);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_REMOVE_ENV, (string) $extension->key]);
            };
            $steps[] = self::STEP_ENV;

            // ── 6. apt : PREMIÈRE exécution de code tiers (maintainer scripts)
            $currentStep = self::STEP_APT;
            $this->callHelper([self::HELPER_INSTALL_PACKAGE, (string) $extension->key, $package['path']]);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_REMOVE_PACKAGE, (string) $extension->key]);
            };
            $steps[] = self::STEP_APT;

            // ── 7. Unité systemd ───────────────────────────────────────────
            $currentStep = self::STEP_SERVICE;
            $this->callHelper([self::HELPER_ENABLE_SERVICE, (string) $extension->key]);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_DISABLE_SERVICE, (string) $extension->key]);
            };
            $steps[] = self::STEP_SERVICE;

            // ── 8. Exposition Apache — DERNIER geste système ───────────────
            $currentStep = self::STEP_APACHE;
            $this->callHelper([self::HELPER_WRITE_FRAGMENT, (string) $extension->key, (string) $port]);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_REMOVE_FRAGMENT, (string) $extension->key]);
                $this->callHelper([self::HELPER_RELOAD_APACHE]);
            };
            $this->callHelper([self::HELPER_RELOAD_APACHE]);
            $steps[] = self::STEP_APACHE;

            // ── 9. Base : l'acte et sa trace, dans la même transaction ─────
            $currentStep = self::STEP_REGISTRY;
            $this->lifecycle->markAppInstalled(
                (int) $extension->id,
                (string) $extension->version,
                $port,
                $actor,
            );
            $steps[] = self::STEP_REGISTRY;
        } catch (Throwable $e) {
            Log::error('[Extensions] Installation interrompue — compensations en cours', [
                'extension' => $extension->key,
                'step' => $currentStep,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->compensate($undo, (string) $extension->key);

            return $this->fail($extension, 'échec à l\'étape '.$currentStep, $actor, $steps);
        }

        Log::info('[Extensions] Extension installée', [
            'extension' => $extension->key,
            'version' => $extension->version,
            'port' => $port,
        ]);

        return $this->result(true, $extension->fresh() ?? $extension, $steps, $port, '');
    }

    // =====================================================================
    // Désinstallation
    // =====================================================================

    /**
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     */
    private function doRemove(string $key, ?User $actor): array
    {
        $extension = $this->resolveForRemoval($key);

        if ($extension->type !== ExtensionType::App) {
            throw ExtensionInstallException::linkNotSupported($key);
        }

        if (($extension->status ?? ExtensionStatus::Available) !== ExtensionStatus::Integrated) {
            // No-op signalé : rien à défaire, aucune ligne d'audit.
            return $this->result(false, $extension, [], null, '');
        }

        $steps = [];
        $currentStep = self::STEP_SERVICE;

        try {
            // Ordre strictement INVERSE. Chaque sous-commande `remove-*` /
            // `disable-service` du helper est idempotente (absent ⇒ exit 0) :
            // `ext:remove` est donc aussi l'outil de nettoyage d'un état
            // dégradé, et se rejoue sans risque après un échec partiel.
            $this->callHelper([self::HELPER_DISABLE_SERVICE, (string) $extension->key]);
            $steps[] = self::STEP_SERVICE;

            $currentStep = self::STEP_APACHE;
            $this->callHelper([self::HELPER_REMOVE_FRAGMENT, (string) $extension->key]);
            $this->callHelper([self::HELPER_RELOAD_APACHE]);
            $steps[] = self::STEP_APACHE;

            $currentStep = self::STEP_APT;
            $this->callHelper([self::HELPER_REMOVE_PACKAGE, (string) $extension->key]);
            $steps[] = self::STEP_APT;

            $currentStep = self::STEP_ENV;
            $this->callHelper([self::HELPER_REMOVE_ENV, (string) $extension->key]);
            $steps[] = self::STEP_ENV;
        } catch (Throwable $e) {
            // Pas de compensation : `remove()` EST le chemin de nettoyage. On
            // s'arrête net, l'état reste `integrated`, et l'opérateur rejoue —
            // ce qui est exactement l'usage prévu (idempotence).
            Log::error('[Extensions] Désinstallation interrompue — état inchangé, relance possible', [
                'extension' => $extension->key,
                'step' => $currentStep,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->result(
                false,
                $extension,
                $steps,
                (int) $extension->installed_port,
                'échec à l\'étape '.$currentStep,
            );
        }

        // ⚠️ Review 56.2 #2 — les trois étapes qui suivent étaient HORS du
        // `try` : une exception de la révocation OIDC ou de `markAppRemoved()`
        // (erreur DB, contrainte) remontait nue jusqu'à `ext:remove`, qui
        // n'attrape qu'`ExtensionInstallException` ⇒ stack trace en CLI, et
        // surtout composants système déjà retirés alors que la base dit encore
        // `integrated` : le zombie INVERSE, celui que NFR8 vise autant que
        // l'autre. Elles sont désormais couvertes par le même filet, avec le
        // même traitement gracieux que les étapes helper.
        try {
            // ── Clients OIDC : TOUS les actifs de cette clé (décision #5) ───
            // Pas seulement le dernier connu : une installation avortée peut
            // avoir laissé des clients fantômes (register réussi, étape
            // suivante en échec, compensation elle-même en échec). L'état final
            // doit être sûr même après plusieurs échecs partiels. La révocation
            // tue les jetons déjà émis — mécanique 55.2 validée en QA 13.8.
            $currentStep = self::STEP_OIDC;
            $revoked = 0;
            foreach (OidcClient::query()->where('extension_key', $extension->key)->where('enabled', true)->get() as $client) {
                $this->registry->revoke((string) $client->client_id);
                $revoked++;
            }
            $steps[] = self::STEP_OIDC;

            // ── Staging : le paquet vérifié n'a plus de consommateur ────────
            $currentStep = self::STEP_PACKAGE;
            $this->purgeStaging((string) $extension->key);
            $steps[] = self::STEP_PACKAGE;

            // ── Base : l'acte et sa trace, dans la même transaction ─────────
            $currentStep = self::STEP_REGISTRY;
            $this->lifecycle->markAppRemoved((int) $extension->id, $actor);
            $steps[] = self::STEP_REGISTRY;
        } catch (Throwable $e) {
            // Même doctrine que ci-dessus : pas de compensation (on ne
            // réinstalle pas ce qu'on vient de retirer), l'état reste
            // `integrated` et l'opérateur rejoue — les étapes déjà faites sont
            // idempotentes, la relance converge.
            Log::error('[Extensions] Désinstallation interrompue après le retrait système — relance nécessaire', [
                'extension' => $extension->key,
                'step' => $currentStep,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->result(
                false,
                $extension,
                $steps,
                (int) $extension->installed_port,
                'échec à l\'étape '.$currentStep,
            );
        }

        Log::info('[Extensions] Extension désinstallée', [
            'extension' => $extension->key,
            'oidc_clients_revoked' => $revoked,
        ]);

        return $this->result(true, $extension->fresh() ?? $extension, $steps, null, '');
    }

    // =====================================================================
    // Résolution, ports, staging
    // =====================================================================

    /**
     * Résout LA ligne d'extension visée. Une clé publiée par plusieurs sources
     * est AMBIGUË (collision tolérée au catalogue, décision 56.1) : on refuse
     * plutôt que de choisir.
     *
     * @throws ExtensionInstallException
     */
    private function resolve(string $key, ?string $sourceKey): Extension
    {
        $candidates = Extension::query()
            ->where('key', $key)
            ->with('source')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            throw ExtensionInstallException::unknownExtension($key);
        }

        if ($sourceKey !== null && $sourceKey !== '') {
            $filtered = $candidates->filter(
                static fn (Extension $e): bool => (string) ($e->source?->key ?? '') === $sourceKey
            );

            if ($filtered->isEmpty()) {
                throw ExtensionInstallException::unknownSourceForKey($key, $sourceKey);
            }

            /** @var Extension $first */
            $first = $filtered->first();

            return $first;
        }

        if ($candidates->count() > 1) {
            throw ExtensionInstallException::ambiguousKey(
                $key,
                $candidates->map(static fn (Extension $e): string => (string) ($e->source?->key ?? '?'))
                    ->unique()->values()->all(),
            );
        }

        /** @var Extension $only */
        $only = $candidates->first();

        return $only;
    }

    /**
     * Résout la ligne à DÉSINSTALLER.
     *
     * Différence assumée avec {@see self::resolve()} : quand plusieurs sources
     * publient la clé, la désinstallation n'est PAS ambiguë — une seule de ces
     * lignes peut être installée (unicité globale garantie à l'installation).
     * Exiger un `--source` ici obligerait l'opérateur à retrouver de quelle
     * source vient ce qui tourne pour pouvoir l'arrêter, alors que le système
     * le sait. On ne retombe sur la résolution générale que s'il n'y a RIEN
     * d'installé, pour produire le bon message (no-op, ou refus d'une `link`).
     *
     * @throws ExtensionInstallException
     */
    private function resolveForRemoval(string $key): Extension
    {
        /** @var Extension|null $installed */
        $installed = Extension::query()
            ->where('key', $key)
            ->where('status', ExtensionStatus::Integrated)
            ->with('source')
            ->orderBy('id')
            ->first();

        return $installed ?? $this->resolve($key, null);
    }

    /**
     * Premier port LIBRE de `extensions.install.port_range`.
     *
     * Les trous sont comblés (une extension désinstallée rend son port), et
     * l'appel se fait SOUS le verrou global : deux installations concurrentes
     * ne peuvent pas se voir attribuer le même port.
     *
     * @return int|null  `null` ⇒ plage épuisée
     */
    private function allocatePort(): ?int
    {
        $range = config('extensions.install.port_range', [8600, 8699]);
        $min = (int) ($range[0] ?? 8600);
        $max = (int) ($range[1] ?? 8699);

        $taken = Extension::query()
            ->whereNotNull('installed_port')
            ->pluck('installed_port')
            ->map(static fn ($p): int => (int) $p)
            ->all();

        for ($port = $min; $port <= $max; $port++) {
            if (! in_array($port, $taken, true)) {
                return $port;
            }
        }

        return null;
    }

    /**
     * Garantit la présence d'un `.deb` VÉRIFIÉ en staging, et renvoie son
     * chemin absolu.
     *
     * Staging **content-addressed** : `<staging>/<key>/<sha256>.deb`. Un paquet
     * déjà présent est RE-HACHÉ avant réutilisation — un fichier corrompu en
     * cache est re-téléchargé, jamais refusé, jamais fait confiance sur son
     * seul nom.
     *
     * ⚠️ La borne de taille est appliquée **à la lecture** (`Content-Length`
     * consulté d'abord, puis coupure par morceaux) et non après coup : une
     * borne vérifiée quand les octets sont déjà arrivés ne borne rien — elle
     * déplace l'épuisement de la RAM vers le disque du serveur, qui porte aussi
     * la base et les journaux (leçon review 56.1 #2, appliquée au paquet).
     *
     * ⚠️ Les redirections ne sont JAMAIS suivies : le paquet vient du MÊME hôte
     * que l'index signé, par construction. Un dépôt ne peut pas emmener SE5
     * ailleurs.
     *
     * @param  array{channel: string, package: string, sha256: string, redirect_paths: list<string>}  $install
     * @return array{path: string|null, error: string}
     */
    private function ensurePackage(Extension $extension, ExtensionSource $source, array $install): array
    {
        $dir = $this->stagingDir((string) $extension->key);

        if (! is_dir($dir) && ! @mkdir($dir, 0o750, true) && ! is_dir($dir)) {
            return ['path' => null, 'error' => 'staging de paquets indisponible'];
        }

        $target = $dir.DIRECTORY_SEPARATOR.$install['sha256'].'.deb';

        // Réutilisation du cache — après re-vérification du hash.
        if (is_file($target)) {
            $computed = @hash_file('sha256', $target);
            if ($computed !== false && hash_equals($install['sha256'], strtolower((string) $computed))) {
                return ['path' => $target, 'error' => ''];
            }

            Log::warning('[Extensions] Paquet en staging corrompu — re-téléchargement', [
                'extension' => $extension->key,
            ]);
            @unlink($target);
        }

        $maxBytes = (int) config('extensions.install.package_max_bytes', 268_435_456);
        $tmp = $dir.DIRECTORY_SEPARATOR.'.ext-'.bin2hex(random_bytes(8)).'.tmp';
        $url = $source->baseUrl().'/'.$install['package'];

        try {
            $response = Http::connectTimeout((int) config('extensions.install.connect_timeout', 5))
                ->timeout((int) config('extensions.install.download_timeout', 300))
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);
        } catch (Throwable $e) {
            @unlink($tmp);
            Log::warning('[Extensions] Paquet injoignable', [
                'extension' => $extension->key,
                'url' => $url,
                'exception' => $e::class,
            ]);

            // ⚠️ Jamais `$e->getMessage()` : Guzzle y suffixe l'URI complète.
            return ['path' => null, 'error' => 'paquet injoignable ('.class_basename($e).')'];
        }

        if (! $response->successful()) {
            @unlink($tmp);

            // Inclut les 3xx : une redirection non suivie EST une indisponibilité.
            return ['path' => null, 'error' => 'téléchargement refusé (HTTP '.$response->status().')'];
        }

        $declared = (string) $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            return ['path' => null, 'error' => 'paquet hors borne de taille'];
        }

        $written = $this->streamToFile($response, $tmp, $maxBytes);
        if ($written === null) {
            @unlink($tmp);

            return ['path' => null, 'error' => 'paquet hors borne de taille'];
        }

        $computed = @hash_file('sha256', $tmp);
        if ($computed === false) {
            @unlink($tmp);

            return ['path' => null, 'error' => 'sha256 incalculable sur le paquet téléchargé'];
        }

        if (! hash_equals($install['sha256'], strtolower((string) $computed))) {
            @unlink($tmp);

            Log::warning('[Extensions] sha256 du paquet NON CONCORDANT — installation refusée', [
                'extension' => $extension->key,
                'expected' => $install['sha256'],
                'computed' => strtolower((string) $computed),
                'url' => $url,
            ]);

            return ['path' => null, 'error' => 'sha256 du paquet non concordant'];
        }

        if (! @rename($tmp, $target)) {
            @unlink($tmp);

            return ['path' => null, 'error' => 'matérialisation du paquet impossible'];
        }

        return ['path' => $target, 'error' => ''];
    }

    /**
     * Écrit le corps STREAMÉ d'une réponse dans un fichier, en coupant net dès
     * `$maxBytes` franchis.
     *
     * @return int|null  octets écrits, ou `null` si la borne a été franchie
     */
    private function streamToFile(\Illuminate\Http\Client\Response $response, string $path, int $maxBytes): ?int
    {
        $body = $response->toPsrResponse()->getBody();
        $out = @fopen($path, 'wb');

        if ($out === false) {
            $body->close();

            return null;
        }

        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::READ_CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }

                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    return null;
                }

                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            // Referme la connexion dans TOUS les cas — sur le refus surtout,
            // où l'intérêt est précisément de ne pas tirer la suite du corps.
            $body->close();
        }

        return $written;
    }

    /** Répertoire de staging d'une extension (content-addressed). */
    private function stagingDir(string $key): string
    {
        $root = rtrim(
            (string) config('extensions.install.staging_path', storage_path('app/extensions/packages')),
            '/\\',
        );

        return $root.DIRECTORY_SEPARATOR.$key;
    }

    /**
     * Supprime le staging d'une extension : après un `remove`, le paquet
     * vérifié n'a plus de consommateur (décision 56.2 #6 — on le conserve après
     * un ÉCHEC d'installation, pour épargner le re-téléchargement à la relance ;
     * on ne le conserve pas après une désinstallation, ce serait un cache
     * orphelin).
     */
    private function purgeStaging(string $key): void
    {
        $dir = $this->stagingDir($key);
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }
            @unlink($dir.DIRECTORY_SEPARATOR.$entry);
        }

        @rmdir($dir);
    }

    // =====================================================================
    // Helper, compensations, résultats
    // =====================================================================

    /**
     * Invoque le helper root et EXIGE un code retour nul.
     *
     * @param  list<string>  $args
     *
     * @throws RuntimeException  attrapée par le plan d'installation, jamais propagée à la CLI
     */
    private function callHelper(array $args, ?string $stdin = null): void
    {
        $result = $this->runner->run($args, $stdin);
        $exitCode = (int) ($result['exitCode'] ?? 1);

        if ($exitCode === 0) {
            return;
        }

        // Le stderr va au JOURNAL SERVEUR, jamais dans `details` ni dans un
        // message d'exception exposé : il peut porter un chemin, une ligne de
        // conf, un extrait de sortie apt.
        Log::error('[Extensions] Helper privilégié en échec', [
            'subcommand' => $args[0] ?? '?',
            'exit_code' => $exitCode,
            'stderr' => $result['stderr'] ?? [],
        ]);

        throw new RuntimeException('helper « '.((string) ($args[0] ?? '?')).' » exit='.$exitCode);
    }

    /**
     * Exécute les compensations en ordre INVERSE, chacune dans son propre
     * try/catch : une compensation qui échoue est journalisée et n'empêche PAS
     * les suivantes (best effort explicite). Laisser une compensation ratée
     * interrompre la chaîne, ce serait garantir l'état zombie qu'on cherche
     * précisément à éviter.
     *
     * @param  list<callable(): void>  $undo
     */
    private function compensate(array $undo, string $key): void
    {
        foreach (array_reverse($undo) as $index => $step) {
            try {
                $step();
            } catch (Throwable $e) {
                Log::error('[Extensions] Compensation en échec — poursuite des suivantes', [
                    'extension' => $key,
                    'undo_index' => $index,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Journalise l'échec (serveur, détaillé) ET l'audite (`install_failed`,
     * catégorie COURTE — jamais d'URL, jamais de secret : règle `last_error` de
     * 56.1, elle-même héritée du piège Guzzle review 39.4 #E11).
     *
     * Une ligne PAR TENTATIVE (décision 56.2 #7) : contrairement à
     * `source_sync_failed`, il n'y a pas de dédoublonnage à la transition —
     * une synchro planifiée se répète toute seule, une installation est un
     * acte volontaire de l'opérateur.
     *
     * @param  list<string>  $steps
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     */
    private function fail(Extension $extension, string $category, ?User $actor, array $steps = []): array
    {
        Log::warning('[Extensions] Installation REFUSÉE', [
            'extension' => $extension->key,
            'category' => $category,
            'steps_done' => $steps,
        ]);

        ExtensionAuditLog::log(
            extensionId: $extension->id,
            extensionKey: (string) $extension->key,
            extensionName: (string) $extension->name,
            action: ExtensionAuditLog::ACTION_INSTALL_FAILED,
            actorUserId: $actor?->id,
            actorLogin: $actor?->login ?? ExtensionAuditLog::ACTOR_SYSTEM,
            details: $category,
        );

        return $this->result(false, $extension, $steps, null, $category);
    }

    /**
     * @param  list<string>  $steps
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     */
    private function result(bool $changed, Extension $extension, array $steps, ?int $port, string $error): array
    {
        return [
            'changed' => $changed,
            'status' => ($extension->status ?? ExtensionStatus::Available)->value,
            'steps' => array_values($steps),
            'port' => $port !== null && $port > 0 ? $port : null,
            'error' => $error,
        ];
    }

    /**
     * Exécute `$work` sous le verrou GLOBAL du moteur.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     *
     * @throws ExtensionInstallException  si le moteur est déjà occupé
     */
    private function underLock(callable $work): mixed
    {
        $lock = Cache::store('file')->lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw ExtensionInstallException::engineBusy();
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }
}
