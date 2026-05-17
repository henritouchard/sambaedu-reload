<?php

declare(strict_types=1);

namespace App\Doctor;

/**
 * Contrat d'un check d'environnement read-only.
 *
 * Chaque check vérifie UN pré-requis et retourne un {@see CheckResult}.
 * Les checks sont auto-découverts par `sambaedu:doctor` via scan du
 * répertoire `app/Doctor/Checks/<Tag>/` et instanciés via le container
 * (DI possible pour injecter config / services).
 *
 * Règle d'or : **aucun side effect**. Si vous avez besoin d'écrire quelque
 * part, c'est un script de provisioning, pas un check.
 */
interface EnvironmentCheck
{
    /**
     * Tag de regroupement (`gpo`, `cache`, `database`, ...). Sert au filtre
     * `sambaedu:doctor --tag=X`. Par convention, dérivé du sous-dossier
     * (`app/Doctor/Checks/Gpo/` → `gpo`).
     */
    public function tag(): string;

    /**
     * Libellé court affiché en bord gauche du rapport (max ~30 chars).
     */
    public function name(): string;

    /**
     * Exécute le check. Doit être idempotent et sans side effect.
     */
    public function run(): CheckResult;
}
