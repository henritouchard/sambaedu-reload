<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Story 54.1 — Une extension du registre local SE5.
 *
 * **Le `manifest` complet est la source de vérité de la fiche** : scopes,
 * dépendances, `entry_url` et `visibility.roles` s'y lisent. Les colonnes
 * `name`/`version`/`publisher`/`icon`/`description`/`type` sont des
 * dénormalisations destinées à la LISTE (afficher la bibliothèque sans décoder
 * N documents JSON).
 *
 * **`status` est affiché, jamais muté par le catalogue** : la synchro de la
 * source bundled ({@see \App\Services\Extensions\ExtensionCatalogService::syncBundled()})
 * ne l'écrit jamais, sinon un rechargement de catalogue dé-intégrerait
 * silencieusement une extension. Les transitions
 * (`available ⇄ integrated`) vivent dans
 * {@see \App\Services\Extensions\ExtensionLifecycleService} (Story 54.2),
 * seul écrivain de cette colonne.
 *
 * ⚠️ Aucune de ces colonnes n'est alimentée par la sync amont (controlHub) :
 * le registre d'extensions est isolé PAR CONSTRUCTION (NFR14).
 *
 * @property int $id
 * @property int $extension_source_id
 * @property string $key
 * @property string $name
 * @property string $version
 * @property string $publisher
 * @property string $icon
 * @property string $description
 * @property ExtensionType $type
 * @property ExtensionStatus $status
 * @property array<string, mixed> $manifest
 * @property string $installed_version
 * @property string $installed_sha256
 * @property int|null $installed_port
 * @property \Illuminate\Support\Carbon|null $installed_at
 * @property string $health_status  '' (jamais sondé) | 'ok' | 'unreachable' (56.5)
 * @property \Illuminate\Support\Carbon|null $health_checked_at
 * @property \Illuminate\Support\Carbon|null $health_last_incident_at
 * @property string $health_last_incident_detail
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ExtensionSource|null $source
 */
class Extension extends Model
{
    use HasFactory;

    protected $table = 'extensions';

    /**
     * ⚠️ `status` est VOLONTAIREMENT absent : rien dans cette classe ne doit
     * pouvoir le muter par assignation de masse. Décision 54.2 (tranchée) :
     * `status` RESTE hors `$fillable` — la transition se fait par
     * ASSIGNATION DE PROPRIÉTÉ explicite dans
     * {@see \App\Services\Extensions\ExtensionLifecycleService}, seul
     * écrivain de la colonne.
     *
     * ⚠️ Story 56.2/56.3 : `installed_version` / `installed_sha256` /
     * `installed_port` / `installed_at`
     * sont absentes POUR LA MÊME RAISON. Le `fill()` de l'upsert de catalogue
     * ({@see \App\Services\Extensions\ExtensionCatalogService::syncManifestsForSource()})
     * reçoit un manifest de source TIERCE : s'il pouvait écrire ces colonnes,
     * un manifest hostile déclarerait `installed_port` et se ferait passer pour
     * installé — donc proposé au lanceur — sans qu'aucun paquet n'ait jamais
     * été vérifié. Une re-synchro de catalogue met à jour `version` (ce que la
     * source PUBLIE) sans jamais toucher `installed_version` (ce qui TOURNE).
     *
     * ⚠️ Story 56.5 : `health_status` / `health_checked_at` /
     * `health_last_incident_at` / `health_last_incident_detail` sont absentes
     * POUR LA MÊME RAISON, et c'est ici que l'enjeu est le plus direct — un
     * manifest hostile qui pourrait se déclarer `health_status = 'ok'`
     * effacerait le seul signal disant que son backend est mort. La santé n'est
     * pas déclarée, elle est OBSERVÉE : seul
     * {@see \App\Services\Extensions\ExtensionHealthService} écrit ces
     * colonnes, par assignation de propriété explicite.
     *
     * @var list<string>
     */
    protected $fillable = [
        'extension_source_id',
        'key',
        'name',
        'version',
        'publisher',
        'icon',
        'description',
        'type',
        'manifest',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => ExtensionType::class,
        'status' => ExtensionStatus::class,
        'manifest' => 'array',
        'installed_port' => 'integer',
        'installed_at' => 'datetime',
        'health_checked_at' => 'datetime',
        'health_last_incident_at' => 'datetime',
    ];

    // ========================================================================
    // SANTÉ (Story 56.5) — état OBSERVÉ, jamais déclaré
    // ========================================================================

    /** Jamais sondée : l'état est INCONNU (et se dit tel quel, pas « ok »). */
    public const HEALTH_UNKNOWN = '';

    /** Le backend a répondu au dernier passage de la sonde. */
    public const HEALTH_OK = 'ok';

    /** Erreur RÉSEAU au dernier passage : aucune réponse HTTP du backend. */
    public const HEALTH_UNREACHABLE = 'unreachable';

