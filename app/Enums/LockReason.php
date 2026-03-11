<?php

namespace App\Enums;

/**
 * Raisons de verrouillage d'un WorkstationGroup
 * 
 * Un groupe verrouillé ne peut pas être modifié ou supprimé via le service.
 */
enum LockReason: string
{
    case ROOT = 'root';
    case CONTROL_HUB = 'control_hub';

    /**
     * Retourne la description de la raison de verrouillage
     */
    public function description(): string
    {
        return match ($this) {
            self::ROOT => 'Groupe racine',
            self::CONTROL_HUB => 'Géré par ControlHub',
        };
    }

    /**
     * Retourne le label pour l'affichage
     */
    public function label(): string
    {
        return $this->description();
    }
}
