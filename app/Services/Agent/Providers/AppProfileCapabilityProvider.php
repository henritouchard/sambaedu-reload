<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 36.5 — provider `app_profile` : redirection du profil applicatif
 * (Firefox, Thunderbird…) vers le home réseau de l'utilisateur (contrat §7.11).
 * SIXIÈME mécanisme HORS-REGISTRE, mais le SEUL de portée **Session** (le
 * COMPAGNON applique — un profil applicatif est une donnée d'UTILISATEUR, pas de
 * machine ; le service SYSTEM ne le touche jamais). Report du mécanisme SE4
 * `Roaming→Server` (`applications.inc.php:538` — lien de dossier vers le home,
 * accès direct serveur SANS copie : un profil Firefox à gros cache/bases sqlite
 * ferait exploser le temps de logon s'il transitait par la copie du profil
 * itinérant).
 *
 * **Patron hybride Drives × capacité (AC2).** Comme {@see DrivesStateProvider} :
 * scope `Session`, maille `User`, `itemsFor()` VIDE si `$ctx->user === null` (le
 * profil dépend du login). Comme {@see FsAclCapabilityProvider} : la donnée vit
 * dans le `spec` d'une {@see CapabilityProjection} (mécanisme `app_profile`) —
 * c'est le CATALOGUE des applications redirigeables (catalogue-first).
 *
 * **Story 36.7 — DÉCORRÉLATION du gate K: (AC3).** Le gate d'instance
 * `FilePolicyService::capabilities()['home']` de la 36.5 (AC7) est SUPPRIMÉ :
 * le lien symbolique pointe DIRECTEMENT l'UNC, Firefox le traverse avec les
 * credentials de session — la lettre K: est purement cosmétique. Couper K: (home
 * invisible dans l'Explorateur) ne doit PAS couper la redirection de profil (cas
 * d'usage explicite : profils suivis, home masqué). La politique fichiers reste
 * un gating CLIENT (ce que l'agent MONTE), jamais une ACL serveur — elle ne
 * gouverne plus ce mécanisme. AC7 de la 36.5 formellement amendé (contrat §7.11).
 *
 * **Story 36.7 — SORTIE DU SOCLE : activation par groupe d'utilisateurs (AC4).**
 * La 36.5 ne consommait que `is_active` (tout-ou-rien à l'instance). Désormais le
 * provider résout les ASSIGNATIONS d'IDENTITÉ D'UTILISATEUR de la capacité (pivot
 * `capability_assignments`, mailles `User`/`UserGroup`) — {@see isEnabledForUser()}.
 * Les mailles poste/parc (`Workstation`/`WorkstationGroup`) sont volontairement
 * IGNORÉES : un profil applicatif SUIT l'utilisateur inter-postes (finalité de la
 * story amont) — le gater par machine le rendrait poste-dépendant. Défaut
 * d'instance (aucune assignation) = `default_value` (aujourd'hui `on` —
 * comportement 36.5 préservé au déploiement).
 *
 * **Tokens, jamais de littéral résolu côté serveur (AC3).** Le `server` du
 * catalogue est RELATIF au home (`.mozilla\firefox\managed.default`) ; le
 * provider émet le TOKEN `\\<se4fs>\users\<user>\<server>` — iso
 * {@see DrivesStateProvider} K:, jamais un chemin résolu. L'agent réutilise sa
 * fonction de substitution unique (`substituteTokens`).
 *
 * **Nom de profil `managed.default` (AC4).** NEUF, STABLE, NON versionné, HORS
 * radical `sambaedu` : jamais matché par la garde `referencesSambaeduProfile()`
 * du mécanisme `legacy_cleanup` (38.3) — les deux canaux coexistent sans se
 * battre à chaque logon. Le libellé exact vit dans le catalogue (`profile_name`).
 * La porte d'évolution passe par un marqueur `.se-profile-version` DANS le
 * profil (côté agent), jamais par un nom versionné (perte de signets silencieuse).
 *
 * Lecture Postgres PURE (NFR7, critère Keycloak) : capacités actives × leur
 * projection `app_profile` windows. Zéro AD/LdapRecord.
 */
final class AppProfileCapabilityProvider implements StateProvider
{
    /**
     * Préfixe token du home réseau (iso {@see DrivesStateProvider} K:). Le
     * `server` du catalogue (relatif au home) y est concaténé — l'agent
     * substitue `<se4fs>`/`<user>` localement.
     */
    public const HOME_TOKEN_PREFIX = '\\\\<se4fs>\\users\\<user>\\';

    public function type(): string
    {
        return CapabilityProjection::MECHANISM_APP_PROFILE;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function scope(): StateScope
    {
        return StateScope::Session;
    }

    /**
     * Un item par application redirigeable du catalogue, maille User. VIDE si :
     *   - contexte machine-only (`$ctx->user === null`) — un profil dépend du
     *     login de session (iso {@see DrivesStateProvider::itemsFor()}) ;
     *   - aucune capacité `app_profile` active ;
     *   - la capacité n'est pas ACTIVÉE pour cet utilisateur (Story 36.7, AC4 —
     *     assignations d'identité, {@see isEnabledForUser()}).
     * Une entrée de catalogue `enabled: false` (Story 36.7, AC2) n'émet aucun item
     * (« off réel » — jamais de suppression physique côté UI). Le gate K: de la
     * 36.5 (AC7) est SUPPRIMÉ (Story 36.7, AC3 — décorrélation).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        if ($ctx->user === null) {
            return collect();
        }

        $capabilities = Capability::query()
            ->where('capabilities.is_active', true)
            ->whereHas('projections', function ($q): void {
                $q->where('os', Capability::OS_WINDOWS)
                    ->where('mechanism', CapabilityProjection::MECHANISM_APP_PROFILE);
            })
            ->with(['projections' => function ($q): void {
                $q->where('os', Capability::OS_WINDOWS)
                    ->where('mechanism', CapabilityProjection::MECHANISM_APP_PROFILE);
            }])
            ->orderBy('id')
            ->get();

        if ($capabilities->isEmpty()) {
            return collect();
        }

        $candidates = [];
        $sourceId = 0;

        // Dédup INTER-capacités par `link` normalisé (C3, post-review) : deux
        // capacités actives visant le même lien (donc le même profiles.ini local)
        // se battraient à chaque logon. PREMIER GAGNANT (ordre stable par
        // capabilities.id ci-dessus), collision tracée. `link` normalisé casse
        // (chemins Windows).
        $claimedLinks = [];

        foreach ($capabilities as $capability) {
            /** @var CapabilityProjection|null $projection */
            $projection = $capability->projections->first();
            if ($projection === null) {
                continue;
            }

            // Sortie du socle (Story 36.7, AC4) : la capacité doit être ACTIVÉE
            // pour CET utilisateur (assignations d'identité). Non activée ⇒ aucun
            // item de cette capacité (le profil local redevient le comportement —
            // vanilla 38.3 ; rien à écrire, cf. AC5 « type absent = pas d'action »).
            if (! $this->isEnabledForUser($capability, $ctx)) {
                continue;
            }

            foreach ($this->apps($projection->spec) as $app) {
                // « off réel » par entrée (Story 36.7, AC2) : une entrée désactivée
                // dans le catalogue n'émet plus d'item (le lien/les ini déjà posés
                // restent — pas de nettoyage, AC5).
                if (($app['enabled'] ?? true) === false) {
                    continue;
                }

                $payload = $this->payloadFor($app);
                if ($payload === null) {
                    // Entrée de catalogue incomplète (guard d'authoring défaillant
                    // en amont) : non émise, tracée — jamais un payload partiel.
                    Log::channel('agent')->warning(
                        '[AppProfileCapabilityProvider] Entrée de catalogue incomplète ignorée',
                        [
                            'action_type' => 'agent.app_profile.catalog_entry_skipped',
                            'capability_id' => $capability->id,
                        ],
                    );

                    continue;
                }

                $linkKey = mb_strtolower($payload['link']);
                if (isset($claimedLinks[$linkKey])) {
                    // Collision inter-capacités : le lien est déjà revendiqué par
                    // une capacité antérieure (premier gagnant). Item ignoré, les
                    // DEUX capacités en collision journalisées.
                    Log::channel('agent')->warning(
                        '[AppProfileCapabilityProvider] Lien app_profile en collision inter-capacités ignoré (premier gagnant)',
                        [
                            'action_type' => 'agent.app_profile.link_collision_skipped',
                            'link' => $payload['link'],
                            'kept_capability_id' => $claimedLinks[$linkKey],
                            'skipped_capability_id' => $capability->id,
                        ],
                    );

                    continue;
                }
                $claimedLinks[$linkKey] = $capability->id;

                $sourceId++;
                $candidates[] = new StateCandidate(
                    maille: StateMaille::User,
                    payload: $payload,
                    updatedAt: $capability->updated_at,
                    sourceId: $sourceId,
                );
            }
        }

        return collect($candidates);
    }

    /**
     * La capacité est-elle ACTIVÉE pour cet utilisateur ? (Story 36.7, AC4 —
     * sortie du socle). Résolution par les assignations d'IDENTITÉ D'UTILISATEUR
     * (pivot `capability_assignments`), mailles `User` puis `UserGroup` — jamais
     * `Workstation`/`WorkstationGroup` (un profil applicatif suit l'utilisateur
     * inter-postes, pas la machine — le poste-dépendant contredirait la finalité).
     *
     * Précédence iso {@see \App\Services\Agent\StateCompiler::specificity()} :
     *   - une assignation `User` (rang 0, la plus spécifique) DÉCIDE seule si
     *     présente — assignation individuelle hors UI 36.7 mais l'infra la porte ;
     *   - sinon les assignations `UserGroup` (rang 1) suivent la sémantique OR
     *     (AC4 « un utilisateur couvert par AU MOINS UNE assignation on reçoit les
     *     items ; couvert uniquement par du off ⇒ exclu ») ;
     *   - aucune assignation d'identité ⇒ DÉFAUT D'INSTANCE = `default_value`
     *     (aujourd'hui `on` — comportement 36.5 préservé ; basculer à `off` inverse
     *     la politique sans code, AC4).
     *
     * `value` du pivot nullable : une ligne à `value = null` vaut le défaut de la
     * capacité (iso {@see AbstractCapabilityStateProvider::itemsFor()}). Lecture
     * Postgres PURE, restreinte aux ids déjà résolus du {@see TargetContext}.
     *
     * Story 36.7 (review #4) : UNE SEULE requête combinée (User ∪ UserGroup),
     * iso l'esprit de {@see AbstractCapabilityStateProvider::resolveOverrides()}
     * (qui n'est pas réutilisable ici — privé, et il agrège les mailles poste/parc
     * qu'`app_profile` doit IGNORER). La précédence est appliquée en PHP.
     */
    private function isEnabledForUser(Capability $capability, TargetContext $ctx): bool
    {
        $default = (string) $capability->default_value;

        // Une requête : les assignations d'identité (User + UserGroup) de cette
        // capacité, restreintes aux ids déjà résolus du contexte.
        $rows = DB::table('capability_assignments')
            ->where('capability_id', $capability->id)
            ->where(function ($q) use ($ctx): void {
                $q->where(fn ($qq) => $qq
                    ->where('assignable_type', User::class)
                    ->where('assignable_id', $ctx->user->id));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('assignable_type', UserGroup::class)
                        ->whereIn('assignable_id', $ctx->userGroupIds));
                }
            })
            ->get(['assignable_type', 'value']);

        // Maille User (rang 0) — décide SEULE si présente (même à `value` null :
        // une ligne User existe ⇒ repli défaut, jamais chute vers le groupe).
        $userRow = $rows->firstWhere('assignable_type', User::class);
        if ($userRow !== null) {
            return $this->isOn((string) ($userRow->value ?? $default));
        }

        // Maille UserGroup (rang 1) — sémantique OR : au moins une assignation
        // effective `on` suffit ; couvert UNIQUEMENT par du off ⇒ exclu (AC4).
        $groupRows = $rows->where('assignable_type', UserGroup::class);
        if ($groupRows->isNotEmpty()) {
            foreach ($groupRows as $row) {
                if ($this->isOn((string) ($row->value ?? $default))) {
                    return true;
                }
            }

            return false;
        }

        // Défaut d'instance (aucune assignation d'identité).
        return $this->isOn($default);
    }

    /** Une valeur de capacité effective vaut-elle « activé » ? (toggle `on`). */
    private function isOn(string $value): bool
    {
        return $value === Capability::TOGGLE_ON;
    }

    /**
     * Entrées `apps[]` d'un `spec` (défensif : spec inattendue = liste vide).
     *
     * @return list<array<string,mixed>>
     */
    private function apps(mixed $spec): array
    {
        if (! is_array($spec) || ! isset($spec['apps']) || ! is_array($spec['apps'])) {
            return [];
        }

        return array_values(array_filter($spec['apps'], 'is_array'));
    }

    /**
     * Payload CONCRET d'une entrée de catalogue — chemin serveur en TOKEN. Les
     * champs minimaux manquants ⇒ `null` (entrée non émise, défensif). `link`,
     * `profile_name` verbatim ; `server` PRÉFIXÉ du token home. `install_hash` et
     * `cache_local` OPTIONNELS (émis seulement si non vides — absence = pas de
     * section Install / pas d'épinglage de cache).
     *
     * @param  array<string,mixed>  $app
     * @return array<string,string>|null
     */
    private function payloadFor(array $app): ?array
    {
        $appId = trim((string) ($app['app'] ?? ''));
        $link = trim((string) ($app['link'] ?? ''));
        $server = trim((string) ($app['server'] ?? ''));
        $profileName = trim((string) ($app['profile_name'] ?? ''));

        if ($appId === '' || $link === '' || $server === '' || $profileName === '') {
            return null;
        }

        $payload = [
            'app' => $appId,
            // `link` = chemin RELATIF au profil Windows (verbatim, l'agent le
            // résout contre %USERPROFILE%).
            'link' => $link,
            // `server` = TOKEN `\\<se4fs>\users\<user>\<server-relatif>` (AC3) —
            // jamais résolu ici. Backslash de jointure normalisé.
            'server' => self::HOME_TOKEN_PREFIX . ltrim($server, '\\'),
            'profile_name' => $profileName,
        ];

        $installHash = trim((string) ($app['install_hash'] ?? ''));
        if ($installHash !== '') {
            $payload['install_hash'] = $installHash;
        }

        $cacheLocal = trim((string) ($app['cache_local'] ?? ''));
        if ($cacheLocal !== '') {
            $payload['cache_local'] = $cacheLocal;
        }

        return $payload;
    }
}
