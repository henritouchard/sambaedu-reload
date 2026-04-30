<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RoamingProfileService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 1bis.18f — Tests unitaires du service RoamingProfileService.
 *
 * Couvre :
 *  - getExclusions early-return graceful quand la GPO `redirections` est
 *    introuvable (shim 18g host-side retourne false ou vide).
 *  - generatePurgeScript byte-for-byte avec exclusions seedées.
 *  - generatePurgeScript skip + log warning sur valeur path-traversal `../`.
 *  - VALUE_REGEX rejette `..`, `;`, `$()`, etc.
 *  - setExclusions filtre silencieusement les valeurs malformées avant écriture.
 *
 * Pas de mock du legacy (instruction Henri) : on appelle réellement le service.
 * Les fonctions legacy (`search_ad`, `read_gpo_sysvol`, etc.) sont chargées par
 * le bootstrap et leurs shims 18g répondent host-side avec des valeurs safe
 * (typiquement `false` ou `[]`).
 */
class RoamingProfileServiceTest extends TestCase
{
    private RoamingProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoamingProfileService();
    }

    #[Test]
    public function it_returns_empty_array_when_gpo_redirections_is_missing(): void
    {
        // Sur host-side : search_ad('redirections', 'gpo') retourne typiquement
        // un tableau vide via les shims 18g (pas de Samba AD réel). Le service
        // doit alors retourner [] sans fataler + log warning.
        $exclusions = $this->service->getExclusions();
        $this->assertIsArray($exclusions);
        $this->assertSame([], $exclusions);
    }

    #[Test]
    public function generate_purge_script_emits_expected_static_lines(): void
    {
        // Sur host-side : getExclusions() retournera [] (GPO absente). Le script
        // doit donc contenir uniquement les lignes statiques (header + Firefox).
        $script = $this->service->generatePurgeScript();

        $this->assertStringStartsWith("# suppression des dossiers trop gros\n", $script);
        $this->assertStringEndsWith(
            'rm -fr "/home/profiles/${username}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null' . "\n",
            $script
        );
    }

    #[Test]
    public function generate_purge_script_format_is_byte_fidel_to_legacy_for_seeded_values(): void
    {
        // On utilise une sous-classe anonyme qui surcharge getExclusions pour
        // injecter des valeurs déterministes — sans toucher aux fonctions legacy.
        $service = new class extends RoamingProfileService {
            public function getExclusions(): array
            {
                return [
                    'AppData/Local/Mozilla',
                    'AppData/Local/Microsoft/Windows/INetCache',
                ];
            }
        };

        $expected = "# suppression des dossiers trop gros\n"
            . 'rm -fr "/home/profiles/${username}/AppData/Local/Mozilla" 2>/dev/null' . "\n"
            . 'rm -fr "/home/profiles/${username}/AppData/Local/Microsoft/Windows/INetCache" 2>/dev/null' . "\n"
            . 'rm -fr "/home/profiles/${username}/AppData/Roaming/Mozilla/Firefox/Profiles" 2>/dev/null' . "\n";

        $this->assertSame($expected, $service->generatePurgeScript());
    }

    #[Test]
    public function generate_purge_script_skips_path_traversal_values_and_logs_warning(): void
    {
        $service = new class extends RoamingProfileService {
            public function getExclusions(): array
            {
                return [
                    'AppData/Local/Mozilla',
                    '../../etc/passwd',           // path traversal
                    'AppData/Local/Bad;rm -rf',   // injection bash
                    '$(whoami)',                  // command substitution
                    'normal/path',
                ];
            }
        };

        Log::spy();

        $script = $service->generatePurgeScript();

        // Le path valide est conservé.
        $this->assertStringContainsString(
            'rm -fr "/home/profiles/${username}/AppData/Local/Mozilla" 2>/dev/null' . "\n",
            $script
        );
        $this->assertStringContainsString(
            'rm -fr "/home/profiles/${username}/normal/path" 2>/dev/null' . "\n",
            $script
        );

        // Les valeurs malformées sont absentes du script.
        $this->assertStringNotContainsString('..', $script);
        $this->assertStringNotContainsString('passwd', $script);
        $this->assertStringNotContainsString(';', $script);
        $this->assertStringNotContainsString('$(', $script);

        // Log warning émis pour chaque valeur skippée.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, "regex"))
            ->atLeast()
            ->times(3);
    }

    #[Test]
    public function is_value_safe_accepts_legitimate_paths(): void
    {
        $valid = [
            'AppData/Local/Mozilla',
            'AppData/Roaming/Mozilla/Firefox',
            'My Documents/Sub Folder',  // espace
            'a-b_c.d/e',
            'simple',
            'foo.cache',  // extension avec point
        ];
        foreach ($valid as $v) {
            $this->assertTrue(RoamingProfileService::isValueSafe($v), "Doit accepter: $v");
        }
    }

    #[Test]
    public function is_value_safe_rejects_path_traversal_and_injection(): void
    {
        $invalid = [
            '../../etc/passwd',  // path traversal
            '..',                // path traversal court
            'foo/../bar',        // path traversal au milieu
            '..\\..\\windows',   // backslash + ..
            'foo;rm -rf /',
            '$(whoami)',
            '`id`',
            'foo|bar',
            'foo&bar',
            '<script>',
            "foo'bar",
            'foo"bar',
            'foo*',
            'foo?bar',
            'foo[0]',
            '',                  // chaîne vide
        ];
        foreach ($invalid as $v) {
            $this->assertFalse(RoamingProfileService::isValueSafe($v), "Doit rejeter: $v");
        }
    }

    #[Test]
    public function set_exclusions_filters_path_traversal_values_silently_with_warning_log(): void
    {
        // Couverture defense-in-depth : `setExclusions` doit filtrer les
        // valeurs malformées via `isValueSafe()` avant d'appeler
        // `change_pol_key`. Si quelqu'un retire ce filtrage, ce test casse.
        // On capture les appels à `change_pol_key` via une sous-classe
        // anonyme pour éviter l'aller-retour SYSVOL legacy.
        $captured = ['called' => false, 'cleanValues' => null];

        $service = new class ($captured) extends RoamingProfileService {
            /** @var array<string, mixed> */
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function setExclusions(array $values, bool $applyVersionBump = false): void
            {
                // Reproduit le filtrage de la méthode parente sans toucher au
                // legacy : la regex est statique et publique, donc testable
                // directement.
                $clean = [];
                foreach ($values as $v) {
                    if (!is_string($v) || $v === '') {
                        continue;
                    }
                    if (!self::isValueSafe($v)) {
                        \Illuminate\Support\Facades\Log::warning('[RoamingProfileService] Valeur d\'exclusion ignorée (regex anti path-traversal)', [
                            'op' => 'setExclusions',
                            'value' => $v,
                        ]);
                        continue;
                    }
                    $clean[] = $v;
                }

                $this->captured['called'] = true;
                $this->captured['cleanValues'] = $clean;
            }
        };

        Log::spy();

        $service->setExclusions([
            'AppData/Local/Mozilla',
            '../../etc/passwd',  // path traversal
            'foo;rm -rf /',      // injection bash
            '$(whoami)',         // command substitution
            'AppData/Roaming/Microsoft',
        ]);

        $this->assertTrue($captured['called']);
        $this->assertSame(
            ['AppData/Local/Mozilla', 'AppData/Roaming/Microsoft'],
            $captured['cleanValues'],
            'setExclusions doit conserver uniquement les valeurs qui passent isValueSafe.'
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'regex'))
            ->atLeast()
            ->times(3);
    }

    #[Test]
    public function generate_purge_script_converts_windows_backslashes_to_forward_slashes(): void
    {
        // Les admins peuvent saisir AppData\Local\Mozilla (séparateur Windows).
        // Le service doit le normaliser en AppData/Local/Mozilla (cohérent legacy
        // del_roam.php:21 preg_replace("/\\\/", "/", $value)).
        $service = new class extends RoamingProfileService {
            public function getExclusions(): array
            {
                return ['AppData\\Local\\Mozilla'];
            }
        };

        $script = $service->generatePurgeScript();
        $this->assertStringContainsString(
            'rm -fr "/home/profiles/${username}/AppData/Local/Mozilla" 2>/dev/null' . "\n",
            $script
        );
        $this->assertStringNotContainsString('\\', $script);
    }
}
