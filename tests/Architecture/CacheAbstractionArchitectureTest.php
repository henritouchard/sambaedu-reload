<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fige la frontière entre les fichiers migrés sur `Cache::store()` et les deux
 * qui doivent garder des appels `apcu_*` directs.
 *
 * Le cache applicatif passe par l'abstraction Laravel : les fichiers listés dans
 * `scopeFiles()` ne doivent plus appeler `apcu_*` du tout. Deux fichiers font
 * exception et sont vérifiés en sens inverse, parce que les abstraire les
 * casserait : `ApcuCheck` est la sonde de diagnostic d'APCu lui-même, et
 * `LegacyBootstrapTokenValidator` lit une entrée écrite par le PHP-FPM legacy
 * dans le segment APCu partagé.
 */
final class CacheAbstractionArchitectureTest extends TestCase
{
    /**
     * Les 5 fichiers du scope migrés — ne doivent plus contenir aucun appel apcu_*.
     *
     * @return list<string>
     */
    private function scopeFiles(): array
    {
        return [
            'app/Services/AppCustomization/CacheAppContextRepository.php',
            'app/Services/AppCustomization/CacheAppContextWriter.php',
            'app/Services/AppCustomization/Contracts/AppContextWriter.php',
            'app/Services/Wallpaper/CacheWallpaperContextRepository.php',
            // `ApplicationsScriptsController`, `ApplicationScriptsGenerator`
            // et `ApplicationScriptsAssembler` (canal de génération de scripts
            // applications legacy) ont été supprimés ; retirés du scope.
            'app/Providers/AppCustomizationServiceProvider.php',
            'app/Providers/WallpaperServiceProvider.php',
        ];
    }

    /**
     * Aucun appel `apcu_*` dans les 5 fichiers migrés.
     *
     * Le scan porte sur le texte brut du fichier : `apcu_*` est proscrit jusque
     * dans les commentaires.
     */
    #[Test]
    public function no_apcu_calls_in_migrated_files(): void
    {
        foreach ($this->scopeFiles() as $relativePath) {
            $absolutePath = base_path($relativePath);

            self::assertFileExists(
                $absolutePath,
                "Fichier {$relativePath} introuvable — vérifier que le git mv a bien été exécuté.",
            );

            $content = (string) file_get_contents($absolutePath);

            self::assertDoesNotMatchRegularExpression(
                '/\bapcu_(store|fetch|delete|enabled|clear_cache|exists|inc|dec|add|cas)\b/i',
                $content,
                "Fichier {$relativePath} doit être 100% migré sur Cache::store() — aucun appel apcu_* direct toléré (Story 16.15 D9).",
            );
        }
    }

    /**
     * Aucune référence symbolique `Apcu*` orpheline dans le scope migré.
     *
     * Si une régression future réintroduit `use App\Services\AppCustomization\ApcuAppContextWriter;`
     * ou une mention de classe `Apcu*` dans un provider, ce test casse.
     */
    #[Test]
    public function no_apcu_class_references_in_migrated_files(): void
    {
        foreach ($this->scopeFiles() as $relativePath) {
            $absolutePath = base_path($relativePath);
            $content = (string) file_get_contents($absolutePath);

            self::assertDoesNotMatchRegularExpression(
                '/\bApcu(AppContextRepository|AppContextWriter|WallpaperContextRepository)\b/',
                $content,
                "Fichier {$relativePath} ne doit plus référencer les classes Apcu* renommées (Story 16.15 — anti-régression review #2).",
            );
        }
    }

    /**
     * `ApcuCheck.php` conserve TOUJOURS ses appels `apcu_*` directs.
     *
     * Ce fichier est la sonde de diagnostic d'APCu : l'abstraire lui retirerait
     * ce qu'il mesure. Si quelqu'un le migre par erreur, ce test casse.
     */
    #[Test]
    public function apcu_check_still_uses_direct_apcu(): void
    {
        $path = base_path('app/Doctor/Checks/Cache/ApcuCheck.php');

        self::assertFileExists(
            $path,
            'ApcuCheck.php doit exister (hors-scope D11 — probe diagnostique APCu).',
        );

        $content = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/\bapcu_(store|fetch|enabled|exists)\b/i',
            $content,
            'ApcuCheck.php doit toujours contenir des appels apcu_* directs (hors-scope D11 Story 16.15 — ne pas abstraire).',
        );
    }

    /**
     * `LegacyBootstrapTokenValidator.php` conserve TOUJOURS ses appels `apcu_*`.
     *
     * Ce fichier lit `apcu_fetch('apps.'.$token)`, une entrée écrite par le
     * PHP-FPM legacy dans le segment APCu partagé. Passer par l'abstraction
     * Cache casserait cette interopérabilité.
     */
    #[Test]
    public function legacy_bootstrap_validator_still_uses_direct_apcu(): void
    {
        $path = base_path('app/Auth/V1/Services/LegacyBootstrapTokenValidator.php');

        self::assertFileExists(
            $path,
            'LegacyBootstrapTokenValidator.php doit exister (hors-scope D11).',
        );

        $content = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            '/\bapcu_(fetch|store|enabled)\b/i',
            $content,
            'LegacyBootstrapTokenValidator.php doit toujours contenir des appels apcu_* (hors-scope D11 Story 16.15 — interop PHP-FPM legacy).',
        );
    }
}
