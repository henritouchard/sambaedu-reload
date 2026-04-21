<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AppCustomization>
 */
class AppCustomizationFactory extends Factory
{
    protected $model = AppCustomization::class;

    public function definition(): array
    {
        return [
            'app_kind' => AppKind::Firefox->value,
            'customizable_type' => null,
            'customizable_id' => null,
            'policies_json' => ['policies' => []],
            'is_default' => false,
        ];
    }

    public function firefox(): static
    {
        return $this->state(fn() => ['app_kind' => AppKind::Firefox->value]);
    }

    public function thunderbird(): static
    {
        return $this->state(fn() => ['app_kind' => AppKind::Thunderbird->value]);
    }

    public function default(): static
    {
        return $this->state(fn() => [
            'customizable_type' => null,
            'customizable_id' => null,
            'is_default' => true,
        ]);
    }

    public function forScope(Model $scope): static
    {
        return $this->state(fn() => [
            'customizable_type' => $scope::class,
            'customizable_id' => $scope->getKey(),
            'is_default' => false,
        ]);
    }
}
