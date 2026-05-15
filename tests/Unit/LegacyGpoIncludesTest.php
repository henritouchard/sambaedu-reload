<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Tests unitaires pour le chargement des 4 includes GPO core (story 1bis.18a).
 *
 * Vérifie que le bootstrap charge samba-tool.inc.php, gpo.inc.php,
 * delegations.inc.php et gpo_ui.inc.php sans erreur fatale,
 * que les fonctions clés sont accessibles et que les constantes sont définies.
 */
class LegacyGpoIncludesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Désactivé : portage natif Laravel des fonctions GPO en cours
        // (Epic 16/17). Vérifier la chargeabilité des includes legacy
        // GPO (samba-tool.inc.php, gpo.inc.php, ...) n'a plus de sens
        // dès lors que ces fonctions seront retirées du périmètre runtime.
        // @todo Supprimer ce test lors de story 16.13 (retrait des shims GPO).
        $this->markTestSkipped('Désactivé pendant le portage natif Laravel des fonctions GPO (Epic 16/17).');

        $this->withoutVite();
    }

    // ─── AC #1 : Chargement sans erreur fatale ─────────────────────────

    /**
     * AC1 — Les fonctions de samba-tool.inc.php sont disponibles après bootstrap.
     */
    public function test_samba_tool_functions_exist(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('sambatool'), 'sambatool() doit exister');
        $this->assertTrue(function_exists('userexist'), 'userexist() doit exister');
        $this->assertTrue(function_exists('useradd'), 'useradd() doit exister');
        $this->assertTrue(function_exists('groupadd'), 'groupadd() doit exister');
        $this->assertTrue(function_exists('gpocreate'), 'gpocreate() doit exister');
        $this->assertTrue(function_exists('gpodel'), 'gpodel() doit exister');
        $this->assertTrue(function_exists('gposetlink'), 'gposetlink() doit exister');
        $this->assertTrue(function_exists('gpodellink'), 'gpodellink() doit exister');
        $this->assertTrue(function_exists('gpolistcontainers'), 'gpolistcontainers() doit exister');
        $this->assertTrue(function_exists('gpogetlink'), 'gpogetlink() doit exister');
    }

    /**
     * AC1 — Les fonctions de gpo.inc.php sont disponibles après bootstrap.
     */
    public function test_gpo_functions_exist(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('read_pol'), 'read_pol() doit exister');
        $this->assertTrue(function_exists('write_pol'), 'write_pol() doit exister');
        $this->assertTrue(function_exists('specialise_gpo'), 'specialise_gpo() doit exister');
        $this->assertTrue(function_exists('generalise_gpo'), 'generalise_gpo() doit exister');
        $this->assertTrue(function_exists('import_gpo'), 'import_gpo() doit exister');
        $this->assertTrue(function_exists('export_gpo'), 'export_gpo() doit exister');
        $this->assertTrue(function_exists('delete_gpo'), 'delete_gpo() doit exister');
        $this->assertTrue(function_exists('read_gpo_sysvol'), 'read_gpo_sysvol() doit exister');
        $this->assertTrue(function_exists('update_gpo_sysvol'), 'update_gpo_sysvol() doit exister');
        $this->assertTrue(function_exists('get_domain_sid'), 'get_domain_sid() doit exister');
        $this->assertTrue(function_exists('get_sid_from_name'), 'get_sid_from_name() doit exister');
        $this->assertTrue(function_exists('get_name_from_sid'), 'get_name_from_sid() doit exister');
    }

    /**
     * AC1 — Les fonctions de delegations.inc.php sont disponibles après bootstrap.
     */
    public function test_delegations_functions_exist(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('add_delegation_salle'), 'add_delegation_salle() doit exister');
        $this->assertTrue(function_exists('remove_delegation_salle'), 'remove_delegation_salle() doit exister');
        $this->assertTrue(function_exists('list_delegation_salles'), 'list_delegation_salles() doit exister');
        $this->assertTrue(function_exists('list_salle_delegations'), 'list_salle_delegations() doit exister');
        $this->assertTrue(function_exists('add_delegation_policy'), 'add_delegation_policy() doit exister');
        $this->assertTrue(function_exists('del_delegation_policy'), 'del_delegation_policy() doit exister');
        $this->assertTrue(function_exists('list_delegation_policies'), 'list_delegation_policies() doit exister');
    }

    /**
     * AC1 — Les fonctions de gpo_ui.inc.php sont disponibles après bootstrap.
     */
    public function test_gpo_ui_functions_exist(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('gpo_form_no_roam'), 'gpo_form_no_roam() doit exister');
        $this->assertTrue(function_exists('table_roam_stats'), 'table_roam_stats() doit exister');
        $this->assertTrue(function_exists('table_roam_stats_user'), 'table_roam_stats_user() doit exister');
    }

    // ─── AC #3 : Accessibilité depuis GpoSyncService ───────────────────

    /**
     * AC3 — GpoSyncService peut être instancié et les fonctions legacy sont accessibles.
     */
    public function test_gpo_sync_service_can_access_legacy_functions(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $service = new \App\Services\GpoSyncService();
        $this->assertInstanceOf(\App\Services\GpoSyncService::class, $service);

        // Les fonctions que GpoSyncService vérifie via function_exists()
        $this->assertTrue(function_exists('add_delegation_salle'));
        $this->assertTrue(function_exists('remove_delegation_salle'));
        $this->assertTrue(function_exists('get_config'));
    }

    // ─── AC #4 : Constantes gpo.inc.php définies ───────────────────────

    /**
     * AC4 — Les constantes registre de gpo.inc.php sont définies après chargement.
     */
    public function test_gpo_registry_constants_defined(): void
    {
        require_once base_path('legacy/bootstrap.php');

        // Constantes de type registre
        $this->assertTrue(defined('REG_NONE'));
        $this->assertTrue(defined('REG_SZ'));
        $this->assertTrue(defined('REG_EXPAND_SZ'));
        $this->assertTrue(defined('REG_BINARY'));
        $this->assertTrue(defined('REG_DWORD'));
        $this->assertTrue(defined('REG_DWORD_BIG_ENDIAN'));
        $this->assertTrue(defined('REG_LINK'));
        $this->assertTrue(defined('REG_MULTI_SZ'));
        $this->assertTrue(defined('REG_QWORD'));

        // Constantes de signature
        $this->assertTrue(defined('REGFILE_SIGNATURE'));
        $this->assertTrue(defined('REGFILE_VERSION'));

        // Constantes BOM
        $this->assertTrue(defined('UTF16_LITTLE_ENDIAN_BOM'));
        $this->assertTrue(defined('UTF16_BIG_ENDIAN_BOM'));
        $this->assertTrue(defined('UTF8_BOM'));
    }

    /**
     * AC4 — Les constantes de structure GPO sont définies.
     */
    public function test_gpo_structure_constants_defined(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(defined('GPO_SDDL'));
        $this->assertTrue(defined('GPT_INI'));
        $this->assertTrue(defined('USER_GPO'));
        $this->assertTrue(defined('MACHINE_GPO'));
        $this->assertTrue(defined('USER_PRINTERS'));
        $this->assertTrue(defined('USER_SHORTCUTS'));
        $this->assertTrue(defined('USER_REGISTRY'));
        $this->assertTrue(defined('MACHINE_REGISTRY'));
        $this->assertTrue(defined('MACHINE_SECEDIT'));

        // Vérifier les valeurs des constantes de type
        $this->assertSame(1, REG_SZ);
        $this->assertSame(4, REG_DWORD);
        $this->assertSame(7, REG_MULTI_SZ);
    }

    // ─── AC #4 : Pas d'erreur fatale au chargement passif ──────────────

    /**
     * AC4 — Le chargement passif (sans connexion AD) ne produit pas d'erreur
     * fatale ni de warning PHP capturé par le set_error_handler du bootstrap.
     */
    public function test_passive_loading_no_fatal_error(): void
    {
        // Capture les erreurs/warnings PHP émis pendant le chargement.
        $errors = [];
        set_error_handler(function ($severity, $message, $file, $line) use (&$errors) {
            $errors[] = compact('severity', 'message', 'file', 'line');
            return true; // empêche le handler par défaut
        });

        try {
            require_once base_path('legacy/bootstrap.php');
        } finally {
            restore_error_handler();
        }

        $this->assertEmpty(
            $errors,
            'Aucun warning/notice PHP ne doit être émis au chargement : '
                . json_encode($errors, JSON_UNESCAPED_SLASHES)
        );
    }

    // ─── AC #5 : Ordre de chargement respecté ──────────────────────────

    /**
     * AC5 — L'ordre de chargement est correct : toutes les fonctions
     * appelées par delegations.inc.php (de samba-tool + gpo) sont disponibles.
     */
    public function test_loading_order_dependencies_satisfied(): void
    {
        require_once base_path('legacy/bootstrap.php');

        // delegations.inc.php dépend de samba-tool.inc.php
        $this->assertTrue(function_exists('gpocreate'), 'gpocreate() (samba-tool) doit être chargé avant delegations');
        $this->assertTrue(function_exists('gposetlink'), 'gposetlink() (samba-tool) doit être chargé avant delegations');
        $this->assertTrue(function_exists('groupaddmember'), 'groupaddmember() (samba-tool) doit être chargé avant delegations');

        // delegations.inc.php dépend de gpo.inc.php
        $this->assertTrue(function_exists('read_gpo_sysvol'), 'read_gpo_sysvol() (gpo) doit être chargé avant delegations');
        $this->assertTrue(function_exists('get_sid_from_name'), 'get_sid_from_name() (gpo) doit être chargé avant delegations');

        // delegations.inc.php dépend de stubs (guid)
        $this->assertTrue(function_exists('guid'), 'guid() (stub) doit être disponible pour delegations');

        // gpo_ui.inc.php dépend de stubs (roaming_profiles_stats)
        $this->assertTrue(function_exists('roaming_profiles_stats'), 'roaming_profiles_stats() (stub) doit être disponible pour gpo_ui');

        // delegations.inc.php dépend de stubs (search_parcs)
        $this->assertTrue(function_exists('search_parcs'), 'search_parcs() (stub) doit être disponible pour delegations');
    }

    // ─── AC #6 : Idempotence ───────────────────────────────────────────

    /**
     * AC6 — Double require du bootstrap ne produit pas d'erreur.
     */
    public function test_double_require_is_idempotent(): void
    {
        require_once base_path('legacy/bootstrap.php');
        require_once base_path('legacy/bootstrap.php');

        // Si on arrive ici sans "Cannot redeclare" ou "Constant already defined", c'est OK
        $this->assertTrue(defined('LEGACY_BOOTSTRAP_LOADED'));
        $this->assertTrue(defined('REG_SZ'));
        $this->assertTrue(function_exists('sambatool'));
    }

    // ─── Stubs GPO deps ────────────────────────────────────────────────

    /**
     * Le stub guid() retourne un GUID au format Microsoft
     * (accolades + majuscules) identique au legacy printers.inc.php.
     */
    public function test_guid_stub_returns_microsoft_format(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $guid = guid();
        $this->assertMatchesRegularExpression(
            '/^\{[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\}$/',
            $guid,
            'guid() doit retourner un GUID au format {XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}'
        );

        // Deux appels retournent des valeurs différentes
        $guid2 = guid();
        $this->assertNotSame($guid, $guid2);
    }

    /**
     * Le stub roaming_profiles_stats() retourne un tableau vide.
     */
    public function test_roaming_profiles_stats_stub_returns_empty_array(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $result = roaming_profiles_stats();
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Le stub search_parcs() retourne un tableau vide.
     */
    public function test_search_parcs_stub_returns_empty_array(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $config = ['bind' => null, 'ldap_base_dn' => 'dc=test,dc=local'];
        $result = search_parcs($config, 'salle1', 'salle');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ─── Shims fonctions LDAP natives ───────────────────────────────────

    /**
     * Les shims de mutation ldap_* sont présents après bootstrap
     * (filet de sécurité quand ext-ldap n'est pas chargé).
     */
    public function test_ldap_mutation_shims_exist(): void
    {
        require_once base_path('legacy/bootstrap.php');

        $this->assertTrue(function_exists('ldap_add'));
        $this->assertTrue(function_exists('ldap_delete'));
        $this->assertTrue(function_exists('ldap_mod_replace'));
        $this->assertTrue(function_exists('ldap_mod_add'));
        $this->assertTrue(function_exists('ldap_mod_del'));
        $this->assertTrue(function_exists('ldap_modify_batch'));
        $this->assertTrue(function_exists('ldap_modify'));
        $this->assertTrue(function_exists('ldap_rename'));
        $this->assertTrue(function_exists('ldap_error'));
        $this->assertTrue(function_exists('ldap_errno'));
        $this->assertTrue(function_exists('ldap_read'));
        $this->assertTrue(function_exists('ldap_search'));
        $this->assertTrue(function_exists('ldap_get_entries'));
    }
}