    /** La source d'où provient cette extension. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(ExtensionSource::class, 'extension_source_id');
    }

    /** URL d'entrée déclarée par le manifest (cible de la tuile pour un `link`). */
    public function entryUrl(): string
    {
        return (string) ($this->manifest['entry_url'] ?? '');
    }

    /**
     * Story 56.2 — Bloc `install` du manifest v1 (extension ADDITIVE) : ce qui
     * permet d'installer une `app`. Absent ⇒ `null` (un manifest 54.x/56.1 sans
     * bloc `install` reste valide verbatim, NFR11).
     *
     * La forme est celle NORMALISÉE par
     * {@see \App\Services\Extensions\ExtensionManifestValidator} :
     * `['channel' => 'deb', 'package' => 'packages/…deb', 'sha256' => '<64 hex>',
     *   'redirect_paths' => list<string>]`.
     *
     * ⚠️ Le `sha256` lu ici vient du manifest PERSISTÉ, c'est-à-dire du dernier
     * index VÉRIFIÉ contre la clé pinnée de la source (56.1) : la vérification
     * du paquet contre cette valeur EST donc la vérification « contre la clé
     * déclarée de sa source » (NFR2), à la manière d'apt
     * (`Release` signé → `Packages` → `.deb` par hash).
     *
     * @return array{channel: string, package: string, sha256: string, redirect_paths: list<string>}|null
     */
    public function installBlock(): ?array
    {
        $install = $this->manifest['install'] ?? null;
        if (! is_array($install) || $install === []) {
            return null;
        }

        $channel = (string) ($install['channel'] ?? '');
        $package = (string) ($install['package'] ?? '');
        $sha256 = (string) ($install['sha256'] ?? '');

        if ($channel === '' || $package === '' || $sha256 === '') {
            return null;
        }

        $redirectPaths = $install['redirect_paths'] ?? [];
        if (! is_array($redirectPaths)) {
            $redirectPaths = [];
        }

        return [
            'channel' => $channel,
            'package' => $package,
            'sha256' => $sha256,
            'redirect_paths' => array_values(array_map(static fn ($p): string => (string) $p, $redirectPaths)),
        ];
    }

    /**
     * Story 56.3 — Le manifest porte-t-il un bloc `install` EXPLOITABLE par le
     * moteur (présent, complet, et sur un canal supporté) ?
     *
     * ⚠️ Règle à UN SEUL énoncé, avec deux appelants qui doivent dire la même
     * chose (leçon review 56.1 #3) :
     *  - {@see \App\Services\Extensions\ExtensionCatalogService} s'en sert pour
     *    décider si la bibliothèque PROPOSE le bouton « Intégrer » ;
     *  - {@see \App\Services\Extensions\ExtensionInstallService} refuse
     *    exactement dans le cas complémentaire (avec deux messages distincts —
     *    « bloc install absent » vs « canal non supporté » — parce que
     *    l'opérateur doit savoir LEQUEL des deux, mais le périmètre du refus
     *    est identique, et un test le verrouille).
     *
     * L'UI ne doit jamais proposer ce que le moteur refusera.
     */
    public function hasSupportedInstallBlock(): bool
    {
        $install = $this->installBlock();

        if ($install === null) {
            return false;
        }

        return in_array(
            $install['channel'],
            \App\Services\Extensions\ExtensionManifestValidator::SUPPORTED_INSTALL_CHANNELS,
            true,
        );
    }

    /**
     * Cette extension est-elle une `app` réellement INSTALLÉE sur l'instance ?
     *
     * `status = integrated` ET `type = app` : les deux sont nécessaires — une
     * `link` intégrée n'a jamais rien installé, et une `app` `available` n'a
     * ni port, ni unité, ni fragment Apache.
     */
    public function isInstalledApp(): bool
    {
        return $this->type === ExtensionType::App
            && ($this->status ?? ExtensionStatus::Available) === ExtensionStatus::Integrated;
    }

    /**
     * Story 56.5 — Cette extension a-t-elle un backend à SONDER ?
     *
     * `isInstalledApp()` ne suffit pas : sans `installed_port`, il n'y a aucune
     * adresse de boucle locale à interroger (même doctrine que « pas de tuile
     * sans port », 56.2 — une ligne fabriquée à la main n'a rien derrière elle).
     * C'est le périmètre EXACT de la sonde planifiée, de la carte « Santé » de
     * la fiche et du check doctor : un seul énoncé, trois consommateurs.
     */
    public function isHealthMonitored(): bool
    {
        return $this->isInstalledApp() && $this->installed_port !== null;
    }

