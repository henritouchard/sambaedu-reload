<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

/**
 * Story 57.1 — **L'ANTI-REJEU `jti` CÔTÉ CLIENT.**
 *
 * SE5 ÉMET un `jti` (UUID v4) dans chaque id_token, mais il ne REVOIT jamais un
 * id_token : les seuls jetons qu'il reçoit sont des codes d'autorisation (usage
 * unique sous verrou, côté fournisseur) et des access tokens opaques. L'usage
 * unique de l'id_token se joue donc chez le CONSOMMATEUR — ici.
 *
 * Interface plutôt que classe concrète pour une seule raison : le vérificateur
 * doit être testable sans base, et l'implémentation de production doit pouvoir
 * changer de stockage sans que le vérificateur ne bouge.
 */
interface ReplayGuard
{
    /**
     * `true` = premier usage ; `false` = rejeu OU impossibilité de trancher.
     *
     * **Fail-closed** : magasin indisponible, jeton déjà expiré, exception —
     * on refuse. Un jeton d'entrée humain ne s'accepte pas dans le doute.
     *
     * @param  int  $expiresAt  Inutile de mémoriser un `jti` au-delà de
     *                          l'expiration du jeton : le vérificateur le
     *                          rejetterait de toute façon.
     */
    public function consumeOnce(string $jti, int $expiresAt): bool;
}
