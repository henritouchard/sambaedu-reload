<?php

/**
 * Contrôleur pour la gestion du changement de mot de passe de l'utilisateur 
 * pour la page de changement de mdp lors du login.
 */

namespace App\Http\Controllers;

use App\Constants\Errors\AuthenticationErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Services\AuthenticationService;
use App\Services\UserService;

class ChangePasswordController extends Controller
{
    private AuthenticationService $authService;
    private UserService $userService;

    public function __construct(AuthenticationService $authService, UserService $userService)
    {
        $this->authService = $authService;
        $this->userService = $userService;
    }

    public function index(Request $request)
    {
        $token = $request->get('token');
        $tokenData = $request->get('token_data'); // Fourni par le middleware
        
        if (!$token || !$tokenData) {
            return redirect()->route('auth.login')
                ->with('toast_error', 'Token manquant ou invalide');
        }
        
        return view('changePassword.index', [
            'login' => $tokenData['login'],
            'token' => $token,
            'expires_at' => date('H:i:s', $tokenData['expires'])
        ]);
    }

    public function changePassword(Request $request)
    {
        $tokenData = $request->get('token_data'); // Fourni par le middleware
        
        if (!$tokenData) {
            return redirect()->route('auth.login')
                ->with('toast_error', 'Session expirée');
        }

        // Validation des données
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|min:1',
            'new_password' => 'required|min:8|confirmed',
            'new_password_confirmation' => 'required|min:8'
        ], [
            'current_password.required' => 'Le mot de passe actuel est requis',
            'current_password.min' => 'Le mot de passe actuel est requis',
            'new_password.required' => 'Le nouveau mot de passe est requis',
            'new_password.min' => 'Le nouveau mot de passe doit contenir au moins 8 caractères',
            'new_password.confirmed' => 'La confirmation du mot de passe ne correspond pas',
            'new_password_confirmation.required' => 'La confirmation du mot de passe est requise',
            'new_password_confirmation.min' => 'La confirmation du mot de passe doit contenir au moins 8 caractères'
        ]);

        if ($validator->fails()) {
            $errorMessages = $validator->errors()->all();
            $errorMessage = implode(' ', $errorMessages);
            
            return redirect()->back()
                ->with('toast_error', $errorMessage)
                ->withInput();
        }

        try {
            // Utiliser l'utilisateur du token au lieu de la session
            $currentUser = $tokenData['login'];

            // Vérifier le mot de passe actuel
            $authResult = $this->authService->authenticate($currentUser, $request->current_password, $request->ip());

            if (!$authResult['success'] && ($authResult['code'] ?? '') !== AuthenticationErrors::ERROR_AUTHENTICATION_PASSWORD_CHANGE_REQUIRED) {
                return redirect()->back()
                    ->with('toast_error', 'Le mot de passe actuel est incorrect')
                    ->withInput();
            }

            // Changer le mot de passe via UserService (LdapRecord)
            $changeResult = $this->userService->changePasswordInAd($currentUser, $request->new_password, false);
            
            if ($changeResult) {
                // Marquer le token comme utilisé
                $this->authService->markTokenAsUsed();
                
                // Créer une vraie session utilisateur
                $this->authService->createSession($currentUser);
                
                Log::info('Mot de passe modifié avec succès', [
                    'user' => $currentUser,
                    'ip' => $request->ip()
                ]);

                return redirect()->route('app.dashboard')
                    ->with('toast_success', 'Votre mot de passe a été modifié avec succès');
            } else {
                return redirect()->back()
                    ->with('toast_error', 'Erreur lors de la modification du mot de passe')
                    ->withInput();
            }

        } catch (\Exception $e) {
            Log::error('Erreur lors du changement de mot de passe', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user' => $currentUser ?? 'unknown',
                'ip' => $request->ip()
            ]);

            return redirect()->back()
                ->with('toast_error', 'Une erreur technique est survenue')
                ->withInput();
        }
    }

}
