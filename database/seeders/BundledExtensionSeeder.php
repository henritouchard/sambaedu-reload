<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ExtensionSourceKind;
use App\Models\ExtensionSource;
use App\Services\Extensions\ExtensionCatalogService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Story 54.1 — Pose la source EMBARQUÉE du registre d'extensions puis charge
 * ses manifests (`resources/extensions/<id>/manifest.json`).
 *
 * Idempotent / rejouable (patron {@see DirectoryTemplateSeeder}) :
 *
 *  - la source est posée par `updateOrCreate` sur la clé naturelle `bundled` —
 *    le CODE est la baseline canonique de son libellé et de sa nature ;
 *  - le chargement des manifests est délégué à
 *    {@see ExtensionCatalogService::syncBundled()}, qui upsert sur
 *    `(source, key)` et **n'écrit jamais `status`** : re-seeder ne duplique rien
 *    et ne dé-intègre aucune extension.
 *
 * Retourne des compteurs testables.
 *
 * ⚠️ Pré-déploiement VM : `php artisan db:seed --class=BundledExtensionSeeder`
 * (déjà couvert par `DatabaseSeeder`).
 */
class BundledExtensionSeeder extends Seeder
{
    /**
     * @return array{loaded: int, created: int, updated: int, skipped: int, pruned: int}
     */
    public function run(): array
    {
        ExtensionSource::updateOrCreate(
            ['key' => ExtensionSource::KEY_BUNDLED],
            [
                'name' => ExtensionSource::NAME_BUNDLED,
                'kind' => ExtensionSourceKind::Bundled,
                // Source locale : aucun point d'accès distant. Jamais NULL.
                'url' => '',
                'is_official' => true,
                'enabled' => true,
            ],
        );

        $stats = app(ExtensionCatalogService::class)->syncBundled();

        Log::info('[BundledExtensionSeeder] Seed terminé', $stats);

        return $stats;
    }
}
