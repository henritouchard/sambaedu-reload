<?php

namespace App\Policies\Traits;

use Illuminate\Support\Facades\Gate;

trait RegistersGates
{
    /**
     * Enregistre automatiquement les gates définis dans $gates
     *
     * @throws \RuntimeException Si une méthode de policy n'existe pas
     */
    public static function registerGates(): void
    {
        if (!isset(static::$gates)) {
            throw new \RuntimeException('La propriété $gates doit être définie dans ' . static::class);
        }

        foreach (static::$gates as $gateName => $method) {
            if (!method_exists(static::class, $method)) {
                throw new \RuntimeException("Méthode '{$method}' introuvable dans " . static::class);
            }
            Gate::define($gateName, [static::class, $method]);
        }
    }
}
