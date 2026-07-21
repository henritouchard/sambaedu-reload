<?php

namespace Tests\Unit\Models;

use App\Models\Shortcut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * La cible d'un raccourci web doit TOUJOURS être un exécutable.
 *
 * L'agent la passe à `IShellLink::SetPath()`, qui n'accepte qu'un chemin de
 * fichier. Le legacy stockait les sentinelles `default` / `microsoft-edge`
 * puis les réécrivait en `rundll32 url.dll,FileProtocolHandler` au moment de
 * générer le `.lnk` ; les porter telles quelles produisait sur le poste
 * « l'élément auquel ce raccourci renvoie a été modifié ou déplacé ».
 */
class ShortcutWebTargetTest extends TestCase
{
    public static function browsers(): array
    {
        return array_map(
            fn (string $key) => [$key],
            array_keys(Shortcut::BROWSERS)
        );
    }

    #[Test]
    #[DataProvider('browsers')]
    public function la_cible_windows_est_un_executable(string $browserKey): void
    {
        $attributes = Shortcut::webTargetAttributes('https://exemple.fr', $browserKey);

        $this->assertMatchesRegularExpression(
            '/\.exe$/i',
            $attributes['windows_link'],
            "Le navigateur « {$browserKey} » doit cibler un .exe, pas une sentinelle ni une URL."
        );
        $this->assertStringStartsNotWith(
            'http',
            $attributes['windows_link'],
            "Une URL en cible casse IShellLink::SetPath()."
        );
    }

    #[Test]
    #[DataProvider('browsers')]
    public function l_url_est_toujours_retrouvable_et_le_navigateur_aussi(string $browserKey): void
    {
        $url = 'https://exemple.fr/page?a=1';
        $shortcut = new Shortcut(Shortcut::webTargetAttributes($url, $browserKey));

        $this->assertTrue($shortcut->is_url);
        $this->assertSame($url, $shortcut->getUrl());
        $this->assertSame(
            $browserKey,
            $shortcut->detectBrowserKey(),
            "Aller-retour cassé pour « {$browserKey} » : le formulaire rouvrirait sur le mauvais navigateur."
        );
    }

    #[Test]
    public function edge_passe_par_le_gestionnaire_de_protocole(): void
    {
        $attributes = Shortcut::webTargetAttributes('https://exemple.fr', 'edge');

        // Sans le schéma `microsoft-edge:`, rundll32 ouvrirait le navigateur
        // par défaut — le choix « Edge » serait silencieusement ignoré.
        $this->assertStringContainsString('microsoft-edge:https://exemple.fr', $attributes['windows_args']);
    }

    #[Test]
    public function edge_et_defaut_partagent_rundll32_mais_restent_distinguables(): void
    {
        $defaut = new Shortcut(Shortcut::webTargetAttributes('https://exemple.fr', ''));
        $edge = new Shortcut(Shortcut::webTargetAttributes('https://exemple.fr', 'edge'));

        $this->assertSame($defaut->windows_link, $edge->windows_link);
        $this->assertSame('', $defaut->detectBrowserKey());
        $this->assertSame('edge', $edge->detectBrowserKey());
    }

    #[Test]
    public function les_sentinelles_legacy_sont_encore_reconnues(): void
    {
        // Formes stockées avant le passage par rundll32. Sans ce rattrapage,
        // réécrire un raccourci Edge existant le ferait retomber sur le
        // navigateur par défaut, sans le moindre signal.
        $edge = new Shortcut([
            'is_url' => true,
            'windows_link' => 'microsoft-edge',
            'windows_args' => 'https://exemple.fr',
        ]);
        $this->assertSame('edge', $edge->detectBrowserKey());

        $defaut = new Shortcut([
            'is_url' => true,
            'windows_link' => 'default',
            'windows_args' => 'https://exemple.fr',
        ]);
        $this->assertSame('', $defaut->detectBrowserKey());
    }

    #[Test]
    public function une_url_posee_en_cible_est_relue_comme_navigateur_par_defaut(): void
    {
        // Forme produite avant ce correctif : on doit continuer à la lire
        // plutôt que d'afficher un navigateur au hasard dans le formulaire.
        $legacy = new Shortcut([
            'is_url' => true,
            'windows_link' => 'https://exemple.fr',
            'windows_args' => '',
        ]);

        $this->assertSame('', $legacy->detectBrowserKey());
        $this->assertSame('https://exemple.fr', $legacy->getUrl());
    }
}
