<?php

declare(strict_types=1);

namespace App\Services\Wallpaper;

use App\Dto\Wallpaper\WallpaperContext;
use App\Dto\Wallpaper\WallpaperResolution;
use App\Services\Filesystem\XfsQuotaService;
use Illuminate\Support\Facades\Log;

/**
 * Composition moderne des fonds d'écran (Option C — refonte 2026).
 *
 * Story 4.7 — AC 5, 5 bis, 5 ter.
 *
 * Rend un blob image/jpeg|png prêt pour les clients Linux/Windows via
 * `wallpaper_out.php`. La logique visuelle est concentrée dans des helpers
 * privés (drawBottomBanner / drawAlertCard / drawQuotaOverlay / loadBadge).
 *
 * Constantes design dans `config('wallpapers.*')`.
 *
 * UserStatusService est injecté en optionnel — son absence désactive
 * silencieusement le badge "multi-session" (AC 5 ter) sans crasher.
 */
class WallpaperComposer
{
    /** Cache de badges (PNG ImageMagick) pour la durée de la requête. */
    private array $badgeCache = [];

    /** Flag statique pour n'appliquer les limits Imagick qu'une seule fois par process. */
    private static bool $imagickLimitsConfigured = false;

    public function __construct(
        private readonly ?XfsQuotaService $quotaService = null,
        private readonly ?\App\Services\UserSessionsService $sessions = null,
    ) {
        self::configureImagickLimits();
    }

