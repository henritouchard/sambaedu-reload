<?php

declare(strict_types=1);

use SambaEdu\ExtBbb\Url;

/**
 * Story 57.1 — Les deux seuls raccourcis autorisés dans les vues.
 *
 * Préfixés `bbb_` : chargés par l'autoload de l'extension, ils cohabitent avec
 * n'importe quel autre code du même processus sans risque de collision.
 */
if (! function_exists('bbb_e')) {
    /** Échappement HTML — la valeur par DÉFAUT dans une vue, jamais l'exception. */
    function bbb_e(mixed $value): string
    {
        return htmlspecialchars(
            is_scalar($value) ? (string) $value : '',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
    }
}

if (! function_exists('bbb_url')) {
    /**
     * L'UNIQUE fabrique d'URL des vues (piège n°1 : le proxy retire le
     * préfixe). Aucune URL en dur, nulle part.
     */
    function bbb_url(string $path = '/'): string
    {
        return Url::to($path);
    }
}
