<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La création d'un groupe d'utilisateurs est passée d'une page dédiée à une
 * modale (`group-form-modal`) hébergée sur la page /users. Comme la page hôte
 * n'est protégée que par `can:user.read`, la garde `can:user.modify` — portée
 * auparavant par la middleware de la route /users/groups/new — doit être
 * ré-affirmée dans la modale. Ces tests verrouillent ce contrat + la validation.
 */
class UserGroupCreateModalTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::users.groups._partials.group-form-modal';

    #[Test]
    public function open_is_forbidden_without_user_modify(): void
    {
        $user = User::query()->create(['login' => 'lecteur', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($user);
        self::assertTrue(Gate::forUser($user)->denies('user.modify'));

        Livewire::test(self::COMPONENT)
            ->call('open')
            ->assertForbidden();
    }

    #[Test]
    public function save_is_forbidden_without_user_modify(): void
    {
        $user = User::query()->create(['login' => 'lecteur2', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($user);

        Livewire::test(self::COMPONENT)
            ->set('name', 'arts-plastiques')
            ->call('save')
            ->assertForbidden();
    }

    #[Test]
    public function save_rejects_invalid_name(): void
    {
        $user = User::query()->create(['login' => 'admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($user);
        // On accorde la garde ; c'est la validation du nom (regex CN AD) qui doit
        // refuser — les espaces/accents sont interdits.
        Gate::before(fn ($u, string $ability) => $ability === 'user.modify' ? true : null);

        Livewire::test(self::COMPONENT)
            ->set('name', 'nom invalide !')
            ->set('type', 'custom')
            ->call('save')
            ->assertHasErrors('name');
    }
}
