<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Enums\WorkstationEnvironment;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\FilePolicyService;
use Illuminate\Support\Collection;

/**
 * Type `shortcuts` (contrat §7, identifiant DÉJÀ figé — NFR12) — projection en
 * lecture seule des règles de raccourcis (`shortcuts` × `shortcut_assignables`)
 * vers des candidats d'état (Story 27.1, AC1/AC2/AC3).
 *
 * **Le fix définitif du Bug C** : le chemin du bureau cible n'est plus une
 * branche figée dans un `.cmd` legacy (pansement réseau `4e5a152`) mais une
 * donnée du domaine — résolue CÔTÉ SERVEUR (décision n° 3) via le
 * {@see WorkstationEnvironmentResolver} (26.1) : bureau RÉSEAU si le parc est
 * `shared_local`, bureau LOCAL si `personal_local`/`nomade`. L'agent reste
 * bête : il pose le `.lnk` au `desktop_path` reçu (tokens `<se4fs>`/`<user>`
 * substitués localement).
 *
 * **Story 27.21** : le bureau RÉSEAU exige EN PLUS que le home soit accessible
 * ({@see FilePolicyService::capabilities()} clé `home`) — un
 * bureau réseau posé alors que K: est coupé n'est jamais vu par l'utilisateur.
 * Voir {@see self::desktopPathFor()}.
 *
 * **Story 27.21 (arbitrage option A)** : le serveur émet EN PLUS la liste des
 * emplacements Bureau à BALAYER ({@see self::desktopSweepPathsFor()}, champ
 * `desktop_sweep_paths`). POSE et BALAYAGE sont deux notions distinctes :
 * l'agent pose au seul `desktop_path`, mais il ne nettoie QUE les emplacements
 * que le serveur lui nomme — il n'en invente aucun. Sans cela, un poste
 * perdir/nomade balayait le Bureau réseau, emplacement PARTAGÉ entre tous les
 * postes de l'utilisateur, et y supprimait les `.lnk` d'un poste de classe.
 *
 * **Lecture Postgres PURE** (NFR7, critère Keycloak) : l'ancien canal legacy
 * (supprimé en 27.14) lisait `ad_users`/`ad_user_groups` (CN AD) via
 * `whereJsonContains` + cache APCu — INTERDIT ici. Ce provider ne
 * touche JAMAIS l'AD : il lit le pivot polymorphe `shortcut_assignables`
 * (WorkstationGroup + Workstation + UserGroup + User — ciblage MVP pivot SQL,
 * décision n° 8) restreint aux ids déjà résolus du {@see TargetContext}. Les
 * colonnes `ad_users`/`ad_user_groups` ne sont PAS lues (hors-scope, NFR7).
 *
 * **Sémantique `aggregate`** (union, décision n° 4) : un poste reçoit l'union
 * des raccourcis de toutes ses mailles. Le provider étiquette ses candidats
 * BRUTS (peut produire des doublons quand un même raccourci est assigné au parc
 * ET au poste) — zéro précédence, zéro tri, zéro dédup : la dédup par contenu
 * vit dans le `StateCompiler` SEUL (D2).
 *
 * **Scope `machine_user`** (décision n° 1) : le set de raccourcis dépend du
 * user, mais le CHEMIN du bureau dépend du POSTE (`WorkstationEnvironment`) —
 * le calcul est un croisement (poste, user), compilé par couple.
 *
 * Payload v1 (décision n° 6) : `{name, target, args, icon, place, desktop_path,
 * desktop_sweep_paths}` — `desktop_path` présent uniquement si `place=desktop` ;
 * `desktop_sweep_paths` (liste, Story 27.21) sur TOUS les items. Pas de float (§4.1).
 * Story 27.7 (AC2) : payload étendu de `{icon_asset, icon_checksum}` quand
 * l'icône est un NOM NU uploadé content-addressed (champs ajoutés,
 * forward-compatible — l'agent dérive l'URL statique).
 */
