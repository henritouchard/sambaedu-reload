<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.5 — Débranchement sec de l'embed legacy.
 *
 * La route `users.groups.legacy-new` (embed `annu2/add_group.php` via
 * LegacyEmbedController/LegacyEmbedService) a été SUPPRIMÉE : création de groupe
 * native livrée (modale group-form-modal), route orpheline. On vérifie que la
 * route a bien disparu et que le contrôleur/service embed n'existent plus.
 */
class LegacyEmbedRouteRemovedTest extends TestCase
{
    #[Test]
    public function legacy_new_group_route_is_removed(): void
    {
        self::assertFalse(
            Route::has('users.groups.legacy-new'),
            'La route users.groups.legacy-new doit avoir été retirée (débranchement sec 38.5).',
        );
    }

    #[Test]
    public function embed_controller_and_service_classes_no_longer_exist(): void
    {
        self::assertFalse(
            class_exists(\App\Http\Controllers\LegacyEmbedController::class),
            'LegacyEmbedController doit avoir été supprimé.',
        );
        self::assertFalse(
            class_exists(\App\Services\LegacyEmbedService::class),
            'LegacyEmbedService doit avoir été supprimé.',
        );
    }
}
