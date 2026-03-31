<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Facades\SEConfig;
use App\Models\User;
use App\Types\User as UserDTO;
use Tests\TestCase;

class UserExternalTest extends TestCase
{
    /**
     * Test: school_code différent de l'UAI courant → isExternal() = true
     */
    public function test_eloquent_user_is_external_when_school_code_differs(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $user = new User();
        $user->school_code = '0770001a';

        $this->assertTrue($user->isExternal());
    }

    /**
     * Test: school_code identique à l'UAI courant → isExternal() = false
     */
    public function test_eloquent_user_is_not_external_when_school_code_matches(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $user = new User();
        $user->school_code = '0991229y';

        $this->assertFalse($user->isExternal());
    }

    /**
     * Test: mono-établissement (school_code = '0') → pas d'externe
     */
    public function test_eloquent_user_is_not_external_when_school_code_is_zero(): void
    {
        $user = new User();
        $user->school_code = '0';

        $this->assertFalse($user->isExternal());
    }

    /**
     * Test: school_code null → pas d'externe
     */
    public function test_eloquent_user_is_not_external_when_school_code_is_null(): void
    {
        $user = new User();
        $user->school_code = null;

        $this->assertFalse($user->isExternal());
    }

    /**
     * Test: SER sans UAI configuré → pas d'externe
     */
    public function test_eloquent_user_is_not_external_when_ser_has_no_uai(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0');

        $user = new User();
        $user->school_code = '0770001a';

        $this->assertFalse($user->isExternal());
    }

    /**
     * Test: DTO isExternal() avec school_code différent
     */
    public function test_dto_is_external_when_etab_code_differs(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $dto = new UserDTO(
            login: 'jdoe',
            fullname: 'John Doe',
            etabCode: '0770001a',
        );

        $this->assertTrue($dto->isExternal());
    }

    /**
     * Test: DTO isExternal() avec school_code identique
     */
    public function test_dto_is_not_external_when_etab_code_matches(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229y');

        $dto = new UserDTO(
            login: 'jdoe',
            fullname: 'John Doe',
            etabCode: '0991229y',
        );

        $this->assertFalse($dto->isExternal());
    }

    /**
     * Test: DTO mono-étab (etabCode '0') → pas d'externe
     */
    public function test_dto_is_not_external_when_etab_code_is_zero(): void
    {
        $dto = new UserDTO(
            login: 'jdoe',
            fullname: 'John Doe',
            etabCode: '0',
        );

        $this->assertFalse($dto->isExternal());
    }

    /**
     * Test: DTO SER sans UAI configuré (retourne '0') → pas d'externe
     */
    public function test_dto_is_not_external_when_ser_has_no_uai(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0');

        $dto = new UserDTO(
            login: 'jdoe',
            fullname: 'John Doe',
            etabCode: '0770001a',
        );

        $this->assertFalse($dto->isExternal());
    }

    /**
     * Test: DTO SER sans UAI configuré (retourne null) → pas d'externe
     */
    public function test_dto_is_not_external_when_ser_has_null_uai(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn(null);

        $dto = new UserDTO(
            login: 'jdoe',
            fullname: 'John Doe',
            etabCode: '0770001a',
        );

        $this->assertFalse($dto->isExternal());
    }

    /**
     * Test: comparaison case-insensitive des UAI
     */
    public function test_external_comparison_is_case_insensitive(): void
    {
        SEConfig::shouldReceive('getCurrentEstablishmentCode')->andReturn('0991229Y');

        $user = new User();
        $user->school_code = '0991229y';

        $this->assertFalse($user->isExternal());
    }

    /**
     * Test: school_code avec espaces uniquement → pas d'externe
     */
    public function test_eloquent_user_is_not_external_when_school_code_is_whitespace(): void
    {
        $user = new User();
        $user->school_code = '  ';

        $this->assertFalse($user->isExternal());
    }

    /**
     * Test: DTO etabCode avec espaces uniquement → pas d'externe
     */
    public function test_dto_is_not_external_when_etab_code_is_whitespace(): void
    {
        $dto = new UserDTO(
            login: 'jdoe',
            fullname: 'John Doe',
            etabCode: '  ',
        );

        $this->assertFalse($dto->isExternal());
    }
}
