<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\NetworkShare;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Type `drives` (contrat §7, identifiant DÉJÀ figé — NFR12) — **projection en
 * lecture seule** des partages réseau standards SambaEdu vers des montages de
 * lecteur, gérés NATIVEMENT par l'agent (et non plus par l'attribut AD
 * `homeDrive`/`homeDirectory` ni la GPO « lecteurs reseau » legacy).
 *
 * **Pourquoi natif** : le bon `K: = home` venait jusqu'ici du compte AD
 * (appliqué par Windows au logon), pas de SE5. L'agent doit devenir l'autorité
 * sur les lecteurs (successeur de GPO/AD) — sinon deux mécanismes se marchent
 * dessus (l'ancien provider posait un lecteur de CLASSE sur K:, écrasant le home
 * natif pour les élèves). Décision Henri (2026-06-29) : l'agent émet le jeu
 * standard iso-legacy, lettres FIXES.
 *
 * **Lettres figées serveur** (iso-legacy `individuel.php`) :
 *  - `K:` = **home** de l'utilisateur (partage `users`, sous-dossier = login) —
 *    `\\<se4fs>\users\<user>\`. C'est « Mes documents / Bureau ».
 *  - `H:` = **racine du partage `classes`** — `\\<se4fs>\classes\`. L'utilisateur
 *    navigue vers sa/ses classe(s) (`H:\Classe_<nom>\<login>`, ACL POSIX par
 *    élève). On ne cible JAMAIS une classe unique : un user peut en avoir
 *    plusieurs (jusqu'à 3) — un lecteur par classe écraserait les autres.
 *
 * `I:` (Docs) et `L:` (Progs) ne sont **pas** portés : leur usage est couvert
 * autrement en SE5 (fonds d'écran natifs, distribution applicative WPKG) ou
 * relève d'un futur système de partages/ACL (cf. module legacy `acls/`,
 * restauration au déploiement via `/admin/sync-from-ad`).
 *
 * **Story 34.1 — répertoires réseau gérés CONFIGURABLES.** En PLUS du jeu fixe
 * K:/H:, le provider émet un candidat par `network_shares` applicable au
 * {@see TargetContext} : la lettre s'affiche pour TOUTE maille assignée
 * (`User` / `UserGroup` / `WorkstationGroup`) — l'union/dédup/précédence du
 * `StateCompiler` gère tout (ZÉRO modif compilateur). L'accès RÉEL RO/RW est
 * gouverné par l'ACL POSIX côté serveur ({@see \App\Services\Filesystem\NetworkShareService}),
 * PAS par le payload : le payload v1 reste `{letter, unc, label}` INCHANGÉ.
 * Lecture **Postgres only** (relations/ids du contexte) — zéro AD/LdapRecord,
 * zéro re-requête d'appartenance (NFR7, critère Keycloak).
 *
 * **Lettre auto-assignée** : si `network_shares.letter` est null, le provider
 * attribue déterministiquement la première lettre libre du pool `M..Z` (exclut
 * `A,B,C,D,H,I,K,L` + toute lettre déjà émise dans le même set), shares triés
 * par `id` asc. Voir {@see nextFreeLetter()}.
 *
 * **Émis pour toute session user**, indépendamment du `WorkstationEnvironment`
 * (un montage réseau est réseau par nature) et de l'appartenance à une classe.
 * Machine-only (`user` null) → aucun lecteur : un montage dépend du login de
 * session (les shares assignés à un WG ne s'affichent donc qu'EN session user).
 *
 * **Scope `session`** : monté DANS la session user (lettre par-user, UNC du home
 * dépendant du login), appliqué par le compagnon de session.
 *
 * Payload v1 : `{letter, unc, label}` — tokens `<se4fs>`/`<user>` substitués
 * LOCALEMENT par l'agent (iso 27.1). Toujours des strings (§4.1).
 */
final class DrivesStateProvider implements StateProvider
{
    /**
     * Lettres réservées (jamais auto-assignées) : `K:`/`H:` émis ici en dur,
     * `I:`/`L:` legacy (Docs/Progs), `A:`/`B:` floppy, `C:`/`D:` disques locaux.
     *
     * Story 34.2 (finding 34.1 #4, Q4) — `public` : foyer canonique unique de la
     * liste des lettres réservées, CONSOMMÉE par la validation prédictive
     * {@see \App\Services\Filesystem\NetworkShareValidator} (refus à la saisie
     * d'une lettre explicite réservée). Aucune nouvelle abstraction (mémoire
     * `no_overengineered_choices`). L'algorithme d'auto-assignation
     * ({@see resolveLetters()}) reste INCHANGÉ — seule la visibilité de la const
     * a évolué (golden / `FROZEN_STATE_HASH` non affectés).
     */
    public const RESERVED_LETTERS = ['A', 'B', 'C', 'D', 'H', 'I', 'K', 'L'];

    /**
     * Pool sûr d'auto-assignation (`M..Z`) — par construction disjoint des
     * réservées ci-dessus (toutes < `M`), filtré quand même pour robustesse.
     *
     * @var list<string>
     */
    public const LETTER_POOL = ['M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

    public function type(): string
    {
        return NetworkShare::TYPE_DRIVES;
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
     * Jeu standard {K: home, H: classes} pour toute session user PUIS les
     * répertoires réseau configurés applicables. Ordre des candidats aggregate
     * fixé par `sourceId` asc (K=1, H=2, puis les shares — déterminisme du
     * hash). Quand AUCUN `network_shares` n'existe, la sortie est byte-identique
     * au jeu fixe (golden `state.v1.json` / `FROZEN_STATE_HASH` inchangés).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        if ($ctx->user === null) {
            return collect();
        }

        $candidates = [
            // K: — home de l'utilisateur (partage `users`, sous-dossier = login).
            new StateCandidate(
                maille: StateMaille::User,
                payload: [
                    'letter' => 'K:',
                    'unc' => '\\\\<se4fs>\\users\\<user>\\',
                    'label' => 'Mes documents',
                ],
                updatedAt: null,
                sourceId: 1,
            ),
            // H: — racine du partage `classes` (navigation vers la/les classe(s)).
            new StateCandidate(
                maille: StateMaille::Broadcast,
                payload: [
                    'letter' => 'H:',
                    'unc' => '\\\\<se4fs>\\classes\\',
                    'label' => 'Classes',
                ],
                updatedAt: null,
                sourceId: 2,
            ),
        ];

        foreach ($this->networkShareCandidates($ctx) as $candidate) {
            $candidates[] = $candidate;
        }

        return collect($candidates);
    }

    /**
     * Candidats des répertoires réseau applicables au contexte — un candidat par
     * (share × assignation matchante), étiqueté de sa maille. Lecture Postgres
     * PURE : pivot `network_share_assignables` restreint aux ids déjà résolus du
     * {@see TargetContext} (jamais d'AD, jamais de re-requête d'appartenance).
     *
     * @return list<StateCandidate>
     */
    private function networkShareCandidates(TargetContext $ctx): array
    {
        $userId = $ctx->user?->id;
        if ($userId === null) {
            return [];
        }
        $wgIds = $ctx->workstationGroupIds();

        $rows = DB::table('network_share_assignables as nsa')
            ->join('network_shares as ns', 'ns.id', '=', 'nsa.network_share_id')
            ->where(function ($q) use ($userId, $ctx, $wgIds): void {
                $q->where(fn ($qq) => $qq
                    ->where('nsa.assignable_type', User::class)
                    ->where('nsa.assignable_id', $userId));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('nsa.assignable_type', UserGroup::class)
                        ->whereIn('nsa.assignable_id', $ctx->userGroupIds));
                }

                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('nsa.assignable_type', WorkstationGroup::class)
                        ->whereIn('nsa.assignable_id', $wgIds));
                }
            })
            ->orderBy('ns.id')
            ->orderBy('nsa.id')
            ->get([
                'ns.id as share_id',
                'ns.name as share_name',
                'ns.label as share_label',
                'ns.letter as share_letter',
                'ns.directory_name as directory_name',
                'nsa.id as pivot_id',
                'nsa.assignable_type as assignable_type',
                'nsa.assignable_id as assignable_id',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $letterByShare = $this->resolveLetters($rows);

        $candidates = [];
        foreach ($rows as $row) {
            // Un share sans lettre résolue (pool épuisé — cf. resolveLetters) est
            // OMIS (fail-soft tracé), jamais émis avec une lettre invalide.
            $letter = $letterByShare[(int) $row->share_id] ?? null;
            if ($letter === null) {
                continue;
            }
            $label = ($row->share_label === null || $row->share_label === '')
                ? (string) $row->share_name
                : (string) $row->share_label;

            $candidates[] = new StateCandidate(
                maille: $this->mailleFor($row, $ctx),
                payload: [
                    'letter' => $letter,
                    'unc' => '\\\\<se4fs>\\partages\\' . $row->directory_name . '\\',
                    'label' => $label,
                ],
                updatedAt: null,
                // sourceId déterministe ≥ 3 (après K=1/H=2) et INJECTIF : la
                // ligne pivot a un id auto-incrémenté unique.
                sourceId: 2 + (int) $row->pivot_id,
            );
        }

        return $candidates;
    }

    /**
     * Résout la lettre de CHAQUE share applicable, déterministiquement.
     * Première passe : les lettres EXPLICITES (`network_shares.letter`) sont
     * retenues — SAUF si elles tombent sur une lettre réservée (K/H/I/L/A-D),
     * auquel cas elles sont ignorées (warning) et le share bascule en
     * auto-assignation pour ne pas écraser un lecteur fixe (home K:, classes H:).
     * Seconde passe (shares à lettre null OU réservée, `id` asc) : auto-assigne
     * la prochaine lettre libre du pool, en accumulant les lettres déjà émises
     * « dans le même set ».
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $rows
     * @return array<int, string>  share_id → lettre (ex. `'P:'`)
     */
    private function resolveLetters(Collection $rows): array
    {
        // Shares distincts dans l'ordre `id` asc (les rows sont déjà triés).
        $shares = [];
        foreach ($rows as $row) {
            $shares[(int) $row->share_id] ??= $row->share_letter;
        }

        $used = [];
        $resolved = [];

        // Passe 1 : lettres explicites.
        foreach ($shares as $shareId => $rawLetter) {
            $bare = $this->bareLetter($rawLetter);
            if ($bare === null) {
                continue;
            }
            // Garde lettres réservées (piège #4) : une lettre explicite
            // K/H/I/L/A-D écraserait un lecteur fixe (home K:, classes H:) ou un
            // disque local. On NE la résout PAS — le share bascule en
            // auto-assignation (passe 2) sur une lettre sûre du pool, au lieu
            // d'être émis avec une lettre collisionnant silencieusement le home.
            // (La collision lettre↔lettre entre deux répertoires DIFFÉRENTS reste
            // volontairement déléguée à la validation prédictive 34.2 — piège #3.)
            if (in_array($bare, self::RESERVED_LETTERS, true)) {
                Log::channel('agent')->warning(
                    '[DrivesStateProvider] Lettre explicite réservée ignorée (bascule auto-assignation)',
                    [
                        'action_type' => 'agent.drives.reserved_letter_ignored',
                        'share_id' => $shareId,
                        'letter' => $bare . ':',
                    ],
                );

                continue;
            }
            $used[$bare] = true;
            $resolved[$shareId] = $bare . ':';
        }

        // Passe 2 : auto-assignation des shares sans lettre, `id` asc.
        foreach ($shares as $shareId => $rawLetter) {
            if (isset($resolved[$shareId])) {
                continue;
            }
            $letter = $this->nextFreeLetter($used);
            if ($letter === null) {
                // Pool épuisé (> 14 répertoires auto applicables au même
                // contexte) : on n'émet PAS de lettre invalide (l'agent ne
                // pourrait pas monter). Fail-soft mais TRACÉ (cohérent avec le
                // reste du canal agent) — le share est omis.
                Log::channel('agent')->warning(
                    '[DrivesStateProvider] Pool de lettres épuisé — répertoire omis',
                    [
                        'action_type' => 'agent.drives.letter_pool_exhausted',
                        'share_id' => $shareId,
                    ],
                );

                continue;
            }
            $used[$letter] = true;
            $resolved[$shareId] = $letter . ':';
        }

        return $resolved;
    }

    /**
     * Normalise une lettre stockée (`'P:'`, `'p'`, ` P `) en char nu majuscule
     * (`'P'`), ou `null` si vide/non alphabétique.
     */
    private function bareLetter(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = ltrim($raw);
        if ($trimmed === '') {
            return null;
        }
        $char = strtoupper($trimmed[0]);

        return ($char >= 'A' && $char <= 'Z') ? $char : null;
    }

    /**
     * Première lettre libre du pool `M..Z` (hors réservées et hors `$used`), ou
     * `null` si le pool est épuisé. Déterministe (ordre du pool).
     *
     * @param  array<string, bool>  $used  lettres nues majuscules déjà prises
     */
    private function nextFreeLetter(array $used): ?string
    {
        foreach (self::LETTER_POOL as $letter) {
            if (in_array($letter, self::RESERVED_LETTERS, true)) {
                continue;
            }
            if (! isset($used[$letter])) {
                return $letter;
            }
        }

        return null;
    }

    /**
     * Étiquetage de la maille d'une ligne d'assignation. La distinction
     * physique/logique d'un `WorkstationGroup` se fait par les listes du
     * contexte (la requête a déjà restreint aux groupes du poste) —
     * étiquetage, PAS précédence (D2 = compilateur seul).
     */
    private function mailleFor(\stdClass $row, TargetContext $ctx): StateMaille
    {
        return match ($row->assignable_type) {
            User::class => StateMaille::User,
            UserGroup::class => StateMaille::UserGroup,
            WorkstationGroup::class => in_array((int) $row->assignable_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            // Inatteignable via la requête (le WHERE ne ramène que ces 3 types) —
            // garde-fou explicite (iso ShortcutsStateProvider).
            default => throw new \LogicException(
                "assignable_type inattendu pour network_share : {$row->assignable_type}",
            ),
        };
    }
}
