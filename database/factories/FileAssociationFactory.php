<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FileAssociation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileAssociation>
 *
 * Story 27.3bis — associations de catalogue de test. Par défaut une extension
 * (`file`) ; `protocol()` bascule en protocole.
 */
class FileAssociationFactory extends Factory
{
    protected $model = FileAssociation::class;

    public function definition(): array
    {
        $ext = '.' . fake()->unique()->lexify('????');

        return [
            'key' => 'assoc_' . fake()->unique()->numerify('####'),
            'label' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'identifier' => $ext,
            'assoc_type' => FileAssociation::ASSOC_TYPE_FILE,
            'progid' => 'App.' . fake()->unique()->lexify('?????'),
            // Par défaut `native` (toujours applicable) — pas de dépendance de
            // paquet à câbler dans les tests qui ne testent pas la validation WPKG.
            'source' => FileAssociation::SOURCE_NATIVE,
            'wpkg_package' => null,
            'is_active' => true,
        ];
    }

    public function protocol(): static
    {
        return $this->state(fn () => [
            'identifier' => fake()->unique()->lexify('????'),
            'assoc_type' => FileAssociation::ASSOC_TYPE_PROTOCOL,
        ]);
    }

    public function file(): static
    {
        return $this->state(fn () => [
            'identifier' => '.' . fake()->unique()->lexify('????'),
            'assoc_type' => FileAssociation::ASSOC_TYPE_FILE,
        ]);
    }

    /** Association fournie par un paquet WPKG (D-Henri n°7). */
    public function wpkg(string $package = 'firefox'): static
    {
        return $this->state(fn () => [
            'source' => FileAssociation::SOURCE_WPKG,
            'wpkg_package' => $package,
        ]);
    }

    /** Association native (built-in Windows) — toujours applicable. */
    public function native(): static
    {
        return $this->state(fn () => [
            'source' => FileAssociation::SOURCE_NATIVE,
            'wpkg_package' => null,
        ]);
    }
}
