<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Wallpaper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallpaper>
 */
class WallpaperFactory extends Factory
{
    protected $model = Wallpaper::class;

    public function definition(): array
    {
        return [
            'name' => 'test-' . fake()->unique()->numerify('###'),
            'path' => '/etc/sambaedu/applications/wallpaper/test.jpg',
            'type' => Wallpaper::TYPE_WALLPAPER,
            'owner_type' => null,
            'owner_id' => null,
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn() => [
            'owner_type' => null,
            'owner_id' => null,
            'is_default' => true,
            'path' => '/etc/sambaedu/applications/wallpaper/wallpaper.jpg',
            'name' => 'default',
        ]);
    }

    public function lockscreen(): static
    {
        return $this->state(fn() => ['type' => Wallpaper::TYPE_LOCKSCREEN]);
    }
}
