<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Wallpaper;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use App\Services\Wallpaper\WallpaperComposer;
use App\Services\Wallpaper\WallpaperResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint legacy wallpaper + miniature admin.
 *
 * - `legacyOut(Request)` : remplace `sambaedu/gpo/wallpaper_out.php` (appelé
 *   par logon.{linux,windows} / startup.{linux,windows}). Story 4.7 AC 2, 7, 13.
 * - `thumbnail(Wallpaper)` : miniature PNG pour l'UI admin Livewire (AC 8).
 */
class WallpaperController extends Controller
{
    public function __construct(
        private readonly WallpaperContextRepository $contextRepository,
        private readonly WallpaperResolver $resolver,
        private readonly WallpaperComposer $composer,
    ) {}

    /**
     * Intercepte `gpo/wallpaper_out.php`.
     *
     * Paramètres POST/GET :
     *   - action : wallpaper | wallpaper-wait | lockscreen | veyon
     *   - id     : md5 contexte (clé APCu apps.$id)
     *   - format : jpg | png  (défaut: jpg)
     *
     * `icone` n'est **pas** supportée : elle servait à l'UI admin legacy,
     * remplacée par l'UI Livewire (route thumbnail dédiée).
     */
    public function legacyOut(Request $request): Response
    {
        $action = (string) ($request->input('action', ''));
        $id = (string) ($request->input('id', ''));
        $format = strtolower((string) ($request->input('format', 'jpg')));

        if (! in_array($action, ['wallpaper', 'wallpaper-wait', 'lockscreen', 'veyon'], true)) {
            return response('Unknown action', 400);
        }
        if ($id === '' || ! preg_match('/^[a-f0-9]{32}$/i', $id)) {
            return response('Invalid id', 400);
        }
        if (! in_array($format, ['jpg', 'jpeg', 'png'], true)) {
            return response('Invalid format', 400);
        }
        $format = $format === 'jpeg' ? 'jpg' : $format;

        $context = $this->contextRepository->findById($id);
        if ($context === null) {
            return response('Context expired', 404);
        }

        try {
            switch ($action) {
                case 'lockscreen':
                    $resolution = $this->resolver->resolve($context, Wallpaper::TYPE_LOCKSCREEN);
                    $blob = $this->composer->composeLockscreen($resolution, $context, $format);
                    break;
                case 'wallpaper-wait':
                    $resolution = $this->resolver->resolve($context, Wallpaper::TYPE_WALLPAPER);
                    $blob = $this->composer->composeWallpaper($resolution, $context, wait: true, veyon: false, format: $format);
                    break;
                case 'veyon':
                    $resolution = $this->resolver->resolve($context, Wallpaper::TYPE_WALLPAPER);
                    $blob = $this->composer->composeWallpaper($resolution, $context, wait: false, veyon: true, format: $format);
                    break;
                case 'wallpaper':
                default:
                    $resolution = $this->resolver->resolve($context, Wallpaper::TYPE_WALLPAPER);
                    $blob = $this->composer->composeWallpaper($resolution, $context, wait: false, veyon: false, format: $format);
                    break;
            }
        } catch (\Throwable $e) {
            Log::error('[WallpaperController] compose failed', [
                'action' => $action,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return response('Compose error', 500);
        }

        return response($blob, 200, [
            'Content-Type' => 'image/' . ($format === 'png' ? 'png' : 'jpeg'),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Miniature PNG d'un wallpaper en base (UI admin).
     */
    public function thumbnail(Wallpaper $wallpaper): Response
    {
        if (! is_file($wallpaper->path)) {
            abort(404, 'Fichier wallpaper introuvable');
        }

        if (! class_exists('Imagick')) {
            return response()->file($wallpaper->path, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        try {
            $imagick = new \Imagick($wallpaper->path);
            $imagick->scaleImage(0, 160);
            $imagick->setImageFormat('png');
            $blob = (string) $imagick->getImageBlob();
            $imagick->destroy();

            $etag = '"' . sha1(($wallpaper->updated_at?->toDateTimeString() ?? '') . '|' . $wallpaper->id) . '"';

            return response($blob, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=3600',
                'ETag' => $etag,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[WallpaperController] thumbnail failed', [
                'wallpaper_id' => $wallpaper->id,
                'error' => $e->getMessage(),
            ]);
            return response()->file($wallpaper->path, [
                'Content-Type' => 'image/jpeg',
            ]);
        }
    }
}
