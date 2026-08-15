<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\WorkstationEnvironment;

/**
 * Source unique du **chemin du Bureau** de l'utilisateur, côté serveur.
 *
 * Historiquement ce mapping vivait en privé dans
 * {@see \App\Services\Agent\Providers\ShortcutsStateProvider} : lui seul avait
 * besoin de savoir OÙ poser un `.lnk`. Depuis la Story 58.1 un SECOND consommateur
 * existe — {@see \App\Services\Agent\Providers\ShellFoldersStateProvider}, qui
 * fait porter à l'agent la REDIRECTION elle-même (`User Shell Folders\Desktop`).
 * Les deux DOIVENT répondre le même chemin, sinon l'agent poserait les raccourcis
 * dans un dossier que le shell de la session ne regarde pas : c'est exactement la
 * panne constatée en juillet 2026 (raccourcis déposés dans le Bureau réseau,
 * jamais affichés, parce que plus personne n'écrivait la redirection).
 *
 * D'où l'extraction : **un seul mapping, deux consommateurs**. Les valeurs des
 * gabarits sont reprises À L'IDENTIQUE (golden `shortcuts` inchangé — la
 * refactorisation est un déplacement, pas une évolution de contrat).
 *
 * Tokens `<se4fs>` (nom du serveur de fichiers) et `<user>` (login courant)
 * laissés au payload : l'agent les substitue LOCALEMENT (aucune dépendance
 * réseau au calcul, aucune fuite). Classe PURE — aucune dépendance base / HTTP /
 * AD (critère Keycloak).
 */
final class DesktopPathResolver
{
    /**
     * Bureau RÉSEAU (redirigé sur le home de l'utilisateur). Backslash final =
     * convention legacy. **Seul le serveur** connaît ce gabarit : l'agent ne le
     * dérive JAMAIS lui-même, il le reçoit (`desktop_path` pour la POSE,
     * `desktop_sweep_paths` pour le BALAYAGE — Story 27.21, option A ; `path`
     * pour la REDIRECTION — Story 58.1).
     */
    public const NETWORK = '\\\\<se4fs>\\users\\<user>\\Bureau\\';

    /** Bureau LOCAL du profil Windows (jamais de dépendance réseau). */
    public const LOCAL = '%USERPROFILE%\\Desktop\\';

    /**
     * Chemin du bureau résolu côté SERVEUR (décision n° 3, fix Bug C).
     *
     * Le mapping environnement→chemin vit ici (pas dans l'enum, pas dans
     * l'agent) — l'agent reste bête. **SEULE porte** de résolution du bureau :
     * aucune autre branche réseau/local n'existe côté serveur (Story 27.21).
     *
     * **UN SEUL facteur : l'environnement du parc** (Story 63.2 — le
     * découplage). Le bureau RÉSEAU vit dans le home SMB
     * (`\\<se4fs>\users\<user>\`), et ce partage-là **est toujours là** : il
     * porte deux choses distinctes que la 27.21 avait confondues —
     *
     *  - les **fichiers personnels** de l'utilisateur, qui peuvent déménager
     *    (l'espace perso peut être servi par un cloud) ;
     *  - l'**infrastructure de l'agent** (bureau redirigé, `.lnk` gérés,
     *    profils applicatifs), qui ne déménage jamais et n'est pas un réglage.
     *
     * L'ancien paramètre `bool $homeEnabled` faisait basculer le bureau d'un
     * poste partagé en LOCAL dès que le lecteur `K:` était coupé. C'était un
     * effet de bord : couper `K:` ne rend pas le home inaccessible, il le rend
     * seulement non monté POUR L'UTILISATEUR — l'agent, lui, continue d'y lire
     * et d'y écrire (précision Henri du 2026-08-15). Le bureau ne suit donc
     * plus aucun réglage de fichiers, et ce résolveur ne lit plus rien.
     *
     * **La seule exception est l'exception « portables »**, et elle est juste :
     * un poste personnel ou nomade n'a pas d'autorité sur le Bureau réseau,
     * partagé entre tous les postes de l'utilisateur — son bureau est LOCAL.
     *
     * ⚠️ POSE ≠ BALAYAGE : cette méthode résout le seul emplacement où l'agent
     * POSE les `.lnk` (et, depuis 58.1, celui vers lequel il REDIRIGE le shell —
     * les deux sont volontairement le MÊME). Les emplacements qu'il BALAIE sont
     * une autre décision, prise par {@see self::sweepPathsFor()}.
     */
    public function pathFor(WorkstationEnvironment $environment): string
    {
        return match ($environment) {
            // Poste partagé : bureau redirigé RÉSEAU (le défaut du pansement Bug
            // C, mais désormais PARAMÉTRABLE par parc et non figé). Plus aucune
            // condition : le home SMB qui l'héberge est toujours là pour l'agent.
            WorkstationEnvironment::SharedLocal => self::NETWORK,
            // Perdir / nomade : bureau LOCAL du profil — le `.lnk` est posé
            // dans le profil de l'utilisateur, pas sur le partage réseau.
            WorkstationEnvironment::PersonalLocal,
            WorkstationEnvironment::Nomade => self::LOCAL,
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
     *    anti-orphelins de l'AC2/AC3 : un poste partagé nettoie les DEUX
     *    emplacements, donc jamais un `.lnk` géré ne reste sur celui qu'on
     *    vient de quitter.
     *  - `PersonalLocal` / `Nomade` ⇒ `[local]` SEULEMENT — ces postes n'ont
     *    aucune autorité sur le Bureau réseau et ne doivent JAMAIS y toucher.
     *
     * **La liste ne dépend QUE de l'environnement** — délibérément, et ça n'a
     * jamais changé : elle ne suivait pas la politique home hier, elle ne suit
     * pas les emplacements de fichiers aujourd'hui ({@see self::pathFor()} est
     * le seul point où une décision se prend). Les deux emplacements d'un parc
     * partagé restent balayés quoi qu'il arrive, sinon celui qui vient d'être
     * abandonné ne serait plus jamais nettoyé (le scénario cible de l'AC3 de la
     * 27.21). Effet de bord utile : déplacer la POSE ne fait bouger qu'un seul
     * champ du payload.
     *
     * L'ORDRE est fixe (réseau puis local) : la liste est hachée telle quelle
     * (les listes ne sont pas triées par {@see StateHasher}), un ordre stable
     * garantit un hash stable.
     *
     * @return list<string>
     */
    public function sweepPathsFor(WorkstationEnvironment $environment): array
    {
        return match ($environment) {
            WorkstationEnvironment::SharedLocal => [self::NETWORK, self::LOCAL],
            WorkstationEnvironment::PersonalLocal,
            WorkstationEnvironment::Nomade => [self::LOCAL],
        };
    }
}