    /**
     * Story 56.5 — L'état de santé PERSISTÉ est-il périmé (ou inexistant) ?
     *
     * Périmé ⇒ on ne SAIT plus. Un scheduler mort n'est pas une extension
     * morte : la tuile ne dit alors rien (afficher « indisponible » sur 30
     * tuiles parce que le cron est arrêté serait pire que le silence), et c'est
     * le doctor qui porte CE diagnostic-là (`warn` « état périmé — vérifier le
     * scheduler »).
     *
     * ⚠️ Comparaison CÔTÉ PHP, jamais en SQL (calque du docblock
     * d'{@see ExtensionInstallRun::isStale()}) : les sessions Postgres sont en
     * UTC et l'application à Paris — un `where('health_checked_at', '<', ...)`
     * décalerait le verdict de deux heures (fiche « Fuseau session Postgres »).
     *
     * Seuil : `config('extensions.health.stale_after')`, dérivé de la période de
     * sonde (voir le commentaire de la config).
     */
    public function healthIsStale(): bool
    {
        $checkedAt = $this->health_checked_at;

        if ($checkedAt === null) {
            return true;
        }

        $staleAfter = max(1, (int) config('extensions.health.stale_after', 900));

        return $checkedAt->lt(now()->subSeconds($staleAfter));
    }

    /**
     * Story 56.5 (FR35) — **LA règle d'affichage « Indisponible »**, en UN SEUL
     * énoncé (patron {@see ExtensionSource::offersAvailableExtensions()}, leçon
     * la plus répétée de l'epic : review 56.1 #1 « une garantie qui n'existe que
     * dans la vue n'est pas une garantie », #3 « une règle, un seul énoncé »).
     *
     * Consommée par le lanceur
     * ({@see \App\Services\Extensions\ExtensionLauncherService::tilesFor()}), la
     * bibliothèque et la fiche
     * ({@see \App\Services\Extensions\ExtensionCatalogService}) — et personne
     * d'autre.
     *
     * Trois conditions, aucune facultative :
     *  1. c'est une `app` réellement installée (rien d'autre n'a de backend) ;
     *  2. la dernière observation dit `unreachable` ;
     *  3. cette observation est FRAÎCHE — on ne signale que ce qu'on SAIT.
     *
     * ⚠️ Un badge n'est JAMAIS une autorisation (FR14) : la tuile marquée reste
     * un lien cliquable. L'état peut dater de 5 minutes, et bloquer
     * transformerait un AFFICHAGE en AUTORISATION.
     *
     * ⚠️ Fenêtre `update.sh` : avant `migrate --force`, la colonne n'existe pas
     * et l'accès rend `null` — la comparaison est alors fausse et la méthode
     * rend `false` (pas de badge) SANS lever. La condition 2 court-circuite
     * avant tout calcul de fraîcheur : c'est voulu.
     */
    public function isFlaggedUnreachable(): bool
    {
        return $this->isHealthMonitored()
            && $this->health_status === self::HEALTH_UNREACHABLE
            && ! $this->healthIsStale();
    }

    /**
     * Story 56.5 — ÉCART BRUT entre la version qui TOURNE et celle que le
     * catalogue PUBLIE. C'est un **fait**, pas une décision.
     *
     * ⚠️ Ne pas confondre avec
     * `ExtensionCatalogService::hasUpdateAvailable()` (56.3), qui est la
     * **décision** « une mise à jour est PROPOSABLE » : ce fait-ci PLUS l'état
     * de la source (une source gelée ou dont le catalogue n'a pas pu être
     * vérifié ne propose plus rien). Les deux ne disent pas la même chose et ne
     * doivent pas être interchangés : le doctor CONSTATE un écart de versions
     * (l'opérateur veut le savoir même si la source est tombée), l'UI PROPOSE un
     * bouton.
     *
     * Depuis la review 56.5 #5, `hasUpdateAvailable()` **compose** cette
     * méthode au lieu de recopier son expression : le fait n'a qu'un énoncé,
     * la décision l'utilise.
     */
    public function hasVersionDrift(): bool
    {
        if (! $this->isInstalledApp()) {
            return false;
        }

        $installed = (string) $this->installed_version;

        return $installed !== '' && $installed !== (string) $this->version;
    }

    /**
     * Scopes DEMANDÉS par le manifest — information admin (FR3) en 54.1 :
     * affichés, jamais consommés (les scopes effectifs relèvent des Epics 55/56).
     *
     * @return list<string>
     */
    public function requestedScopes(): array
    {
        return $this->stringList('scopes');
    }

    /**
     * Dépendances déclarées par le manifest (autres extensions requises).
     *
     * @return list<string>
     */
    public function dependencies(): array
    {
        return $this->stringList('dependencies');
    }

    /**
     * Rôles MÉTIER (`admin`/`prof`/`eleve`) auxquels la tuile est destinée.
     * 54.1 STOCKE cette donnée ; c'est la Story 54.3 (lanceur) qui la RÉSOUT.
     * Ce ne sont jamais des `SambaPermission` (AR8).
     *
     * @return list<string>
     */
    public function visibilityRoles(): array
    {
        $visibility = $this->manifest['visibility'] ?? null;
        if (! is_array($visibility) || ! isset($visibility['roles']) || ! is_array($visibility['roles'])) {
            return [];
        }

        return array_values(array_map(static fn ($role): string => (string) $role, $visibility['roles']));
    }

    /**
     * Liste de chaînes d'une clé racine du manifest (absente ⇒ `[]`).
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->manifest[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn ($item): string => (string) $item, $value));
    }
}
