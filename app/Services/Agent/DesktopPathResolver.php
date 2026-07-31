<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\WorkstationEnvironment;
use App\Services\FilePolicyService;

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
     * ⚠️ POSE ≠ BALAYAGE : cette méthode résout le seul emplacement où l'agent
     * POSE les `.lnk` (et, depuis 58.1, celui vers lequel il REDIRIGE le shell —
     * les deux sont volontairement le MÊME). Les emplacements qu'il BALAIE sont
     * une autre décision, prise par {@see self::sweepPathsFor()}.
     */
    public function pathFor(WorkstationEnvironment $environment, bool $homeEnabled): string
    {
        return match ($environment) {
            // Poste partagé : bureau redirigé RÉSEAU (le défaut du pansement Bug
            // C, mais désormais PARAMÉTRABLE par parc et non figé) — SEULEMENT
            // si le home est accessible, sinon le bureau réseau est invisible.
            WorkstationEnvironment::SharedLocal => $homeEnabled
                ? self::NETWORK
                : self::LOCAL,
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
