<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FolderAccessRule;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Story 36.4 — factory de règle d'accès aux dossiers. Par défaut : un
 * `deny list_folder folder_only` sur `D:\Ressources` (masquer sans casser, hors
 * racine protégée → passe le guard), actif, ciblant un groupe frais.
 *
 * @extends Factory<FolderAccessRule>
 */
class FolderAccessRuleFactory extends Factory
{
    protected $model = FolderAccessRule::class;

    public function definition(): array
    {
        return [
            'path' => 'D:\\Ressources',
            'user_group_id' => UserGroup::factory(),
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
            'label' => 'Règle ' . fake()->unique()->numerify('###'),
            'is_active' => true,
            'created_by_user_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function allow(): static
    {
        return $this->state(fn (): array => ['ace_type' => 'allow', 'rights' => 'read']);
    }
}
