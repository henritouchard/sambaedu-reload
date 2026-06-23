<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.15 — AC8.
 *
 * Tests architecture invariance pour la migration APCu → Cache::store() sur
 * les 5 fichiers du scope (D9 Story 16.15).
 *
 * Garantit :
 *  1. Zéro appel `apcu_*` dans les 5 fichiers migrés (AC8.2).
 *  2. `ApcuCheck.php` conserve toujours ses appels `apcu_*` directs
 *     (hors-scope intentionnel — anti-régression frontière D11, AC8.3).
 *  3. `LegacyBootstrapTokenValidator.php` conserve toujours ses appels
 *     `apcu_*` directs (hors-scope intentionnel — D11, AC8.4).
 *
 * Pattern iso `MigrationModuleArchitectureTest` (Story 16.13bis).
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
            // Story 27.14 — `ApplicationsScriptsController`, `ApplicationScriptsGenerator`
            // et `ApplicationScriptsAssembler` (canal de génération de scripts
            // applications legacy) ont été supprimés ; retirés du scope.
            'app/Providers/AppCustomizationServiceProvider.php',
            'app/Providers/WallpaperServiceProvider.php',
        ];
    }

    /**
     * AC8.2 — Aucun appel `apcu_*` dans les 5 fichiers migrés.
     *
     * La regex est intentionnellement stricte : elle interdit `apcu_*` même
     * dans les commentaires (pollution conceptuelle — cf. Dev Notes piège #4).
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
     * AC8.2bis — Aucun import / référence symbolique `Apcu*` orpheline dans le scope migré.
     *
     * Si une régression future réintroduit `use App\Services\AppCustomization\ApcuAppContextWriter;`
     * ou une mention de classe `Apcu*` dans un provider, ce test casse (Story 16.15 review #2).
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
     * AC8.3 — `ApcuCheck.php` conserve TOUJOURS ses appels `apcu_*` directs.
     *
     * Garde-fou anti-régression : ce fichier est hors-scope (probe diagnostique
     * spécifique APCu — D11 Story 16.15). Si quelqu'un l'abstrait par erreur,
     * ce test casse.
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
     * AC8.4 — `LegacyBootstrapTokenValidator.php` conserve TOUJOURS ses appels `apcu_*`.
     *
     * Garde-fou : ce fichier lit `apcu_fetch('apps.'.$token)` pour interop PHP-FPM
     * legacy (D11 Story 16.15). L'abstraction Cache casserait l'interop.
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
