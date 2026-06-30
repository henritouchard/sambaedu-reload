<?php

declare(strict_types=1);

namespace App\Services\Filesystem;

use App\Models\NetworkShare;

/**
 * Story 34.3 — résultat de {@see DirectoryTemplateService::materialize()}.
 *
 * Porte le {@see NetworkShare} matérialisé, les avertissements NON bloquants à
 * surfacer en `toastWarning` (WG-montage-seul — vide en 34.3 puisque les recettes
 * n'assignent jamais de parc, mais conservé pour homogénéité avec la page détail
 * 34.2), et le résultat `bool` du provisioning synchrone (mappé en toast).
 */
final readonly class TemplateMaterializationResult
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public NetworkShare $share,
        public array $warnings,
        public bool $provisioned,
    ) {
    }
}
