<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sémantique de combinaison d'un type de ressource dans un état cible
 * (`se5.desired-state/v1`).
 *
 * - `Aggregate` : plusieurs items du même type s'additionnent (ex. `overlay`,
 *   plusieurs raccourcis) — l'agent applique l'union.
 * - `Exclusive` : un seul item fait foi pour la ressource (ex. `wallpaper`) —
 *   l'agent applique le dernier / l'unique, jamais une union.
 *
 * Identifiant figé (NFR12) : une valeur publiée ne se renomme jamais.
 */
enum ResourceSemantics: string
{
    case Aggregate = 'aggregate';
    case Exclusive = 'exclusive';
}
