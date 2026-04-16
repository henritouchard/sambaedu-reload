<?php

namespace Tests\Unit;

use App\Models\ErrorLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Tests unitaires pour les shims GPO (story 1bis.18g).
 *
 * Couvre :
 *  - search_ad(type='gpo'|'site'|'subnet') : LDAP RÉEL (pas Eloquent), mock
 *    via $GLOBALS['__shim_ldap_call_override'].
 *  - modify_ad(type='gpo', mode='replace') : LDAP RÉEL, mock idem.
 *  - Wrappers samba-tool GPO fallback (gpolistcontainers, gpogetlink,
 *    gposetlink, gpodellink) : mock via $GLOBALS['__shim_gpo_exec_override'].
 *  - Fonctions SYSVOL fallback (sysvol_put, read_gpo_sysvol, update_gpo_sysvol,
 *    sysvol_acl_reset) : mock exec + vérification atomicité temp+rename
 *    pour update_gpo_sysvol.
 *  - Audit escapeshellarg : vérification que les paramètres utilisateur sont
 *    échappés via inspection regex du code source.
 *  - Cas d'erreur : GPO absente, ticket Kerberos expiré, connexion LDAP refusée.
 *
 * IMPORTANT — Stratégie de mock :
 * On ne mocke PAS Eloquent ni les modèles User/UserGroup/Workstation. Les
 * cases 'gpo'/'site'/'subnet' de search_ad N'interrogent PAS la DB — c'est
 * exclusivement LDAP. Les tests valident donc le chemin ldap_connect +
 * ldap_bind + ldap_search via le wrapper `_shim_ldap_call`.
 */
class LegacyGpoShimsTest extends TestCase
{
    private array $config = [];

    /** @var list<array{fn:string,args:array}> */
    private array $ldapCalls = [];

    /** @var list<string> */
    private array $execCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->createTestTables();

        Config::set('sambaedu.legacy_ldap.base_dn', 'DC=ecole,DC=local');
        Config::set('sambaedu.etab_ou', '');

        require_once base_path('legacy/bootstrap.php');

        $this->config = [
            'ldap_base_dn'       => 'DC=ecole,DC=local',
            'domain'             => 'ecole.local',
            'etab_ou'            => '',
            'ldap_admin_name'    => 'administrator',
            'ldap_admin_passwd'  => 'secret',
            'se4ad_ip'           => '127.0.0.1',
            'bind'               => new \LdapShimConnection(),
        ];

        // Reset overrides et call logs
        $this->ldapCalls = [];
        $this->execCalls = [];
        unset($GLOBALS['__shim_ldap_call_override']);
        unset($GLOBALS['__shim_gpo_exec_override']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__shim_ldap_call_override']);
        unset($GLOBALS['__shim_gpo_exec_override']);
        ErrorLog::query()->delete();
        parent::tearDown();
    }

