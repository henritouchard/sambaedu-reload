<?php

namespace App\Http\Middleware\Auth;

use App\Models\User;
use App\Services\AuthenticationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guard de test pour les e2e Laravel Dusk (host isolé).
 *
 * Le {@see SambaEduAuthGuard} revérifie l'existence de l'utilisateur dans le
 * LDAP/AD à CHAQUE requête (`UserRepository::findByLogin`), injoignable hors
 * VM → il déconnecterait la session Dusk aussitôt. Ce guard reproduit le
 * contrat minimal (session valide → `Auth::login` d'un `User` Eloquent) SANS
 * aucun accès annuaire : la source de vérité devient la table `users` SQL.
 *
 * Bindé UNIQUEMENT sous `APP_ENV=dusk` via {@see \App\Providers\AppServiceProvider}.
 * Défense en profondeur : `handle()` abort() si l'environnement n'est pas dusk.
 */
class DuskAuthGuard implements AuthGuardInterface
{
    public function __construct(private AuthenticationService $authService) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment('dusk'), 500, 'DuskAuthGuard hors environnement dusk');

        if (! $this->authService->isAlreadyAuthenticated()) {
            return $this->unauthorized($request);
        }

        $login = $this->authService->getCurrentUser();
        if (empty($login)) {
            return $this->unauthorized($request);
        }

        // Find-or-create : parité avec l'auto-provisioning du guard réel, mais
        // sans passer par un objet AD (source LDAP indisponible).
        $user = User::firstOrCreate(
            ['login' => $login],
            ['fullname' => $login, 'role' => 'admin', 'is_active' => true],
        );

        $request->attributes->set('sambaedu_user', $user);
        $request->attributes->set('sambaedu_login', $login);

        if (Auth::id() !== $user->id) {
            Auth::login($user);
        }

        return $next($request);
    }

    private function unauthorized(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => 'Unauthorized', 'auth_url' => '/login'], 401);
        }

        session()->put('url.intended', $request->path());

        return redirect(route('auth.login'));
    }
}
