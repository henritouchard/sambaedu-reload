<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Maille de ciblage d'un candidat d'état (Story 23.4) — enum **interne** au
 * serveur : elle n'apparaît jamais dans le JSON `se5.desired-state/v1` (donc
 * non soumise à NFR12). Les providers étiquettent leurs candidats avec elle ;
 * le compilateur l'utilise pour appliquer D2.
 *
 * ⚠️ AUCUNE méthode de rang ici : l'ordre de spécificité — Story 27.3 (D-Q3)
 * INVERSE `logical_group`/`physical_group` GLOBALEMENT (le parc logique bat la
 * salle physique). Story 28.3 ajoute le tier AMONT au-dessus de tout le local
 * (le contrat imposé par l'autorité amont prime sur le réglage local — FR2) :
 * `upstream > user > user_group > workstation > logical_group > physical_group >
 * broadcast` — vit dans le **StateCompiler seul** — l'y dupliquer ferait
 * fuiter D2 vers les providers (anti-pattern bloquant, architecture
 * Enforcement Guidelines).
 *
 * ⚠️ GARDE-FOU R3 (Story 28.3) : le tier amont est `Upstream` (valeur
 * `'upstream'`), JAMAIS « central ». Vocabulaire « amont » / `Upstream`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
enum StateMaille: string
{
    /**
     * Maille AMONT (Story 28.3) — un item imposé par le contrat amont
     * (controlHub) ; plus spécifique que TOUTE maille locale → il prime au
     * compilateur (FR2). La précédence est arbitrée par
     * {@see \App\Services\Agent\StateCompiler::specificity()} SEUL : un candidat
     * étiqueté `Upstream` reste un candidat BRUT (D2 ne fuit pas).
     */
    case Upstream = 'upstream';
    case User = 'user';
    case UserGroup = 'user_group';
    case Workstation = 'workstation';
    case PhysicalGroup = 'physical_group';
    case LogicalGroup = 'logical_group';
    case Broadcast = 'broadcast';
}
