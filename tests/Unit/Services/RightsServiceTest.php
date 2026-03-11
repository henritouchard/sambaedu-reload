<?php

namespace Tests\Unit\Services;

use App\Services\RightsService;
use Tests\TestCase;

class RightsServiceTest extends TestCase
{
    private RightsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RightsService();
    }

    public function test_has_right_with_exact_match(): void
    {
        $userRights = RightsService::SE_USER_ADMIN; // 0xFF

        $this->assertTrue(
            $this->service->hasRight($userRights, RightsService::SE_USER_ADMIN, true)
        );
    }

    public function test_has_right_with_partial_match(): void
    {
        $userRights = RightsService::SE_USER_READ | RightsService::SE_USER_MODIFY; // 0x06

        // Avec OR=true, vérifie si AU MOINS UN bit est présent
        $this->assertTrue(
            $this->service->hasRight($userRights, RightsService::SE_USER_READ, true)
        );

        // L'utilisateur n'a pas SE_USER_ADMIN complet
        $this->assertFalse(
            $this->service->hasRight($userRights, RightsService::SE_USER_ADMIN, false)
        );
    }

    public function test_has_right_with_no_rights(): void
    {
        $userRights = RightsService::SE_NO_RIGHT;

        $this->assertFalse(
            $this->service->hasRight($userRights, RightsService::SE_USER_ADMIN, true)
        );
    }

    public function test_calculate_rights_for_admin_user(): void
    {
        // L'utilisateur 'admin' a tous les droits
        $rights = $this->service->calculateRights([], 'admin');

        $this->assertEquals(RightsService::SE_ADMIN, $rights);
    }

    public function test_calculate_rights_with_empty_groups(): void
    {
        $rights = $this->service->calculateRights([], 'regular_user');

        $this->assertEquals(RightsService::SE_NO_RIGHT, $rights);
    }

    public function test_computer_admin_constant_value(): void
    {
        // Vérifier les droits réellement inclus dans SE_COMPUTER_ADMIN
        $includedRights = RightsService::SE_COMPUTER_VIEW
            | RightsService::SE_COMPUTER_CONTROL
            | RightsService::SE_COMPUTER_ELEVATE
            | RightsService::SE_COMPUTER_INSTALL
            | RightsService::SE_WPKG_ADD; // Note: SE_WPKG_ASSIGN n'est pas inclus

        // SE_COMPUTER_ADMIN doit inclure ces droits spécifiques
        $this->assertEquals(
            $includedRights, 
            RightsService::SE_COMPUTER_ADMIN & $includedRights,
            'SE_COMPUTER_ADMIN doit inclure les droits machines spécifiés'
        );
        
        // Vérifier que SE_WPKG_ASSIGN n'est PAS inclus
        $this->assertEquals(
            0, 
            RightsService::SE_COMPUTER_ADMIN & RightsService::SE_WPKG_ASSIGN,
            'SE_COMPUTER_ADMIN ne doit pas inclure SE_WPKG_ASSIGN'
        );
        
        // Vérifier que la constante a la valeur attendue
        $this->assertEquals(0xEF00, RightsService::SE_COMPUTER_ADMIN);
    }
}
