<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Printer;
use App\Models\PrinterDriver;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 6.2 — Factory pour le modèle `PrinterDriver` (PK composite).
 *
 * @extends Factory<PrinterDriver>
 */
class PrinterDriverFactory extends Factory
{
    protected $model = PrinterDriver::class;

    public function definition(): array
    {
        return [
            // Par défaut, on rattache à une nouvelle imprimante factory.
            'printer_cups_name' => Printer::factory()->create()->cups_name,
            'architecture' => 'x64',
            'driver_name' => fake()->company() . ' PostScript Printer',
            'source' => 'synced',
            'orphan' => false,
            'notes' => null,
            'created_by_user_id' => null,
        ];
    }

    /**
     * Driver détecté lors d'un sync (cohérent défaut, sans utilisateur).
     */
    public function synced(): static
    {
        return $this->state(fn() => [
            'source' => 'synced',
            'created_by_user_id' => null,
        ]);
    }

    /**
     * Driver uploadé via le workflow pivot W10.
     */
    public function uploaded(): static
    {
        return $this->state(fn() => [
            'source' => 'upload-w10',
        ]);
    }

    /**
     * Driver marqué orphan (présent en SER mais absent de Samba).
     */
    public function orphan(): static
    {
        return $this->state(fn() => [
            'orphan' => true,
        ]);
    }
}
