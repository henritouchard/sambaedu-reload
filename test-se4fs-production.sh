#!/bin/bash
# Version production-safe du script de test
echo "🛡️  Production-Safe SE4FS Tests"
echo "============================="

# Vérifier que nous sommes bien en mode test
if [ "$APP_ENV" = "production" ]; then
    echo "⚠️  Running on production environment"
    echo "🔍 Verifying test isolation..."
    
    # Vérifier la config de test
    php artisan config:show database.connections.testing 2>/dev/null || echo "❌ No testing DB config"
    
    # Afficher un avertissement
    echo "⚠️  Press ENTER to continue or Ctrl+C to abort"
    read
fi

# Forcer lenvironnement de test
export APP_ENV=testing

# Exécuter les tests avec isolation maximale
php artisan test tests/Unit/Services/StatsServiceLocationTest.php --env=testing
unit_result=$?

echo ""
echo "📊 Production-safe test completed"
if [ $unit_result -eq 0 ]; then
    echo "✅ All tests passed safely"
else
    echo "❌ Tests failed"
fi

exit $unit_result
