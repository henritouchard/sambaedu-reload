<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\AppKind;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\AppCustomization;
use App\Models\WorkstationGroup;
use App\Services\AppCustomization\AppCustomizationService;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Type `app_config` (contrat §7, identifiant DÉJÀ figé — NFR12,
 * {@see AppCustomization::TYPE_APP_CONFIG}) — projection en LECTURE SEULE des
 * policies d'app résolues (`app_customizations`, story 4.8) vers des candidats
 * d'état (Story 27.4, AC1/AC2).
 *
 * **Le `wallpapers` de cette story.** Contrairement à 27.3/27.3bis (premiers
 * types SANS table métier, catalogue créé), `app_config` est dans le cas
 * 27.1/27.2 : la table métier `app_customizations` + son service de résolution
 * `AppCustomizationService` EXISTENT (4.8). On les LIT, on ne les double pas
 * (créer une 2ᵉ table de policies = doublon de source de vérité, interdit).
 *
 * **Lecture Postgres + config PURE** (NFR7, critère Keycloak — audit
 * re-confirmé en T0). `AppCustomizationService::resolvePoliciesForMachine`
 * applique 6 niveaux entièrement PG + config : template/auto = fichiers FS +
 * `config()` ; défaut étab / WG / UserGroups / User = `AppCustomization::query()`
 * directs (PG) ; `userGroups()` = `BelongsToMany` PG. AUCUN `Cache::`/APCu/AD/
 * `samba-tool` (le cache APCu `CacheAppContextRepository`/`apps.$id` sert
 * l'AUTRE canal, legacy-port `ApplicationScriptsAssembler` — PAS ce chemin).
 *
 * **Sémantique `aggregate` PAR `app_kind`** (décision n° 2). Un poste reçoit la
 * config de PLUSIEURS apps (Firefox ET Thunderbird) → un item par app. Mais
 * pour UNE app, il n'y a qu'UN jeu de policies effectif : la résolution
 * hiérarchique 4.8 a DÉJÀ fusionné les 6 niveaux en UN `policies_json`. Le
 * provider émet donc UN candidat par `app_kind` déjà résolu ; le compilateur ne
 * fait que dédoublonner par contenu (aggregate). Ce n'est PAS au compilateur de
 * départager deux mailles : la fusion 6-niveaux est métier (faite SERVEUR par
 * `resolvePoliciesForMachine`), comme `WallpaperResolver` — pas de la précédence
 * de maille, donc pas une violation de D2.
 *
 * **Scope `machine`** (correctif post-review 2026-06-17, review #1). Le legacy
 * traite Firefox via DEUX mécanismes distincts qui coexistent :
 *   - **Mécanisme A — config** = `policies.json` (marque-pages, page d'accueil,
 *     extensions, proxy), écrit dans
 *     `%ProgramFiles%\Mozilla Firefox\distribution\policies.json` =
 *     **machine-wide, contexte SYSTEM/admin, PAR-PARC** (jamais par-user).
 *     **C'est le périmètre de 27.4.**
 *   - **Mécanisme B — profil user** = jonctions/redirection du dossier profil
 *     vers le home (roaming). **HORS 27.4** (story roaming de suivi).
 * Un `policies.json` est machine-wide, sous Program Files (admin-write) : un
 * compagnon aux droits user ne peut pas l'écrire (ACCESS_DENIED à chaque logon)
 * — d'où la portée **`machine`** (le service SYSTEM écrit, iso le handler
 * `registry` HKLM 27.3). Le par-user de Firefox = le PROFIL (Mécanisme B), PAS
 * `policies.json` : on n'a donc PAS besoin des niveaux user (5-6) dans la
 * résolution. Le provider résout **niveaux 1-4** (template + auto + défaut étab
 * + WG) avec `$user = null`.
 *
 * **Impédance « WG unique 4.8 » vs « mailles Epic 27 » (piège n° 6).**
 * `resolvePoliciesForMachine` attend UN `WorkstationGroup` (sa salle), alors que
 * le {@see TargetContext} expose des LISTES de mailles (salle + parcs). On
 * collapse l'axe WG en feedant le WG **gagnant en précédence** du poste
 * (`logique > physique` — inversion globale story 27.3, alignée sur
 * `StateCompiler::specificity()`) : ce WG représentatif passe au niveau 4 de la
 * chaîne 4.8, et le provider émet EXACTEMENT un candidat par `app_kind`. Sans ce
 * collapse, itérer tous les WG produirait plusieurs items Firefox (un par
 * override de WG) — ce qui contredirait « un item par app ».
 *
 * ⚠️ **Limite connue (review #2, statu quo assumé).** Un poste appartenant à
 * PLUSIEURS parcs logiques avec des policies différentes : seul le WG gagnant
 * (précédence `logique > physique`, puis plus petit id — déterminisme) est
 * résolu ; les autres parcs logiques sont **silencieusement ignorés**.
 * `policies.json` est machine-wide (un fichier par install) : on ne peut pas
 * porter deux configs Firefox concurrentes sur un même poste. Tiebreak documenté
 * ici et dans `docs/agent/state-providers.md`.
 *
 * **Payload v1 (décision n° 1)** : `{app_kind, policies}` — `app_kind` =
 * `firefox`|`thunderbird` ; `policies` = les policies RÉSOLUES CONCRÈTES (le
 * contenu que l'agent écrit dans `policies.json`), JAMAIS un `customization_id`
 * ni un scope (invariant central, iso 27.3). Le payload est NORMALISÉ sans float
 * (contrat §4.1 : un float de policy → string). Une app sans aucune policy
 * résolue émet quand même son item (template + auto donnent toujours un socle ;
 * c'est « voici la config de cette app », pas « pas de config »).
 */
final class AppConfigStateProvider implements StateProvider
{
    /**
     * Rangs d'ordre par `app_kind` (alphabétique), pré-calculés UNE fois
     * (review #5) — déterminisme de `sourceId` pour l'aggregate.
     *
     * @var array<string,int>|null
     */
    private static ?array $kindRanks = null;

    public function __construct(
        private readonly AppCustomizationService $service,
    ) {}

    public function type(): string
    {
        return AppCustomization::TYPE_APP_CONFIG;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function scope(): StateScope
    {
        // MACHINE (correctif post-review #1) : `policies.json` est machine-wide
        // sous Program Files (admin-write) → écrit par le service SYSTEM, jamais
        // par le compagnon user. Résolution PAR PARC (niveaux 1-4), le par-user
        // Firefox = le profil (Mécanisme B / roaming, hors 27.4).
        return StateScope::Machine;
    }

    /**
     * Un candidat PAR `app_kind` configurable (`AppKind::cases()` = Firefox,
     * Thunderbird). Les policies sont résolues SERVEUR (niveaux 1-4 : template +
     * auto + défaut étab + WG gagnant) en feedant `$user = null` — la portée est
     * MACHINE / par-parc (review #1) : le par-user Firefox = le profil
     * (Mécanisme B / roaming, hors 27.4). L'agent étant Windows-cible,
     * `$os = 'windows'`. Étiquetage par maille (du WG représentatif, ou Broadcast
     * si le poste n'a aucun WG) — sans incidence de précédence (aggregate =
     * union), mais cohérent pour les logs/tests.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        [$wg, $maille] = $this->precedenceWinningGroup($ctx);

        $candidates = [];
        foreach (AppKind::cases() as $kind) {
            // `$user = null` : résolution PAR PARC (niveaux 1-4). Le scope MACHINE
            // n'a pas de user en contexte ; les niveaux 5-6 (UserGroups/User) ne
            // sont PAS résolus — le par-user de Firefox passe par le profil
            // (Mécanisme B / roaming, hors 27.4), pas par `policies.json`.
            $policies = $this->service->resolvePoliciesForMachine(
                $wg,
                null,
                $kind,
                'windows',
            );

            $candidates[] = new StateCandidate(
                maille: $maille,
                payload: [
                    'app_kind' => $kind->value,
                    // Policies CONCRÈTES résolues — jamais un id de scope.
                    // Normalisées sans float (contrat §4.1).
                    'policies' => $this->normalizePolicies($policies),
                ],
                updatedAt: $this->latestUpdatedAt($kind, $wg),
                // Ordre aggregate stable : un rang par app_kind (l'ordre n'a pas
                // de signification métier — union — seul son déterminisme compte
                // pour `AggregateHash`/ETag).
                sourceId: $this->kindRank($kind),
            );
        }

        return collect($candidates);
    }

    /**
     * WG représentatif du poste pour la résolution 4.8 (impédance, piège n° 6) :
     * le parc LOGIQUE l'emporte sur la salle PHYSIQUE (inversion globale story
     * 27.3, alignée sur `StateCompiler::specificity()`). À défaut de parc, la
     * salle ; à défaut de tout WG, `null` (la chaîne 4.8 saute simplement le
     * niveau 4). La maille retournée étiquette le candidat.
     *
     * ⚠️ COUPLAGE à tenir en phase avec `StateCompiler::specificity()`
     * (`LogicalGroup` < `PhysicalGroup`) : ce choix local est l'adaptation de
     * l'API mono-WG de 4.8 au modèle multi-mailles d'Epic 27.
     *
     * @return array{0: ?WorkstationGroup, 1: StateMaille}
     */
    private function precedenceWinningGroup(TargetContext $ctx): array
    {
        // Parc logique > salle physique (D-Q3 27.3). À spécificité égale,
        // l'id le plus petit (déterminisme — l'ordre des ids du contexte est
        // déjà trié, cf. TargetContext::ids()).
        if ($ctx->logicalGroupIds !== []) {
            $id = $ctx->logicalGroupIds[0];

            return [WorkstationGroup::query()->find($id), StateMaille::LogicalGroup];
        }

        if ($ctx->physicalGroupIds !== []) {
            $id = $ctx->physicalGroupIds[0];

            return [WorkstationGroup::query()->find($id), StateMaille::PhysicalGroup];
        }

        return [null, StateMaille::Broadcast];
    }

    /**
     * Récence informative du candidat = la dernière modification d'une règle
     * `app_customizations` susceptible d'avoir influé la résolution PAR PARC de
     * CETTE app (défaut étab + WG représentatif — niveaux 3-4, la portée MACHINE
     * ne résout PAS les niveaux user 5-6, review #1/#4). Sert le tiebreak de
     * conflit (sans incidence ici : aggregate dédoublonne par contenu) et trace
     * une fraîcheur cohérente. `null` si aucune règle (template/auto only).
     */
    private function latestUpdatedAt(AppKind $kind, ?WorkstationGroup $wg): ?\DateTimeInterface
    {
        $query = AppCustomization::query()->ofKind($kind);

        $query->where(function ($q) use ($wg): void {
            // Défaut étab (NULL/NULL) — niveau 3.
            $q->orWhere(fn ($qq) => $qq
                ->whereNull('customizable_type')
                ->whereNull('customizable_id'));

            // WG représentatif — niveau 4.
            if ($wg !== null) {
                $q->orWhere(fn ($qq) => $qq
                    ->where('customizable_type', WorkstationGroup::class)
                    ->where('customizable_id', $wg->getKey()));
            }
        });

        $latest = $query->max('updated_at');

        return $latest !== null ? Carbon::parse($latest) : null;
    }

    /**
     * Rang d'ordre stable et injectif par `app_kind` (ordre alphabétique des
     * cases), pré-calculé UNE fois (review #5). L'ordre aggregate final est trié
     * par `sourceId` asc au compilateur ; seul son déterminisme importe (même
     * état = même hash/ETag).
     */
    private function kindRank(AppKind $kind): int
    {
        if (self::$kindRanks === null) {
            $values = array_map(static fn (AppKind $k): string => $k->value, AppKind::cases());
            sort($values, SORT_STRING);
            self::$kindRanks = array_flip($values);
        }

        return self::$kindRanks[$kind->value] ?? 0;
    }

    /**
     * Normalise un arbre de policies pour le contrat §4.1 (zéro float) : tout
     * float est converti en string (un timeout décimal, une version « 1.5 », …).
     * Les int/bool/null/string restent intacts ; objets et listes sont
     * normalisés récursivement. La structure n'est PAS modifiée autrement (le
     * handler agent écrit le contenu À L'OCTET PRÈS de la cible serveur).
     *
     * @param  array<int|string,mixed>  $policies
     * @return array<int|string,mixed>
     */
    private function normalizePolicies(array $policies): array
    {
        $out = [];
        foreach ($policies as $key => $value) {
            $out[$key] = $this->normalizeValue($value);
        }

        return $out;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->normalizePolicies($value);
        }

        if (is_float($value)) {
            // Float interdit au contrat (§4.1) — représentation string stable
            // (jamais de notation exponentielle, jamais de virgule locale).
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return $value;
    }
}
