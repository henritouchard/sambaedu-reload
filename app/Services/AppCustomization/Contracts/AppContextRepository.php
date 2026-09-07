<?php

declare(strict_types=1);

namespace App\Services\AppCustomization\Contracts;

use App\Dto\AppCustomization\AppContext;

/**
 * Contrat d'accès au contexte runtime APCu posé par `applications.inc.php::get_apps()`
 * (dict `apps.$id` TTL 1800s).
 *
 * Permet de swap l'implémentation APCu vers une version cache Laravel quand
 * `applications.inc.php` sera porté natif (hors scope).
 */
interface AppContextRepository
{
    public function findById(string $id): ?AppContext;
}
