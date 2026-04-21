<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Mixin pour les modèles scopables (User, UserGroup, WorkstationGroup).
 *
 * Story 4.8 — AC 3. Factorise la relation morphMany + les méthodes wrapper
 * `customizationFor(AppKind)` et `setCustomization(AppKind, array, User)`.
 */
trait HasAppCustomizations
{
    /**
     * Tous les overrides AppCustomization pour ce scope (toutes apps).
     */
    public function appCustomizations(): MorphMany
    {
        return $this->morphMany(AppCustomization::class, 'customizable');
    }

    /**
     * Récupère l'override pour une AppKind donnée sur ce scope (ou `null`).
     */
    public function customizationFor(AppKind $kind): ?AppCustomization
    {
        /** @var AppCustomization|null */
        return $this->appCustomizations()
            ->where('app_kind', $kind->value)
            ->first();
    }

    /**
     * Sugar updateOrCreate — renvoie la row créée/modifiée avec le scope courant.
     *
     * @param  array<string,mixed>  $policies
     */
    public function setCustomization(AppKind $kind, array $policies, ?User $author = null): AppCustomization
    {
        $authorId = $author?->getKey();

        /** @var AppCustomization $customization */
        $customization = AppCustomization::updateOrCreate(
            [
                'app_kind' => $kind->value,
                'customizable_type' => static::class,
                'customizable_id' => $this->getKey(),
            ],
            [
                'policies_json' => $policies,
                'is_default' => false,
                'updated_by' => $authorId,
                // created_by est posé uniquement à la création
            ],
        );

        if ($customization->wasRecentlyCreated && $authorId !== null) {
            $customization->created_by = $authorId;
            $customization->save();
        }

        return $customization;
    }
}