final class ShortcutsStateProvider implements StateProvider
{
    /**
     * Bureau RÉSEAU (redirigé sur le home de l'utilisateur). Tokens `<se4fs>` /
     * `<user>` substitués LOCALEMENT par l'agent. Backslash final = convention
     * legacy. **Seul le serveur** connaît ce gabarit : l'agent ne le dérive
     * JAMAIS lui-même, il le reçoit (`desktop_path` pour la POSE,
     * `desktop_sweep_paths` pour le BALAYAGE — Story 27.21, option A).
     */
    private const DESKTOP_PATH_NETWORK = '\\\\<se4fs>\\users\\<user>\\Bureau\\';

    /** Bureau LOCAL du profil Windows (jamais de dépendance réseau). */
    private const DESKTOP_PATH_LOCAL = '%USERPROFILE%\\Desktop\\';

    public function __construct(
        private readonly WorkstationEnvironmentResolver $environmentResolver,
    ) {}

    public function type(): string
    {
        return Shortcut::TYPE_SHORTCUTS;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Aggregate;
    }

    public function scope(): StateScope
    {
        return StateScope::MachineUser;
    }

    /**
     * Un couple (raccourci actif × assignation applicable au contexte) = un
     * candidat. Le `desktop_path` est résolu UNE fois pour le poste (donnée
     * machine) puis injecté dans chaque candidat `place=desktop` (le set
     * dépend du user, le chemin du poste — décision n° 1/n° 3).
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        $wgIds = $ctx->workstationGroupIds();

        // L'environnement du parc est résolu UNE SEULE FOIS ici : il gouverne à
        // la fois l'emplacement de POSE (`desktop_path`) et les emplacements de
        // BALAYAGE (`desktop_sweep_paths`) — deux notions distinctes, une seule
        // résolution (contrainte de réutilisation 27.21 : ne jamais interroger
        // le WorkstationEnvironmentResolver deux fois pour le même contexte).
        $environment = $this->environmentResolver->resolveForGroupIds($wgIds);
        $homeEnabled = FilePolicyService::capabilities()['home'];

        $desktopPath = $this->desktopPathFor($environment, $homeEnabled);
        $desktopSweepPaths = $this->desktopSweepPathsFor($environment);

        $rows = Shortcut::query()
            ->where('shortcuts.is_active', true)
            ->join('shortcut_assignables', 'shortcuts.id', '=', 'shortcut_assignables.shortcut_id')
            ->where(function ($q) use ($ctx, $wgIds): void {
                if ($wgIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', WorkstationGroup::class)
                        ->whereIn('shortcut_assignables.assignable_id', $wgIds));
                }

                $q->orWhere(fn ($qq) => $qq
                    ->where('shortcut_assignables.assignable_type', Workstation::class)
                    ->where('shortcut_assignables.assignable_id', $ctx->workstation->id));

                if ($ctx->userGroupIds !== []) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', UserGroup::class)
                        ->whereIn('shortcut_assignables.assignable_id', $ctx->userGroupIds));
                }

                if ($ctx->user !== null) {
                    $q->orWhere(fn ($qq) => $qq
                        ->where('shortcut_assignables.assignable_type', User::class)
                        ->where('shortcut_assignables.assignable_id', $ctx->user->id));
                }
            })
            ->get([
                'shortcuts.id',
                'shortcuts.name',
                'shortcuts.place',
                'shortcuts.windows_link',
                'shortcuts.windows_args',
                'shortcuts.windows_icon',
                'shortcuts.icon_path',
                'shortcuts.icon_asset',
                'shortcuts.icon_checksum',
                'shortcuts.updated_at',
                'shortcut_assignables.assignable_type',
                'shortcut_assignables.assignable_id',
            ]);

        return $rows->map(fn (Shortcut $row): StateCandidate => new StateCandidate(
            maille: $this->mailleFor($row, $ctx),
            payload: $this->payloadFor($row, $desktopPath, $desktopSweepPaths),
            updatedAt: $row->updated_at,
            sourceId: (int) $row->id,
        ));
    }

    /**
     * Chemin du bureau résolu côté SERVEUR (décision n° 3, fix Bug C). Tokens
     * `<se4fs>` (nom serveur de fichiers) et `<user>` (login courant) laissés
     * au payload : l'agent les substitue LOCALEMENT (aucune fuite de secret,
     * aucune dépendance réseau au calcul). Backslash final = convention legacy.
     *
     * Le mapping environnement→chemin vit ici (pas dans l'enum, pas dans
     * l'agent) — l'agent reste bête. **SEULE porte** de résolution du bureau :
     * aucune autre branche réseau/local n'existe côté serveur (Story 27.21).
     *
     * **Story 27.21 — UN facteur ajouté : la politique home (K:).** Le bureau
     * RÉSEAU vit dans le home de l'utilisateur (`\\<se4fs>\users\<user>\`) :
     * quand l'admin coupe l'accès au home (`/admin/settings/files`,
     * {@see FilePolicyService::capabilities()} clé `home`), la session affiche
     * le bureau LOCAL et des `.lnk` posés en réseau seraient INVISIBLES (constat
     * terrain 2026-07-22). On ne pousse jamais une donnée vers un emplacement
     * que l'utilisateur ne peut pas atteindre : `home=false` ⇒ bureau LOCAL,
     * quel que soit l'environnement du parc.
     *
     * Symétrie assumée avec `app_profile` (36.7) : là la redirection de profil a
     * été DÉCORRÉLÉE de K: (le profil suit toujours, l'UNC reste joignable sans
     * montage) ; ici le bureau SUIT K: — dans les deux cas on place la donnée là
     * où l'utilisateur peut effectivement la voir.
     *
     * Lecture canonique iso {@see DrivesStateProvider}
     * (appel statique direct, aucun cache — le réglage est global d'instance).
     *
     * ⚠️ POSE ≠ BALAYAGE : cette méthode résout le seul emplacement où l'agent
     * POSE les `.lnk`. Les emplacements qu'il BALAIE (pour y supprimer les `.lnk`
     * gérés sortis des règles) sont une autre décision, prise par
     * {@see self::desktopSweepPathsFor()} — ne pas confondre les deux.
     */
    private function desktopPathFor(WorkstationEnvironment $environment, bool $homeEnabled): string
    {
        return match ($environment) {
            // Poste partagé : bureau redirigé RÉSEAU (le défaut du pansement Bug
            // C, mais désormais PARAMÉTRABLE par parc et non figé) — SEULEMENT
            // si le home est accessible, sinon le bureau réseau est invisible.
            WorkstationEnvironment::SharedLocal => $homeEnabled
                ? self::DESKTOP_PATH_NETWORK
                : self::DESKTOP_PATH_LOCAL,
            // Perdir / nomade : bureau LOCAL du profil — le `.lnk` est posé
            // dans le profil de l'utilisateur, pas sur le partage réseau.
            WorkstationEnvironment::PersonalLocal,
            WorkstationEnvironment::Nomade => self::DESKTOP_PATH_LOCAL,
        };
    }

