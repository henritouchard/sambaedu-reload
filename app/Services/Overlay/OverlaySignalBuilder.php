<?php

declare(strict_types=1);

namespace App\Services\Overlay;

use App\Dto\Overlay\OverlayAlert;
use App\Dto\Wallpaper\WallpaperContext;
use App\Services\Filesystem\XfsQuotaService;
use App\Services\UserSessionsService;
use Illuminate\Support\Facades\Log;

/**
 * Construit les signaux overlay **dérivés** (recalculés à chaque poll).
 *
 * Reprend la logique de dégradation de l'ancien compositing serveur
 * (WallpaperComposer, retiré au profit de l'overlay) pour rester
 * iso-comportemental.
 *
 * Seuls les signaux **calculables côté serveur depuis le login** sont produits
 * ici : `multi-session` et `quota`. Le signal `veyon` (prise de contrôle à
 * distance) n'est PAS dérivable du contexte serveur — c'est un état détecté
 * par le poste → il transite par le canal « posté » (`overlay_signals`), avec
 * le garde-fou submarine appliqué par `OverlayService`.
 *
 * Les services sont optionnels : leur absence désactive silencieusement le
 * signal correspondant (parité dégradation de l'ancien compositing serveur).
 */
class OverlaySignalBuilder
{
    public function __construct(
        private readonly ?UserSessionsService $sessions = null,
        private readonly ?XfsQuotaService $quota = null,
    ) {}

    /**
     * @return list<OverlayAlert>
     */
    public function buildDerived(WallpaperContext $ctx): array
    {
        if ($ctx->userLogin === '') {
            return [];
        }

        $alerts = [];

        $session = $this->buildMultiSession($ctx);
        if ($session !== null) {
            $alerts[] = $session;
        }

        $quota = $this->buildQuota($ctx);
        if ($quota !== null) {
            $alerts[] = $quota;
        }

        return $alerts;
    }

    private function buildMultiSession(WallpaperContext $ctx): ?OverlayAlert
    {
        if ($this->sessions === null) {
            return null;
        }

        try {
            $others = $this->sessions->getOtherMachines($ctx->userLogin, $ctx->machineName);
        } catch (\Throwable $e) {
            Log::warning('[OverlaySignalBuilder] multi-session detect failed', ['error' => $e->getMessage()]);
            return null;
        }

        if ($others === []) {
            return null;
        }

        $text = ((string) config('wallpapers.messages.multi_session', 'Sessions détectées sur : '))
            . implode(', ', $others);

        return new OverlayAlert(
            id: 'multi_session',
            source: OverlayAlert::SOURCE_DERIVED,
            kind: 'session',
            severity: OverlayAlert::SEVERITY_WARNING,
            title: 'Sessions multiples',
            text: $text,
            meta: ['machines' => array_values($others)],
        );
    }

    private function buildQuota(WallpaperContext $ctx): ?OverlayAlert
    {
        if ($this->quota === null) {
            return null;
        }

        try {
            $usage = $this->quota->getDiskUsage($ctx->userLogin);
        } catch (\Throwable $e) {
            Log::warning('[OverlaySignalBuilder] quota read failed', ['error' => $e->getMessage()]);
            return null;
        }

        $overHard = (bool) ($usage['home']['is_over_hard'] ?? false)
            || (bool) ($usage['sambaedu']['is_over_hard'] ?? false);
        $overSoft = (bool) ($usage['home']['is_over_soft'] ?? false)
            || (bool) ($usage['sambaedu']['is_over_soft'] ?? false);

        if (! $overSoft && ! $overHard) {
            return null;
        }

        $partitions = [];
        try {
            $partitions = $this->quota->getOverQuotaPartitionsFormatted($ctx->userLogin);
        } catch (\Throwable $e) {
            Log::warning('[OverlaySignalBuilder] quota partitions failed', ['error' => $e->getMessage()]);
        }

        return new OverlayAlert(
            id: 'quota',
            source: OverlayAlert::SOURCE_DERIVED,
            kind: 'quota',
            severity: $overHard ? OverlayAlert::SEVERITY_CRITICAL : OverlayAlert::SEVERITY_WARNING,
            title: (string) config('wallpapers.messages.quota_title', 'Stockage saturé'),
            text: (string) config('wallpapers.messages.quota_body', "Libérez de l'espace et reconnectez-vous."),
            meta: $partitions === [] ? [] : ['partitions' => array_values($partitions)],
        );
    }
}
