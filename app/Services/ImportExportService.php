<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImportExportService
{
    private array $config;

    public function __construct()
    {
        // Récupération de la config legacy
        $this->config = $this->getLegacyConfig();
    }

    /**
     * Import depuis un fichier CSV
     */
    public function importFromCSV(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'message' => 'Fichier non trouvé'
                ];
            }

            $data = $this->parseCSV($filePath);
            if (empty($data)) {
                return [
                    'success' => false,
                    'message' => 'Fichier CSV vide ou invalide'
                ];
            }

            $validation = $this->validateImportData($data);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'Données invalides: ' . implode(', ', $validation['errors'])
                ];
            }

            return $this->processImport($data);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'import CSV', ['file' => $filePath, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Erreur lors de l\'import: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Export vers un fichier CSV
     */
    public function exportToCSV(string $parcId = null): string
    {
        try {
            $data = $this->getExportData($parcId);
            $filename = 'export_parcs_' . date('Y-m-d_H-i-s') . '.csv';
            $filePath = storage_path('app/temp/' . $filename);

            // Création du répertoire si nécessaire
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }

            $this->writeCSV($filePath, $data);
            
            return $filePath;
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'export CSV', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Validation des données d'import
     */
    public function validateImportData(array $data): array
    {
        $errors = [];
        $valid = true;

        foreach ($data as $index => $row) {
            $rowNumber = $index + 1;
            
            // Validation des champs obligatoires
            if (empty($row['machine_name'])) {
                $errors[] = "Ligne {$rowNumber}: nom de machine manquant";
                $valid = false;
            }

            if (empty($row['parc'])) {
                $errors[] = "Ligne {$rowNumber}: parc manquant";
                $valid = false;
            }

            // Validation du format IP si présent
            if (!empty($row['ip']) && !filter_var($row['ip'], FILTER_VALIDATE_IP)) {
                $errors[] = "Ligne {$rowNumber}: adresse IP invalide ({$row['ip']})";
                $valid = false;
            }

            // Validation du format MAC si présent
            if (!empty($row['mac']) && !$this->validateMacAddress($row['mac'])) {
                $errors[] = "Ligne {$rowNumber}: adresse MAC invalide ({$row['mac']})";
                $valid = false;
            }
        }

        return [
            'valid' => $valid,
            'errors' => $errors
        ];
    }

    /**
     * Prévisualisation des données d'import
     */
    public function previewImport(string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                return [
                    'success' => false,
                    'message' => 'Fichier non trouvé'
                ];
            }

            $data = $this->parseCSV($filePath, 10); // Limite à 10 lignes pour la prévisualisation
            $totalRows = $this->countCSVRows($filePath);
            $validation = $this->validateImportData($data);

            return [
                'success' => true,
                'data' => $data,
                'validation' => $validation,
                'total_rows' => $totalRows
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors de la prévisualisation', ['file' => $filePath, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Erreur lors de la prévisualisation: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Parse un fichier CSV
     */
    private function parseCSV(string $filePath, int $limit = null): array
    {
        $data = [];
        $headers = null;
        $rowCount = 0;

        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                if ($headers === null) {
                    $headers = array_map('trim', $row);
                    continue;
                }

                if ($limit && $rowCount >= $limit) {
                    break;
                }

                $rowData = array_combine($headers, array_map('trim', $row));
                if ($rowData !== false) {
                    $data[] = $rowData;
                    $rowCount++;
                }
            }
            fclose($handle);
        }

        return $data;
    }

    /**
     * Écrit des données dans un fichier CSV
     */
    private function writeCSV(string $filePath, array $data): void
    {
        if (empty($data)) {
            return;
        }

        $handle = fopen($filePath, 'w');
        if ($handle === false) {
            throw new \Exception('Impossible de créer le fichier d\'export');
        }

        // En-têtes
        $headers = array_keys($data[0]);
        fputcsv($handle, $headers, ';');

        // Données
        foreach ($data as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);
    }

    /**
     * Récupère les données pour l'export
     */
    private function getExportData(string $parcId = null): array
    {
        try {
            $data = [];

            if (function_exists('export_parc_data')) {
                return \export_parc_data($this->config, $parcId) ?? [];
            }

            // Implémentation alternative
            if ($parcId) {
                // Export d'un parc spécifique
                if (function_exists('list_members_parc')) {
                    $machines = list_members_parc($this->config, $parcId) ?? [];
                    foreach ($machines as $machine) {
                        $data[] = $this->formatMachineForExport($machine);
                    }
                }
            } else {
                // Export global
                if (function_exists('list_parcs')) {
                    $parcs = list_parcs($this->config) ?? [];
                    foreach ($parcs as $parc) {
                        if (function_exists('list_members_parc')) {
                            $machines = list_members_parc($this->config, $parc['cn']) ?? [];
                            foreach ($machines as $machine) {
                                $machineData = $this->formatMachineForExport($machine);
                                $machineData['parc'] = $parc['cn'];
                                $data[] = $machineData;
                            }
                        }
                    }
                }
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des données d\'export', ['parc_id' => $parcId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Formate une machine pour l'export
     */
    private function formatMachineForExport(array $machine): array
    {
        return [
            'machine_name' => $machine['cn'] ?? '',
            'description' => $machine['description'] ?? '',
            'ip' => $machine['ip'] ?? '',
            'mac' => $machine['mac'] ?? '',
            'os' => $machine['os'] ?? '',
            'location' => $machine['location'] ?? '',
            'last_seen' => $machine['last_seen'] ?? '',
            'status' => $machine['status'] ?? ''
        ];
    }

    /**
     * Traite l'import des données
     */
    private function processImport(array $data): array
    {
        try {
            $imported = 0;
            $errors = [];

            foreach ($data as $index => $row) {
                $rowNumber = $index + 1;
                
                try {
                    if (function_exists('import_machine')) {
                        $result = \import_machine($this->config, $row);
                        if ($result) {
                            $imported++;
                        } else {
                            $errors[] = "Ligne {$rowNumber}: échec de l'import";
                        }
                    } else {
                        $errors[] = "Fonction d'import non disponible";
                        break;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Ligne {$rowNumber}: " . $e->getMessage();
                }
            }

            return [
                'success' => $imported > 0,
                'message' => "{$imported} machines importées" . (empty($errors) ? '' : ', ' . count($errors) . ' erreurs'),
                'imported' => $imported,
                'errors' => $errors
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors du traitement de l\'import', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Erreur lors du traitement: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Compte les lignes d'un fichier CSV
     */
    private function countCSVRows(string $filePath): int
    {
        $count = 0;
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                $count++;
            }
            fclose($handle);
        }
        return max(0, $count - 1); // -1 pour exclure les en-têtes
    }

    /**
     * Valide une adresse MAC
     */
    private function validateMacAddress(string $mac): bool
    {
        return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac);
    }

    /**
     * Récupère la configuration legacy
     */
    private function getLegacyConfig(): array
    {
        try {
            // Chargement de la config depuis les fichiers legacy
            if (function_exists('get_config')) {
                return get_config();
            }
            
            // Fallback vers des valeurs par défaut
            return [
                'ldap_server' => env('LDAP_SERVER', 'localhost'),
                'ldap_port' => env('LDAP_PORT', 389),
                'ldap_base_dn' => env('LDAP_BASE_DN', ''),
                'ldap_bind_dn' => env('LDAP_BIND_DN', ''),
                'ldap_bind_password' => env('LDAP_BIND_PASSWORD', ''),
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement de la config legacy', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