    /**
     * Emplacements Bureau que l'agent doit BALAYER (`desktop_sweep_paths`,
     * Story 27.21 — arbitrage « option A », le serveur pilote).
     *
     * **Pourquoi le serveur et pas l'agent (finding 🔴 #1 de la review 27.21).**
     * Le Bureau RÉSEAU `\\<se4fs>\users\<user>\Bureau\` vit dans le home : c'est
     * un emplacement PAR UTILISATEUR, PARTAGÉ entre TOUS ses postes. Le
     * desired-state, lui, est compilé par couple (poste, user). Un agent qui
     * déciderait SEUL de balayer cet emplacement y supprimerait les `.lnk`
     * gérés légitimement posés par un AUTRE poste du même utilisateur (le
     * portable perdir d'un prof effaçant les raccourcis que le poste de classe
     * vient de poser, et réciproquement — ping-pong permanent sur un partage de
     * production). Seul le serveur connaît l'environnement du parc, donc
     * l'autorité :
     *
     *  - `SharedLocal`             ⇒ `[réseau, local]` — le double-balayage
     *    anti-orphelins de l'AC2/AC3 : une bascule de la politique home ne laisse
     *    jamais de `.lnk` géré à l'emplacement devenu inactif.
     *  - `PersonalLocal` / `Nomade` ⇒ `[local]` SEULEMENT — ces postes n'ont
     *    aucune autorité sur le Bureau réseau et ne doivent JAMAIS y toucher.
     *
     * **Indépendant de la politique home** — délibérément : basculer K: ne change
     * QUE l'emplacement de POSE. Les deux emplacements d'un parc partagé restent
     * balayés dans les deux états, sinon celui qui vient d'être abandonné ne
     * serait plus jamais nettoyé (c'est exactement le scénario cible de l'AC3).
     * Effet de bord utile : couper K: ne fait bouger qu'un champ du payload.
     *
     * L'ORDRE est fixe (réseau puis local) : la liste est hachée telle quelle
     * (les listes ne sont pas triées par {@see \App\Services\Agent\StateHasher}),
     * un ordre stable garantit un hash stable.
     *
     * @return list<string>
     */
    private function desktopSweepPathsFor(WorkstationEnvironment $environment): array
    {
        return match ($environment) {
            WorkstationEnvironment::SharedLocal => [self::DESKTOP_PATH_NETWORK, self::DESKTOP_PATH_LOCAL],
            WorkstationEnvironment::PersonalLocal,
            WorkstationEnvironment::Nomade => [self::DESKTOP_PATH_LOCAL],
        };
    }

