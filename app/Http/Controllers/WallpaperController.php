<?php

namespace App\Http\Controllers;

use App\Services\WorkstationGroupLdapService;
use Illuminate\Http\Response;

/**
 * Contrôleur dédié au service des images de fonds d'écran
 */
class WallpaperController extends Controller
{
    public function __construct(
        private WorkstationGroupLdapService $parcService
    ) {
    }

    /**
     * Récupère l'image de fond d'écran d'un parc
     */
    public function getImage(string $parc, string $type): Response
    {
        $content = $this->parcService->getWallpaperContent($parc, $type);

        if ($content === null) {
            abort(404, 'Image non trouvée');
        }

        return response($content, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * Récupère la miniature d'un fond d'écran
     */
    public function getThumbnail(string $parc, string $type): Response
    {
        $content = $this->parcService->getWallpaperThumbnail($parc, $type, 124);

        if ($content === null) {
            abort(404, 'Image non trouvée');
        }

        return response($content, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
