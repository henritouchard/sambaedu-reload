<?php

namespace Tests\Feature\Shortcuts;

use App\Models\Shortcut;
use App\Services\ShortcutsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Étape 8 de « Sync from AD » : les raccourcis web hérités doivent ressortir
 * avec une cible exécutable.
 *
 * Le legacy stockait des sentinelles (`default`, `microsoft-edge`) dans
 * `windows.link`, traduites seulement à la génération du `.lnk`. L'agent, lui,
 * passe la cible à `IShellLink::SetPath()` : une sentinelle produit sur le
 * poste « l'élément auquel ce raccourci renvoie a été modifié ou déplacé ».
 */
class RepairWebTargetsTest extends TestCase
{
    use RefreshDatabase;

    private function makeShortcut(array $attributes): Shortcut
    {
        return Shortcut::create(array_merge([
            'key' => 'k_'.uniqid(),
            'name' => 'Site',
            'place' => Shortcut::PLACE_DESKTOP,
            'is_global' => false,
        ], $attributes));
    }

    #[Test]
    public function la_sentinelle_edge_devient_une_cible_executable(): void
    {
        $shortcut = $this->makeShortcut([
            'windows_link' => 'microsoft-edge',
            'windows_args' => 'https://eduscol.education.fr',
        ]);

        $repaired = app(ShortcutsService::class)->repairWebTargets();

        $this->assertSame(1, $repaired);
        $shortcut->refresh();
        $this->assertSame('C:\\Windows\\System32\\rundll32.exe', $shortcut->windows_link);
        $this->assertStringContainsString(
            'microsoft-edge:https://eduscol.education.fr',
            $shortcut->windows_args,
            'Le choix Edge doit survivre à la réparation.'
        );
        $this->assertTrue($shortcut->is_url);
    }

    #[Test]
    public function la_sentinelle_default_devient_le_gestionnaire_de_protocole(): void
    {
        $shortcut = $this->makeShortcut([
            'windows_link' => 'default',
            'windows_args' => 'https://exemple.fr',
        ]);

        app(ShortcutsService::class)->repairWebTargets();

        $shortcut->refresh();
        $this->assertSame('C:\\Windows\\System32\\rundll32.exe', $shortcut->windows_link);
        $this->assertSame('url.dll,FileProtocolHandler https://exemple.fr', $shortcut->windows_args);
        $this->assertSame('', $shortcut->detectBrowserKey());
    }

    #[Test]
    public function une_url_posee_en_cible_est_reecrite(): void
    {
        // Forme produite par SE5 avant le correctif — absente du JSON legacy,
        // donc jamais couverte par le seul ré-import.
        $shortcut = $this->makeShortcut([
            'windows_link' => 'https://exemple.fr',
            'windows_args' => '',
        ]);

        app(ShortcutsService::class)->repairWebTargets();

        $shortcut->refresh();
        $this->assertSame('C:\\Windows\\System32\\rundll32.exe', $shortcut->windows_link);
        $this->assertSame('https://exemple.fr', $shortcut->getUrl());
    }

    #[Test]
    public function un_navigateur_du_catalogue_est_preserve(): void
    {
        $shortcut = $this->makeShortcut([
            'windows_link' => 'c:\\Program Files\\Mozilla Firefox\\Firefox.exe',
            'windows_args' => 'https://exemple.fr',
        ]);

        app(ShortcutsService::class)->repairWebTargets();

        $shortcut->refresh();
        $this->assertSame('firefox', $shortcut->detectBrowserKey());
        $this->assertSame('https://exemple.fr', $shortcut->getUrl());
    }

    #[Test]
    public function une_application_classique_nest_pas_touchee(): void
    {
        $shortcut = $this->makeShortcut([
            'windows_link' => 'C:\\Windows\\notepad.exe',
            'windows_args' => '',
        ]);

        $repaired = app(ShortcutsService::class)->repairWebTargets();

        $this->assertSame(0, $repaired);
        $shortcut->refresh();
        $this->assertSame('C:\\Windows\\notepad.exe', $shortcut->windows_link);
        $this->assertFalse((bool) $shortcut->is_url);
    }

    #[Test]
    public function la_reparation_est_idempotente(): void
    {
        $this->makeShortcut([
            'windows_link' => 'microsoft-edge',
            'windows_args' => 'https://exemple.fr',
        ]);

        $service = app(ShortcutsService::class);

        $this->assertSame(1, $service->repairWebTargets());
        $this->assertSame(0, $service->repairWebTargets(), 'Un second passage ne doit rien réécrire.');
    }

    #[Test]
    public function un_raccourci_controlhub_est_laisse_intact(): void
    {
        // Piloté en amont : SE5 ne réécrit jamais ses cibles.
        $shortcut = $this->makeShortcut([
            'is_global' => true,
            'windows_link' => 'microsoft-edge',
            'windows_args' => 'https://exemple.fr',
        ]);

        $this->assertSame(0, app(ShortcutsService::class)->repairWebTargets());
        $shortcut->refresh();
        $this->assertSame('microsoft-edge', $shortcut->windows_link);
    }
}