    /**
     * Impose des limites de ressources Imagick pour protéger contre les
     * bombes pixel (fichiers malformés qui consomment GB de RAM).
     *
     * Post-review #10. Idempotent via flag statique : appelé par Composer ET
     * UploadService, mais n'exécute qu'une fois les `setResourceLimit`.
     */
    public static function configureImagickLimits(): void
    {
        if (self::$imagickLimitsConfigured) {
            return;
        }
        if (! class_exists('Imagick')) {
            self::$imagickLimitsConfigured = true;
            return;
        }
        try {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024); // 256 MB
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);    // 512 MB
        } catch (\Throwable $e) {
            Log::warning('[WallpaperComposer] Imagick setResourceLimit failed', [
                'error' => $e->getMessage(),
            ]);
        }
        self::$imagickLimitsConfigured = true;
    }

    /**
     * Compose un wallpaper (1920×1080).
     *
     * @return string blob binaire image/{$format}
     */
    public function composeWallpaper(
        WallpaperResolution $res,
        WallpaperContext $ctx,
        bool $wait,
        bool $veyon,
        string $format,
    ): string {
        $format = strtolower($format) === 'png' ? 'png' : 'jpg';

        if ($res->isQuotaOverride) {
            return $this->composeQuotaOverride($ctx, $format);
        }

        $image = $this->loadBaseImage($res->sourcePath, 1920, 1080);

        try {
            if ($wait) {
                $this->drawAlertCard($image, config('wallpapers.messages.wait', 'En cours de connexion…'), 'rgba(0,0,0,0.75)', 440);
                return $this->finalize($image, $format);
            }

            // Alertes cartouches centrales
            $alertCards = [];
            if ($veyon && $this->isVeyonActive()) {
                $msg = (string) config('wallpapers.messages.veyon', 'Prise de contrôle à distance en cours');
                $extra = (string) config('sambaedu.veyon_message', '');
                if ($extra !== '') {
                    $msg .= ' — ' . $extra;
                }
                $alertCards[] = ['text' => $msg, 'color' => 'rgba(200,30,30,0.85)'];
            }

            $multiSessions = $this->detectMultiSessions($ctx);
            if ($multiSessions !== null && $multiSessions !== []) {
                $msg = ((string) config('wallpapers.messages.multi_session', 'Sessions détectées sur : '))
                    . implode(', ', $multiSessions);
                $alertCards[] = ['text' => $msg, 'color' => 'rgba(230,140,30,0.85)'];
            }

            // Empilage central (400 / 640 si 2 cartouches)
            $yStart = 340;
            foreach ($alertCards as $card) {
                $this->drawAlertCard($image, $card['text'], $card['color'], $yStart);
                $yStart += 220;
            }

            // Badges (droite du bandeau)
            $badges = $this->collectBadges($ctx, $veyon, $multiSessions);

            // Bandeau inférieur (gauche identité, droite badges)
            $this->drawBottomBanner($image, $ctx, $badges);

            return $this->finalize($image, $format);
        } finally {
            $this->cleanup($image);
        }
    }

    /**
     * Compose un lockscreen (1920×1080 ou 1280×720 pour png).
     */
    public function composeLockscreen(
        WallpaperResolution $res,
        WallpaperContext $ctx,
        string $format,
    ): string {
        $format = strtolower($format) === 'png' ? 'png' : 'jpg';
        $width = 1920;
        $height = 1080;

        $image = $this->loadBaseImage($res->sourcePath, $width, $height);

        try {
            // Bandeau simplifié (sans identité user — écran verrouillé)
            $this->drawLockscreenBanner($image, $ctx);

            if ($format === 'png') {
                $image->resizeImage(1280, 720, \Imagick::FILTER_LANCZOS, 1, true);
                $image->setImageFormat('png');
                $image->setImageCompressionQuality(90);
            } else {
                $image->setImageFormat('jpg');
                $image->setImageCompressionQuality(90);
            }

            return (string) $image->getImageBlob();
        } finally {
            $this->cleanup($image);
        }
    }

    // ========================================================================
    // OVERLAY — quota saturé (écran rouge foncé plein écran, cartouche blanc)
    // ========================================================================

    private function composeQuotaOverride(WallpaperContext $ctx, string $format): string
    {
        $image = new \Imagick();
        $image->newImage(1920, 1080, new \ImagickPixel('#8B0000'));
        $image->setImageFormat($format === 'png' ? 'png' : 'jpg');

        try {
            $partitions = $this->quotaService !== null && $ctx->userLogin !== ''
                ? $this->safeGetOverQuotaPartitions($ctx->userLogin)
                : [];

            $this->drawQuotaOverlay($image, $partitions);

            return $this->finalize($image, $format);
        } finally {
            $this->cleanup($image);
        }
    }

    private function drawQuotaOverlay(\Imagick $image, array $partitions): void
    {
        // Cartouche blanc 800×400 centré
        $cardX = (1920 - 800) / 2;
        $cardY = (1080 - 400) / 2;

        $draw = new \ImagickDraw();
        try {
            // Ombre portée
            $draw->setFillColor(new \ImagickPixel('rgba(0,0,0,0.35)'));
            $draw->roundRectangle($cardX + 6, $cardY + 6, $cardX + 806, $cardY + 406, 16, 16);
            // Card blanche
            $draw->setFillColor(new \ImagickPixel('#FFFFFF'));
            $draw->roundRectangle($cardX, $cardY, $cardX + 800, $cardY + 400, 12, 12);
            $image->drawImage($draw);

            // Titre
            $title = (string) config('wallpapers.messages.quota_title', 'Stockage saturé');
            $this->drawText(
                $image,
                $title,
                $cardX + 400,
                $cardY + 110,
                42,
                '#8B0000',
                'bold',
                \Imagick::ALIGN_CENTER,
            );

            // Ligne par partition
            $bodyY = $cardY + 165;
            foreach ($partitions as $part) {
                $grace = $part['grace_days'] !== null
                    ? " (blocage dans {$part['grace_days']} j)"
                    : '';
                $line = sprintf(
                    '%s : %d Mo / %d Mo%s',
                    $part['label'],
                    $part['used_mb'],
                    $part['soft_mb'],
                    $grace,
                );
                $this->drawText($image, $line, $cardX + 50, $bodyY, 22, '#1A1A1A', 'regular');
                $bodyY += 36;
            }

            if ($partitions === []) {
                $this->drawText(
                    $image,
                    'Votre quota est saturé.',
                    $cardX + 50,
                    $bodyY,
                    22,
                    '#1A1A1A',
                    'regular',
                );
                $bodyY += 36;
            }

            $this->drawText(
                $image,
                (string) config('wallpapers.messages.quota_body', "Libérez de l'espace et reconnectez-vous."),
                $cardX + 50,
                $cardY + 350,
                22,
                '#444444',
                'regular',
            );
        } finally {
            $draw->clear();
            $draw->destroy();
        }
    }

    // ========================================================================
    // BANDEAU INFÉRIEUR (wallpaper) — gauche identité / droite badges
    // ========================================================================

    private function drawBottomBanner(\Imagick $image, WallpaperContext $ctx, array $badges): void
    {
        $height = (int) config('wallpapers.banner_height', 120);
        $paddingH = (int) config('wallpapers.banner_padding_horizontal', 64);
        $paddingV = (int) config('wallpapers.banner_padding_vertical', 20);
        $imgWidth = 1920;
        $imgHeight = 1080;

        // 1) Gradient bandeau (haut vers bas : transparent → sombre 0.65)
        $grad = new \Imagick();
        $grad->newPseudoImage(
            $imgWidth,
            $height,
            'gradient:rgba(0,0,0,0)-rgba(0,0,0,0.65)',
        );
        $image->compositeImage($grad, \Imagick::COMPOSITE_OVER, 0, $imgHeight - $height);
        $grad->clear();
        $grad->destroy();

        $minimal = (bool) config('wallpapers.minimal_mode', false);

        // 2) Zone gauche — identité user (sauf minimal_mode)
        if (! $minimal) {
            $titleSize = (int) config('wallpapers.title_font_size', 28);
            $subSize = (int) config('wallpapers.subtitle_font_size', 18);
            $yTitle = $imgHeight - $height + $paddingV + $titleSize;
            $ySub = $yTitle + $subSize + 6;

            $fullname = $ctx->userFullname !== '' ? $ctx->userFullname : $ctx->userLogin;
            if ($fullname !== '') {
                $this->drawText($image, $fullname, $paddingH, $yTitle, $titleSize, '#FFFFFF', 'bold');
            }

            $sub = trim(($ctx->machineName ?: '—') . ($ctx->salleName !== '' ? ' · ' . $ctx->salleName : ''));
            $this->drawText($image, $sub, $paddingH, $ySub, $subSize, 'rgba(255,255,255,0.70)', 'regular');
        }

        // 3) Zone droite — badges
        if ($badges !== []) {
            $badgeSize = (int) config('wallpapers.badge_size', 48);
            $gap = (int) config('wallpapers.badge_gap', 16);
            $total = count($badges) * $badgeSize + (count($badges) - 1) * $gap;
            $xStart = $imgWidth - $paddingH - $total;
            $y = $imgHeight - $height + ($height - $badgeSize) / 2;

            foreach ($badges as $idx => $name) {
                $badge = $this->loadBadge($name);
                if ($badge === null) {
                    continue;
                }
                $x = $xStart + $idx * ($badgeSize + $gap);
                $image->compositeImage($badge, \Imagick::COMPOSITE_OVER, (int) $x, (int) $y);
            }
        }
    }

    private function drawLockscreenBanner(\Imagick $image, WallpaperContext $ctx): void
    {
        $height = (int) config('wallpapers.banner_height', 120);
        $paddingH = (int) config('wallpapers.banner_padding_horizontal', 64);
        $paddingV = (int) config('wallpapers.banner_padding_vertical', 20);
        $imgWidth = 1920;
        $imgHeight = 1080;

        $grad = new \Imagick();
        $grad->newPseudoImage(
            $imgWidth,
            $height,
            'gradient:rgba(0,0,0,0)-rgba(0,0,0,0.65)',
        );
        $image->compositeImage($grad, \Imagick::COMPOSITE_OVER, 0, $imgHeight - $height);
        $grad->clear();
        $grad->destroy();

        // Gauche : machine · salle uniquement
        $subSize = (int) config('wallpapers.subtitle_font_size', 18);
        $yText = $imgHeight - $height + $paddingV + $subSize + 8;
        $label = trim(($ctx->machineName ?: '—') . ($ctx->salleName !== '' ? ' · ' . $ctx->salleName : ''));
        if ($label !== '' && $label !== '—') {
            $this->drawText($image, $label, $paddingH, $yText, $subSize, 'rgba(255,255,255,0.85)', 'regular');
        }

        // Droite : logo étab si dispo
        $logoEtab = (string) config('wallpapers.logo_etab_path');
        if ($logoEtab !== '' && is_file($logoEtab)) {
            try {
                $logo = new \Imagick($logoEtab);
                $logo->resizeImage(0, 64, \Imagick::FILTER_LANCZOS, 1);
                $logoWidth = $logo->getImageWidth();
                $x = $imgWidth - $paddingH - $logoWidth;
                $y = $imgHeight - $height + ($height - 64) / 2;
                $image->compositeImage($logo, \Imagick::COMPOSITE_OVER, (int) $x, (int) $y);
                $logo->clear();
                $logo->destroy();
            } catch (\Throwable $e) {
                Log::warning('[WallpaperComposer] logo étab failed', ['error' => $e->getMessage()]);
            }
        }
    }

    // ========================================================================
    // CARTOUCHE ALERTE (Veyon, multi-session, wait…)
    // ========================================================================

    private function drawAlertCard(\Imagick $image, string $text, string $color, int $yOffset): void
    {
        $imgWidth = 1920;
        $cardH = 200;
        $paddingH = 120;

        $draw = new \ImagickDraw();
        try {
            $draw->setFillColor(new \ImagickPixel($color));
            $draw->roundRectangle($paddingH, $yOffset, $imgWidth - $paddingH, $yOffset + $cardH, 16, 16);
            $image->drawImage($draw);

            $fontSize = (int) config('wallpapers.alert_font_size', 36);
            $this->drawText(
                $image,
                $text,
                (int) ($imgWidth / 2),
                $yOffset + (int) ($cardH / 2) + ($fontSize / 3),
                $fontSize,
                '#FFFFFF',
                'bold',
                \Imagick::ALIGN_CENTER,
            );
        } finally {
            $draw->clear();
            $draw->destroy();
        }
    }

    // ========================================================================
    // HELPERS — badges, fonts, base image, finalize
    // ========================================================================

    /**
     * @return list<string> noms de badges à afficher (sans extension)
     */
    private function collectBadges(WallpaperContext $ctx, bool $veyon, ?array $multiSessions): array
    {
        $badges = [];
        if ($ctx->userIsAdmin) {
            $badges[] = 'admin';
        }
        // Mode submarine : Veyon actif mais invisible — ni cartouche ni badge
        // (le badge trahirait la présence de Veyon). Post-review fix #2.
        if ($veyon && $this->isVeyonActive()) {
            $badges[] = 'veyon';
        }
        if ($multiSessions !== null && $multiSessions !== []) {
            $badges[] = 'multi-session';
        }
        // Quota warning (soft, pas hard)
        if ($this->quotaService !== null && $ctx->userLogin !== '') {
            if ($this->isUserOverQuotaSoftSafe($ctx->userLogin)) {
                $badges[] = 'quota-warning';
            }
        }

        return $badges;
    }

    private function loadBadge(string $name): ?\Imagick
    {
        if (isset($this->badgeCache[$name])) {
            return clone $this->badgeCache[$name];
        }
        $map = (array) config('wallpapers.badges', []);
        if (! isset($map[$name])) {
            return null;
        }
        $path = resource_path((string) $map[$name]);
        if (! is_file($path)) {
            Log::warning('[WallpaperComposer] badge missing', ['name' => $name, 'path' => $path]);
            return null;
        }
        try {
            $badge = new \Imagick($path);
            $this->badgeCache[$name] = $badge;
            return clone $badge;
        } catch (\Throwable $e) {
            Log::warning('[WallpaperComposer] badge load failed', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function drawText(
        \Imagick $image,
        string $text,
        int $x,
        int $y,
        int $size,
        string $color,
        string $weight = 'regular',
        int $align = \Imagick::ALIGN_LEFT,
    ): void {
        $draw = new \ImagickDraw();
        try {
            $font = $this->resolveFont($weight);
            if ($font !== null) {
                $draw->setFont($font);
            }
            $draw->setFontSize($size);
            $draw->setFillColor(new \ImagickPixel($color));
            $draw->setTextAlignment($align);
            $image->annotateImage($draw, $x, $y, 0, $text);
        } finally {
            $draw->clear();
            $draw->destroy();
        }
    }

    /** Première font dispo parmi `config('wallpapers.fonts.$weight')`. */
    private function resolveFont(string $weight): ?string
    {
        $candidates = (array) config("wallpapers.fonts.{$weight}", []);
        foreach ($candidates as $candidate) {
            $path = str_starts_with((string) $candidate, '/')
                ? (string) $candidate
                : base_path((string) $candidate);
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    /** Charge et redimensionne l'image source, fallback sur newImage si vide. */
    private function loadBaseImage(string $path, int $width, int $height): \Imagick
    {
        if ($path !== '' && is_file($path)) {
            try {
                $img = new \Imagick($path);
                $img->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1, false);
                // Force exactement 1920×1080 (l'image pourrait garder ratio)
                if ($img->getImageWidth() !== $width || $img->getImageHeight() !== $height) {
                    $img->cropThumbnailImage($width, $height);
                }
                return $img;
            } catch (\Throwable $e) {
                Log::warning('[WallpaperComposer] load base failed, fallback blank', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $img = new \Imagick();
        $img->newImage($width, $height, new \ImagickPixel('#202020'));
        return $img;
    }

    private function finalize(\Imagick $image, string $format): string
    {
        $image->setImageFormat($format === 'png' ? 'png' : 'jpg');
        $image->setImageCompressionQuality(90);
        return (string) $image->getImageBlob();
    }

    private function cleanup(?\Imagick $image): void
    {
        if ($image !== null) {
            try {
                $image->clear();
                $image->destroy();
            } catch (\Throwable) {
                // ignore
            }
        }
        foreach ($this->badgeCache as $badge) {
            try {
                $badge->clear();
                $badge->destroy();
            } catch (\Throwable) {
                // ignore
            }
        }
        $this->badgeCache = [];
    }

    // ========================================================================
    // Intégrations tierces — safe wrappers
    // ========================================================================

    /**
     * Détecte les sessions multiples — retourne la liste des autres machines
     * où l'utilisateur a une session active.
     *
     * Post-review #A : délégué au service dédié `UserSessionsService` (bindé
     * via `WallpaperServiceProvider`). Si le service est absent (null), on
     * retourne null → badge et cartouche multi-session silencieusement masqués.
     *
     * @return list<string>|null liste de machines ou null si indisponible
     */
    private function detectMultiSessions(WallpaperContext $ctx): ?array
    {
        if ($this->sessions === null || $ctx->userLogin === '') {
            return null;
        }
        try {
            $others = $this->sessions->getOtherMachines($ctx->userLogin, $ctx->machineName);
            return $others === [] ? null : $others;
        } catch (\Throwable $e) {
            Log::warning('[WallpaperComposer] multi-session detect failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Hook sambaedu : si le flag veyon est désactivé ou qu'on est en mode submarine. */
    private function isVeyonActive(): bool
    {
        if ((bool) config('sambaedu.veyon_submarine', false)) {
            return false;
        }
        return true;
    }

    private function isUserOverQuotaSoftSafe(string $login): bool
    {
        if ($this->quotaService === null) {
            return false;
        }
        try {
            $usage = $this->quotaService->getDiskUsage($login);
            return (bool) ($usage['home']['is_over_soft'] ?? false)
                || (bool) ($usage['sambaedu']['is_over_soft'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int,array{label:string,used_mb:int,soft_mb:int,grace_days:int|null}>
     */
    private function safeGetOverQuotaPartitions(string $login): array
    {
        try {
            if (method_exists($this->quotaService, 'getOverQuotaPartitionsFormatted')) {
                /** @phpstan-ignore-next-line */
                return (array) $this->quotaService->getOverQuotaPartitionsFormatted($login);
            }
        } catch (\Throwable $e) {
            Log::warning('[WallpaperComposer] quota partitions failed', [
                'error' => $e->getMessage(),
            ]);
        }
        return [];
    }
}
