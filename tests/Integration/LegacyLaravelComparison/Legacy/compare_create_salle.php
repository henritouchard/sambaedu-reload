<?php
/**
 * Script de comparaison Legacy vs Laravel pour la création d'une salle (P2)
 *
 * Ce script:
 * 1. Crée une salle avec Legacy
 * 2. Lit les attributs AD créés
 * 3. Supprime la salle Legacy
 * 4. Crée une salle avec Laravel (via artisan tinker ou direct)
 * 5. Lit les attributs AD créés
 * 6. Compare les résultats
 *
 * Usage: php compare_create_salle.php
 */

// Bootstrapper Laravel en premier pour avoir accès à la config
require __DIR__ . '/../../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Charger la configuration depuis Laravel (via LegacyConfigBridge ou SambaEduConfig)
use App\Config\SambaEduConfig;
use App\Config\LegacyConfigBridge;

$sambaConfig = app(SambaEduConfig::class);

// Essayer de charger la config legacy via le bridge
$legacyBridge = app(LegacyConfigBridge::class);
$config = $legacyBridge->getConfig();

// Vérifier que la config est chargée
if (empty($config) || empty($config['ldap_base_dn'])) {
    echo "⚠ Configuration legacy non disponible depuis /etc/sambaedu/\n";
    echo "  Fallback vers SambaEduConfig (source unique) \n\n";

    $ldapCfg = $sambaConfig->ldap();
    $config = [
        'ldap_url' => $ldapCfg->url,
        'ldap_base_dn' => $ldapCfg->baseDn,
        'ldap_admin_name' => $ldapCfg->adminName,
        'ldap_admin_passwd' => $ldapCfg->adminPassword,
        'domain' => $ldapCfg->domain,
        'parcs_rdn' => 'OU=Parcs',
        'suffix' => '$',
        'etab_ou' => '0',
    ];

    // Construire les DN
    $config['dn'] = [
        'computers' => 'OU=Computers,' . $config['ldap_base_dn'],
    ];
}

// Vérifier la connexion LDAP
$ldapCfg  = $sambaConfig->ldap();
$ldapHost = $ldapCfg->etabServerIp ?: ($ldapCfg->serverIp ?: 'localhost');
$ldapPort = $ldapCfg->port;

echo "Configuration LDAP:\n";
echo "  Host: $ldapHost:$ldapPort\n";
echo "  Base DN: " . $config['ldap_base_dn'] . "\n";
echo "  Admin: " . $config['ldap_admin_name'] . "\n\n";

// Connexion LDAP directe (sans les fonctions legacy qui peuvent ne pas être disponibles)
$ldapUri = "ldaps://$ldapHost:$ldapPort";
$config['bind'] = @ldap_connect($ldapUri);

if (!$config['bind']) {
    die("Erreur: Impossible de se connecter à $ldapUri\n");
}

