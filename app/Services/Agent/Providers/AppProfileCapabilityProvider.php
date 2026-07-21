<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\FilePolicyService;
use Illuminate\Support\Collection;
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
 * profil dépend du login), gate d'instance `FilePolicyService::capabilities()
 * ['home']` (rediriger vers une cible non montée n'a aucun sens — AC7). Comme
 * {@see FsAclCapabilityProvider} : la donnée vit dans le `spec` d'une
 * {@see CapabilityProjection} (mécanisme `app_profile`) — c'est le CATALOGUE des
 * applications redirigeables (catalogue-first). L'`is_active` de la capacité
 * gate tout le mécanisme (socle commun — pas d'override par maille : « quel
 * poste » = les assignations, mais ici le socle s'applique à toute session user
 * dès que la capacité est active, iso le jeu K:/H: des lecteurs).
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
     *   - la politique de gestion des fichiers désactive le home (`home=false`,
     *     AC7) — la cible du profil ne serait pas montée ;
     *   - aucune capacité `app_profile` active.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        if ($ctx->user === null) {
            return collect();
        }

        // Gate d'instance (AC7) : le home réseau K: est gouverné par un réglage
        // global (FilePolicyService, /admin/settings/files). Home coupé ⇒ la
        // cible du profil n'existe pas ⇒ aucun item (jamais de config morte).
        if (! FilePolicyService::capabilities()['home']) {
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

            foreach ($this->apps($projection->spec) as $app) {
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
