<?php

namespace App\Services\Parc;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

// Constantes legacy pour les droits
if (!defined('SE_COMPUTER_CONTROL')) {
    define('SE_COMPUTER_CONTROL', 0x0080);
}

/**
 * Service pour la gestion de l'accès distant aux machines (Guacamole)
 * 
 * Ce service encapsule la logique legacy de l'accès distant via Guacamole
 * en utilisant les fonctions existantes du système SambaEdu.
 */
class RemoteAccessService
{
    public const DEFAULT_CONNECTION_TYPE = 'rdp';
    /**
     * @return string Absolute path to legacy sambaedu root (sibling of /laravel)
     */
    private function legacyRootPath(): string
    {
        return dirname(base_path());
    }

    private function includeLegacyConfig(): void
    {
        if (function_exists('get_config')) {
            return;
        }

        $legacyRoot = $this->legacyRootPath();
        require_once $legacyRoot . '/includes/config.inc.php';
        require_once $legacyRoot . '/includes/functions.inc.php';
    }

    private function includeLegacyRemoteStack(): void
    {
        if (function_exists('search_machine') && function_exists('create_remote_token')) {
            return;
        }

        $legacyRoot = $this->legacyRootPath();
        require_once $legacyRoot . '/includes/ldap.inc.php';
        require_once $legacyRoot . '/includes/functions.inc.php';
        require_once $legacyRoot . '/includes/remote.inc.php';
    }

    /**
     * Vérifie si le service d'accès distant est disponible
     */
    public function isRemoteAccessAvailable(): bool
    {
        try {
            $this->includeLegacyConfig();

            $config = get_config();
            return !empty($config['guacamole_url']) && file_exists('/etc/sambaedu/sambaedu.conf.d/guacamole.conf');
        } catch (\Exception $e) {
            Log::error('[RemoteAccess] Erreur vérification disponibilité: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Génère un token d'accès distant pour une machine spécifique
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
                throw new \Exception('Service d\'accès distant non disponible');
            }

            $this->includeLegacyConfig();
            $this->includeLegacyRemoteStack();

            $config = get_config();

            // Récupérer les informations de la machine
            $machine = search_machine($config, $machineName);
            if (!$machine) {
                throw new \Exception("Machine '{$machineName}' non trouvée");
            }

            // Récupérer le mot de passe utilisateur depuis la session
            $password = Session::get('passwd', '');

            // Générer le token via la fonction legacy
            $tokenUrl = create_remote_token($config, $machine, $type, $config['login'], $password, $timeout);

            if ($tokenUrl && is_string($tokenUrl)) {
                Log::info('[RemoteAccess] Token généré pour la machine', [
                    'machine' => $machineName,
                    'type' => $type,
                    'timeout' => $timeout
                ]);
                return $tokenUrl;
            }

            throw new \Exception('Échec de la génération du token');
        } catch (\Exception $e) {
            Log::error('[RemoteAccess] Erreur génération token: ' . $e->getMessage(), [
                'machine' => $machineName,
                'type' => $type
            ]);
            return null;
        }
    }

    /**
     * Génère un token d'accès distant administrateur
     * 
     * @param string $machineName Nom de la machine
     * @param string $type Type de connexion (rdp, ssh, veyon, master)
     * @param int $timeout Durée de validité du token en secondes
     * @return string|null URL de connexion Guacamole ou null en cas d'erreur
     */
    public function generateAdminRemoteToken(string $machineName, string $type = 'rdp', int $timeout = 7200): ?string
    {
        try {
            if (!$this->isRemoteAccessAvailable()) {
                throw new \Exception('Service d\'accès distant non disponible');
            }

            $this->includeLegacyConfig();
            $this->includeLegacyRemoteStack();

            $config = get_config();

            // Récupérer les informations de la machine
            $machine = search_machine($config, $machineName);
            if (!$machine) {
                throw new \Exception("Machine '{$machineName}' non trouvée");
            }

            // Générer le token admin via la fonction legacy
            $tokenUrl = create_remote_admin_token($config, $machineName, $type, $timeout);

            if ($tokenUrl && is_string($tokenUrl)) {
                Log::info('[RemoteAccess] Token admin généré pour la machine', [
                    'machine' => $machineName,
                    'type' => $type,
                    'timeout' => $timeout
                ]);
                return $tokenUrl;
            }

            throw new \Exception('Échec de la génération du token admin');
        } catch (\Exception $e) {
            Log::error('[RemoteAccess] Erreur génération token admin: ' . $e->getMessage(), [
                'machine' => $machineName,
                'type' => $type
            ]);
            return null;
        }
    }

    /**
     * Retourne les types de connexions disponibles
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
     * Vérifie si l'utilisateur a les droits pour l'accès distant
     * 
     * @return bool
     */
    public function hasRemoteAccessRights(): bool
    {
        try {
            $this->includeLegacyConfig();
            $config = get_config();

            // Inclure les fonctions legacy nécessaires
            if (!function_exists('have_right')) {
                require_once $this->legacyRootPath() . '/includes/functions.inc.php';
            }

            // Vérifier les droits de contrôle de machine
            return have_right($config, SE_COMPUTER_CONTROL);
        } catch (\Exception $e) {
            Log::error('[RemoteAccess] Erreur vérification droits: ' . $e->getMessage());
            return false;
        }
    }
}
