<?php

declare(strict_types=1);

namespace App\Services\Agent\Providers;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Services\Agent\Contracts\StateProvider;
use App\Services\Agent\DesktopPathResolver;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\FilePolicyService;
use Illuminate\Support\Collection;

/**
 * Type `folders` (contrat §7.12, Story 58.1) — **redirection des dossiers shell
 * Windows**, portée par l'AGENT.
 *
 * # Le trou que ce provider bouche
 *
 * `\\<se4fs>\users\<user>\Bureau\` n'est le Bureau de l'utilisateur que si la
 * valeur `HKCU\Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell
 * Folders\Desktop` pointe dessus. Cette valeur n'a JAMAIS été écrite par SE5 :
 * elle venait du script de la GPO legacy « Bureau » (paquet `folders`, scripts
 * `bureau_samba` / `bureau_local`, discriminés par le groupe `Port_perdir`).
 *
 * Ce dernier émetteur a été coupé le **2026-07-20** : l'OU des comptes de
 * l'établissement porte `gPOptions: 1` (héritage bloqué) sans `gPLink`, donc
 * plus aucune GPO legacy ne s'applique aux utilisateurs. Aucun successeur SE5
 * n'avait été prévu.
 *
 * La panne est restée invisible parce que la valeur, écrite UNE fois, est FIGÉE
 * dans le profil itinérant (`/home/profiles/<user>.V6/NTUSER.DAT`) : les profils
 * créés avant la coupure la conservent indéfiniment et paraissent corrects ; les
 * profils créés APRÈS ne l'ont jamais eue. Constat lab1 du 2026-07-30 : trois
 * comptes de juin avec la redirection, trois comptes du 29/07 sans — un écart
 * qui ressemblait à une différence de profil (prof / administratif) et n'en
 * était pas.
 *
 * Conséquence concrète : {@see ShortcutsStateProvider} continue de faire déposer
 * les `.lnk` dans le Bureau RÉSEAU, mais le shell de la session regarde le
 * Bureau LOCAL — **tout raccourci `place=desktop` est invisible** pour les
 * profils postérieurs à la coupure.
 *
 * # Pourquoi c'est l'agent qui doit porter ça
 *
 * Re-lier la GPO legacy sur l'OU réintroduirait le canal qu'on est précisément
 * en train d'éteindre (Epic 38). L'agent est le successeur désigné : il converge
 * en continu (level-triggered) au lieu de dépendre d'un script joué au logon, et
 * il connaît déjà le poste — ce qui compte ici, car le bon Bureau DÉPEND DU
 * POSTE.
 *
 * # Décisions
 *
 * **Un SEUL chemin de vérité.** Le `path` émis ici est celui que
 * {@see DesktopPathResolver::pathFor()} donne à `shortcuts` — littéralement le
 * même appel. Poser les raccourcis et rediriger le shell sont deux moitiés d'un
 * même geste : les faire diverger, c'est reproduire la panne à l'identique.
 *
 * **On émet TOUJOURS un item, réseau OU local** (règle des maps symétriques) :
 * un poste perdir doit ÉCRIRE `%USERPROFILE%\Desktop`, pas « ne rien dire ». Le
 * profil itinérant est partagé entre tous les postes de l'utilisateur — sans
 * écriture explicite, un portable perdir hériterait du Bureau réseau laissé par
 * le poste de classe (et réciproquement). « Ne pas gérer » (contrat §8) laisse
 * la dernière valeur en place : ici ce serait la mauvaise.
 *
 * **Scope `machine_user`** — iso {@see ShortcutsStateProvider} : la valeur
 * s'écrit dans la ruche de l'UTILISATEUR (le compagnon de session), mais elle se
 * calcule à partir du POSTE (`WorkstationEnvironment` du parc). C'est un
 * croisement (poste, user), compilé par couple.
 *
 * **Semantics `exclusive`** — un dossier shell a UNE valeur. L'identité est le
 * `folder` ; aucune union n'aurait de sens. Le provider émet un unique candidat
 * en maille `Broadcast` : la valeur n'est pas assignable par maille (elle dérive
 * du parc et de la politique de fichiers, pas d'une table d'authoring).
 *
 * **Lecture PURE** — aucune requête : ni Postgres, ni AD (NFR7, critère
 * Keycloak). Tout vient du {@see TargetContext} déjà résolu et de la capacité
 * globale {@see FilePolicyService::capabilities()}.
 *
 * Payload v1 : `{folder, path}` — exactement 2 clés, toujours des strings
 * (§4.1). Tokens `<se4fs>` / `<user>` substitués LOCALEMENT par l'agent, jamais
 * résolus ici (iso `drives`, `shortcuts`, `app_profile`).
 */
final class ShellFoldersStateProvider implements StateProvider
{
    /** Identifiant de type figé (contrat §7.12). */
    public const TYPE_FOLDERS = 'folders';

    /**
     * Dossier shell « Bureau ». Mot MÉTIER, pas le nom de la valeur de registre
     * (`Desktop`) : l'agent traduit `folder` → mécanisme, le serveur n'écrit
     * jamais de chemin de clé (invariant capability-first 27.12).
     */
    public const FOLDER_DESKTOP = 'desktop';

    public function __construct(
        private readonly WorkstationEnvironmentResolver $environmentResolver,
        private readonly DesktopPathResolver $desktopPaths,
    ) {}

    public function type(): string
    {
        return self::TYPE_FOLDERS;
    }

    public function semantics(): ResourceSemantics
    {
        return ResourceSemantics::Exclusive;
    }

    public function scope(): StateScope
    {
        return StateScope::MachineUser;
    }

    /**
     * Un unique candidat : la redirection du Bureau vers le chemin résolu pour
     * ce couple (poste, user).
     *
     * Machine seule (`user` null) ⇒ AUCUN item : `User Shell Folders` est une
     * clé per-utilisateur (HKCU) ; sans session il n'y a pas de ruche à écrire.
     * Le service SYSTEM ne fait donc rien de ce type — c'est le compagnon qui
     * l'applique, à l'ouverture de session.
     *
     * @return Collection<int, StateCandidate>
     */
    public function itemsFor(TargetContext $ctx): Collection
    {
        if ($ctx->user === null) {
            return collect();
        }

        // MÊME résolution que `shortcuts` — c'est l'invariant de la story :
        // l'endroit où l'agent POSE les raccourcis et l'endroit vers lequel il
        // REDIRIGE le shell sont un seul et même chemin.
        $environment = $this->environmentResolver->resolveForGroupIds($ctx->workstationGroupIds());
        $path = $this->desktopPaths->pathFor(
            $environment,
            FilePolicyService::capabilities()['home'],
        );

        return collect([
            new StateCandidate(
                // Broadcast : la valeur dérive du parc + de la politique globale
                // de fichiers, pas d'une assignation par maille. Aucun arbitrage
                // de précédence à faire — un seul candidat, toujours.
                maille: StateMaille::Broadcast,
                payload: [
                    'folder' => self::FOLDER_DESKTOP,
                    'path' => $path,
                ],
                updatedAt: null,
                sourceId: 1,
            ),
        ]);
    }
}