    private function createTestTables(): void
    {
        if (!Schema::hasTable('error_logs')) {
            Schema::create('error_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source', 10);
                $table->text('message');
                $table->timestamp('created_at');
            });
        }
    }

    /**
     * Installe un override ldap_call qui retourne des valeurs prédéfinies
     * par nom de fonction. Les appels sont tracés dans $this->ldapCalls.
     *
     * @param array<string, mixed|callable> $responses
     */
    private function mockLdap(array $responses): void
    {
        $calls = &$this->ldapCalls;
        $GLOBALS['__shim_ldap_call_override'] = function (string $fn, array $args) use ($responses, &$calls) {
            $calls[] = ['fn' => $fn, 'args' => $args];
            if (!array_key_exists($fn, $responses)) {
                // Valeurs par défaut sensées pour ne pas exploser les tests
                return match ($fn) {
                    'ldap_connect'     => 'FAKE_LDAP_CONN',
                    'ldap_set_option'  => true,
                    'ldap_bind'        => true,
                    'ldap_sasl_bind'   => true,
                    'ldap_search'      => 'FAKE_LDAP_RESULT',
                    'ldap_get_entries' => ['count' => 0],
                    'ldap_mod_replace' => true,
                    default            => false,
                };
            }
            $val = $responses[$fn];
            return is_callable($val) ? $val($args) : $val;
        };
    }

    /**
     * Installe un override gpo_exec qui retourne une sortie prédéfinie.
     *
     * @param callable|array{output:list<string>,return:int} $response
     */
    private function mockExec($response): void
    {
        $calls = &$this->execCalls;
        $GLOBALS['__shim_gpo_exec_override'] = function (string $command) use ($response, &$calls) {
            $calls[] = $command;
            return is_callable($response) ? $response($command) : $response;
        };
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #1 — search_ad(type='gpo')
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #1 — search_ad(type='gpo') interroge l'AD via ldap_search sur la
     * branche CN=Policies,CN=System, avec le filtre exact attendu.
     */
    public function test_search_ad_gpo_found_returns_ldap_entry(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => [
                'count' => 1,
                0 => [
                    'count' => 8,
                    'dn' => 'CN={31B2F340-016D-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=ecole,DC=local',
                    'cn' => ['count' => 1, 0 => '{31B2F340-016D-11D2-945F-00C04FB984F9}'],
                    'displayname' => ['count' => 1, 0 => 'Wallpaper'],
                    'gpcfilesyspath' => ['count' => 1, 0 => '\\\\ecole.local\\sysvol\\ecole.local\\Policies\\{31B2F340-016D-11D2-945F-00C04FB984F9}'],
                    'versionnumber' => ['count' => 1, 0 => '65537'],
                    'gpcuserextensionnames' => ['count' => 1, 0 => '[{35378EAC-683F-11D2-A89A-00C04FBBCFA2}{53D6AB1B-2488-11D1-A28C-00C04FB94F17}]'],
                    'gpcmachineextensionnames' => ['count' => 1, 0 => '[{35378EAC-683F-11D2-A89A-00C04FBBCFA2}{53D6AB1B-2488-11D1-A28C-00C04FB94F17}]'],
                    'gpcfunctionalityversion' => ['count' => 1, 0 => '2'],
                    'flags' => ['count' => 1, 0 => '0'],
                ],
            ],
        ]);

        $result = search_ad($this->config, 'Wallpaper', 'gpo');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertEquals(1, $result['count']);
        $this->assertEquals('{31B2F340-016D-11D2-945F-00C04FB984F9}', $result[0]['cn']);
        $this->assertEquals('Wallpaper', $result[0]['displayname']);
        $this->assertEquals('65537', $result[0]['versionnumber']);
        $this->assertEquals('2', $result[0]['gpcfunctionalityversion']);
        $this->assertStringContainsString('\\\\ecole.local\\sysvol', $result[0]['gpcfilesyspath']);

        // Vérifier le filtre LDAP et la branche
        $searchCall = $this->findLdapCall('ldap_search');
        $this->assertNotNull($searchCall, 'ldap_search doit être appelé');
        $this->assertEquals('CN=Policies,CN=System,DC=ecole,DC=local', $searchCall['args'][1]);
        $this->assertStringContainsString('(objectclass=grouppolicycontainer)', $searchCall['args'][2]);
        $this->assertStringContainsString('cn=Wallpaper', $searchCall['args'][2]);
        $this->assertStringContainsString('displayname=Wallpaper', $searchCall['args'][2]);
    }

    /**
     * AC #1 — Given la GPO n'existe pas, Then retour `{count: 0}`
     * (et PAS un tableau vide qui confondrait "not found" et "unimplemented").
     */
    public function test_search_ad_gpo_not_found_returns_count_zero(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => ['count' => 0],
        ]);

        $result = search_ad($this->config, 'DoesNotExist', 'gpo');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('count', $result);
        $this->assertEquals(0, $result['count']);
        // Distinction clé : résultat valide vide, PAS un tableau totalement vide
        $this->assertNotEquals([], $result, 'Doit être {count:0}, pas []');
    }

    /**
     * AC #1 — Given connexion LDAP refusée, Then retour `false`
     * (remonter l'erreur, ne pas la masquer en "not found").
     */
    public function test_search_ad_gpo_ldap_down_returns_false(): void
    {
        $this->mockLdap([
            'ldap_connect' => false,
        ]);

        $result = search_ad($this->config, 'Wallpaper', 'gpo');

        $this->assertFalse($result, 'Erreur LDAP doit remonter false, pas count:0');
    }

    /**
     * AC #1 — Wildcard : `search_ad(*, 'gpo')` utilise le filtre objectclass
     * seul (pas de matching sur cn/displayname).
     */
    public function test_search_ad_gpo_wildcard_uses_objectclass_only_filter(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => ['count' => 0],
        ]);

        search_ad($this->config, '*', 'gpo');

        $searchCall = $this->findLdapCall('ldap_search');
        $this->assertNotNull($searchCall);
        $this->assertEquals('(objectclass=grouppolicycontainer)', $searchCall['args'][2]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #2 — search_ad(type='site') et search_ad(type='subnet')
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #2 — search_ad(type='site') interroge CN=Sites,CN=Configuration.
     */
    public function test_search_ad_site_uses_correct_branch(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => [
                'count' => 1,
                0 => [
                    'count' => 2,
                    'dn' => 'CN=Default-First-Site-Name,CN=Sites,CN=Configuration,DC=ecole,DC=local',
                    'cn' => ['count' => 1, 0 => 'Default-First-Site-Name'],
                    'description' => ['count' => 1, 0 => 'Site par défaut'],
                ],
            ],
        ]);

        $result = search_ad($this->config, 'Default-First-Site-Name', 'site');

        $this->assertEquals(1, $result['count']);
        $this->assertEquals('Default-First-Site-Name', $result[0]['cn']);

        $searchCall = $this->findLdapCall('ldap_search');
        $this->assertNotNull($searchCall);
        $this->assertEquals('CN=Sites,CN=Configuration,DC=ecole,DC=local', $searchCall['args'][1]);
        $this->assertStringContainsString('(objectclass=site)', $searchCall['args'][2]);
    }

    /**
     * AC #2 — search_ad(type='subnet') interroge CN=Subnets,CN=Sites,CN=Configuration.
     */
    public function test_search_ad_subnet_uses_correct_branch(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => ['count' => 0],
        ]);

        search_ad($this->config, '192.168.1.0/24', 'subnet');

        $searchCall = $this->findLdapCall('ldap_search');
        $this->assertNotNull($searchCall);
        $this->assertEquals('CN=Subnets,CN=Sites,CN=Configuration,DC=ecole,DC=local', $searchCall['args'][1]);
        $this->assertStringContainsString('objectclass=Subnet', $searchCall['args'][2]);
    }

    /**
     * AC #2 — search_ad(type='subnet', *) retourne tous les subnets.
     */
    public function test_search_ad_subnet_returns_empty_count_zero_when_none(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => ['count' => 0],
        ]);

        $result = search_ad($this->config, '*', 'subnet');

        $this->assertEquals(0, $result['count']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #3 — modify_ad(type='gpo')
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #3 — modify_ad(type='gpo') avec un DN direct appelle
     * ldap_mod_replace avec les bons attrs.
     */
    public function test_modify_ad_gpo_replace_with_dn_calls_ldap_mod_replace(): void
    {
        $this->mockLdap([
            'ldap_mod_replace' => true,
        ]);

        $dn = 'CN={31B2F340-016D-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=ecole,DC=local';
        $attrs = [
            'versionnumber' => '65538',
            'gpcuserextensionnames' => '[{35378EAC-683F-11D2-A89A-00C04FBBCFA2}]',
        ];

        $result = modify_ad($this->config, $dn, 'gpo', $attrs, 'replace');

        $this->assertTrue($result);

        $modCall = $this->findLdapCall('ldap_mod_replace');
        $this->assertNotNull($modCall);
        $this->assertEquals($dn, $modCall['args'][1]);
        $this->assertEquals($attrs, $modCall['args'][2]);
    }

    /**
     * AC #3 — modify_ad(type='gpo') avec un CN résout d'abord le DN.
     */
    public function test_modify_ad_gpo_resolves_dn_from_cn(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => [
                'count' => 1,
                0 => [
                    'count' => 2,
                    'dn' => 'CN={GUID},CN=Policies,CN=System,DC=ecole,DC=local',
                    'cn' => ['count' => 1, 0 => '{GUID}'],
                    'displayname' => ['count' => 1, 0 => 'Wallpaper'],
                ],
            ],
            'ldap_mod_replace' => true,
        ]);

        $result = modify_ad($this->config, 'Wallpaper', 'gpo', ['versionnumber' => '2'], 'replace');

        $this->assertTrue($result);
        $this->assertNotNull($this->findLdapCall('ldap_search'));
        $this->assertNotNull($this->findLdapCall('ldap_mod_replace'));
    }

    /**
     * AC #3 — modify_ad(type='gpo') sur GPO inexistante → false + log.
     */
    public function test_modify_ad_gpo_missing_returns_false_and_logs(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => ['count' => 0],
        ]);

        $result = modify_ad($this->config, 'DoesNotExist', 'gpo', ['versionnumber' => '1'], 'replace');

        $this->assertFalse($result);
        $this->assertDatabaseHas('error_logs', ['source' => 'legacy']);
    }

    /**
     * AC #3 — modify_ad(type='gpo', mode='add') n'est pas supporté → false + log.
     */
    public function test_modify_ad_gpo_unsupported_mode_returns_false(): void
    {
        $result = modify_ad($this->config, 'dn=fake', 'gpo', ['versionnumber' => '1'], 'add');

        $this->assertFalse($result);
        $this->assertDatabaseHas('error_logs', ['source' => 'legacy']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #4 — Wrappers samba-tool GPO (fallback shim)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #4 — gpolistcontainers parse correctement la sortie samba-tool.
     * Testé via la fonction exposée dans gpo_shim.inc.php (fallback shim
     * utilisé sur host — en VM les includes legacy originaux prennent le
     * dessus mais le contrat est identique).
     */
    public function test_gpolistcontainers_parses_samba_tool_output(): void
    {
        $this->mockExec([
            'output' => [
                'DN: OU=Salle1,OU=Machines,DC=ecole,DC=local',
                'DN: OU=Salle2,OU=Machines,DC=ecole,DC=local',
            ],
            'return' => 0,
        ]);

        $dns = gpolistcontainers($this->config, 'Wallpaper');

        $this->assertIsArray($dns);
        $this->assertCount(2, $dns);
        $this->assertEquals('OU=Salle1,OU=Machines,DC=ecole,DC=local', $dns[0]);

        // Vérifier que la commande utilise escapeshellarg
        $this->assertStringContainsString("'Wallpaper'", end($this->execCalls));
    }

    /**
     * AC #4 + #10 — gpolistcontainers avec nom malicieux (injection) est
     * correctement échappé via escapeshellarg. L'assertion vérifie que la
     * quote fermante de l'attaquant est bien échappée (les ' à l'intérieur
     * deviennent '\''), ce qui empêche le shell d'exécuter la commande
     * `rm -rf` en dehors du contexte quoté.
     */
    public function test_gpolistcontainers_escapes_shell_injection_attempts(): void
    {
        $this->mockExec(['output' => [], 'return' => 0]);

        gpolistcontainers($this->config, "foo'; rm -rf /tmp/x; echo '");

        $command = end($this->execCalls);
        // escapeshellarg transforme ' en '\'' pour empêcher l'échappement.
        // Le résultat attendu contient donc "'\''" (single-quote échappée).
        $this->assertStringContainsString("'\\''", $command,
            "escapeshellarg() doit échapper les single-quotes via '\\''");
        // La chaîne "rm -rf" reste littéralement dans la commande, mais
        // comme elle est encapsulée dans un seul argument quoté, elle ne
        // s'exécute pas. On vérifie qu'elle n'apparaît PAS comme token shell
        // séparé (i.e. précédée d'un espace non-quoté).
        // Plus pragmatique : on vérifie que escapeshellarg a été utilisée
        // en testant que la chaîne commence bien par une quote simple.
        $this->assertMatchesRegularExpression(
            "/listcontainers '/",
            $command,
            "L'argument doit commencer par une quote single (escapeshellarg)"
        );
    }

    /**
     * AC #4 — gpogetlink parse format "GPO: ...", "Name: ...", "Options: ...".
     */
    public function test_gpogetlink_parses_output_into_gpo_array(): void
    {
        $this->mockExec([
            'output' => [
                'GPO          : {31B2F340-016D-11D2-945F-00C04FB984F9}',
                'Name         : Default Domain Policy',
                'Options      : 0',
                'GPO          : {6AC1786C-016F-11D2-945F-00C04FB984F9}',
                'Name         : Default Domain Controllers Policy',
                'Options      : 0',
            ],
            'return' => 0,
        ]);

        $gpos = gpogetlink($this->config, 'DC=ecole,DC=local');

        $this->assertCount(2, $gpos);
        $this->assertEquals('{31B2F340-016D-11D2-945F-00C04FB984F9}', $gpos[0]['uuid']);
        $this->assertEquals('Default Domain Policy', $gpos[0]['displayname']);
        $this->assertEquals('0', $gpos[0]['options']);
    }

    /**
     * AC #4 — gposetlink retourne true quand samba-tool réussit.
     */
    public function test_gposetlink_succeeds_when_exit_code_zero(): void
    {
        $this->mockExec(['output' => [], 'return' => 0]);

        $result = gposetlink($this->config, 'OU=Machines,DC=ecole,DC=local', 'Wallpaper', true, false);

        $this->assertTrue($result);
        $command = end($this->execCalls);
        $this->assertStringContainsString('--enforce', $command);
        $this->assertStringContainsString("'OU=Machines,DC=ecole,DC=local'", $command);
        $this->assertStringContainsString("'Wallpaper'", $command);
    }

    /**
     * AC #4 — gpodellink accepte 0 ou 255 comme succès (convention legacy).
     */
    public function test_gpodellink_accepts_exit_code_255_as_success(): void
    {
        $this->mockExec(['output' => [], 'return' => 255]);

        $result = gpodellink($this->config, 'OU=Machines,DC=ecole,DC=local', 'Wallpaper');

        $this->assertTrue($result, 'Le code 255 doit être considéré comme succès (legacy)');
    }

    /**
     * AC #4 — gpodellink échoue sur un code autre que 0 ou 255.
     */
    public function test_gpodellink_fails_on_other_exit_codes(): void
    {
        $this->mockExec(['output' => [], 'return' => 1]);

        $result = gpodellink($this->config, 'OU=Machines,DC=ecole,DC=local', 'Wallpaper');

        $this->assertFalse($result);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #5 — Fonctions SYSVOL + bridge Kerberos
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #5 + #11 — Avant tout exec smbclient, _shim_gpo_ensure_krb5ccname
     * positionne KRB5CCNAME.
     */
    public function test_sysvol_put_sets_krb5ccname_before_exec(): void
    {
        putenv('KRB5CCNAME'); // clear
        $this->mockExec(['output' => [], 'return' => 0]);

        $gpo = ['cn' => '{GUID}', 'displayname' => 'Wallpaper'];
        sysvol_put($this->config, $gpo, '/tmp/source');

        $krb = getenv('KRB5CCNAME');
        $this->assertNotFalse($krb, 'KRB5CCNAME doit être positionné');
        $this->assertNotEmpty($krb);
    }

    /**
     * AC #5 — sysvol_put avec source string appelle smbclient avec
     * --use-kerberos=required et construit la bonne commande.
     */
    public function test_sysvol_put_uses_kerberos_required(): void
    {
        $this->mockExec(['output' => [], 'return' => 0]);

        $gpo = ['cn' => '{GUID}', 'displayname' => 'Wallpaper'];
        $ok = sysvol_put($this->config, $gpo, '/tmp/source');

        $this->assertTrue($ok);
        $command = end($this->execCalls);
        $this->assertStringContainsString('smbclient', $command);
        $this->assertStringContainsString('--use-kerberos=required', $command);
        $this->assertStringContainsString('/sysvol', $command);
    }

    /**
     * AC #5 — read_gpo_sysvol retourne false si smbclient ne télécharge
     * aucun fichier (cas "GPO file absent attendu").
     */
    public function test_read_gpo_sysvol_returns_false_when_file_missing(): void
    {
        $this->mockExec(['output' => [], 'return' => 1]);

        $gpo = ['cn' => '{GUID}', 'displayname' => 'WpMissing'];
        $file = ['file' => 'Registry.pol', 'path' => '/User', 'type' => 'gpo'];

        $data = read_gpo_sysvol($this->config, $gpo, $file);

        $this->assertFalse($data);
    }

    /**
     * AC #5 + #9i — update_gpo_sysvol écrit atomiquement (temp+rename).
     * On vérifie qu'après l'appel, le fichier final existe et aucun .tmp.*
     * ne traîne.
     */
    public function test_update_gpo_sysvol_writes_atomically(): void
    {
        $this->mockExec(['output' => [], 'return' => 0]);

        // Note : le tmppath est maintenant construit via _shim_gpo_safe_tmppath
        // (#M2) = sys_get_temp_dir() . '/sambaedu_sysvol_' . safe($gpo['cn']).
        // $gpo['cn'] est prioritaire sur displayname pour anti path traversal.
        $cnRaw = '{GUID-' . uniqid() . '}';
        $gpo = ['cn' => $cnRaw, 'displayname' => 'AtomicTestDisplay'];
        $file = ['file' => 'Registry.pol', 'path' => '/User', 'type' => 'gpo', 'target' => 'user'];
        $data = "content atomique";

        $ok = update_gpo_sysvol($this->config, $gpo, $file, $data, false);

        $this->assertTrue($ok);

        // Le nom dans le path est sanitize (seulement [a-zA-Z0-9_{}.-]).
        $safeCn = preg_replace('/[^a-zA-Z0-9_{}\.-]/', '_', $cnRaw);
        $tmpDir = sys_get_temp_dir() . '/sambaedu_sysvol_' . $safeCn;
        $finalPath = $tmpDir . '/Registry.pol';
        $this->assertFileExists($finalPath);
        $this->assertEquals($data, file_get_contents($finalPath));

        // Aucun fichier .tmp.* ne doit subsister
        $residue = glob($tmpDir . '/*.tmp.*');
        $this->assertEmpty($residue, 'Aucun fichier .tmp.* ne doit subsister après rename atomique');

        // Cleanup
        @unlink($finalPath);
        @rmdir($tmpDir);
    }

    /**
     * AC #5 + #9i — update_gpo_sysvol en mode commit=true déclenche sysvol_put
     * et décore $gpo avec increment_user/increment_machine selon file.target.
     */
    public function test_update_gpo_sysvol_commit_decorates_gpo_with_increment_flags(): void
    {
        $this->mockExec(['output' => [], 'return' => 0]);

        $cnRaw = '{GUID-commit-' . uniqid() . '}';
        $gpo = ['cn' => $cnRaw, 'displayname' => 'CommitTest'];
        $file = ['file' => 'Registry.pol', 'path' => '/User', 'type' => 'gpo', 'target' => 'user'];

        $ok = update_gpo_sysvol($this->config, $gpo, $file, "data", true);

        $this->assertTrue($ok);
        $this->assertArrayHasKey('increment_user', $gpo);
        $this->assertTrue($gpo['increment_user']);

        // Cleanup (chemin = sambaedu_sysvol_{cn-sanitized}).
        $safeCn = preg_replace('/[^a-zA-Z0-9_{}\.-]/', '_', $cnRaw);
        @rmdir(sys_get_temp_dir() . '/sambaedu_sysvol_' . $safeCn);
    }

    /**
     * AC #5 + #9g — sysvol_acl_reset appelle smbcacls avec les bons args.
     */
    public function test_sysvol_acl_reset_calls_smbcacls(): void
    {
        $this->mockExec(['output' => [], 'return' => 0]);

        $msg = [];
        sysvol_acl_reset($this->config, 'Wallpaper', $msg);

        $command = end($this->execCalls);
        $this->assertStringContainsString('smbcacls', $command);
        $this->assertStringContainsString('--use-kerberos=required', $command);
        $this->assertStringContainsString('--sddl', $command);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #10 — Audit sécurité escapeshellarg
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #10 — Inspection du code source : toutes les commandes exec des
     * wrappers GPO fallback utilisent escapeshellarg() pour les paramètres
     * d'entrée utilisateur ($name, $gpo, $container, $source).
     */
    public function test_all_exec_calls_use_escapeshellarg(): void
    {
        $src = file_get_contents(base_path('legacy/gpo_shim.inc.php'));
        $this->assertNotFalse($src);

        // Pour chaque fonction fallback qui exec directement, vérifier que
        // les variables sensibles passent par escapeshellarg (#7 — ajout de
        // gpogetlink, gpodellink, read_gpo_sysvol, sysvol_acl_reset).
        // Note : update_gpo_sysvol n'exec pas directement — il délègue à
        // sysvol_put qui est déjà testée.
        $patterns = [
            'gpolistcontainers' => '/function\s+gpolistcontainers.*?^}/ms',
            'gpogetlink'        => '/function\s+gpogetlink.*?^}/ms',
            'gposetlink'        => '/function\s+gposetlink.*?^}/ms',
            'gpodellink'        => '/function\s+gpodellink.*?^}/ms',
            'sysvol_put'        => '/function\s+sysvol_put.*?^}/ms',
            'read_gpo_sysvol'   => '/function\s+read_gpo_sysvol.*?^}/ms',
            'sysvol_acl_reset'  => '/function\s+sysvol_acl_reset.*?^}/ms',
        ];

        foreach ($patterns as $fn => $pattern) {
            preg_match($pattern, $src, $m);
            $this->assertNotEmpty($m, "Bloc de {$fn} doit être trouvable dans gpo_shim.inc.php");
            $this->assertStringContainsString(
                'escapeshellarg(',
                $m[0],
                "{$fn} doit utiliser escapeshellarg sur ses paramètres"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Review 1bis-18g #3 — Ticket Kerberos expiré : log + retour false
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Review #3 — sysvol_put retourne false ET log un message explicite quand
     * smbclient remonte NT_STATUS_NO_LOGON_SERVERS (cas ticket Kerberos expiré).
     */
    public function test_sysvol_put_logs_error_on_kerberos_expired(): void
    {
        // Mock smbclient qui remonte une erreur Kerberos typique.
        $this->mockExec([
            'output' => [
                'session setup failed: NT_STATUS_NO_LOGON_SERVERS',
            ],
            'return' => 1,
        ]);

        ErrorLog::query()->delete();

        $gpo = ['cn' => '{GUID-krb5}', 'displayname' => 'KrbExpired'];
        $ok = sysvol_put($this->config, $gpo, '/tmp/source');

        $this->assertFalse($ok, 'sysvol_put doit retourner false sur échec smbclient');

        // Le log doit mentionner sysvol_put et l'erreur Kerberos.
        $this->assertDatabaseHas('error_logs', ['source' => 'legacy']);
        $logs = ErrorLog::where('source', 'legacy')->get();
        $matched = false;
        foreach ($logs as $log) {
            if (str_contains($log->message, 'sysvol_put')
                && str_contains($log->message, 'NT_STATUS_NO_LOGON_SERVERS')) {
                $matched = true;
                break;
            }
        }
        $this->assertTrue(
            $matched,
            'Le log doit contenir "sysvol_put" et le message Kerberos NT_STATUS_NO_LOGON_SERVERS'
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Review 1bis-18g #M9 — Escape LDAP injection dans le filtre
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Review #M9 — search_ad(type='gpo') avec un nom contenant des caractères
     * LDAP spéciaux (`*`, `(`, `)`) doit les échapper via escape_ldap_name
     * avant de les intégrer au filtre. Pas d'injection LDAP possible.
     */
    public function test_search_ad_gpo_escapes_ldap_filter_injection(): void
    {
        $capturedFilter = null;
        $GLOBALS['__shim_ldap_call_override'] = function (string $fn, array $args) use (&$capturedFilter) {
            if ($fn === 'ldap_search') {
                $capturedFilter = $args[2]; // 3e arg = filtre LDAP
                return 'FAKE_LDAP_RESULT';
            }
            return match ($fn) {
                'ldap_connect'     => 'FAKE_LDAP_CONN',
                'ldap_set_option'  => true,
                'ldap_bind'        => true,
                'ldap_get_entries' => ['count' => 0],
                'ldap_unbind'      => true,
                default            => false,
            };
        };

        // Payload d'injection classique : tente de casser le filtre pour
        // matcher tous les objets via un OR sur objectclass=*.
        search_ad($this->config, 'foo*)(&(objectclass=*))', 'gpo');

        $this->assertNotNull($capturedFilter, 'ldap_search doit avoir été appelé');

        // Après escape : `*` → `\2a`, `(` → `\28`, `)` → `\29`.
        // L'important : après le "foo" initial, il ne doit plus rester aucun
        // caractère LDAP spécial NON échappé (sauf ceux du filtre englobant
        // construit par le shim lui-même).
        // On extrait la portion du filtre où le user-input est inséré :
        // `(cn={inject})` et `(displayname={inject})`.
        if (preg_match('/\(cn=([^)]*)\)/', $capturedFilter, $m)) {
            $cnValue = $m[1];
            $this->assertStringNotContainsString(
                '*',
                $cnValue,
                'Le wildcard `*` doit être échappé dans (cn=...)'
            );
            $this->assertStringContainsString(
                '\\2a',
                $cnValue,
                'Le wildcard doit être encodé en `\\2a`'
            );
            // La parenthèse ouvrante de l'injection `(&(` doit être échappée
            // en `\28`. Comme la première `(` est déjà hors de la valeur cn,
            // on vérifie la présence de `\28` dans le résultat de l'escape.
            $this->assertStringContainsString(
                '\\28',
                $cnValue,
                'La parenthèse d\'injection doit être encodée en `\\28`'
            );
        } else {
            $this->fail("Impossible d'extraire la portion (cn=...) du filtre : {$capturedFilter}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AC #12 — Error logger propre en cas de fonctionnement normal
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * AC #12 — search_ad(type='gpo') avec params valides et LDAP OK ne
     * génère AUCUN log ERROR sur channel legacy.
     */
    public function test_search_ad_gpo_valid_call_does_not_log_error(): void
    {
        $this->mockLdap([
            'ldap_get_entries' => ['count' => 0],
        ]);

        ErrorLog::query()->delete();
        search_ad($this->config, 'Wallpaper', 'gpo');

        $errors = ErrorLog::where('source', 'legacy')->get();
        $this->assertCount(0, $errors, 'Pas de log legacy attendu pour un appel valide');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Helpers internes
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array{fn:string,args:array}|null
     */
    private function findLdapCall(string $fn): ?array
    {
        foreach ($this->ldapCalls as $call) {
            if ($call['fn'] === $fn) {
                return $call;
            }
        }
        return null;
    }
}
