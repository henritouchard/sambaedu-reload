<?php

declare(strict_types=1);

namespace App\Auth\Federated\Session;

use Illuminate\Http\Request;

/**
 * Story 20.1 — D-5.
 *
 * Marqueur de session « fédérée ». Source de vérité partagée entre :
 *  - {@see \App\Auth\Federated\Http\FederatedLoginController} (pose le marqueur
 *    après `Auth::login`) ;
 *  - {@see \App\Http\Middleware\Auth\SambaEduAuthGuard} (lit le marqueur pour
 *    SAUTER la vérification LDAP et valider `ExternalIdentity.is_active` à la
 *    place).
 *
 * On stocke la clé sur la session Laravel (`SessionInterface`) ET sur le
 * superglobal `$_SESSION` legacy, pour rester cohérent avec le double système
 * de session du projet (le guard lit via la session Laravel de la requête).
 */
final class FederatedSession
{
    /** Clé de marquage : présence = session fédérée ; valeur = external_identity_id. */
    public const SESSION_KEY = 'federated_auth.external_identity_id';

    /**
     * Marque la session courante comme fédérée et mémorise l'identité externe.
     */
    public static function mark(Request $request, ?int $externalIdentityId): void
    {
        $request->session()->put(self::SESSION_KEY, $externalIdentityId);
        $_SESSION[self::SESSION_KEY] = $externalIdentityId;
    }

    /**
     * La session courante est-elle fédérée ?
     */
    public static function isFederated(Request $request): bool
    {
        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            return true;
        }

        return array_key_exists(self::SESSION_KEY, $_SESSION ?? []);
    }

    /**
     * Récupère l'identifiant d'identité externe lié à la session (ou null).
     */
    public static function externalIdentityId(Request $request): ?int
    {
        if ($request->hasSession() && $request->session()->has(self::SESSION_KEY)) {
            $value = $request->session()->get(self::SESSION_KEY);

            return $value === null ? null : (int) $value;
        }

        $value = ($_SESSION ?? [])[self::SESSION_KEY] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Purge le marquage (appelé lors d'un logout / désactivation).
     */
    public static function forget(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
        unset($_SESSION[self::SESSION_KEY]);
    }
}
