<?php

declare(strict_types=1);

namespace Tests\Feature\AppProfile;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.4 / Décision B 2026-05-07 — Test STRUCTUREL d'extraction.
 *
 * Les 3 modales d'attach de `parc-settings/profiles` ont été extraites en
 * composants partagés sous `resources/views/components/organisms/wpkg/`.
 * Ce test vérifie uniquement la **structure des fichiers et références** :
 * 1. Les nouveaux fichiers composants existent.
 * 2. Les anciens partials `_partials/add-{apps,groups,workstations}-modal.blade.php`
 *    n'existent plus (zéro doublon).
 * 3. La page `parc-settings/profiles/index.blade.php` référence les nouveaux
 *    composants `<x-organisms.wpkg.attach-*-modal>` et plus les anciens `@include`.
 *
 * Ce test ne valide PAS le comportement Livewire (montage, props, dispatch
 * d'events). Pour cela, voir `ProfileAttachModalsIntegrationTest`.
 */
class ProfileAttachModalsExtractionTest extends TestCase
{
    #[Test]
    public function shared_components_exist(): void
    {
        $base = resource_path('views/components/organisms/wpkg');
        self::assertFileExists($base.'/attach-apps-modal.blade.php');
        self::assertFileExists($base.'/attach-groups-modal.blade.php');
        self::assertFileExists($base.'/attach-workstations-modal.blade.php');
    }

    #[Test]
    public function legacy_partials_have_been_removed(): void
    {
        $base = resource_path('views/pages/parc-settings/profiles/_partials');
        self::assertFileDoesNotExist($base.'/add-apps-modal.blade.php');
        self::assertFileDoesNotExist($base.'/add-groups-modal.blade.php');
        self::assertFileDoesNotExist($base.'/add-workstations-modal.blade.php');
    }

    #[Test]
    public function profile_page_uses_shared_modal_components(): void
    {
        $page = file_get_contents(resource_path('views/pages/parc-settings/profiles/index.blade.php'));
        self::assertNotFalse($page);

        // Les 3 composants partagés doivent être référencés.
        self::assertStringContainsString('<x-organisms.wpkg.attach-apps-modal', $page);
        self::assertStringContainsString('<x-organisms.wpkg.attach-groups-modal', $page);
        self::assertStringContainsString('<x-organisms.wpkg.attach-workstations-modal', $page);

        // Les anciens @include ne doivent plus exister.
        self::assertStringNotContainsString("_partials.add-apps-modal", $page);
        self::assertStringNotContainsString("_partials.add-groups-modal", $page);
        self::assertStringNotContainsString("_partials.add-workstations-modal", $page);
    }
}
