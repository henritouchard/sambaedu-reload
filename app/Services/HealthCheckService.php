<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;

/**
 * Service de vérification de l'état de santé de l'application
 * 
 * Effectue des vérifications sur les composants critiques de l'application
 * (base de données, stockage, version) et retourne un rapport d'état.
 */
class HealthCheckService
{
    /**
     * Effectue une vérification complète de l'état de santé de l'application
     * 
     * Vérifie les composants suivants :
     * - Connexion à la base de données
     * - Accès en lecture/écriture au système de fichiers
     * - Version de l'application et de Laravel
     * - Environnement d'exécution
     * - Version PHP
     * 
     * @return array Tableau contenant l'état de chaque composant et un statut global
     * @example
     * [
     *   'database' => ['status' => true, 'message' => '...'],
     *   'storage' => ['status' => true, 'message' => '...'],
     *   'version' => ['status' => true, 'version' => '1.0.0'],
     *   'environment' => 'production',
     *   'php_version' => '8.2.0',
     *   'response_time' => 45.23,
     *   'status' => true
     * ]
     */
    public function check(): array
    {
        $startTime = microtime(true);

        $checks = [
            'database' => $this->checkDatabase(),
            'storage' => $this->checkStorage(),
            'version' => $this->getVersion(),
            'environment' => config('app.env'),
            'php_version' => PHP_VERSION,
        ];

        $checks['response_time'] = round((microtime(true) - $startTime) * 1000, 2); // en millisecondes
        $checks['status'] = !in_array(false, array_column($checks, 'status', null), true);

        return $checks;
    }

    /**
     * Vérifie la connexion à la base de données
     * 
     * Tente d'établir une connexion PDO à la base de données configurée.
     * 
     * @return array Statut de la connexion avec le type de connexion utilisé
     * @example ['status' => true, 'message' => 'Base de données connectée', 'connection' => 'mysql']
     */
    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return [
                'status' => true,
                'message' => 'Base de données connectée',
                'connection' => config('database.default')
            ];
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Erreur de connexion à la base de données',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Vérifie l'accès en lecture/écriture au système de fichiers
     * 
     * Crée un fichier de test temporaire puis le supprime pour valider
     * que l'application peut écrire et supprimer des fichiers.
     * 
     * @return array Statut de l'accès au stockage avec le chemin du répertoire
     * @example ['status' => true, 'message' => 'Système de fichiers accessible', 'path' => '/var/www/...']
     */
    private function checkStorage(): array
    {
        try {
            $testFile = 'health_check_test.txt';
            Storage::put($testFile, 'test');
            Storage::delete($testFile);

            return [
                'status' => true,
                'message' => 'Système de fichiers accessible en lecture/écriture',
                'path' => Storage::path('')
            ];
        } catch (Exception $e) {
            return [
                'status' => false,
                'message' => 'Erreur d\'accès au système de fichiers',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Récupère les informations de version de l'application
     * 
     * Lit le fichier composer.json pour obtenir la version de l'application
     * et récupère la version de Laravel via l'application.
     * 
     * @return array Versions de l'application et de Laravel
     * @example ['status' => true, 'version' => '1.0.0', 'laravel_version' => '10.x']
     */
    private function getVersion(): array
    {
        $composerJson = json_decode(file_get_contents(base_path('composer.json')), true);
        return [
            'status' => true,
            'version' => $composerJson['version'] ?? '1.0.0',
            'laravel_version' => app()->version()
        ];
    }
}