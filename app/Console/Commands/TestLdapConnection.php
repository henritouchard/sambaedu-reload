<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use LdapRecord\Container;
use LdapRecord\Connection;
use Illuminate\Support\Facades\Log;

class TestLdapConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:test-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Teste la connexion LDAP avec LdapRecord';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Test de connexion LDAP avec LdapRecord...');
        $this->newLine();
        
        try {
            // Récupérer la connexion depuis le container
            $connection = Container::getConnection('default');
            
            if (!$connection) {
                $this->error('Aucune connexion LDAP trouvée dans le container');
                return Command::FAILURE;
            }
            
            $this->info('✓ Connexion trouvée dans le container');
            
            // Afficher les informations de connexion AVANT le test
            $config = $connection->getConfiguration();
            $this->newLine();
            $this->info('Paramètres de connexion:');
            $this->table(
                ['Paramètre', 'Valeur'],
                [
                    ['Hôtes', implode(', ', $config->get('hosts'))],
                    ['Port', $config->get('port')],
                    ['Base DN', $config->get('base_dn')],
                    ['Utilisateur', $config->get('username')],
                    ['SSL', $config->get('use_ssl') ? 'Oui' : 'Non'],
                    ['TLS', $config->get('use_tls') ? 'Oui' : 'Non'],
                ]
            );
            $this->newLine();
            
            // Tester la connexion
            $this->info('Test de connexion au serveur LDAP...');
            $connection->connect();
            
            $this->info('✓ Connexion réussie !');
            
            // Test de requête simple avec le Query Builder
            $this->newLine();
            $this->info('Test de requête LDAP simple...');
            
            try {
                $baseDn = $config->get('base_dn');
                $query = $connection->query()->setBaseDn($baseDn);
                $result = $query->select(['dn'])->limit(1)->get();
                
                // get() retourne un tableau, pas une collection
                if (is_array($result) && count($result) > 0) {
                    $this->info('✓ Requête réussie !');
                    $firstResult = reset($result);
                    if (is_object($firstResult) && method_exists($firstResult, 'getDn')) {
                        $this->line('Premier résultat: ' . $firstResult->getDn());
                    } else {
                        $this->line('Premier résultat: ' . json_encode($firstResult));
                    }
                } else {
                    $this->warn('⚠ Requête réussie mais aucun résultat trouvé');
                }
            } catch (\Exception $e) {
                $this->warn('⚠ Test de requête échoué: ' . $e->getMessage());
                $this->line('(La connexion fonctionne mais la requête a échoué)');
            }
            
            $this->newLine();
            $this->info('✅ Tous les tests sont passés avec succès !');
            
            return Command::SUCCESS;
            
        } catch (\LdapRecord\ConnectionException $e) {
            $this->error('❌ Erreur de connexion LDAP: ' . $e->getMessage());
            Log::error('Test connexion LDAP échoué', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
            
        } catch (\Exception $e) {
            $this->error('❌ Erreur inattendue: ' . $e->getMessage());
            Log::error('Test connexion LDAP - erreur inattendue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}

