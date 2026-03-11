<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;
use App\Services\AuthenticationService;
use App\Repositories\UserRepository;
use App\Types\User;

/**
 * Middleware pour vérifier que l'utilisateur a les droits d'administration
 * 
 * Utilise les services Laravel modernes (AuthenticationService, UserRepository)
 * au lieu des fonctions legacy (have_right, search_user, etc.)
 * 
 * Doit être utilisé APRÈS le middleware SambaEduAuth
 */
class RequireAdminRights
{
    public function __construct(
        private AuthenticationService $authService,
        private UserRepository $userRepository
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Récupérer l'utilisateur depuis la requête (déjà vérifié par SambaEduAuth)
        $user = $request->attributes->get('sambaedu_user');
        $login = $request->attributes->get('sambaedu_login');

        // Si pas d'utilisateur dans la requête, essayer de le récupérer
        if (!$user && $login) {
            $user = $this->userRepository->findByLogin($login);
        }

        // Si toujours pas d'utilisateur, récupérer depuis le service d'auth
        if (!$user) {
            $login = $this->authService->getCurrentUser();
            if ($login) {
                $user = $this->userRepository->findByLogin($login);
            }
        }

        if (!$user) {
            Log::warning('RequireAdminRights: Aucun utilisateur trouvé');
            return $this->unauthorized($request);
        }

        // Vérifier les droits admin via le DTO User
        if (!$this->hasAdminRights($user)) {
            Log::warning('RequireAdminRights: Accès non autorisé', [
                'login' => $user->login,
                'role' => $user->role
            ]);
            return $this->unauthorized($request, 'Vous n\'avez pas les droits d\'administration nécessaires');
        }

        return $next($request);
    }

    /**
     * Vérifie si l'utilisateur a les droits d'administration
     * 
     * Un utilisateur est admin s'il :
     * - Est membre du groupe Administrateurs ou Domain Admins
     * - Ou a des droits spécifiques dans ses groupes de droits
     */
    private function hasAdminRights(User $user): bool
    {
        // Vérifier via la méthode isAdmin() du DTO
        if ($user->isAdmin()) {
            return true;
        }

        // Vérifier les droits spécifiques dans les groupes de droits
        // Les droits SambaEdu sont stockés dans les groupes de la branche "rights"
        $adminRightGroups = [
            'user_admin',
            'computer_admin',
            'admin_se4',
            'admins',
            'administrateurs',
        ];

        foreach ($user->rights as $right) {
            $rightLower = strtolower($right);
            foreach ($adminRightGroups as $adminRight) {
                if (str_contains($rightLower, $adminRight)) {
                    return true;
                }
            }
        }

        // Vérifier aussi dans les groupes généraux
        foreach ($user->groups as $group) {
            $groupLower = strtolower($group);
            if (
                str_contains($groupLower, 'admin') ||
                str_contains($groupLower, 'domain admins')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne une réponse non autorisée
     */
    private function unauthorized(Request $request, string $message = 'Accès non autorisé'): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => $message
            ], 403);
        }

        return redirect()->back()->with('error', $message);
    }
}
