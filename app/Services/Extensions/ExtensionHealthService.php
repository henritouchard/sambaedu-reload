<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\Extension;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Story 56.5 (FR34, NFR6, NFR9) — **LA SANTÉ DES EXTENSIONS `app`** : sonder un
 * backend, et persister ce qu'on a observé.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  UN SEUL MESUREUR, TROIS LECTEURS
 *
 *  1. `ext:health:check` (toutes les 5 min, `routes/console.php`) : MESURE et
 *     PERSISTE. C'est le seul chemin automatique.
 *  2. Le LANCEUR ({@see ExtensionLauncherService::tilesFor()}) : LIT les
 *     colonnes persistées dans sa requête unique. **Zéro HTTP au rendu** — la
 *     navbar est rendue sur TOUTE page authentifiée, une sonde par tuile et par
 *     page vue serait la violation directe de NFR9.
 *  3. Le DOCTOR et le bouton « Sonder maintenant » de la fiche : sondent EN
 *     DIRECT, parce que ce sont des actes de diagnostic explicites. Le doctor
 *     n'écrit RIEN (règle d'or {@see \App\Doctor\EnvironmentCheck} : aucun side
 *     effect) ; le bouton de la fiche écrit, c'est tout son intérêt.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **ÉCRIVAIN UNIQUE.** Ce service est le SEUL à écrire `extensions.health_*`
 * (colonnes hors `$fillable`, mutées par assignation de propriété explicite) —
 * même doctrine que « `ExtensionLifecycleService` est le seul écrivain de
 * `status` » (54.2). Aucun autre service, aucune commande, aucune vue ne touche
 * ces quatre colonnes.
 *
 * **ZÉRO AUDIT.** La santé est de la TÉLÉMÉTRIE, pas un acte : rien ici n'écrit
 * au journal `extension_audit_logs`. Régime `source_sync_failed` en plus strict
 * encore (doctrine 56.1 : une synchro réussie n'est pas auditée, un dépôt
 * injoignable non plus) — un scheduler qui passe toutes les 5 minutes empilerait
 * 288 lignes par jour et par extension morte, et noierait le journal de
 * conformité sous de la métrologie. Le « dernier incident » de FR34 vit dans les
 * colonnes, écrit à la TRANSITION seulement.
 *
 * **AUCUNE SURFACE PRIVILÉGIÉE.** La sonde est un `GET` HTTP sur la boucle
 * locale — pas un `systemctl status` via le helper root (décision n° 1 de la
 * story). Trois raisons : le helper n'a aucune sous-commande de LECTURE et lui
 * en ajouter une étendrait la surface `sudoers` ; une unité `active` dont le
 * backend ne répond pas est EXACTEMENT la panne qu'on veut voir ; et le
 * `ProxyPass "/ext/<key>" "http://127.0.0.1:<port>/"` fait que sonder cette
 * adresse, c'est sonder ce que l'utilisateur consomme réellement.
 *
 * ⚠️ Ce fichier est scanné par `ExtensionIsolationTest` : AUCUNE primitive
 * d'exécution système n'y est autorisée (précédent `RemoteCatalogSyncService`,
 * qui fait lui aussi du `Http::`). Le seam privilégié
 * ({@see SudoExtensionHelperRunner}) reste l'exemption unique du domaine.
 *
 * NFR15 : rien d'Eloquent ne sort d'ici — uniquement des tableaux plats.
 */
class ExtensionHealthService
{
    /** Borne de `health_last_incident_detail` (alignée sur la migration 56.5). */
    public const INCIDENT_DETAIL_MAX = 200;

