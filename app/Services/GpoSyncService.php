<?php

namespace App\Services;

use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Service de synchronisation GPO pour les délégations computer.elevate
 *
 * Conception unidirectionnelle SQL → AD : ce service écrit dans l'AD,
 * jamais l'inverse. Il restera identique après la transition source de vérité.
 *
 * Ne concerne que la permission 'computer.elevate' (admin local temporaire).
 * Les autres permissions sont vérifiées côté web uniquement.
 *
 * @deprecated Story 16.1 (Epic 16) — sera replié dans
 *             {@see \App\Gpo\Services\GpoService} à partir de Story 16.4+.
 *             Ne pas ajouter de nouvelle logique métier dans cette classe :
 *             toute évolution doit aller dans le namespace `App\Gpo`.
 *             Le service reste vivant pendant toute la transition Epic 16
 *             pour ne pas casser les délégations `computer.elevate` existantes
 *             (Spatie\Permission). Suppression effective : Story 16.4+ après
 *             implémentation du volet écriture (`create`, `setLink`, etc.).
 * @see \App\Gpo\Services\GpoService
 * @see _bmad-output/implementation-artifacts/16-1-fondations-gpo-natives-audit-legacy.md AC3.4
 */
class GpoSyncService
{
    /**
     * Synchronise la GPO pour une délégation computer.elevate accordée
     * 
     * Crée ou met à jour la GPO qui ajoute l'utilisateur au groupe
     * Administrateurs locaux (S-1-5-32-544) des machines du WorkstationGroup.
     */
    public function syncGpoForGrant(User $user, WorkstationGroup $group): bool
    {
        if (!$group->is_physical) {
            Log::warning('[GpoSyncService] Tentative de sync GPO sur un groupe non-physique', [
                'group' => $group->name,
            ]);
            return false;
        }

        Log::info('[GpoSyncService] Sync GPO pour délégation accordée', [
            'user' => $user->login,
            'group' => $group->name,
        ]);

        // Appel aux fonctions legacy si disponibles
        if (function_exists('add_delegation_salle') && function_exists('get_config')) {
            try {
                $config = get_config();
                $result = add_delegation_salle($config, $user->login, $group->name);

                Log::info('[GpoSyncService] GPO créée via legacy', [
                    'user' => $user->login,
                    'group' => $group->name,
                    'result' => $result,
                ]);

                return (bool) $result;
            } catch (\Exception $e) {
                Log::error('[GpoSyncService] Erreur création GPO via legacy', [
                    'user' => $user->login,
                    'group' => $group->name,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        // Fallback : samba-tool directement
        return $this->syncGpoViaSambaTool($user, $group, 'grant');
    }

    /**
     * Supprime la GPO pour une délégation computer.elevate révoquée
     */
    public function syncGpoForRevoke(User $user, WorkstationGroup $group): bool
    {
        if (!$group->is_physical) {
            return false;
        }

        Log::info('[GpoSyncService] Sync GPO pour délégation révoquée', [
            'user' => $user->login,
            'group' => $group->name,
        ]);

        // Appel aux fonctions legacy si disponibles
        if (function_exists('remove_delegation_salle') && function_exists('get_config')) {
            try {
                $config = get_config();
                $result = remove_delegation_salle($config, $user->login, $group->name);

                Log::info('[GpoSyncService] GPO supprimée via legacy', [
                    'user' => $user->login,
                    'group' => $group->name,
                    'result' => $result,
                ]);

                return (bool) $result;
            } catch (\Exception $e) {
                Log::error('[GpoSyncService] Erreur suppression GPO via legacy', [
                    'user' => $user->login,
                    'group' => $group->name,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        return $this->syncGpoViaSambaTool($user, $group, 'revoke');
    }

    /**
     * Fallback : utilise samba-tool directement
     */
    private function syncGpoViaSambaTool(User $user, WorkstationGroup $group, string $action): bool
    {
        // TODO: Implémenter la création/suppression de GPO via samba-tool
        // quand les fonctions legacy ne sont pas disponibles
        Log::warning('[GpoSyncService] samba-tool fallback pas encore implémenté', [
            'user' => $user->login,
            'group' => $group->name,
            'action' => $action,
        ]);

        return false;
    }

    /**
     * Vérifie si une délégation nécessite une synchronisation GPO
     */
    public static function requiresGpoSync(string $permissionName): bool
    {
        return $permissionName === 'computer.elevate';
    }
}