ldap_set_option($config['bind'], LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($config['bind'], LDAP_OPT_REFERRALS, 0);

// Désactiver la vérification du certificat SSL pour les tests (environnement de dev)
ldap_set_option($config['bind'], LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);

$bindDn = $config['ldap_admin_name'] . '@' . $config['domain'];
$bindPwd = $config['ldap_admin_passwd'];

if (!@ldap_bind($config['bind'], $bindDn, $bindPwd)) {
    die("Erreur: Bind LDAP échoué: " . ldap_error($config['bind']) . "\n");
}

echo "✓ Connexion LDAP réussie\n\n";

use App\Services\AdSync\AdSyncService;
use App\Config\LdapDnHelper;
use App\Models\WorkstationGroup;
use LdapRecord\Models\ActiveDirectory\Group;
use LdapRecord\Models\ActiveDirectory\OrganizationalUnit;

// Génère un identifiant unique pour ce test
$testId = date('Ymd_His');
$legacySalleName = "test-legacy-$testId";
$laravelSalleName = "test-laravel-$testId";
$description = "Salle de test comparaison $testId";

// Construire les DN
$parcsDn = $config['parcs_rdn'] . "," . $config['ldap_base_dn'];
$computersDn = $config['dn']['computers'] ?? "OU=Computers," . $config['ldap_base_dn'];
$suffix = $config['suffix'] ?? '$';

echo "╔═══════════════════════════════════════════════════════════════════════════╗\n";
echo "║  COMPARAISON CRÉATION SALLE: LEGACY vs LARAVEL (P2)                       ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════════╝\n\n";

echo "Test ID: $testId\n";
echo "Salle Legacy: $legacySalleName\n";
echo "Salle Laravel: $laravelSalleName\n";
echo "Description: $description\n";
echo "Parcs DN: $parcsDn\n";
echo "Computers DN: $computersDn\n\n";

$results = [
    'legacy' => ['cn' => null, 'ou' => null],
    'laravel' => ['cn' => null, 'ou' => null],
];

// ═══════════════════════════════════════════════════════════════════════════════
// ÉTAPE 1: Créer avec LEGACY (simulation des appels ldap_add)
// ═══════════════════════════════════════════════════════════════════════════════
echo "┌─────────────────────────────────────────────────────────────────────────────┐\n";
echo "│ ÉTAPE 1: Création avec LEGACY (simulation directe ldap_add)                 │\n";
echo "└─────────────────────────────────────────────────────────────────────────────┘\n\n";

/**
 * Simulation des opérations legacy pour créer une salle:
 * 1. groupadd() - Crée CN dans OU=Parcs
 * 2. ouadd() - Crée OU dans OU=Computers
 */

$startTime = microtime(true);
$legacyResult = true;

// 1. Créer le groupe CN (comme groupadd() dans samba-tool.inc.php:562-611)
$cnDn = "CN=$legacySalleName,$parcsDn";
$cnAttrs = [
    "cn" => $legacySalleName,
    "objectclass" => ["top", "group"],
    "samaccountname" => $legacySalleName . $suffix,
    "grouptype" => "2147483650", // 0x80000002 - Domain Local Security Group
    "description" => $description,
];

echo "  [1] Création CN: $cnDn\n";
echo "      Attributs: cn, objectclass, samaccountname, grouptype, description\n";

if (!@ldap_add($config['bind'], $cnDn, $cnAttrs)) {
    echo "      ✗ ERREUR: " . ldap_error($config['bind']) . "\n";
    $legacyResult = false;
} else {
    echo "      ✓ OK\n";
}

// 2. Créer l'OU (comme ouadd() dans samba-tool.inc.php:387-431)
$ouDn = "OU=$legacySalleName,$computersDn";
$ouAttrs = [
    "ou" => $legacySalleName,
    "objectClass" => "organizationalUnit",
];

echo "  [2] Création OU: $ouDn\n";
echo "      Attributs: ou, objectClass\n";

if (!@ldap_add($config['bind'], $ouDn, $ouAttrs)) {
    echo "      ✗ ERREUR: " . ldap_error($config['bind']) . "\n";
    $legacyResult = false;
} else {
    echo "      ✓ OK\n";
}

$legacyTime = round((microtime(true) - $startTime) * 1000);

if ($legacyResult) {
    echo "\n✓ Salle Legacy créée en {$legacyTime}ms\n\n";

    // Lire les attributs du CN
    $cnSearch = @ldap_read($config['bind'], $cnDn, '(objectClass=*)', ['*']);
    if ($cnSearch) {
        $entries = ldap_get_entries($config['bind'], $cnSearch);
        if ($entries['count'] > 0) {
            $results['legacy']['cn'] = extractAttributes($entries[0]);
        }
    }

    // Lire les attributs de l'OU
    $ouSearch = @ldap_read($config['bind'], $ouDn, '(objectClass=*)', ['*']);
    if ($ouSearch) {
        $entries = ldap_get_entries($config['bind'], $ouSearch);
        if ($entries['count'] > 0) {
            $results['legacy']['ou'] = extractAttributes($entries[0]);
        }
    }
} else {
    echo "\n✗ ERREUR création Legacy\n\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉTAPE 2: Créer avec LARAVEL
// ═══════════════════════════════════════════════════════════════════════════════
echo "┌─────────────────────────────────────────────────────────────────────────────┐\n";
echo "│ ÉTAPE 2: Création avec LARAVEL                                              │\n";
echo "└─────────────────────────────────────────────────────────────────────────────┘\n\n";

try {
    $adSyncService = app(AdSyncService::class);
    $dnHelper = app(LdapDnHelper::class);

    // Créer le WorkstationGroup en base
    $workstationGroup = new WorkstationGroup();
    $workstationGroup->name = $laravelSalleName;
    $workstationGroup->description = $description;
    $workstationGroup->is_physical = true;  // true = salle, false = parc logique
    $workstationGroup->parent_id = null;
    $workstationGroup->is_active = true;
    $workstationGroup->save();

    // Appeler le service de synchronisation AD
    $startTime = microtime(true);
    $laravelResult = $adSyncService->createWorkstationGroup($workstationGroup);
    $laravelTime = round((microtime(true) - $startTime) * 1000);

    if ($laravelResult['success']) {
        echo "✓ Salle Laravel créée en {$laravelTime}ms\n";
        echo "  CN GUID: " . ($laravelResult['cn_guid'] ?? 'N/A') . "\n";
        echo "  OU GUID: " . ($laravelResult['ou_guid'] ?? 'N/A') . "\n\n";

        // Lire les attributs via LdapRecord
        $parcsDnLaravel = $dnHelper->parcs();
        $computersDnLaravel = $dnHelper->computers();

        $cnGroup = Group::find("CN=$laravelSalleName,$parcsDnLaravel");
        if ($cnGroup) {
            $results['laravel']['cn'] = extractLdapRecordAttributes($cnGroup);
        }

        $ou = OrganizationalUnit::find("OU=$laravelSalleName,$computersDnLaravel");
        if ($ou) {
            $results['laravel']['ou'] = extractLdapRecordAttributes($ou);
        }
    } else {
        echo "✗ ERREUR création Laravel: " . ($laravelResult['error'] ?? 'Erreur inconnue') . "\n\n";
    }
} catch (\Exception $e) {
    echo "✗ EXCEPTION Laravel: " . $e->getMessage() . "\n\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉTAPE 3: COMPARAISON
// ═══════════════════════════════════════════════════════════════════════════════
echo "┌─────────────────────────────────────────────────────────────────────────────┐\n";
echo "│ ÉTAPE 3: COMPARAISON DES ATTRIBUTS                                          │\n";
echo "└─────────────────────────────────────────────────────────────────────────────┘\n\n";

echo "=== GROUPE CN (dans OU=Parcs) ===\n\n";
compareAttributes($results['legacy']['cn'], $results['laravel']['cn'], [
    'cn', 'samaccountname', 'grouptype', 'description', 'objectclass'
]);

echo "\n=== OU (dans OU=Computers) ===\n\n";
compareAttributes($results['legacy']['ou'], $results['laravel']['ou'], [
    'ou', 'description', 'objectclass'
]);

// ═══════════════════════════════════════════════════════════════════════════════
// ÉTAPE 4: NETTOYAGE
// ═══════════════════════════════════════════════════════════════════════════════
echo "\n┌─────────────────────────────────────────────────────────────────────────────┐\n";
echo "│ ÉTAPE 4: NETTOYAGE                                                          │\n";
echo "└─────────────────────────────────────────────────────────────────────────────┘\n\n";

// Supprimer les salles de test
echo "Suppression salle Legacy... ";
@ldap_delete($config['bind'], "OU=$legacySalleName,$computersDn");
@ldap_delete($config['bind'], "CN=$legacySalleName,$parcsDn");
echo "OK\n";

echo "Suppression salle Laravel... ";
try {
    $ou = OrganizationalUnit::find("OU=$laravelSalleName," . $dnHelper->computers());
    if ($ou) $ou->delete();
    $cn = Group::find("CN=$laravelSalleName," . $dnHelper->parcs());
    if ($cn) $cn->delete();
    // Supprimer aussi de la base de données
    WorkstationGroup::where('name', $laravelSalleName)->delete();
    echo "OK\n";
} catch (\Exception $e) {
    echo "Erreur: " . $e->getMessage() . "\n";
}

ldap_close($config['bind']);

echo "\n╔═══════════════════════════════════════════════════════════════════════════╗\n";
echo "║  TEST TERMINÉ                                                              ║\n";
echo "╚═══════════════════════════════════════════════════════════════════════════╝\n";

// ═══════════════════════════════════════════════════════════════════════════════
// FONCTIONS UTILITAIRES
// ═══════════════════════════════════════════════════════════════════════════════

function extractAttributes(array $entry): array
{
    $attrs = [];
    $exclude = ['count', 'dn'];

    foreach ($entry as $key => $value) {
        if (is_numeric($key) || in_array($key, $exclude)) continue;
        if (is_array($value) && isset($value['count'])) {
            unset($value['count']);
            $attrs[$key] = count($value) === 1 ? $value[0] : $value;
        } else {
            $attrs[$key] = $value;
        }
    }

    return $attrs;
}

function extractLdapRecordAttributes($model): array
{
    $attrs = [];
    $rawAttrs = $model->getAttributes();

    foreach ($rawAttrs as $key => $value) {
        if (is_array($value)) {
            $attrs[$key] = count($value) === 1 ? $value[0] : $value;
        } else {
            $attrs[$key] = $value;
        }
    }

    return $attrs;
}

function compareAttributes(?array $legacy, ?array $laravel, array $keysToCompare): void
{
    if (!$legacy) {
        echo "  ⚠ Attributs Legacy non disponibles\n";
        return;
    }
    if (!$laravel) {
        echo "  ⚠ Attributs Laravel non disponibles\n";
        return;
    }

    $maxKeyLen = max(array_map('strlen', $keysToCompare));

    foreach ($keysToCompare as $key) {
        $legacyVal = $legacy[$key] ?? null;
        $laravelVal = $laravel[$key] ?? null;

        // Normaliser pour comparaison
        $legacyNorm = normalizeValue($legacyVal);
        $laravelNorm = normalizeValue($laravelVal);

        $match = ($legacyNorm === $laravelNorm);
        $icon = $match ? '✓' : '✗';

        $keyPadded = str_pad($key, $maxKeyLen);

        echo "  $icon $keyPadded:\n";
        echo "      Legacy:  " . formatValue($legacyVal) . "\n";
        echo "      Laravel: " . formatValue($laravelVal) . "\n";

        if (!$match) {
            echo "      ⚠ DIFFÉRENCE DÉTECTÉE\n";
        }
        echo "\n";
    }
}

function normalizeValue($value): string
{
    if (is_array($value)) {
        $value = array_map('strtolower', $value);
        sort($value);
        return implode(',', $value);
    }
    return strtolower((string)$value);
}

function formatValue($value): string
{
    if ($value === null) return '(null)';
    if (is_array($value)) return '[' . implode(', ', $value) . ']';
    return (string)$value;
}
