<?php

namespace App\Services;

use App\Models\DelegationHistory;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service d'écriture dans l'historique (audit trail) des délégations.
 *
 * Point d'entrée unique pour tracer une opération métier (grant / revoke /
 * negate / expire). Le service ne fait PAS l'opération elle-même — il est
 * appelé par `PermissionService` après la persistance DB côté `delegations`.
 *
 * Idempotence : le service n'impose pas d'unicité sur les lignes d'historique
 * (N `grant` successifs = N lignes, c'est le journal). L'idempotence métier
 * se joue en amont dans `PermissionService::grantDelegation` via `updateOrCreate`
 * sur la clé (user, group, permission, is_negative). `log()` est appelé
 * uniquement si l'opération a effectivement modifié la donnée.
 *
 * Échec d'écriture audit : un échec est loggué en `error` mais n'annule pas
 * l'opération métier — le service n'a pas à faire foirer un grant réussi.
 * L'AC5 exige un log d'erreur + toast admin : le log est fait ici, le toast
 * est la responsabilité du caller Livewire (qui catch l'exception).
 */
class DelegationHistoryService
{
    /**
     * Persiste une entrée d'historique pour une opération de délégation.
     *
     * @param string $action            `grant` | `revoke` | `negate` | `expire`
     * @param User|null $actor          Qui a agi (null si acteur inconnu / système)
     * @param User|null $target         Cible de la délégation (null si déjà supprimé)
     * @param WorkstationGroup|null $group Périmètre (null si déjà supprimé)
     * @param string $permissionName    Nom Spatie de la permission
     * @param bool $isNegative          true si délégation négative (exclusion)
     * @param array $context            Métadonnées libres (IP, UA, batch_id, …)
     *
     * @return DelegationHistory|null   La ligne créée, ou null si échec d'écriture
     */
    public function log(
        string $action,
        ?User $actor,
        ?User $target,
        ?WorkstationGroup $group,
        string $permissionName,
        bool $isNegative = false,
        array $context = []
    ): ?DelegationHistory {
        if (!in_array($action, DelegationHistory::ACTIONS, true)) {
            Log::error('[DelegationHistoryService] Action inconnue', [
                'action' => $action,
                'permission' => $permissionName,
            ]);
            return null;
        }

        try {
            return DelegationHistory::create([
                'actor_user_id' => $actor?->id,
                'target_user_id' => $target?->id,
                'workstation_group_id' => $group?->id,
                'permission_name' => $permissionName,
                'action' => $action,
                'is_negative' => $isNegative,
                'context' => !empty($context) ? $context : null,
            ]);
        } catch (Throwable $e) {
            // AC5 : un échec d'écriture audit ne doit pas faire passer l'op
            // en silence. On log ici en error — le caller (service ou composant
            // Livewire) décide s'il remonte un toast admin.
            Log::error('[DelegationHistoryService] Échec persistance historique', [
                'action' => $action,
                'actor_id' => $actor?->id,
                'target_id' => $target?->id,
                'group_id' => $group?->id,
                'permission' => $permissionName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Résout l'acteur courant si non fourni explicitement.
     *
     * Pattern utilisé par PermissionService : si l'appelant ne passe pas
     * explicitement un $actor, on retombe sur `auth()->user()`. Accepte
     * uniquement un `App\Models\User` (pas d'Authenticatable générique :
     * les Policies Spatie opèrent sur le modèle Eloquent).
     */
    public function resolveActor(?User $actor): ?User
    {
        if ($actor !== null) {
            return $actor;
        }

        $auth = auth()->user();
        return $auth instanceof User ? $auth : null;
    }
}
