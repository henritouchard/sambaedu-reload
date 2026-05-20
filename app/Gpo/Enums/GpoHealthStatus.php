<?php

declare(strict_types=1);

namespace App\Gpo\Enums;

/**
 * Statuts de santé d'une GPO — Story 16.14 (B — panneau filtres avancés).
 *
 * Définitions selon D4 :
 *   - Healthy    : versionNumber > 0 ET au moins 1 OU liée.
 *   - Orphaned   : aucune OU liée détectée (total liens = 0).
 *   - Conflicting: au moins 2 GPOs sur le même containerDn matchent la même section native.
 *   - Stale      : versionNumber === 0 (proxy best-effort Phase 2).
 */
enum GpoHealthStatus: string
{
    case Healthy     = 'healthy';
    case Orphaned    = 'orphaned';
    case Conflicting = 'conflicting';
    case Stale       = 'stale';

    public function label(): string
    {
        return match ($this) {
            self::Healthy     => 'Saine',
            self::Orphaned    => 'Orpheline',
            self::Conflicting => 'Conflit',
            self::Stale       => 'Obsolète',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Healthy     => 'badge-success',
            self::Orphaned    => 'badge-warning',
            self::Conflicting => 'badge-error',
            self::Stale       => 'badge-ghost',
        };
    }
}
