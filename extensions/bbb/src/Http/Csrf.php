<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

/**
 * Story 57.2 — **LE JETON ANTI-CSRF, FACTORISÉ** (il était privé à la page des
 * serveurs en 57.1, et deux contrôleurs le veulent maintenant).
 *
 * Le principe tenu ici est celui de 57.1, et il vaut d'être redit : **un POST
 * sans jeton valide n'est pas une saisie de l'utilisateur, c'est une requête
 * fabriquée ailleurs. On ne la corrige pas, on la refuse.**
 *
 * Cela compte particulièrement pour les salons : sans ce contrôle, une page
 * tierce visitée par un professeur pourrait faire ouvrir un meeting à son insu —
 * un appel sortant, sur un serveur mono-processus, déclenché par quelqu'un
 * d'autre que lui.
 *
 * Le jeton est propre à un espace de clé (`admin.csrf`, `rooms.csrf`) : les deux
 * pages ne se prêtent pas leur jeton, et le renouvellement de l'une ne casse pas
 * le formulaire ouvert de l'autre.
 */
final class Csrf
{
    public function __construct(private readonly string $key)
    {
    }

    /** Le jeton courant, tiré de `random_bytes` au premier besoin. */
    public function token(SessionStore $store): string
    {
        $token = $store->get($this->key);

        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $store->put($this->key, $token);
        }

        return $token;
    }

    /** Comparaison à temps constant, et refus par défaut si l'un des deux manque. */
    public function matches(Request $request, SessionStore $store): bool
    {
        $expected = $store->get($this->key);
        $received = $request->input('_token');

        return is_string($expected) && $expected !== '' && $received !== '' && hash_equals($expected, $received);
    }
}
