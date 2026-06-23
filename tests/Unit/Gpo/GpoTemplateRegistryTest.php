<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Support\GpoTemplateRegistry;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unitaires — résolution/validation des templates GPO par
 * `GpoTemplateRegistry` (classe SURVIVANTE après l'extinction du canal legacy,
 * story 27.14 : elle pilote `isPublishable('se4_agent_bootstrap')` dont dépend
 * la GPO-dispatcher bootstrap 25.4).
 *
 * Couverture rapatriée depuis l'ex-`GpoDetailPublishTest` (supprimé avec la
 * publication étage-2 legacy en 27.14) : ces 5 cas négatifs/limites testent le
 * comportement GÉNÉRIQUE du registre (forme répertoire vs archive, section CSE
 * obligatoire, préfixe autorisé, GPT.INI obligatoire) — non couverts par
 * `Se4AgentBootstrapTemplateTest` qui ne valide que le bootstrap concret réel.
 */
class GpoTemplateRegistryTest extends TestCase
{
    private string $templatesDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // Répertoire de templates isolé par test (override config).
        $this->templatesDir = sys_get_temp_dir() . '/gpo-templates-' . uniqid('', true) . '/';
        File::makeDirectory($this->templatesDir, 0755, true);
        config(['sambaedu.gpo.templates_dir' => $this->templatesDir]);
    }

    protected function tearDown(): void
    {
        if ($this->templatesDir !== '' && is_dir($this->templatesDir)) {
            File::deleteDirectory($this->templatesDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function registry_resolves_directory_template_case_insensitively(): void
    {
        $this->makeTemplate('SE4_WPKG', version: 7); // forme répertoire sous sambaedu-gpo/
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertTrue($registry->isPublishable('se4_wpkg'));
        $template = $registry->templateFor('se4_wpkg');
        $this->assertNotNull($template);
        $this->assertSame('SE4_WPKG', $template->archive, 'forme répertoire → archive = nom nu (résolu sous sambaedu-gpo/ par le legacy)');
        $this->assertSame(7, $template->version);
        $this->assertNull($registry->templateFor('inexistante'));
    }

    #[Test]
    public function registry_resolves_zip_template_with_filename_as_archive(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ext-zip absent');
        }
        $this->makeZipTemplate('se4_applications', version: 4);
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $template = $registry->templateFor('se4_applications');
        $this->assertNotNull($template);
        $this->assertSame('se4_applications.zip', $template->archive, 'forme archive → archive = nom de fichier .zip');
    }

    #[Test]
    public function registry_rejects_template_without_cse_section(): void
    {
        // GPT.INI sans [CSE] → invalide pour import_gpo/get_gpo_template_info (F1).
        $this->makeTemplate('se4_broken', withCse: false);
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertFalse($registry->isPublishable('se4_broken'));
    }

    #[Test]
    public function registry_ignores_archive_without_allowed_prefix(): void
    {
        if (! extension_loaded('zip')) {
            $this->markTestSkipped('ext-zip absent');
        }
        // Archive valide (CSE présent) mais nom hors préfixe se4_/etab_ → ignorée (F7).
        $this->makeZipTemplate('random_thing');
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertNull($registry->templateFor('random_thing'));
    }

    #[Test]
    public function registry_ignores_directory_without_gpt_ini(): void
    {
        File::makeDirectory($this->templatesDir . 'sambaedu-gpo/se4_empty', 0755, true);
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertNull($registry->templateFor('se4_empty'));
    }

    /**
     * Story 27.16 — le nouveau préfixe SE5 `se_` reconnaît `SE_agent_bootstrap`
     * (matching `mb_strtolower`) SANS dégrader la reconnaissance des templates
     * legacy `se4_`/`etab_` (non-régression), ET sans chevaucher `se4_` :
     * `se4_wpkg` lowercasé commence par `se4`, jamais par `se_`.
     */
    #[Test]
    public function registry_recognizes_se_prefix_for_bootstrap_without_regressing_legacy_prefixes(): void
    {
        $this->makeTemplate('SE_agent_bootstrap');   // préfixe se_ (insensible casse)
        $this->makeTemplate('se4_wpkg');             // préfixe se4_ (legacy)
        $this->makeTemplate('etab_custom');          // préfixe etab_ (legacy)
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertTrue($registry->isPublishable('SE_agent_bootstrap'), 'se_ doit reconnaître le bootstrap SE5');
        $this->assertTrue($registry->isPublishable('se4_wpkg'), 'se4_ doit rester reconnu (non-régression)');
        $this->assertTrue($registry->isPublishable('etab_custom'), 'etab_ doit rester reconnu (non-régression)');
    }

    /** Story 27.16 — `se_` ne capture pas un nom hors préfixe (`session_x`). */
    #[Test]
    public function registry_se_prefix_does_not_capture_unrelated_names(): void
    {
        // `session_x` ne commence PAS par `se_` (c'est `ses…`) → non publiable.
        $this->makeTemplate('session_x');
        $registry = $this->app->make(GpoTemplateRegistry::class);

        $this->assertFalse($registry->isPublishable('session_x'));
    }

    private function makeTemplate(string $name, int $version = 5, bool $withCse = true): void
    {
        File::makeDirectory($this->templatesDir . 'sambaedu-gpo/' . $name, 0755, true);
        File::put($this->templatesDir . 'sambaedu-gpo/' . $name . '/GPT.INI', $this->gptIni($name, $version, $withCse));
    }

    /** Crée une template en forme **archive** `<dir>/<name>.zip`. */
    private function makeZipTemplate(string $name, int $version = 5, bool $withCse = true): void
    {
        $zip = new \ZipArchive();
        $zip->open($this->templatesDir . $name . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('GPT.INI', $this->gptIni($name, $version, $withCse));
        $zip->close();
    }

    private function gptIni(string $displayName, int $version = 5, bool $withCse = true): string
    {
        $ini = "[General]\ndisplayName={$displayName}\nVersion={$version}\n";
        if ($withCse) {
            $ini .= "[CSE]\ngPCMachineExtensionNames=[{12345-CSE}]\n";
        }
        return $ini;
    }
}
