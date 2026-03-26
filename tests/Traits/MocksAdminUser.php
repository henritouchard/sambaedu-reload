<?php

declare(strict_types=1);

namespace Tests\Traits;

use Mockery;

trait MocksAdminUser
{
    private function actAsAdmin(string $login = 'admin'): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class
        );
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('checkPermissionTo')->andReturn(true);
        $user->shouldReceive('hasPermissionTo')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $user->login = $login;

        $this->actingAs($user);
    }
}