    /**
     * SONDE PURE — aucune écriture, aucun effet de bord.
     *
     * **LE seul énoncé de « joignable »** du projet, consommé par
     * {@see self::checkOne()} (qui persiste) ET par
     * {@see \App\Doctor\Checks\Extensions\ExtensionsReachableCheck} (qui ne
     * persiste pas) : les deux ne peuvent donc pas rendre des verdicts
     * divergents (leçon review 56.1 #3).
     *
     * N'importe quelle réponse HTTP — **4xx et 5xx comprises** — prouve la
     * joignabilité : le service RÉPOND, il n'est pas mort. Seule une erreur
     * réseau (connexion refusée, timeout, DNS) vaut « injoignable ». Patron
     * LITTÉRAL de {@see \App\Doctor\Checks\ControlHub\ControlHubReachableCheck}.
     *
     * Les redirections ne sont jamais suivies : une 3xx est une réponse, pas une
     * invitation à sortir de la boucle locale.
     *
     * @return array{reachable: bool, category: string}
     */
    public function probe(Extension $extension): array
    {
        $port = $extension->installed_port;

        if ($port === null) {
            // Ne devrait pas arriver (`isHealthMonitored()` filtre en amont) :
            // fail-closed explicite plutôt qu'une URL malformée.
            return ['reachable' => false, 'category' => 'aucun port assigné'];
        }

        try {
            Http::connectTimeout((int) config('extensions.health.connect_timeout', 2))
                ->timeout((int) config('extensions.health.timeout', 3))
                ->withOptions(['allow_redirects' => false])
                ->get('http://127.0.0.1:'.$port.'/');

            return ['reachable' => true, 'category' => ''];
        } catch (Throwable $e) {
            // Le détail COMPLET va dans le journal serveur, jamais en base ni à
            // l'écran : un message d'exception Guzzle suffixe l'URI appelée
            // (piège review 39.4 #E11), et cette catégorie est lisible par tout
            // admin sur la fiche.
            Log::info('[Extensions] Sonde de santé en échec', [
                'extension' => $extension->key,
                'port' => $port,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ['reachable' => false, 'category' => $this->categorize($e)];
        }
    }

    /**
     * Sonde UNE extension et PERSISTE le résultat.
     *
     * Transitions (décision n° 2 de la story) :
     *  - `health_checked_at` est réécrit à CHAQUE passage — c'est lui qui porte
     *    la fraîcheur, donc la crédibilité de tout le reste ;
     *  - `health_last_incident_*` n'est écrit qu'à la TRANSITION
     *    `''`/`ok` → `unreachable`. Un backend mort depuis trois jours ne doit
     *    pas voir son incident redaté toutes les 5 minutes : ce serait perdre
     *    l'information « depuis quand » ;
     *  - le retour du backend repasse `health_status` à `ok` en CONSERVANT
     *    l'incident : « ça a été indisponible, voici quand » est précisément ce
     *    que FR34 demande d'afficher.
     *
     * Une extension hors périmètre (`link`, `app` non installée, `app` sans
     * port) n'est jamais sondée et rien n'est écrit.
     *
     * @return array{key: string, monitored: bool, reachable: bool, status: string, category: string}
     */
    public function checkOne(Extension $extension): array
    {
        if (! $extension->isHealthMonitored()) {
            return [
                'key' => (string) $extension->key,
                'monitored' => false,
                'reachable' => false,
                'status' => (string) $extension->health_status,
                'category' => '',
            ];
        }

        $probe = $this->probe($extension);

        $previous = (string) $extension->health_status;
        $status = $probe['reachable'] ? Extension::HEALTH_OK : Extension::HEALTH_UNREACHABLE;

        $extension->health_status = $status;
        $extension->health_checked_at = now();

        if ($status === Extension::HEALTH_UNREACHABLE && $previous !== Extension::HEALTH_UNREACHABLE) {
            $extension->health_last_incident_at = now();
            $extension->health_last_incident_detail = Str::limit(
                $probe['category'],
                self::INCIDENT_DETAIL_MAX,
                '',
            );
        }

        $extension->save();

        return [
            'key' => (string) $extension->key,
            'monitored' => true,
            'reachable' => $probe['reachable'],
            'status' => $status,
            'category' => $probe['category'],
        ];
    }

    /**
     * Sonde à la demande depuis l'UI, par IDENTIFIANT.
     *
     * NFR15 (3 couches) : la fiche Livewire ne touche jamais Eloquent — elle
     * appelle ce point d'entrée, qui résout la ligne et délègue à
     * {@see self::checkOne()}. `null` ⇒ identifiant inconnu, ou extension sans
     * backend à sonder (la carte « Santé » n'est alors même pas affichée).
     *
     * @return array{key: string, monitored: bool, reachable: bool, status: string, category: string}|null
     */
    public function checkById(int $extensionId): ?array
    {
        $extension = Extension::query()->find($extensionId);

        if ($extension === null || ! $extension->isHealthMonitored()) {
            return null;
        }

        return $this->checkOne($extension);
    }

    /**
     * Sonde TOUTES les `app` installées, et remet à zéro l'état de santé de
     * celles qui n'en sont plus.
     *
     * Ce second geste est du SELF-HEALING, pas une réparation d'extension : une
     * `app` désinstallée garde sinon un `unreachable` fossilisé, qui ferait
     * mentir la fiche et le doctor pour toujours. Il vit ici — et pas dans
     * `ExtensionLifecycleService::markAppRemoved()` — pour tenir la doctrine
     * 54.2 : un service par story, les précédents à zéro diff. Le prix est un
     * décalage d'au plus 5 minutes sur des colonnes que plus rien ne lit
     * (`isFlaggedUnreachable()` exige `isHealthMonitored()`).
     *
     * Aucun verrou : la sonde est idempotente, et `withoutOverlapping()` borne
     * le scheduler. On ne touche SURTOUT pas au verrou du moteur
     * (`extensions:install-engine`) : sonder n'est pas installer.
     *
     * ⚠️ **Une extension ne fait jamais tomber le passage des autres** (NFR6) :
     * chaque itération est isolée. `probe()` avale déjà ses propres erreurs
     * réseau, mais la PERSISTANCE peut échouer (ligne supprimée en concurrence,
     * contrainte, base indisponible) — et une exception au premier élément
     * laisserait toutes les extensions suivantes non mesurées, donc leur état
     * périmé, donc leurs tuiles muettes. Le comptage `failed` le dit à
     * l'opérateur au lieu de le taire.
     *
     * @return array{checked: int, unreachable: int, failed: int, reset: int, unreachable_keys: list<string>}
     */
    public function checkAll(): array
    {
        $checked = 0;
        $failed = 0;
        $unreachableKeys = [];

        foreach ($this->monitoredExtensions() as $extension) {
            try {
                $result = $this->checkOne($extension);
            } catch (Throwable $e) {
                $failed++;
                Log::error('[Extensions] Sonde de santé NON PERSISTÉE — les autres extensions continuent', [
                    'extension' => $extension->key,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $checked++;

            if (! $result['reachable']) {
                $unreachableKeys[] = $result['key'];
            }
        }

        return [
            'checked' => $checked,
            'unreachable' => count($unreachableKeys),
            'failed' => $failed,
            'reset' => $this->resetStaleOwners(),
            'unreachable_keys' => $unreachableKeys,
        ];
    }

    /**
     * Les extensions à sonder : `app` + `integrated` + `installed_port` non nul.
     *
     * Le filtre est en SQL (indexable, table minuscule) et non en PHP : c'est le
     * même périmètre que `tilesFor()`, exprimé dans les mêmes termes.
     *
     * @return \Illuminate\Support\Collection<int, Extension>
     */
    private function monitoredExtensions()
    {
        return Extension::query()
            ->where('type', ExtensionType::App)
            ->where('status', ExtensionStatus::Integrated)
            ->whereNotNull('installed_port')
            ->orderBy('key')
            ->get();
    }

    /**
     * Efface l'état de santé des lignes qui portent une trace de sonde alors
     * qu'elles n'ont plus de backend (désinstallées, redevenues `available`,
     * port libéré).
     *
     * @return int Nombre de lignes remises à zéro.
     */
    private function resetStaleOwners(): int
    {
        try {
            return $this->doResetStaleOwners();
        } catch (Throwable $e) {
            // Du ménage sur des colonnes que plus rien ne lit : son échec ne doit
            // pas annuler des mesures déjà faites et déjà persistées.
            Log::warning('[Extensions] Remise à zéro des états de santé orphelins impossible', [
                'exception' => $e::class,
            ]);

            return 0;
        }
    }

    /** @return int Nombre de lignes remises à zéro. */
    private function doResetStaleOwners(): int
    {
        $candidates = Extension::query()
            ->where(function ($query): void {
                $query
                    ->where('health_status', '<>', Extension::HEALTH_UNKNOWN)
                    ->orWhereNotNull('health_checked_at')
                    ->orWhereNotNull('health_last_incident_at');
            })
            ->get()
            ->filter(fn (Extension $extension): bool => ! $extension->isHealthMonitored());

        foreach ($candidates as $extension) {
            $extension->health_status = Extension::HEALTH_UNKNOWN;
            $extension->health_checked_at = null;
            $extension->health_last_incident_at = null;
            $extension->health_last_incident_detail = '';
            $extension->save();
        }

        return $candidates->count();
    }

    /**
     * Catégorie COURTE et STABLE d'un échec de sonde.
     *
     * Jamais `$e->getMessage()` : Guzzle y suffixe l'URI appelée, et la règle
     * `last_error` de 56.1 interdit URL et secret dans une colonne lisible par
     * tout admin. On ne garde que la NATURE de l'échec — c'est tout ce dont
     * l'admin a besoin pour savoir s'il doit regarder le service ou le réseau.
     */
    private function categorize(Throwable $e): string
    {
        return match (class_basename($e)) {
            'ConnectionException' => 'backend injoignable (connexion refusée ou expirée)',
            'RequestException' => 'requête refusée par le backend',
            default => 'erreur réseau ('.Str::limit(class_basename($e), 60, '').')',
        };
    }
}
