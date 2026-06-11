<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\StateScope;

/**
 * Source unique du contrat d'état cible `se5.desired-state/v1`.
 *
 * Le nom du schéma est un irréversible figé (NFR12) : c'est une **constante**,
 * jamais une variable d'environnement. Un agent déployé fige le wire format ;
 * toute évolution passe par un bump explicite (`v2`) + golden files mis à jour
 * (cf. `docs/agent/contract-v1.md`, règle d'évolution).
 *
 * Cette story ne crée **pas** `config/agent.php` (relève de 23.5) : le serveur
 * et les tests référencent ces constantes directement.
 */
final class StateContract
{
    /** Version du contrat. L'agent refuse un major inconnu. */
    public const SCHEMA = 'se5.desired-state/v1';

    /** Clés d'enveloppe des trois portées (= valeurs de {@see StateScope}). */
    public const SCOPE_MACHINE = 'machine';

    public const SCOPE_SESSION = 'session';

    public const SCOPE_MACHINE_USER = 'machine_user';

    /**
     * Les trois portées de l'enveloppe, dans l'ordre canonique.
     *
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [
            StateScope::Machine->value,
            StateScope::Session->value,
            StateScope::MachineUser->value,
        ];
    }
}
