<?php

declare(strict_types=1);

namespace App\Services\Wallpaper\Contracts;

use App\Dto\Wallpaper\WallpaperContext;

/**
 * Contrat pour la source de contexte wallpaper (clé `apps.$id`).
 *
 * Story 4.7 — AC 3. L'implémentation par défaut lit APCu (posé par le legacy
 * `applications.inc.php`). Une future implémentation `CacheWallpaperContextRepository`
 * prendra le relais quand `applications.php` sera porté en Laravel.
 */
interface WallpaperContextRepository
{
    /**
     * Retourne le contexte associé à un identifiant md5, ou `null` si expiré /
     * inconnu.
     */
    public function findById(string $id): ?WallpaperContext;
}
