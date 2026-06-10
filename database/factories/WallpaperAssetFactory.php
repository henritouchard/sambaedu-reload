<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WallpaperAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WallpaperAsset>
 */
class WallpaperAssetFactory extends Factory
{
    protected $model = WallpaperAsset::class;

    public function definition(): array
    {
        $checksum = hash('sha256', fake()->unique()->uuid());

        return [
            'filename' => substr($checksum, 0, 24) . '.jpg',
            'original_name' => 'test.jpg',
            'checksum' => $checksum,
            'byte_size' => fake()->numberBetween(10_000, 500_000),
            'uploaded_by' => null,
        ];
    }
}
