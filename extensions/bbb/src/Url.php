<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb;

use RuntimeException;

/**
 * Story 57.1 — **LE GÉNÉRATEUR D'URL UNIQUE (piège n°1 de l'extension).**
 *
 * Le fragment Apache généré par le helper root **retire** le préfixe :
 *
 * ```apache
 * ProxyPass        "/ext/bbb" "http://127.0.0.1:<port>/" retry=0
 * ProxyPassReverse "/ext/bbb" "http://127.0.0.1:<port>/"
 * RequestHeader set X-Forwarded-Prefix "/ext/bbb"
 * ```
 *
 * Le backend reçoit donc `/`, `/login`, `/oidc/callback`, `/admin/servers` —
 * **jamais** `/ext/bbb/...`. Deux conséquences non négociables :
 *
 * 1. le routeur matche des chemins NUS ;
 * 2. **toute** URL émise vers le navigateur (href, `action` de formulaire,
 *    en-tête `Location:`, feuille de style, chemin du cookie) doit au contraire
 *    porter le préfixe, sinon elle sort de l'extension.
 *
 * `ProxyPassReverse` réécrit les `Location:` absolus pointant le backend, mais
 * PAS les chemins relatifs fabriqués à la main : on ne s'y fie pas, on émet
 * directement le bon chemin.
 *
 * D'où **un seul point de fabrication**, ici, et zéro URL en dur ailleurs. Le
 * préfixe vient de `SE5_EXT_BASE_PATH` (source de vérité ; `X-Forwarded-Prefix`
 * n'en est que le témoin HTTP), et il **peut être vide** en développement.
 */
final class Url
{
    private static ?string $basePath = null;

    /** Pose le préfixe pour tout le processus. Idempotente. */
    public static function configure(string $basePath): void
    {
        $normalized = rtrim($basePath, '/');

        if ($normalized !== '' && ! str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }

        self::$basePath = $normalized;
    }

    /** Le préfixe courant (`''` est une valeur légitime, pas une absence). */
    public static function basePath(): string
    {
        if (self::$basePath === null) {
            throw new RuntimeException('Url::configure() n\'a pas été appelée : aucune URL ne peut être fabriquée.');
        }

        return self::$basePath;
    }

    /**
     * Fabrique une URL absolue de l'extension depuis un chemin NU.
     *
     * `Url::to('/')` ⇒ `/ext/bbb/` ; `Url::to('/login')` ⇒ `/ext/bbb/login`.
     * Sans préfixe : `/` et `/login`. Un chemin sans `/` initial en reçoit un —
     * on ne veut pas d'URL concaténée par accident (`/ext/bbblogin`).
     */
    public static function to(string $path = '/'): string
    {
        $base = self::basePath();

        if ($path === '') {
            $path = '/';
        }

        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $base . $path;
    }

    /**
     * La seule URL de SE5 que l'extension connaisse : son issuer OIDC. Sert de
     * cible au lien « Retour à SambaEdu » (FR16). Jamais d'URL codée en dur.
     */
    public static function backToSambaEdu(Env $env): string
    {
        return $env->issuer !== '' ? $env->issuer : '/';
    }

    /** Remise à zéro — usage de test exclusivement. */
    public static function reset(): void
    {
        self::$basePath = null;
    }
}
