<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Maille de ciblage d'un candidat d'état (Story 23.4) — enum **interne** au
 * serveur : elle n'apparaît jamais dans le JSON `se5.desired-state/v1` (donc
 * non soumise à NFR12). Les providers étiquettent leurs candidats avec elle ;
 * le compilateur l'utilise pour appliquer D2.
 *
 * ⚠️ AUCUNE méthode de rang ici : l'ordre de spécificité
 * (`user > user_group > workstation > physical_group > logical_group >
 * broadcast`) vit dans le **StateCompiler seul** — l'y dupliquer ferait
 * fuiter D2 vers les providers (anti-pattern bloquant, architecture
 * Enforcement Guidelines).
 */
enum StateMaille: string
{
    case User = 'user';
    case UserGroup = 'user_group';
    case Workstation = 'workstation';
    case PhysicalGroup = 'physical_group';
    case LogicalGroup = 'logical_group';
    case Broadcast = 'broadcast';
}
