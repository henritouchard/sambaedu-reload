<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ActiveCloud;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\DesktopPathResolver;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocationService;
use App\Services\Shortcuts\PortalShortcutIcon;
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
 * **Story 63.2** : ce chemin ne dépend PLUS que du parc. Le bureau réseau vit
 * dans le home SMB, et ce partage-là est toujours là pour l'agent même quand
 * l'espace perso de l'utilisateur a déménagé au cloud — le provider ne lit donc
 * aucun réglage pour résoudre le Bureau ({@see DesktopPathResolver::pathFor()}).
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
 *
 * **UN candidat n'a PAS de ligne source : le raccourci vers le portail web**
 * ({@see self::portalCandidate()}). Il naît du PLAN DE FICHIERS
 * ({@see \App\Services\Filesystem\FileLocationService::current()}) — un cloud
 * actif ET au moins un des deux espaces servi par lui —, pas d'une
 * assignation, parce que ce qu'il rend visible n'est pas une règle
 * d'établissement mais une conséquence technique : un espace servi par un cloud
 * n'a AUCUN chemin SMB, donc aucune lettre de lecteur ({@see DrivesStateProvider}
 * ne lui en donne pas), et son seul chemin d'accès est le navigateur. C'est le
 * SEUL écart au « projection en lecture seule de `shortcuts` » énoncé ci-dessus,
 * et il ne change rien pour l'agent : un `.lnk` de plus dans le même payload,
 * aucune version d'agent à publier.
 */
final class ShortcutsStateProvider implements StateProvider
{
    /**
     * Nom affiché du raccourci-portail sur le Bureau.
     *
     * Il nomme la DESTINATION, pas le produit : l'utilisateur cherche ses
     * fichiers, il ne cherche pas « Nextcloud ». Le même libellé servira si
     * l'instance est servie par un autre produit, sans que les bureaux de
     * l'établissement changent de vocabulaire.
     */
    public const PORTAL_SHORTCUT_NAME = 'Mes fichiers en ligne';

    public function __construct(
        private readonly WorkstationEnvironmentResolver $environmentResolver,
        // Story 58.1 — le mapping environnement→chemin du Bureau a QUITTÉ ce
        // provider pour {@see DesktopPathResolver} : il est désormais partagé
        // avec {@see ShellFoldersStateProvider}, qui fait porter à l'agent la
        // REDIRECTION du shell vers ce même chemin. Deux mappings jumeaux, c'est
        // la garantie que l'agent pose les `.lnk` dans un dossier que le shell
        // ne regarde pas. Déplacement PUR : valeurs et payload inchangés.
        private readonly DesktopPathResolver $desktopPaths,
        // Le couple `{asset, checksum}` de l'icône du raccourci-portail, publié
        // par l'écran de réglages des fichiers. Injecté plutôt qu'appelé
        // statiquement : le provider doit rester testable sans système de
        // fichiers, et c'est la seule dépendance de ce genre qu'il porte.
        private readonly PortalShortcutIcon $portalIcon,
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

        $desktopPath = $this->desktopPaths->pathFor($environment);
        $desktopSweepPaths = $this->desktopPaths->sweepPathsFor($environment);

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

        $candidates = $rows->map(fn (Shortcut $row): StateCandidate => new StateCandidate(
            maille: $this->mailleFor($row, $ctx),
            payload: $this->payloadFor($row, $desktopPath, $desktopSweepPaths),
            updatedAt: $row->updated_at,
            sourceId: (int) $row->id,
        ));

        $candidates = $candidates->concat(
            $this->parcDefaultCandidates($desktopPath, $desktopSweepPaths),
        );

        $portal = $this->portalCandidate($desktopPath, $desktopSweepPaths);

        return $portal === null ? $candidates : $candidates->prepend($portal);
    }