    /**
     * Payload v1 (décision n° 6, étendu Story 27.7). `desktop_path` présent
     * UNIQUEMENT pour `place=desktop`. `target` = la cible (exe/URL), `args` =
     * arguments, `icon` = chemin d'icône (windows_icon prioritaire, fallback
     * icon_path). Toujours des strings (jamais de float, §4.1).
     *
     * **Story 27.7 — distinction nom nu / chemin réel (AC2, piège n° 3).**
     * Le champ `icon` peut valoir un CHEMIN réel (`firefox.exe,0`,
     * `%APPDATA%\x.ico` — posé tel quel) OU le NOM NU d'une icône UPLOADÉE
     * (`Calculatrice` — pas un chemin, le `.ico` réel vit côté serveur). On
     * reproduit la détection legacy `!preg_match('#[\\/.,%]#', $icon)`
     * (pas de séparateur `\ / . , %` = nom nu — regex iso-legacy conservée).
     * Si c'est un nom nu ET qu'un asset content-addressed existe en base
     * (`icon_asset` non null) → on émet `{icon_asset, icon_checksum}` (PAS
     * d'URL, décision n° 4 — l'agent dérive l'URL) à CÔTÉ de `icon` (champs
     * ajoutés, forward-compatible). L'agent préfère l'asset local content-
     * addressed ; faute d'asset téléchargé il retombe gracieusement (pas de
     * « feuille blanche », jamais une icône cassée). Un nom nu SANS asset
     * backfillé (`icon_asset` null) → `icon` brut seul (ancien comportement,
     * jamais un asset cassé).
     *
     * **Story 27.21 — `desktop_sweep_paths` (champ additif, §9).** Contrairement
     * à `desktop_path` (présent seulement pour `place=desktop`), ce champ est
     * émis sur TOUS les items du type. Ce n'est pas une propriété du raccourci
     * mais une donnée de CONTEXTE (le poste) : l'agent doit connaître les
     * Bureaux à balayer MÊME quand plus aucune règle `place=desktop` n'existe —
     * sinon un Bureau vidé de ses règles ne serait plus jamais nettoyé et
     * garderait ses `.lnk` gérés orphelins à vie (leçon de la review #2 de
     * 27.1). LIMITE (préexistante, niveau moteur) : ne tient que tant qu'il
     * reste AU MOINS UN item `shortcuts` ; si la DERNIÈRE règle disparaît, aucun
     * item n'est émis, le handler n'est jamais convoqué et un `.lnk` résiduel
     * reste orphelin — hors périmètre 27.21 (cf. Points ouverts de la story).
     * Un agent ANTÉRIEUR À 27.21 (≤ 2.13.0) ignore le champ inconnu sans erreur
     * (§9, forward-compatible) et conserve son balayage local. La 2.14.0
     * (balayage réseau inconditionnel) est répudiée, jamais publiée.
     *
     * @param  list<string>  $desktopSweepPaths
     * @return array<string,mixed>
     */
    private function payloadFor(Shortcut $row, string $desktopPath, array $desktopSweepPaths): array
    {
        $icon = (string) ($row->windows_icon ?? $row->icon_path ?? '');

        $payload = [
            'name' => (string) $row->name,
            'target' => (string) ($row->windows_link ?? ''),
            'args' => (string) ($row->windows_args ?? ''),
            'icon' => $icon,
            'place' => (string) $row->place,
        ];

        // Icône UPLOADÉE (nom nu) backfillée en asset content-addressed : on
        // ajoute les champs asset. Lecture de colonnes pures (zéro hash/I/O au
        // render — invariant perf, piège n° 2).
        if ($this->isBareIconName($icon)
            && $row->icon_asset !== null && $row->icon_asset !== ''
            && $row->icon_checksum !== null && $row->icon_checksum !== ''
        ) {
            $payload['icon_asset'] = (string) $row->icon_asset;
            $payload['icon_checksum'] = (string) $row->icon_checksum;
        }

        if ($row->place === Shortcut::PLACE_DESKTOP) {
            $payload['desktop_path'] = $desktopPath;
        }

        // Émis sur TOUS les emplacements (cf. docbloc) — le balayage n'est pas
        // une propriété du raccourci mais du poste.
        $payload['desktop_sweep_paths'] = $desktopSweepPaths;

        return $payload;
    }

