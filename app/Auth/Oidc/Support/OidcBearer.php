<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Support;

use Illuminate\Http\Request;

/**
 * Story 56.4 — **LE point unique d'extraction d'un Bearer opaque** (RFC 6750 §2.1).
 *
 * Extrait la règle qui vivait dans `UserinfoController::extractBearer()` (55.2)
 * pour que l'API extensions n'en fasse pas une seconde copie : deux extracteurs,
 * c'est deux occasions d'accepter un jour le jeton en query « juste ici ».
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  EN-TÊTE `Authorization` UNIQUEMENT
 *
 *  La RFC 6750 §2.3 autorise la forme « URI query parameter » : SE5 ne la
 *  supporte PAS, et ce n'est pas un oubli. Un `?access_token=…` finit dans les
 *  journaux du serveur, l'historique du navigateur et l'en-tête `Referer`
 *  (doctrine D-3 du login fédéré, reprise par 55.1 et 55.2). Un jeton présenté
 *  là — ou dans le corps — est simplement IGNORÉ, donc traité comme absent.
 * ══════════════════════════════════════════════════════════════════════════
 */
final class OidcBearer
{
    /**
     * Le jeton du SEUL en-tête `Authorization: Bearer …`, ou `null`.
     *
     * `null` pour tout le reste : en-tête absent, schéma `Basic`, `Bearer` sans
     * valeur. Aucune inspection de la query string ni du corps.
     */
    public static function fromRequest(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));

        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
