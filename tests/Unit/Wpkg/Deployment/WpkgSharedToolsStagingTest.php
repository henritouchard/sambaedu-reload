<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.20 — Staging des outils WPKG partagés (`%Z%\wpkg\tools\`).
 *
 * PIVOT ARCHITECTURAL (post-review) : le staging des outils est désormais
 * AGENT-DRIVEN (module Go `agent/provision` + `manifest.json` côté serveur), PAS
 * dans `resources/wpkg/wpkg.cmd` — ce dernier ne s'exécute sur AUCUN chemin runtime
 * SE5 (l'agent déclenche WPKG via `cscript wpkg-client.vbs`, jamais wpkg.cmd ; le
 * bundle HTTP ne re-fetche que la vbs). La logique outils dans wpkg.cmd était donc
 * INERTE → wpkg.cmd a été REVERTI à HEAD.
 *
 * Ces tests valident le CÂBLAGE INFRA SERVEUR (scripts shell), seule surface PHP
 * de la story (le moteur de staging vit côté agent Go, testé par `go test
 * ./agent/provision`) :
 *   - T1 : alias Apache `/wpkg/tools` scopé + check de complétude `update.sh` ;
 *   - T3 : `ensure_wpkg_tools` provisionne les droits world-readable (664) +
 *          www-admin ET GÉNÈRE le `manifest.json` (énumération + sha256) que
 *          l'agent fetche avant de réconcilier par hash.
 *
 * (Les ex-assertions T2 sur le CONTENU de wpkg.cmd — matérialisation %Z%,
 * manifeste cmd, ordre staging<moteur — sont retirées : wpkg.cmd reverti, logique
 * déplacée dans l'agent.)
 */
#[Group('wpkg')]
#[Group('story-27-20')]
class WpkgSharedToolsStagingTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = base_path($relative);
        self::assertFileExists($path, "Fichier d'infra attendu absent : {$relative}");

        return (string) file_get_contents($path);
    }

    // ── T1 — Alias Apache /wpkg/tools ───────────────────────────────────────

    #[Test]
    public function setup_apache_declares_wpkg_tools_alias_scoped_to_tools_subtree(): void
    {
        $conf = $this->read('scripts/setupApache.sh');

        // L'alias pointe EXACTEMENT le sous-arbre des outils, jamais l'arbre install entier.
        self::assertStringContainsString(
            'Alias /wpkg/tools /var/sambaedu/unattended/install/wpkg/tools',
            $conf,
            'Alias /wpkg/tools manquant ou non scopé sur .../install/wpkg/tools'
        );
        self::assertStringContainsString(
            '<Directory /var/sambaedu/unattended/install/wpkg/tools>',
            $conf,
            'Bloc <Directory> de l\'alias /wpkg/tools manquant'
        );
    }

    #[Test]
    public function wpkg_tools_alias_has_security_guardrails(): void
    {
        $conf = $this->read('scripts/setupApache.sh');

        // On isole le bloc <Directory .../install/wpkg/tools> pour vérifier ses garde-fous.
        $start = strpos($conf, '<Directory /var/sambaedu/unattended/install/wpkg/tools>');
        self::assertNotFalse($start, 'Bloc <Directory> /wpkg/tools introuvable');
        $block = substr($conf, $start, 400);

        // -Indexes (pas de listing), Require all granted, PAS de FallbackResource.
        self::assertStringContainsString('Options -Indexes', $block, '-Indexes requis (pas de listing)');
        self::assertStringContainsString('Require all granted', $block);
        self::assertStringNotContainsString('FallbackResource', $block, 'PAS de FallbackResource (404 reste 404)');
    }

    #[Test]
    public function wpkg_tools_alias_never_exposes_install_root_or_pki(): void
    {
        $conf = $this->read('scripts/setupApache.sh');

        // GARDE-FOU : l'alias ne doit JAMAIS pointer la racine install (qui contient
        // aussi packages, ini, os, wpkg/{tmp2,packages.xml,wpkg-client.vbs}, etc.).
        self::assertDoesNotMatchRegularExpression(
            '#Alias\s+/wpkg/tools\s+/var/sambaedu/unattended/install\s*$#m',
            $conf,
            'L\'alias /wpkg/tools ne doit JAMAIS pointer la racine install'
        );
        // ... ni storage/keys/pki (PFX code-signing + clés CA).
        self::assertDoesNotMatchRegularExpression(
            '#Alias\s+/wpkg/tools\s+\S*storage/keys#',
            $conf,
            'L\'alias /wpkg/tools ne doit JAMAIS pointer storage/keys/pki'
        );
        // Un seul alias /wpkg/tools déclaré.
        self::assertSame(
            1,
            substr_count($conf, 'Alias /wpkg/tools '),
            'Un seul alias /wpkg/tools attendu'
        );
    }

    #[Test]
    public function update_sh_checks_wpkg_tools_alias_completeness(): void
    {
        $update = $this->read('scripts/update.sh');

        // Le test de complétude update_apache() doit exiger l'alias /wpkg/tools,
        // sinon il relance setupApache.sh (idempotent) — vhost antérieur à 27.20.
        self::assertStringContainsString(
            'grep -q "Alias /wpkg/tools" "$APACHE_CONF_TARGET"',
            $update,
            'update_apache() ne vérifie pas la présence de l\'alias /wpkg/tools'
        );
    }

    // ── T2 — wpkg.cmd REVERTI (la logique outils est passée dans l'agent) ───

    #[Test]
    public function wpkg_cmd_is_reverted_and_carries_no_tools_staging_logic(): void
    {
        $cmd = $this->read('resources/wpkg/wpkg.cmd');

        // PIVOT : wpkg.cmd ne s'exécutant sur aucun chemin runtime SE5, toute logique
        // de staging d'outils y serait inerte. wpkg.cmd est reverti à HEAD : il ne
        // contient PLUS le manifeste d'outils ni la matérialisation %Z%.
        self::assertStringNotContainsString(
            'SE4_WPKG_TOOLS_URL',
            $cmd,
            'wpkg.cmd ne doit plus porter le manifeste d\'outils (staging déplacé dans l\'agent)'
        );
        self::assertStringNotContainsString(
            'fsutil reparsepoint query',
            $cmd,
            'La matérialisation %Z% (reparse point) vit désormais dans l\'agent (WindowsResolver), pas wpkg.cmd'
        );
    }

    // ── T3 — Provisioning serveur (ensure_wpkg_tools + manifest.json) ───────

    #[Test]
    public function update_sh_provisions_tools_world_readable_and_www_admin(): void
    {
        $update = $this->read('scripts/update.sh');

        self::assertStringContainsString('ensure_wpkg_tools() {', $update, 'Fonction ensure_wpkg_tools absente');

        // La fonction est appelée dans le flux principal (après ensure_wpkg_smb_client).
        self::assertMatchesRegularExpression(
            '#ensure_wpkg_smb_client\s+echo ""\s+ensure_wpkg_tools#s',
            $update,
            'ensure_wpkg_tools doit être appelée après ensure_wpkg_smb_client'
        );

        // Droits world-readable 664 (un 660 échouerait en silence côté poste « other »).
        $start = strpos($update, 'ensure_wpkg_tools() {');
        $block = substr($update, $start, 4000);
        self::assertStringContainsString('chmod 664', $block, 'Fichiers d\'outils doivent être 664 (world-readable)');
        self::assertStringContainsString('chmod 755', $block, 'Dossiers doivent être 755');
        self::assertStringContainsString('chown -R www-admin:www-admin', $block);

        // Fail-soft : dossier absent → return 0 (jamais d'échec d'update).
        self::assertStringContainsString('return 0', $block);
        self::assertStringContainsString('/var/sambaedu/unattended/install', $block);
    }

    #[Test]
    public function ensure_wpkg_tools_generates_manifest_with_sha256_for_agent_staging(): void
    {
        $update = $this->read('scripts/update.sh');

        $start = strpos($update, 'ensure_wpkg_tools() {');
        self::assertNotFalse($start, 'Fonction ensure_wpkg_tools introuvable');
        $block = substr($update, $start, 4000);

        // PIVOT agent-driven : ensure_wpkg_tools génère un manifest.json (énumération
        // + sha256) que l'agent fetche avant de réconcilier PAR HASH.
        self::assertStringContainsString('manifest.json', $block, 'ensure_wpkg_tools doit générer un manifest.json');
        self::assertStringContainsString('sha256sum', $block, 'Le manifeste doit porter le sha256 par fichier');

        // Schéma JSON aligné sur provision.Resource côté agent (kind/relpath/sha256).
        self::assertStringContainsString('"kind": "wpkg-tool"', $block, 'Entrées du manifeste typées kind="wpkg-tool"');
        self::assertStringContainsString('"relpath"', $block, 'relpath requis (sous-arbo préservée)');
        self::assertStringContainsString('"sha256"', $block, 'sha256 requis (clé d\'idempotence agent)');

        // Le manifeste est servi world-readable (664) + www-admin, comme les outils.
        self::assertStringContainsString('chmod 664 "$manifest"', $block, 'manifest.json doit être 664 (servi à l\'agent en « other »)');
    }
}
