<?php

require_once 'vendor/autoload.php';

// Configuration des variables d'environnement
$_ENV['DB_USERNAME'] = 'laravel';
$_ENV['DB_PASSWORD'] = 'laravel123';

// Initialisation de Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SE4FSApiToken;

echo "🚀 Génération d'un token SE4FS valide\n";
echo "====================================\n\n";

try {
    // 1. Générer un nouveau token
    echo "🔐 Étape 1: Génération d'un token SE4FS\n";
    
    $token = SE4FSApiToken::generateToken();
    $webhookToken = SE4FSApiToken::generateWebhookToken();
    
    echo "✅ Token API généré: " . $token . "\n";
    echo "✅ Token Webhook généré: " . $webhookToken . "\n\n";
    
    // 2. Sauvegarder le token en base
    echo "💾 Étape 2: Sauvegarde en base de données\n";
    
    $tokenRecord = SE4FSApiToken::create([
        'instance_id' => 'curl-test-' . time(),
        'token_hash' => SE4FSApiToken::hashToken($token),
        'client_name' => 'Test cURL Client',
        'client_url' => 'https://curl-test.example.com',
        'client_version' => '1.0.0',
        'webhook_url' => 'https://curl-test.example.com/webhook',
        'webhook_token_hash' => SE4FSApiToken::hashToken($webhookToken),
        'capabilities' => ['user_sync', 'file_sharing', 'monitoring'],
        'expires_at' => now()->addDays(90),
        'created_by_ip' => '127.0.0.1',
    ]);
    
    echo "✅ Token sauvegardé avec ID: " . $tokenRecord->id . "\n";
    echo "✅ Instance ID: " . $tokenRecord->instance_id . "\n";
    echo "✅ Expire le: " . $tokenRecord->expires_at->format('Y-m-d H:i:s') . "\n\n";
    
    // 3. Vérifier la validation
    echo "🔍 Étape 3: Vérification de la validation\n";
    
    $isValid = SE4FSApiToken::validateToken($token);
    echo "✅ Validation: " . ($isValid ? 'VALIDE' : 'INVALIDE') . "\n\n";
    
    // 4. Fournir les commandes curl
    echo "📋 Étape 4: Commandes curl pour tester\n";
    echo "=====================================\n\n";
    
    $serverUrl = "http://192.168.122.50:80";
    
    echo "🌐 Tests avec votre serveur ($serverUrl):\n\n";
    
    $endpoints = [
        'metrics' => '/api/v1/metrics',
        'health' => '/api/v1/health',
        'stats' => '/api/v1/stats',
        'static' => '/api/v1/static',
        'users' => '/api/v1/users'
    ];
    
    foreach ($endpoints as $name => $endpoint) {
        echo "📊 Test $name:\n";
        echo "curl -s -w \"\\nStatus: %{http_code}\\n\" \\\n";
        echo "     -H \"Authorization: Bearer $token\" \\\n";
        echo "     -H \"Accept: application/json\" \\\n";
        echo "     \"$serverUrl$endpoint\"\n\n";
    }
    
    // 5. Test avec endpoint public
    echo "🌍 Test endpoint public (sans authentification):\n";
    echo "curl -s -w \"\\nStatus: %{http_code}\\n\" \\\n";
    echo "     -H \"Accept: application/json\" \\\n";
    echo "     \"$serverUrl/api/v1/public/health\"\n\n";
    
    // 6. Informations de débogage
    echo "🔧 Informations de débogage\n";
    echo "===========================\n\n";
    
    echo "📋 Format du token:\n";
    echo "   - Préfixe: " . substr($token, 0, 6) . "\n";
    echo "   - Longueur: " . strlen($token) . " caractères\n";
    echo "   - Hash SHA-256: " . substr(SE4FSApiToken::hashToken($token), 0, 20) . "...\n\n";
    
    echo "📋 Différence avec votre token:\n";
    echo "   - Votre token: irundo_iJkEgJzLYx8j71RyMhuzvba9BrOJHW7b\n";
    echo "   - Token valide: $token\n";
    echo "   - Problème: Format incorrect (irundo_ au lieu de se4fs_)\n\n";
    
    echo "📋 Statut des tokens en base:\n";
    $totalTokens = SE4FSApiToken::count();
    $activeTokens = SE4FSApiToken::where('is_active', true)
                                 ->where('expires_at', '>', now())
                                 ->count();
    
    echo "   - Total tokens: $totalTokens\n";
    echo "   - Tokens actifs: $activeTokens\n";
    echo "   - Dernier token créé: " . $tokenRecord->created_at->format('Y-m-d H:i:s') . "\n\n";
    
    // 7. Commande de test rapide
    echo "🚀 Test rapide (copiez-collez cette commande):\n";
    echo "===============================================\n\n";
    
    echo "curl -s -w \"\\nStatus: %{http_code}\\n\" \\\n";
    echo "     -H \"Authorization: Bearer $token\" \\\n";
    echo "     -H \"Accept: application/json\" \\\n";
    echo "     \"$serverUrl/api/v1/metrics\"\n\n";
    
    echo "🎯 Si vous obtenez Status: 200, l'authentification fonctionne !\n";
    echo "🎯 Si vous obtenez Status: 401, vérifiez la configuration du serveur.\n\n";
    
} catch (Exception $e) {
    echo "❌ Erreur lors de la génération: " . $e->getMessage() . "\n";
    echo "📍 Fichier: " . $e->getFile() . ":" . $e->getLine() . "\n";
    
    exit(1);
}

echo "🏁 Génération terminée\n"; 