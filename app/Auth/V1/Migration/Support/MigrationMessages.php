<?php

declare(strict_types=1);

namespace App\Auth\V1\Migration\Support;

/**
 * Module de migration SE4 → SE5.
 *
 * Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de
 * migrer un déploiement SE4 vers SE5 (typiquement : quand aucun collège
 * actif n'utilise plus SE4 = sambaedu legacy PHP-only).
 *
 * Sprint Change Proposal 2026-05-19. Story 16.13bis.
 *
 * Constantes centralisées pour les messages user-facing du fragment de
 * migration. Cf. D11 — message FR uniforme Windows + Linux.
 */
final class MigrationMessages
{
    /**
     * Message FR canonique affiché par le fragment juste avant le reboot
     * (parité Windows `shutdown /c "..."` / Linux `wall "..."`).
     */
    public const REBOOT_FR = 'SambaEdu : migration terminée, redémarrage automatique dans 30 secondes.';

    /**
     * Variante sans accents (compat Windows console CP1252 fallback).
     * Utilisée pour le `echo` console pré-shutdown afin d'éviter les
     * caractères mojibake quand `chcp 65001` n'est pas appliqué.
     */
    public const REBOOT_FR_NOACCENTS = 'SambaEdu : migration terminee. Redemarrage automatique dans 30 secondes...';

    /**
     * Message "déjà migré" Windows (sans accents pour console legacy).
     */
    public const NOOP_FR_WIN = 'SambaEdu : poste deja migre, no-op.';

    /**
     * Message "déjà migré" Linux (avec accents — UTF-8 par défaut).
     */
    public const NOOP_FR_LINUX = 'SambaEdu : déjà migré, no-op.';
}