    /**
     * Détecte un NOM NU d'icône uploadée (≠ chemin réel) — regex iso-legacy
     * conservée : aucun séparateur de chemin/index
     * (`\ / . , %`) ⇒ ce n'est pas un chemin, c'est le nom d'un raccourci dont
     * l'icône a été uploadée côté serveur. Chaîne vide = pas un nom nu (pas
     * d'icône). Story 27.7, AC2.
     */
    private function isBareIconName(string $icon): bool
    {
        return $icon !== '' && ! preg_match('#[\\\\/.,%]#', $icon);
    }

    /**
     * Étiquetage assignable → maille (décision n° 8). La distinction
     * physique/logique d'un WorkstationGroup se fait par les listes du contexte
     * (la requête a déjà restreint aux groupes du poste) — étiquetage, pas
     * précédence (D2 = compilateur).
     */
    private function mailleFor(Shortcut $row, TargetContext $ctx): StateMaille
    {
        return match ($row->assignable_type) {
            WorkstationGroup::class => in_array((int) $row->assignable_id, $ctx->physicalGroupIds, true)
                ? StateMaille::PhysicalGroup
                : StateMaille::LogicalGroup,
            Workstation::class => StateMaille::Workstation,
            UserGroup::class => StateMaille::UserGroup,
            User::class => StateMaille::User,
            // Inatteignable via itemsFor() (le WHERE ne ramène que ces types) —
            // garde-fou explicite si une morph aliasée ou une donnée corrompue
            // arrive un jour (iso WallpaperStateProvider).
            default => throw new \LogicException(
                "assignable_type inattendu pour shortcut #{$row->id} : {$row->assignable_type}",
            ),
        };
    }
}
