<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NativeApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NativeApplication>
 *
 * Story 27.11 — applications natives curées de test (built-ins Win32).
 */
class NativeApplicationFactory extends Factory
{
    protected $model = NativeApplication::class;

    public function definition(): array
    {
        $progid = 'txtfile';

        return [
            'key' => 'native_' . fake()->unique()->numerify('####'),
            'label' => fake()->words(2, true),
            'progid' => $progid,
            'executable' => '%SystemRoot%\\system32\\notepad.exe',
            'assoc_types' => ['.txt'],
            'icon_url' => null,
        ];
    }

    /** Bloc-notes (cas canonique de Henri : `.txt → txtfile`). */
    public function notepad(): static
    {
        return $this->state(fn () => [
            'key' => 'bloc_notes_notepad_txtfile',
            'label' => 'Bloc-notes (Notepad)',
            'progid' => 'txtfile',
            'executable' => '%SystemRoot%\\system32\\notepad.exe',
            'assoc_types' => ['.txt'],
        ]);
    }
}
