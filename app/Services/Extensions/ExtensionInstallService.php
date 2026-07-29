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
 * ══════════════════════════════════════════════════════════════════════════
 *  STORY 56.3 — CE QUI S'AJOUTE, ET CE QUI NE BOUGE PAS
 *
 *  Trois ajouts strictement ADDITIFS ; aucun chemin existant n'est retouché.
 *
 *   1. {@see self::update()} — un plan à DEUX étapes privilégiées
 *      (`install-package` puis `restart-service`), précédé de préconditions
 *      vérifiées AVANT d'agir, dont le GAGE DE ROLLBACK. Rien d'autre n'est
 *      touché : port, fragment Apache, fichier d'environnement et client OIDC
 *      sont des invariants de la CLÉ, pas de la version.
 *   2. `?callable $onStep` sur les trois opérations — le pont, et le SEUL,
 *      vers l'état de progression persisté de l'UI. Ce service ne connaît ni
 *      la table des runs, ni les Jobs : la CLI fonctionne sans run, et le
 *      moteur reste testable sans base de runs. ⚠️ Le rapport est ISOLÉ
 *      ({@see self::mark()}) — il ne peut JAMAIS faire échouer une opération.
 *   3. {@see self::stepLabels()} — les libellés d'étapes, remontés des
 *      commandes vers le service : un seul énoncé, quatre consommateurs.
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

    /**
     * Plancher de rétention du verrou. La valeur EFFECTIVE est dérivée du
     * budget de temps du Job ({@see self::lockSeconds()}) — review 56.3 #2.
     */
    private const LOCK_SECONDS_FLOOR = 900;

    /** Marge au-delà du budget du Job, avant expiration du verrou. */
    private const LOCK_SECONDS_MARGIN = 300;

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

    /**
     * Story 56.3 — redémarrage du backend après remplacement de son paquet.
     * `restart` et non `reload` : une extension tierce n'a aucune obligation de
     * savoir recharger sa configuration à chaud, et son binaire vient de
     * changer sur le disque.
     */
    public const HELPER_RESTART_SERVICE = 'restart-service';

    // ── Étiquettes d'étapes (rapport console + `details` d'audit) ───────────
    public const STEP_PACKAGE = 'package';
    public const STEP_OIDC = 'oidc_client';
    public const STEP_ENV = 'env_file';
    public const STEP_APT = 'apt_install';
    public const STEP_SERVICE = 'service';
    public const STEP_APACHE = 'apache';
    public const STEP_REGISTRY = 'registry';

    /**
     * Les trois OPÉRATIONS du moteur — vocabulaire canonique du domaine, défini
     * ICI parce que ce sont exactement les trois méthodes publiques de ce
     * service. {@see \App\Models\ExtensionInstallRun} persiste ces valeurs et
     * les réexpose par référence : un seul énoncé, aucune dérive possible
     * (leçon review 56.1 #3).
     */
    public const OPERATION_INSTALL = 'install';

    public const OPERATION_UPDATE = 'update';

    public const OPERATION_REMOVE = 'remove';

    /**
     * Story 56.3 — catégories de refus PROPRES à la mise à jour.
     *
     * Comme toutes les catégories du moteur (56.2) : courtes, stables, en
     * français, écrites telles quelles dans `extension_audit_logs.details` et
     * affichées telles quelles à l'admin — donc JAMAIS d'URL, JAMAIS de secret
     * (règle `last_error` de 56.1). Elles portent en plus la consigne de
     * sortie, parce qu'il n'y a rien d'autre à en dire : ces deux refus ne se
     * réparent que par désinstallation puis réinstallation.
     */
    public const ERROR_REDIRECT_PATHS_CHANGED = 'URI de redirection modifiées — désinstaller puis réinstaller';

    public const ERROR_ROLLBACK_PACKAGE_MISSING = 'paquet de la version installée absent ou corrompu — désinstaller puis réinstaller';

    public const ERROR_OIDC_CLIENT_MISSING = 'client OIDC de l\'extension introuvable — désinstaller puis réinstaller';

    /**
     * Review 56.3 #1 — la mise à jour a échoué ET le retour arrière aussi.
     * Le seul cas de cette story où l'instance peut rester dans un état que le
     * moteur ne sait plus réparer seul : il doit se dire, pas se confondre avec
     * un échec dont on est revenu proprement.
     */
    public const ERROR_ROLLBACK_FAILED = 'mise à jour ÉCHOUÉE et retour à la version précédente ÉCHOUÉ — vérifier le service, intervention manuelle requise';

    /**
     * Review 56.3 #1 — installation échouée dont le nettoyage est lui-même
     * incomplet : des composants système peuvent subsister. `ext:remove` est
     * l'outil de nettoyage prévu pour cet état (ses étapes sont idempotentes).
     */
    public const ERROR_CLEANUP_INCOMPLETE = 'installation échouée et nettoyage incomplet — relancer « ext:remove » pour repartir d\'un état propre';

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
     * @param  string         $key        clé (`id` du manifest) de l'extension
     * @param  string|null    $sourceKey  source à privilégier si la clé est publiée par plusieurs
     * @param  User|null      $actor      `null` ⇒ acte CLI, audité sous `system`
     * @param  callable|null  $onStep     Story 56.3 — rapport de progression (cf. {@see self::mark()})
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     *
     * @throws ExtensionInstallException  refus de CONTRAT (clé inconnue/ambiguë, type `link`, moteur occupé)
     */
    public function install(string $key, ?string $sourceKey = null, ?User $actor = null, ?callable $onStep = null): array
    {
        return $this->underLock(fn (): array => $this->doInstall($key, $sourceKey, $actor, $onStep));
    }

    /**
     * Désinstalle une extension `app` : ordre INVERSE de l'installation, chaque
     * étape tolérante à l'absent.
     *
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     *
     * @throws ExtensionInstallException
     */
    public function remove(string $key, ?User $actor = null, ?callable $onStep = null): array
    {
        return $this->underLock(fn (): array => $this->doRemove($key, $actor, $onStep));
    }

    /**
     * Story 56.3 (FR11, AR1) — Met à jour une extension `app` DÉJÀ installée
     * vers la version que publie actuellement sa source.
     *
     * ══════════════════════════════════════════════════════════════════════
     *  PÉRIMÈTRE MINIMAL, ASSUMÉ : le paquet et le service, RIEN D'AUTRE
     *
     *  `installed_port`, le fragment Apache, le fichier d'environnement et le
     *  client OIDC sont des invariants de la CLÉ, pas de la version. Les
     *  régénérer serait du churn à risque : le `client_secret` OIDC n'est pas
     *  récupérable (55.1 — seul son sha256 est en base), donc re-enregistrer
     *  le client imposerait de réécrire l'env ET de redémarrer, avec une
     *  compensation impossible à garantir si l'une des deux échoue.
     *
     *  L'UNIQUE cas où un invariant devrait bouger — des `redirect_paths`
     *  différents dans le nouveau manifest — est refusé FAIL-CLOSED avant
     *  toute action, le chemin de secours étant désinstaller puis réinstaller
     *  (la configuration de l'extension est alors purgée : c'est dit dans le
     *  message et dans le runbook QA).
     * ══════════════════════════════════════════════════════════════════════
     *
     * ══════════════════════════════════════════════════════════════════════
     *  LE ROLLBACK N'EST PAS UNE ESPÉRANCE, C'EST UNE PRÉCONDITION VÉRIFIÉE
     *
     *  Avant de toucher quoi que ce soit, le moteur exige que le `.deb` de la
     *  version INSTALLÉE soit présent en staging (il y survit par construction,
     *  décision 56.2 #6), désigné par `installed_sha256`, et RE-HACHÉ conforme
     *  — jamais de confiance au nom de fichier. Absent ou corrompu ⇒ refus
     *  `rollback_package_missing`, sans avoir rien entrepris.
     *
     *  Ainsi l'échec d'apt ou du redémarrage a TOUJOURS une compensation
     *  exécutable : réinstaller l'ancien paquet puis redémarrer. L'état retombe
     *  sur la version d'avant, `installed_*` restent vrais en base (ils n'ont
     *  jamais été touchés), et l'audit consigne `update_failed`. Pas de zombie
     *  (NFR8).
     * ══════════════════════════════════════════════════════════════════════
     *
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     *
     * @throws ExtensionInstallException
     */
    public function update(string $key, ?User $actor = null, ?callable $onStep = null): array
    {
        return $this->underLock(fn (): array => $this->doUpdate($key, $actor, $onStep));
    }

    /**
     * Libellés FR des étapes, PAR OPÉRATION — un seul énoncé, quatre
     * consommateurs : `ext:install`, `ext:remove`, `ext:update` et l'UI 56.3.
     *
     * Cette map vivait en privé dans `ExtensionInstall::renderSteps()` ; l'UI
     * en avait besoin, et la dupliquer aurait garanti la divergence (leçon
     * review 56.1 #3). Les libellés `install` et `remove` sont repris
     * VERBATIM : la sortie des deux commandes 56.2 ne bouge pas d'un caractère.
     *
     * Les mêmes constantes `STEP_*` n'ont pas le même sens selon l'opération —
     * `apt_install` veut dire « paquet installé », « paquet purgé » ou
     * « nouvelle version installée ». D'où le paramètre : une map unique,
     * indexée par opération, plutôt qu'un libellé faussement neutre.
     *
     * @param  string  $operation  une des constantes `OPERATION_*`
     * @return array<string, string>  constante `STEP_*` ⇒ libellé FR
     */
    public static function stepLabels(string $operation = self::OPERATION_INSTALL): array
    {
        $maps = [
            self::OPERATION_INSTALL => [
                self::STEP_PACKAGE => 'paquet téléchargé et sha256 vérifié',
                self::STEP_OIDC => 'client OIDC enregistré',
                self::STEP_ENV => 'fichier d\'environnement posé (0600 root)',
                self::STEP_APT => 'paquet installé (apt)',
                self::STEP_SERVICE => 'unité systemd activée et démarrée',
                self::STEP_APACHE => 'fragment Apache posé et configuration rechargée',
                self::STEP_REGISTRY => 'registre mis à jour et acte journalisé',
            ],
            self::OPERATION_REMOVE => [
                self::STEP_SERVICE => 'unité systemd arrêtée et désactivée',
                self::STEP_APACHE => 'fragment Apache retiré et configuration rechargée',
                self::STEP_APT => 'paquet purgé (apt)',
                self::STEP_ENV => 'fichier d\'environnement supprimé',
                self::STEP_OIDC => 'clients OIDC de l\'extension révoqués (jetons morts)',
                self::STEP_PACKAGE => 'staging du paquet nettoyé',
                self::STEP_REGISTRY => 'registre mis à jour et acte journalisé',
            ],
            self::OPERATION_UPDATE => [
                self::STEP_PACKAGE => 'nouveau paquet téléchargé et sha256 vérifié',
                self::STEP_APT => 'nouvelle version installée (apt)',
                self::STEP_SERVICE => 'service redémarré',
                self::STEP_REGISTRY => 'registre mis à jour et acte journalisé',
            ],
        ];

        return $maps[$operation] ?? $maps[self::OPERATION_INSTALL];
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
    private function doInstall(string $key, ?string $sourceKey, ?User $actor, ?callable $onStep = null): array
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

        /** @var list<string> $steps */
        $steps = [];
        $this->mark($steps, self::STEP_PACKAGE, $onStep);
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
            $this->mark($steps, self::STEP_OIDC, $onStep);

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
            $this->mark($steps, self::STEP_ENV, $onStep);

            // ── 6. apt : PREMIÈRE exécution de code tiers (maintainer scripts)
            $currentStep = self::STEP_APT;
            $this->callHelper([self::HELPER_INSTALL_PACKAGE, (string) $extension->key, $package['path']]);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_REMOVE_PACKAGE, (string) $extension->key]);
            };
            $this->mark($steps, self::STEP_APT, $onStep);

            // ── 7. Unité systemd ───────────────────────────────────────────
            $currentStep = self::STEP_SERVICE;
            $this->callHelper([self::HELPER_ENABLE_SERVICE, (string) $extension->key]);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_DISABLE_SERVICE, (string) $extension->key]);
            };
            $this->mark($steps, self::STEP_SERVICE, $onStep);

            // ── 8. Exposition Apache — DERNIER geste système ───────────────
            $currentStep = self::STEP_APACHE;
            $this->callHelper([self::HELPER_WRITE_FRAGMENT, (string) $extension->key, (string) $port]);
            $undo[] = function () use ($extension): void {
                $this->callHelper([self::HELPER_REMOVE_FRAGMENT, (string) $extension->key]);
                $this->callHelper([self::HELPER_RELOAD_APACHE]);
            };
            $this->callHelper([self::HELPER_RELOAD_APACHE]);
            $this->mark($steps, self::STEP_APACHE, $onStep);

            // ── 9. Base : l'acte et sa trace, dans la même transaction ─────
            $currentStep = self::STEP_REGISTRY;
            $this->lifecycle->markAppInstalled(
                (int) $extension->id,
                (string) $extension->version,
                $port,
                $actor,
                // Story 56.3 — le sha256 VÉRIFIÉ du paquet réellement posé : il
                // désigne, dans le staging content-addressed, le `.deb` vers
                // lequel une future mise à jour ratée devra revenir.
                $install['sha256'],
            );
            $this->mark($steps, self::STEP_REGISTRY, $onStep);
        } catch (Throwable $e) {
            Log::error('[Extensions] Installation interrompue — compensations en cours', [
                'extension' => $extension->key,
                'step' => $currentStep,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Même exigence d'honnêteté que pour la mise à jour (review 56.3
            // #1) : si le nettoyage lui-même est incomplet, des composants
            // système peuvent subsister — le dire, et nommer l'outil qui
            // répare.
            $cleaned = $this->compensate($undo, (string) $extension->key);

            return $this->fail(
                $extension,
                $cleaned ? 'échec à l\'étape '.$currentStep : self::ERROR_CLEANUP_INCOMPLETE,
                $actor,
                $steps,
            );
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
    private function doRemove(string $key, ?User $actor, ?callable $onStep = null): array
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
            $this->mark($steps, self::STEP_SERVICE, $onStep);

            $currentStep = self::STEP_APACHE;
            $this->callHelper([self::HELPER_REMOVE_FRAGMENT, (string) $extension->key]);
            $this->callHelper([self::HELPER_RELOAD_APACHE]);
            $this->mark($steps, self::STEP_APACHE, $onStep);

            $currentStep = self::STEP_APT;
            $this->callHelper([self::HELPER_REMOVE_PACKAGE, (string) $extension->key]);
            $this->mark($steps, self::STEP_APT, $onStep);

            $currentStep = self::STEP_ENV;
            $this->callHelper([self::HELPER_REMOVE_ENV, (string) $extension->key]);
            $this->mark($steps, self::STEP_ENV, $onStep);
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
            $this->mark($steps, self::STEP_OIDC, $onStep);

            // ── Staging : le paquet vérifié n'a plus de consommateur ────────
            $currentStep = self::STEP_PACKAGE;
            $this->purgeStaging((string) $extension->key);
            $this->mark($steps, self::STEP_PACKAGE, $onStep);

            // ── Base : l'acte et sa trace, dans la même transaction ─────────
            $currentStep = self::STEP_REGISTRY;
            $this->lifecycle->markAppRemoved((int) $extension->id, $actor);
            $this->mark($steps, self::STEP_REGISTRY, $onStep);
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
    // Mise à jour (Story 56.3)
    // =====================================================================

    /**
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     */
    private function doUpdate(string $key, ?User $actor, ?callable $onStep): array
    {
        // Résolution façon `resolveForRemoval()` : quand plusieurs sources
        // publient la clé, la ligne INSTALLÉE n'est jamais ambiguë (unicité
        // globale garantie à l'installation, décision 56.2 #11).
        $extension = $this->resolveForRemoval($key);

        if ($extension->type !== ExtensionType::App) {
            throw ExtensionInstallException::linkNotSupported($key);
        }

        // Rien d'installé : NO-OP signalé, pas un échec. C'est exactement la
        // doctrine de `remove()` sur une extension déjà disponible — l'écran
        // qui a déclenché l'acte était simplement périmé (l'autre admin a
        // désinstallé entre-temps), et un clic périmé ne mérite ni ligne
        // d'audit, ni message d'erreur.
        if (($extension->status ?? ExtensionStatus::Available) !== ExtensionStatus::Integrated) {
            return $this->result(false, $extension, [], null, '');
        }

        $source = $extension->source;

        if ($source === null) {
            return $this->failUpdate($extension, 'source introuvable pour cette extension', $actor);
        }

        if (! $source->offersAvailableExtensions()) {
            return $this->failUpdate($extension, 'source désactivée ou catalogue non vérifié', $actor);
        }

        $install = $extension->installBlock();
        if ($install === null) {
            return $this->failUpdate($extension, 'bloc install absent du manifest', $actor);
        }

        if (! in_array($install['channel'], ExtensionManifestValidator::SUPPORTED_INSTALL_CHANNELS, true)) {
            return $this->failUpdate($extension, 'canal d\'installation non supporté', $actor);
        }

        // ── No-op : la source publie ce qui tourne déjà ────────────────────
        // ⚠️ La règle est un ÉCART, jamais un ORDRE : `version` est une chaîne
        // LIBRE du manifest (le validateur ne lui impose aucun format), donc
        // inventer une comparaison sémantique mentirait sur un
        // « 2024-annexe-b ». Une republication antérieure est proposée comme
        // un changement, et c'est voulu — la source est l'autorité de sa
        // fraîcheur (modèle apt), d'où le `--allow-downgrades` du helper.
        if ((string) $extension->version === (string) $extension->installed_version) {
            return $this->result(false, $extension, [], (int) $extension->installed_port, '');
        }

        // ── Invariants de la CLÉ : ils ne doivent pas avoir bougé ──────────
        $prefix = ExtensionManifestValidator::appEntryUrl((string) $extension->key).'/';
        foreach ($install['redirect_paths'] as $path) {
            if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
                return $this->failUpdate($extension, 'URI de redirection hors du préfixe de l\'extension', $actor);
            }
        }

        $client = OidcClient::query()
            ->where('extension_key', $extension->key)
            ->where('enabled', true)
            ->orderByDesc('id')
            ->first();

        if ($client === null) {
            // Une `app` intégrée SANS client actif est un état incohérent qu'un
            // update ne sait pas réparer (le secret n'est pas récupérable).
            return $this->failUpdate($extension, self::ERROR_OIDC_CLIENT_MISSING, $actor);
        }

        $expected = $this->redirectUrisFor((string) $extension->key, $install['redirect_paths']);
        $current = array_values(array_map(static fn ($u): string => (string) $u, (array) $client->redirect_uris));

        // Comparaison par ENSEMBLE : un simple réordonnancement des URI dans le
        // manifest ne change rien au comportement du client OIDC (l'égalité est
        // exacte URI par URI à l'usage, 55.1) — refuser pour cela serait un
        // faux positif. Ce qui est refusé, c'est un ensemble DIFFÉRENT.
        sort($expected);
        sort($current);

        if ($expected !== $current) {
            return $this->failUpdate($extension, self::ERROR_REDIRECT_PATHS_CHANGED, $actor);
        }

        // ── Gage de rollback : vérifié AVANT d'agir, pas espéré après ──────
        $rollbackPath = $this->rollbackPackage($extension);
        if ($rollbackPath === null) {
            return $this->failUpdate($extension, self::ERROR_ROLLBACK_PACKAGE_MISSING, $actor);
        }

        // ── Nouveau paquet : FRONTIÈRE FAIL-CLOSED (identique à l'install) ─
        $package = $this->ensurePackage($extension, $source, $install);
        if ($package['path'] === null) {
            return $this->failUpdate($extension, $package['error'], $actor);
        }

        // ═══ Au-delà de cette ligne, et pas avant, du code tiers s'exécute ═══

        /** @var list<string> $steps */
        $steps = [];
        $this->mark($steps, self::STEP_PACKAGE, $onStep);
        /** @var list<callable(): void> $undo */
        $undo = [];
        $currentStep = self::STEP_APT;

        try {
            // ⚠️ La compensation est enregistrée AVANT l'appel, contrairement au
            // plan d'installation. Là-bas chaque `undo` retire ce que l'étape
            // vient de créer : l'enregistrer après est correct. Ici l'`undo`
            // RESTAURE un état antérieur, et un `apt-get install` interrompu à
            // mi-parcours peut avoir déjà dépaqueté la nouvelle version.
            // Réinstaller l'ancien paquet est idempotent (apt n'a rien à faire
            // si la version est déjà en place) : l'enregistrer trop tôt ne
            // coûte rien, l'enregistrer trop tard laisserait un état hybride
            // sans compensation.
            $undo[] = function () use ($extension, $rollbackPath): void {
                $this->callHelper([self::HELPER_INSTALL_PACKAGE, (string) $extension->key, $rollbackPath]);
                $this->callHelper([self::HELPER_RESTART_SERVICE, (string) $extension->key]);
            };

            $this->callHelper([self::HELPER_INSTALL_PACKAGE, (string) $extension->key, $package['path']]);
            $this->mark($steps, self::STEP_APT, $onStep);

            $currentStep = self::STEP_SERVICE;
            $this->callHelper([self::HELPER_RESTART_SERVICE, (string) $extension->key]);
            $this->mark($steps, self::STEP_SERVICE, $onStep);

            // ── Base : l'acte et sa trace, dans la même transaction ────────
            $currentStep = self::STEP_REGISTRY;
            $this->lifecycle->markAppUpdated(
                (int) $extension->id,
                (string) $extension->version,
                $install['sha256'],
                $actor,
            );
            $this->mark($steps, self::STEP_REGISTRY, $onStep);
        } catch (Throwable $e) {
            Log::error('[Extensions] Mise à jour interrompue — retour à la version installée', [
                'extension' => $extension->key,
                'step' => $currentStep,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Review 56.3 #1 — un rollback qui échoue ne doit pas se raconter
            // comme un rollback réussi : c'est le seul état que le moteur ne
            // sait plus réparer seul, il mérite son propre message et sa propre
            // trace d'audit.
            $restored = $this->compensate($undo, (string) $extension->key);

            return $this->failUpdate(
                $extension,
                $restored ? 'échec à l\'étape '.$currentStep : self::ERROR_ROLLBACK_FAILED,
                $actor,
                $steps,
            );
        }

        Log::info('[Extensions] Extension mise à jour', [
            'extension' => $extension->key,
            // `$extension` n'a pas été rafraîchi : il porte encore la version
            // d'avant, ce qui est exactement ce qu'on veut journaliser.
            'from' => (string) $extension->installed_version,
            'to' => (string) $extension->version,
        ]);

        $fresh = $extension->fresh() ?? $extension;

        return $this->result(true, $fresh, $steps, (int) $fresh->installed_port, '');
    }

    /**
     * Le `.deb` de la version ACTUELLEMENT installée, ou `null` s'il n'offre
     * pas une garantie de retour arrière.
     *
     * Trois conditions, toutes nécessaires : `installed_sha256` renseigné et
     * bien formé, fichier présent dans le staging content-addressed, et
     * empreinte RE-CALCULÉE conforme. Le nom du fichier ne fait jamais foi
     * (patron 56.2 : un paquet en cache est re-haché avant réutilisation).
     */
    private function rollbackPackage(Extension $extension): ?string
    {
        $sha = strtolower((string) $extension->installed_sha256);

        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            return null;
        }

        $path = $this->stagingDir((string) $extension->key).DIRECTORY_SEPARATOR.$sha.'.deb';

        if (! is_file($path)) {
            Log::warning('[Extensions] Paquet de rollback absent du staging — mise à jour refusée', [
                'extension' => $extension->key,
            ]);

            return null;
        }

        $computed = @hash_file('sha256', $path);

        if ($computed === false || ! hash_equals($sha, strtolower((string) $computed))) {
            Log::warning('[Extensions] Paquet de rollback CORROMPU — mise à jour refusée', [
                'extension' => $extension->key,
            ]);

            return null;
        }

        return $path;
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
    private function compensate(array $undo, string $key): bool
    {
        $complete = true;

        foreach (array_reverse($undo) as $index => $step) {
            try {
                $step();
            } catch (Throwable $e) {
                // Best effort maintenu : les compensations suivantes sont
                // tentées quand même. Mais l'échec est désormais REMONTÉ
                // (review 56.3 #1) — jusqu'ici il ne vivait que dans un
                // `Log::error`, et l'appelant rendait alors le même message,
                // la même trace d'audit et le même état qu'une compensation
                // parfaitement réussie. Un opérateur lisait « la version
                // précédente a été rétablie » alors que le service pouvait
                // être resté à l'arrêt.
                $complete = false;

                Log::error('[Extensions] Compensation en échec — poursuite des suivantes', [
                    'extension' => $key,
                    'undo_index' => $index,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $complete;
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
    private function fail(
        Extension $extension,
        string $category,
        ?User $actor,
        array $steps = [],
        string $action = ExtensionAuditLog::ACTION_INSTALL_FAILED,
    ): array {
        Log::warning('[Extensions] Opération REFUSÉE', [
            'extension' => $extension->key,
            'action' => $action,
            'category' => $category,
            'steps_done' => $steps,
        ]);

        // ⚠️ La trace d'un ÉCHEC ne doit pas pouvoir transformer cet échec —
        // déjà compensé, déjà journalisé — en exception nue chez l'appelant.
        // Ce n'est pas l'atomicité « acte ↔ trace » du lifecycle (là-bas, un
        // acte sans trace ne doit pas exister, et le rollback l'empêche) : ici
        // il n'y a PAS d'acte, seulement un refus à rapporter. Perdre la ligne
        // d'audit est un incident de journalisation ; laisser fuir l'exception
        // ferait remonter une stack trace jusqu'à la CLI et laisserait un run
        // d'UI sans terminus.
        try {
            ExtensionAuditLog::log(
                extensionId: $extension->id,
                extensionKey: (string) $extension->key,
                extensionName: (string) $extension->name,
                action: $action,
                actorUserId: $actor?->id,
                actorLogin: $actor?->login ?? ExtensionAuditLog::ACTOR_SYSTEM,
                details: $category,
            );
        } catch (Throwable $e) {
            Log::error('[Extensions] Trace d\'échec NON ÉCRITE — le refus reste rapporté à l\'appelant', [
                'extension' => $extension->key,
                'action' => $action,
                'category' => $category,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->result(false, $extension, $steps, null, $category);
    }

    /**
     * Story 56.3 — Échec d'une MISE À JOUR : même mécanique que
     * {@see self::fail()}, action d'audit `update_failed`.
     *
     * ⚠️ L'extension reste `integrated` et ses colonnes `installed_*` restent
     * VRAIES : la compensation a remis la version d'avant, et rien en base n'a
     * jamais été écrit (la transaction du lifecycle est la dernière étape). Le
     * port retourné est donc celui, toujours valide, du backend qui tourne.
     *
     * @param  list<string>  $steps
     * @return array{changed: bool, status: string, steps: list<string>, port: int|null, error: string}
     */
    private function failUpdate(Extension $extension, string $category, ?User $actor, array $steps = []): array
    {
        $result = $this->fail($extension, $category, $actor, $steps, ExtensionAuditLog::ACTION_UPDATE_FAILED);

        $port = (int) $extension->installed_port;
        $result['port'] = $port > 0 ? $port : null;

        return $result;
    }

    /**
     * Story 56.3 — Enregistre une étape ACCOMPLIE et la rapporte à l'appelant.
     *
     * ⚠️ **Le rapport de progression ne peut JAMAIS faire échouer l'opération.**
     * Le callback écrit en base (la ligne `extension_install_runs` de l'UI) :
     * si cette écriture levait — base indisponible, table absente — l'exception
     * remonterait dans le `try` du plan et déclencherait les compensations
     * d'une installation pourtant RÉUSSIE, désinstallant ce qui vient d'être
     * posé. Un canal d'affichage n'a pas le droit d'avoir cet effet : il est
     * donc isolé, et son échec n'est qu'une ligne de journal.
     *
     * @param  list<string>  $steps
     */
    private function mark(array &$steps, string $step, ?callable $onStep): void
    {
        $steps[] = $step;

        if ($onStep === null) {
            return;
        }

        try {
            $onStep($step);
        } catch (Throwable $e) {
            Log::warning('[Extensions] Rapport de progression en échec — opération poursuivie', [
                'step' => $step,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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
        $lock = Cache::store('file')->lock(self::LOCK_KEY, $this->lockSeconds());

        if (! $lock->get()) {
            throw ExtensionInstallException::engineBusy();
        }

        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * Durée de rétention du verrou, DÉRIVÉE du budget de temps du Job.
     *
     * ⚠️ Review 56.3 #2 — le verrou du store `file` n'est pas lié à la vie du
     * processus : c'est une entrée à expiration, qu'un second appelant peut
     * acquérir dès l'échéance, que le premier ait fini ou non. Une valeur fixe
     * de 600 s face à un `job_timeout` de 1800 s ouvrait donc une fenêtre de
     * 20 minutes pendant laquelle deux opérations pouvaient s'exécuter
     * ensemble — alors que ce verrou est précisément l'arbitre ultime de la
     * concurrence (AC5) : deux allocations de port simultanées, deux
     * transactions `markApp*` entrelacées.
     *
     * La valeur est donc CALCULÉE à partir du même réglage que le Job, pour
     * que les deux ne puissent plus diverger en silence — une règle, un seul
     * énoncé. Le plancher couvre le cas d'un `job_timeout` volontairement
     * court : le verrou ne doit jamais tomber avant la fin d'un `apt` réel.
     */
    private function lockSeconds(): int
    {
        $jobTimeout = (int) config('extensions.install.job_timeout', 1800);

        return max(self::LOCK_SECONDS_FLOOR, $jobTimeout + self::LOCK_SECONDS_MARGIN);
    }
}