    /**
     * Les raccourcis DÉFAUT DE PARC : posés sur tous les postes, sans assignation.
     *
     * Pendant exact des applications `is_parc_default` — même page d'administration,
     * même promesse. Sans eux, un raccourci ne pouvait viser que des cibles nommées,
     * et un parc créé après coup ne l'héritait jamais.
     *
     * Maille `Broadcast` (la moins spécifique) : le type étant `aggregate`, la
     * précédence ne joue pas — le raccourci s'AJOUTE, il n'évince rien. Un raccourci
     * à la fois défaut de parc et assigné produit deux candidats de payload
     * IDENTIQUE, que le compilateur collapse en un seul item.
     *
     * @param  array<int, string>  $desktopSweepPaths
     * @return Collection<int, StateCandidate>
     */
    private function parcDefaultCandidates(string $desktopPath, array $desktopSweepPaths): Collection
    {
        return Shortcut::query()
            ->where('shortcuts.is_active', true)
            ->where('shortcuts.is_parc_default', true)
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
            ])
            ->map(fn (Shortcut $row): StateCandidate => new StateCandidate(
                maille: StateMaille::Broadcast,
                payload: $this->payloadFor($row, $desktopPath, $desktopSweepPaths),
                updatedAt: $row->updated_at,
                sourceId: (int) $row->id,
            ));
    }

    /**
     * LE RACCOURCI SYNTHÉTIQUE VERS LE PORTAIL WEB — le seul candidat de ce
     * provider qui ne vienne pas d'une ligne de `shortcuts`.
     *
     * **Pourquoi il existe.** Un espace servi par un cloud n'a AUCUN chemin SMB :
     * le {@see DrivesStateProvider} ne lui donne aucune lettre de lecteur. Son
     * seul chemin d'accès est le web — et un chemin d'accès que l'utilisateur ne
     * voit nulle part n'existe pas pour lui. Ce raccourci est ce que le poste
     * montre à la place de la lettre de lecteur qui ne viendra pas.
     *
     * **Pourquoi il n'est PAS une ligne de `shortcuts`.** Une ligne semée
     * apparaîtrait dans le catalogue, éditable et supprimable, alors que sa
     * cible (l'URL de l'instance) est gouvernée ailleurs : l'admin pourrait la
     * modifier et voir le réglage la réécrire, ou la supprimer et la voir
     * revenir. Le cloud actif de l'instance est la seule autorité, et il n'y a
     * donc rien à éditer.
     *
     * **Maille `Broadcast`** : c'est un réglage d'INSTANCE, sans assignation. Il
     * est donc le moins spécifique du local (sous toutes les mailles de
     * ciblage) — mais la sémantique du type étant `aggregate`, la précédence ne
     * joue pas : l'item s'AJOUTE aux raccourcis du poste, il n'en évince aucun.
     *
     * **`sourceId: 0`** — libre par construction (les identifiants Postgres
     * commencent à 1) et donc sans collision avec une ligne réelle. Il place le
     * portail en tête de l'ordre stable des items aggregate
     * ({@see \App\Services\Agent\StateCompiler::selectAggregate()}, tri par
     * `sourceId` asc), ce qui rend le hash d'état déterministe.
     *
     * **`updatedAt: null`** : il n'y a pas de ligne, donc pas de date de
     * modification. Le champ ne sert qu'au conflit intra-maille des sémantiques
     * `single`, jamais ici.
     *
     * **Story 63.2 — la SOURCE change, le reste ne bouge pas d'un octet.** Le
     * raccourci naissait de trois conditions du réglage global `files.policy`
     * (capacité Nextcloud, case « poser le raccourci », URL). Il naît désormais
     * du **plan de fichiers** : un cloud actif, ET au moins un des deux espaces
     * servi par ce cloud. La case n'a pas été remplacée par une autre case —
     * elle a disparu, parce qu'elle demandait à l'exploitant de redire une chose
     * qu'il avait déjà dite en disant où vivent ses fichiers.
     *
     * **Pourquoi l'espace au cloud est une condition, et pas seulement le cloud
     * actif** : le raccourci mène là où vivent les fichiers — un cloud seulement
     * *configuré*, dont aucun espace ne dépend, ne justifie pas de faire
     * apparaître une icône sur TOUS les bureaux de l'établissement.
     *
     * **L'URL est celle du cloud ACTIF**, lue dans `files.policy` — jamais via
     * `NextcloudConnectionConfig::current()` / `OpenCloudConnectionConfig::current()`,
     * qui LÈVENT quand la connexion est incomplète : la compilation de l'état
     * d'un poste n'a pas à dépendre de la complétude d'une configuration
     * d'administration.
     *
     * Rend `null` — donc n'émet RIEN — quand il n'y a pas de cloud actif, quand
     * les deux espaces sont sur le serveur de fichiers, ou quand l'URL du cloud
     * actif est vide : un raccourci qui n'ouvre rien est pire que pas de
     * raccourci.
     *
     * @param  list<string>  $desktopSweepPaths
     */
    private function portalCandidate(string $desktopPath, array $desktopSweepPaths): ?StateCandidate
    {
        // AUCUN try/catch : une ligne `files.locations` forgée LÈVE, et
        // l'exception se propage. Se replier sur « aucun cloud » inventerait une
        // décision que personne n'a prise et ferait disparaître en silence, de
        // tous les bureaux de l'établissement, le seul chemin d'accès aux
        // fichiers en ligne.
        $locations = FileLocationService::current();

        // Les DEUX conditions d'emplacement, avant toute autre lecture : un
        // cloud actif, et au moins un espace qu'il sert.
        $unEspaceAuCloud = ! $locations->espacePersoSurSmb() || ! $locations->espacePartageSurSmb();

        if ($locations->cloudActif === ActiveCloud::Aucun || ! $unEspaceAuCloud) {
            return null;
        }

        // `globalConfig()` n'est lu QUE dans les branches qui en ont besoin :
        // c'est une requête, non cachée, sur le chemin chaud de la compilation
        // d'état (un couple poste × utilisateur, à chaque cycle).
        $url = match ($locations->cloudActif) {
            ActiveCloud::Nextcloud => trim((string) FilePolicyService::globalConfig()['nextcloud_server_url']),
            ActiveCloud::OpenCloud => trim((string) FilePolicyService::globalConfig()['opencloud_server_url']),
            // Déjà écarté ci-dessus — laissé explicite pour qu'un troisième
            // produit casse ici plutôt que de retomber en silence.
            ActiveCloud::Aucun => '',
        };

        if ($url === '') {
            return null;
        }

        // La traduction (URL, navigateur) → (cible, arguments) est celle du
        // modèle, PAS une seconde recette : une URL posée en CIBLE de `.lnk`
        // produit « l'élément auquel ce raccourci renvoie a été modifié ou
        // déplacé » sur le poste ({@see Shortcut::webTargetAttributes()}).
        $web = Shortcut::webTargetAttributes($url);

        $payload = [
            'name' => self::PORTAL_SHORTCUT_NAME,
            'target' => (string) $web['windows_link'],
            'args' => (string) $web['windows_args'],
            'icon' => '',
            'place' => Shortcut::PLACE_DESKTOP,
        ];

        // Icône publiée à l'enregistrement de l'écran de réglages (lecture PURE
        // d'une ligne de réglage ici — aucun hash, aucune I/O). Absente ⇒
        // raccourci sans icône plutôt que pas de raccourci du tout.
        //
        // `icon` reste VIDE, et ce n'est pas un oubli : quand `icon_asset` est
        // renseigné, l'agent ne regarde JAMAIS `icon` (`effectiveIcon()`). Y
        // écrire un nom nu laisserait croire à un repli qui n'existe pas.
        $icon = $this->portalIcon->current();
        if ($icon !== null) {
            $payload['icon_asset'] = $icon['asset'];
            $payload['icon_checksum'] = $icon['checksum'];
        }

        $payload['desktop_path'] = $desktopPath;
        $payload['desktop_sweep_paths'] = $desktopSweepPaths;

        return new StateCandidate(
            maille: StateMaille::Broadcast,
            payload: $payload,
            updatedAt: null,
            sourceId: 0,
        );
    }

    // Story 58.1 — `desktopPathFor()` et `desktopSweepPathsFor()` ont été
    // DÉPLACÉS dans {@see DesktopPathResolver} (le raisonnement complet y est
    // conservé mot pour mot). Motif : un SECOND consommateur en a besoin —
    // ShellFoldersStateProvider, qui fait porter à l'agent la redirection
    // `User Shell Folders\Desktop` vers ce MÊME chemin. Deux mappings jumeaux
    // auraient laissé l'agent poser des `.lnk` dans un dossier que le shell ne
    // regarde pas : c'est la panne de juillet 2026. Ne rien réintroduire ici.

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
