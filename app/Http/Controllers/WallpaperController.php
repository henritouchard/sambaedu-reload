<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Dto\Wallpaper\WallpaperContext;
use App\Gpo\Services\WorkstationConfigContextResolver;
use App\Models\Wallpaper;
use App\Services\Wallpaper\Contracts\WallpaperContextRepository;
use App\Services\Wallpaper\WallpaperComposer;
use App\Services\Wallpaper\WallpaperResolver;
use Illuminate\Http\JsonResponse;
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
     * Story 16.13 — endpoint natif `GET /api/v1/workstation-config/wallpaper`.
     *
     * Pattern iso 16.12 strict : `$workstationUuid` extrait EXCLUSIVEMENT
     * du JWT via `$request->attributes->get('auth_v1.workstation_uuid')`
     * (injecté par le middleware `auth.v1.workstation` 16.10). Aucun
     * lookup APCu md5 — résolution serveur DB via
     * `WorkstationConfigContextResolver`.
     *
     * Iso-fonctionnel avec `legacyOut()` : mêmes actions, mêmes formats,
     * mêmes Content-Type, mêmes Cache-Control. Seule déviation D5 :
     * 404 explicite si `workstation_uuid` JWT inconnu en DB (vs 200
     * empty / 404 « Context expired » legacy).
     */
    public function apiV1(Request $request, WorkstationConfigContextResolver $resolver): Response|JsonResponse
    {
        $workstationUuid = (string) $request->attributes->get('auth_v1.workstation_uuid', '');

        $action = (string) ($request->input('action', 'wallpaper'));
        $format = strtolower((string) ($request->input('format', 'jpg')));
        $os = (string) ($request->input('os', 'linux'));
        $userLogin = (string) ($request->input('user', ''));
        $userProfile = (string) ($request->input('userprofile', ''));

        if (! in_array($action, ['wallpaper', 'wallpaper-wait', 'lockscreen', 'veyon'], true)) {
            return response('Unknown action', 400);
        }
        if (! in_array($format, ['jpg', 'jpeg', 'png'], true)) {
            return response('Invalid format', 400);
        }
        $format = $format === 'jpeg' ? 'jpg' : $format;

        $context = $resolver->toWallpaperContext($workstationUuid, $os, $userLogin, $userProfile);
        if ($context === null) {
            // Déviation D5 — observabilité admin (vs 200 vide legacy).
            Log::channel('auth-v1')->warning('[WallpaperController] workstation not found', [
                'action_type' => 'agent.v1.config.workstation_not_found',
                'workstation_uuid_prefix' => substr($workstationUuid, 0, 8),
                'endpoint' => '/api/v1/workstation-config/wallpaper',
            ]);
            // Format JSON unifié post-review (Henri Q2 2026-05-19).
            // Cache-Control délégué au middleware `auth.v1.secure-headers` (Opus-11).
            return response()->json(['error' => 'workstation_not_found'], 404);
        }

        return $this->composeWallpaperResponse($context, $action, $format);
    }

    /**
     * Compose la réponse wallpaper à partir d'un `WallpaperContext` déjà
     * hydraté.
     *
     * Helper utilisé exclusivement par `apiV1()`. La méthode `legacyOut()`
     * conserve son propre switch action+composition (AC7.2 : zéro
     * modification comportementale legacy, pas d'extraction commune).
     * Cette légère duplication garantit la non-régression 4.7 à 100 %.
     */
    private function composeWallpaperResponse(WallpaperContext $context, string $action, string $format): Response
    {
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
                'machine' => $context->machineName,
                'error' => $e->getMessage(),
            ]);
            return response('Compose error', 500);
        }

        // Cache-Control + Pragma délégués au middleware `auth.v1.secure-headers`
        // (Story 16.10 — EnsureSecureApiHeaders). Pas de duplication ici
        // (Opus-11 post-review code-review 16.13).
        return response($blob, 200, [
            'Content-Type' => 'image/' . ($format === 'png' ? 'png' : 'jpeg'),
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
