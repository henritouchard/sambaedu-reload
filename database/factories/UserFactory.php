<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    public function definition(): array
    {
        $firstname = fake()->firstName();
        $lastname = fake()->lastName();
        $login = strtolower($firstname[0].$lastname).fake()->numberBetween(1, 999);

        return [
            'login' => $login,
            'password' => static::$password ??= Hash::make('password'),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'fullname' => "$firstname $lastname",
            'email' => fake()->unique()->safeEmail(),
            'role' => 'eleve',
            'is_active' => true,
            'ad_rights_bitmask' => 0,
        ];
    }
}
