<?php

declare(strict_types=1);

namespace App\Auth\Oidc\Support;

use App\Models\User;
use RuntimeException;

/**
 * Story 55.1 — **LE POINT DE BASCULE DU CLAIM `sub`.**
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  Ce fichier est le SEUL endroit du projet qui décide de ce qu'est le sujet
 *  (`sub`) d'un id_token OIDC. Aucun émetteur, aucun contrôleur, aucun test ne
 *  doit lire `$user->login` (ni `ad_guid`, ni `id`) pour construire un `sub`.
 *  Changer d'identifiant canonique doit coûter UNE méthode, pas une chasse au
 *  trésor à travers le namespace.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Décision en vigueur : `sub` = `users.login`.**
 *
 * C'est l'identité canonique de SE5 : la doctrine projet est « un utilisateur
 * SE5 EST son login » (`%USERNAME%` côté poste), et c'est déjà le `sub` que SE5
 * consomme lorsqu'il est CLIENT d'un IdP fédéré (`app/Auth/Federated/`). Un
 * `sub` lisible facilite en outre le diagnostic d'une intégration d'extension.
 *
 * **Cette valeur est en cours d'arbitrage** (question ouverte de la story, à
 * trancher AVANT 55.2 qui gèle le contrat de claims — NFR11). Les alternatives
 * et leurs conséquences :
 *
 *  - `login` (actuel) — lisible, déjà l'identité SE5. ⚠️ Un renommage de login
 *    casse la continuité des données côté extension (l'extension croira voir un
 *    nouvel utilisateur).
 *  - `ad_guid` — stable au renommage (la sync AD résout d'ailleurs par GUID
 *    précisément parce que les noms changent). ⚠️ ABSENT pour les comptes
 *    non-AD (identités fédérées, `admin` local au premier boot) et opaque au
 *    débogage.
 *  - `users.id` — stable localement. ⚠️ Régénéré à une réinstallation ou un
 *    reseed de l'instance : deux instances n'ont aucune raison d'accorder le
 *    même id au même humain.
 *
 * **Pour basculer** : changer l'implémentation de {@see self::for()} — et
 * elle seule. Le nom de la colonne `oidc_authorization_codes.user_login`
 * deviendrait alors trompeur et mériterait une migration de renommage
 * (`user_sub`), mais aucune LOGIQUE ne serait à revoir.
 *
 * ⚠️ **Fail-closed** : un sujet vide n'est jamais émis. Un id_token sans `sub`
 * exploitable est pire qu'une erreur — le client construirait une session sur
 * une identité indéterminée.
 */
final class OidcSubjectResolver
{
    /**
     * Résout le claim `sub` d'un utilisateur SE5.
     *
     * @throws RuntimeException Si l'identifiant canonique est vide (fail-closed).
     */
    public static function for(User $user): string
    {
        // ───────────── POINT DE BASCULE — une seule ligne à changer ─────────────
        $subject = trim((string) $user->login);
        // ───────────────────────────────────────────────────────────────────────

        if ($subject === '') {
            throw new RuntimeException(
                'Impossible de résoudre le sujet OIDC (`sub`) : identifiant canonique vide '
                . 'pour users.id=' . ($user->id ?? '?') . ' — aucun id_token ne sera émis.'
            );
        }

        return $subject;
    }
}
