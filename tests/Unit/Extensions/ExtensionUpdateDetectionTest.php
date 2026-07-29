<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use App\Enums\ExtensionSourceSyncStatus;
use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Services\Extensions\ExtensionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.3 (AC3) — La détection de mise à jour : **UNE règle, UN endroit**.
 *
 * La règle vit dans `ExtensionCatalogService::toListRow()` (via une privée
 * `hasUpdateAvailable()`), et `toDetail()` = `toListRow()` + des champs de
 * manifest : la liste et la fiche ne PEUVENT pas diverger. Ce fichier le
 * vérifie sur les deux chemins de lecture, avec la même matrice.
 *
 * Ce que la matrice verrouille surtout : la règle est un **écart**, jamais un
 * ordre. `version` est une chaîne libre du manifest — un tri sémantique
 * mentirait sur un « 2024-annexe-b », et une republication antérieure DOIT être
 * proposée comme un changement (la source est l'autorité de sa fraîcheur).
 */
class ExtensionUpdateDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function catalog(): ExtensionCatalogService
    {
        return $this->app->make(ExtensionCatalogService::class);
    }

    private function source(bool $enabled = true, ?ExtensionSourceSyncStatus $sync = null): ExtensionSource
    {
        $source = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();
        $source->enabled = $enabled;
        $source->sync_status = $sync ?? ExtensionSourceSyncStatus::Ok;
        $source->save();

        return $source;
    }

    /**
     * La matrice de l'AC3 : `type = app` ∧ `status = integrated` ∧
     * `installed_version ≠ ''` ∧ `version ≠ installed_version` ∧ source
     * proposante.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function updateMatrix(): array
    {
        $base = [
            'type' => 'app',
            'integrated' => true,
            'published' => '2.0.0',
            'installed' => '1.0.0',
            'enabled' => true,
            'sync' => 'ok',
        ];

        return [
            'app intégrée dont la source publie une autre version' => [$base, true],
            'versions identiques' => [['published' => '1.0.0'] + $base, false],
            'aucune version installée' => [['installed' => ''] + $base, false],
            'extension seulement disponible' => [['integrated' => false] + $base, false],
            'extension de type lien' => [['type' => 'link'] + $base, false],
            'source désactivée' => [['enabled' => false] + $base, false],
            'source en erreur de signature' => [['sync' => 'error'] + $base, false],
            // NFR7 — un dépôt injoignable n'invalide pas le dernier catalogue
            // VÉRIFIÉ : la mise à jour qu'il annonçait reste proposable.
            'source injoignable' => [['sync' => 'unreachable'] + $base, true],
            // Republication ANTÉRIEURE : un écart, donc un changement proposé.
            'la source republie une version antérieure' => [['published' => '0.9.0'] + $base, true],
            // Version non sémantique : la comparaison ne doit pas essayer de
            // « comprendre » la chaîne.
            'versions non sémantiques différentes' => [
                ['published' => '2024-annexe-b', 'installed' => '2024-annexe-a'] + $base, true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[Test]
    #[DataProvider('updateMatrix')]
    public function the_library_applies_the_update_rule(array $state, bool $expected): void
    {
        $extension = $this->build($state);

        $rows = $this->catalog()->library();
        $row = collect($rows)->firstWhere('id', $extension->id);

        self::assertNotNull($row, 'l\'extension doit être listée pour que la règle soit observable');
        self::assertSame($expected, $row['update_available']);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    #[Test]
    #[DataProvider('updateMatrix')]
    public function the_detail_page_inherits_the_very_same_rule(array $state, bool $expected): void
    {
        // `toDetail()` = `toListRow()` + …, donc la fiche ne peut pas dire
        // autre chose que la liste. C'est cette impossibilité qu'on vérifie.
        $extension = $this->build($state);

        $detail = $this->catalog()->find($extension->id);

        self::assertNotNull($detail);
        self::assertSame($expected, $detail['update_available']);
    }

    #[Test]
    public function the_row_exposes_the_installed_version_but_never_its_fingerprint(): void
    {
        // `installed_sha256` est une donnée interne de rollback : aucune valeur
        // pour l'admin, et rien à faire dans une vue.
        $extension = $this->build(['integrated' => true, 'installed' => '1.0.0', 'published' => '2.0.0']);

        $row = collect($this->catalog()->library())->firstWhere('id', $extension->id);

        self::assertSame('1.0.0', $row['installed_version']);
        self::assertArrayNotHasKey('installed_sha256', $row);
        self::assertArrayNotHasKey('installed_sha256', $this->catalog()->find($extension->id));
    }

    #[Test]
    public function an_app_is_installable_only_with_a_usable_install_block_and_a_live_source(): void
    {
        $source = $this->source();

        $withBlock = Extension::factory()->for($source, 'source')->app()->withInstallBlock()->create(['key' => 'avec']);
        $withoutBlock = Extension::factory()->for($source, 'source')->app()->create(['key' => 'sans']);
        $link = Extension::factory()->for($source, 'source')->link()->create(['key' => 'lien']);

        $rows = collect($this->catalog()->library())->keyBy('id');

        self::assertTrue($rows[$withBlock->id]['installable']);
        self::assertFalse($rows[$withoutBlock->id]['installable']);
        self::assertFalse($rows[$link->id]['installable']);
    }

    #[Test]
    public function an_unsupported_channel_makes_the_app_non_installable(): void
    {
        $source = $this->source();
        $extension = Extension::factory()->for($source, 'source')->app()->withInstallBlock()->create(['key' => 'snap']);

        $manifest = $extension->manifest;
        $manifest['install']['channel'] = 'snap';
        $extension->fill(['manifest' => $manifest])->save();

        $row = collect($this->catalog()->library())->firstWhere('id', $extension->id);

        self::assertFalse($row['installable']);
        self::assertFalse($extension->fresh()->hasSupportedInstallBlock());
    }

    /** @param array<string, mixed> $state */
    private function build(array $state): Extension
    {
        $sync = match ($state['sync'] ?? 'ok') {
            'error' => ExtensionSourceSyncStatus::Error,
            'unreachable' => ExtensionSourceSyncStatus::Unreachable,
            default => ExtensionSourceSyncStatus::Ok,
        };

        $source = $this->source((bool) ($state['enabled'] ?? true), $sync);

        $factory = Extension::factory()->for($source, 'source');
        $factory = ($state['type'] ?? 'app') === 'app'
            ? $factory->app()->withInstallBlock()
            : $factory->link();

        if ($state['integrated'] ?? true) {
            $factory = $factory->installed(8600, (string) ($state['installed'] ?? '1.0.0'));
        }

        $extension = $factory->create([
            'key' => 'hello',
            'version' => (string) ($state['published'] ?? '2.0.0'),
        ]);

        // `installed_version` est hors `$fillable` (doctrine 56.2) : on force la
        // valeur vide du cas « intégrée sans version installée ».
        if (($state['integrated'] ?? true) && ($state['installed'] ?? '1.0.0') === '') {
            $extension->forceFill(['installed_version' => ''])->save();
            $extension->refresh();
        }

        return $extension;
    }
}
