<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Applications personnalisables par le système de customization (story 4.8).
 *
 * Extension : ajouter un case ici + une classe Adapter implémentant
 * `App\Services\AppCustomization\Contracts\AppPolicyAdapter` + un composant
 * Livewire SFC nommé comme `renderFormComponent()`. Rien d'autre à toucher
 * (Registry auto-découvre via `AppKind::cases()`).
 */
enum AppKind: string
{
    case Firefox = 'firefox';
    case Thunderbird = 'thunderbird';

    /** Identifiant slug, utilisé dans URLs canoniques et chemins FS. */
    public function alias(): string
    {
        return $this->value;
    }

    /** Libellé FR affiché dans l'UI. */
    public function label(): string
    {
        return match ($this) {
            self::Firefox => 'Firefox',
            self::Thunderbird => 'Thunderbird',
        };
    }

    /** FQN de la classe Adapter bindée par le Registry. */
    public function adapterClass(): string
    {
        return match ($this) {
            self::Firefox => \App\Services\AppCustomization\Firefox\FirefoxPolicyAdapter::class,
            self::Thunderbird => \App\Services\AppCustomization\Thunderbird\ThunderbirdPolicyAdapter::class,
        };
    }
}
