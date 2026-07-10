<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Models\Workstation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

/**
 * Service de gestion de l'accès distant aux machines (Guacamole).
 *
 * Story 38.4 (AC2) — **port natif** : ce service ne charge plus AUCUN fichier
 * legacy `/var/www/sambaedu/includes/*` (les anciens `legacyRootPath()` /
 * `includeLegacyConfig()` / `includeLegacyRemoteStack()` pointaient un
 * `dirname(base_path())/includes` DÉJÀ MORT depuis le root-move,
 * `project_root_is_laravel`). Il :
 *  - résout la machine via le modèle {@see Workstation} (SQL, Postgres-first) ;
 *  - construit et signe le token via {@see GuacamoleTokenService} (port 1:1) ;
 *  - vérifie les droits via la permission Spatie native `computer.control`
 *    (l'ancien bitmask legacy `SE_COMPUTER_CONTROL` — divergent 0x0080/0x200
 *    entre le service et le shim ldap — n'est plus perpétué).
 *
 * Le mot de passe utilisateur reste lu depuis la session (`passwd`), auth
 * iso-legacy (`feedback_auth_iso_legacy`).
 */
class RemoteAccessService
{
    public const DEFAULT_CONNECTION_TYPE = 'rdp';

    public function __construct(
        private readonly GuacamoleTokenService $tokenService,
    ) {}

    /**
     * Vérifie si le service d'accès distant est disponible.
     */
    public function isRemoteAccessAvailable(): bool
    {
        try {
            return $this->tokenService->isAvailable();
        } catch (\Throwable $e) {
            Log::error('[RemoteAccess] Erreur vérification disponibilité: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Génère un token d'accès distant pour une machine spécifique.
     *
     * @param string $machineName Nom de la machine
     * @param string $type Type de connexion (rdp, ssh, veyon, master)
     * @param int $timeout Durée de validité du token en secondes
     * @return string|null URL de connexion Guacamole ou null en cas d'erreur
     */
    public function generateRemoteToken(string $machineName, string $type = 'rdp', int $timeout = 7200): ?string
    {
        try {
            if (!$this->isRemoteAccessAvailable()) {
                throw new \RuntimeException('Service d\'accès distant non disponible');
            }

            $machine = $this->resolveMachine($machineName);
            if ($machine === null) {
                throw new \RuntimeException("Machine '{$machineName}' non trouvée");
            }

            $login = (string) (Auth::user()?->login ?? Session::get('login', ''));
            $password = (string) Session::get('passwd', '');

            $tokenUrl = $this->tokenService->createRemoteToken($machine, $type, $login, $password, $timeout);

            if ($tokenUrl !== null) {
                Log::info('[RemoteAccess] Token généré pour la machine', [
                    'machine' => $machineName,
                    'type' => $type,
                    'timeout' => $timeout,
                ]);

                return $tokenUrl;
            }

            throw new \RuntimeException('Échec de la génération du token');
        } catch (\Throwable $e) {
            Log::error('[RemoteAccess] Erreur génération token: ' . $e->getMessage(), [
                'machine' => $machineName,
                'type' => $type,
            ]);

            return null;
        }
    }

    /**
     * Génère un token d'accès distant administrateur.
     *
     * Story 38.4 : le legacy `create_remote_admin_token` n'a pas été porté
     * (aucun consommateur SE5 — `generateAdminRemoteToken` n'est appelé nulle
     * part). On délègue à `generateRemoteToken` (même token JSON chiffré) pour
     * conserver une signature stable si un appelant réapparaissait.
     *
     * @param string $machineName Nom de la machine
     * @param string $type Type de connexion (rdp, ssh, veyon, master)
     * @param int $timeout Durée de validité du token en secondes
     * @return string|null URL de connexion Guacamole ou null en cas d'erreur
     */
    public function generateAdminRemoteToken(string $machineName, string $type = 'rdp', int $timeout = 7200): ?string
    {
        return $this->generateRemoteToken($machineName, $type, $timeout);
    }

    /**
     * Retourne les types de connexions disponibles.
     *
     * @return array<int, array{key: string, label: string, icon: string, description: string}>
     */
    public function getAvailableConnectionTypes(): array
    {
        return [
            [
                'key' => 'rdp',
                'label' => 'Bureau à distance (RDP)',
                'icon' => 'fa-solid fa-desktop',
                'description' => 'Connexion RDP standard au bureau de la machine',
            ],
            [
                'key' => 'ssh',
                'label' => 'Terminal SSH',
                'icon' => 'fa-solid fa-terminal',
                'description' => 'Accès terminal root à la machine (serveurs uniquement)',
            ],
            [
                'key' => 'veyon',
                'label' => 'Veyon Poste',
                'icon' => 'fa-solid fa-eye',
                'description' => 'Accès Veyon pour surveiller et contrôler le poste',
            ],
            [
                'key' => 'master',
                'label' => 'Veyon Master',
                'icon' => 'fa-solid fa-chalkboard',
                'description' => 'Console Veyon Master pour gestion de classe',
            ],
        ];
    }

    /**
     * Vérifie si l'utilisateur a les droits pour l'accès distant.
     *
     * Story 38.4 : permission Spatie native `computer.control` (aligne sur les
     * gates parc de {@see \App\Services\Parc\WorkstationGroupService} et
     * {@see \App\Policies\WorkstationGroupPolicy}) — remplace l'ancien
     * `have_right($config, SE_COMPUTER_CONTROL)` legacy.
     */
    public function hasRemoteAccessRights(): bool
    {
        try {
            $user = Auth::user();
            if ($user === null) {
                return false;
            }

            return method_exists($user, 'can')
                ? (bool) $user->can('computer.control')
                : false;
        } catch (\Throwable $e) {
            Log::error('[RemoteAccess] Erreur vérification droits: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Résout une machine par son nom en une structure legacy-like consommée par
     * {@see GuacamoleTokenService} (`cn`, `etab`). Retourne `null` si absente.
     *
     * @return array<string,mixed>|null
     */
    private function resolveMachine(string $machineName): ?array
    {
        $name = strtolower(trim($machineName));
        $workstation = Workstation::query()->whereRaw('LOWER(name) = ?', [$name])->first();

        if ($workstation === null) {
            return null;
        }

        return [
            'cn' => $workstation->name,
            'etab' => (string) config('sambaedu.etab_ou', ''),
        ];
    }
}
