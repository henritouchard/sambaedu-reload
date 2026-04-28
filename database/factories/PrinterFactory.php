<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Printer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Printer>
 */
class PrinterFactory extends Factory
{
    protected $model = Printer::class;

    public function definition(): array
    {
        // PK string max 15 chars : `imp` + 6 digits = 9 chars max.
        return [
            'cups_name' => 'imp' . fake()->unique()->numerify('######'),
            'orphan' => false,
            'description_ser' => null,
            'created_by_user_id' => null,
        ];
    }

    public function orphan(): static
    {
        return $this->state(fn() => ['orphan' => true]);
    }
}
