<?php

declare(strict_types=1);

namespace App\Services\Overlay;

use App\Dto\Overlay\OverlayAlert;
use App\Dto\Overlay\OverlayPayload;
use App\Dto\Wallpaper\WallpaperContext;
use App\Models\OverlaySignal;
use App\Models\Workstation;
use Illuminate\Support\Carbon;

/**
 * Facade overlay — encapsule la production du payload de poll ET la gestion
 * des signaux postés.
 *
 * Frontière neutre vis-à-vis de l'outil overlay (Rainmeter / Conky) : ni l'un
 * ni l'autre n'apparaît ici. Deux sources d'alertes, fusionnées en un seul
 * tableau (`source = derived|posted`) :
 *  - dérivées : `OverlaySignalBuilder` (multi-session, quota) ;
 *  - postées  : `overlay_signals` actifs pour ce poste/user.
 *
 * Garde-fou submarine (consentement prise de contrôle à distance) : un signal
 * `kind = remote_control` n'est ni stocké ni renvoyé tant que
 * `config('sambaedu.veyon_submarine')` est actif. La vérif est faite au POST
 * (évite des lignes mortes) ET au POLL (le flag peut basculer après coup —
 * c'est cette dernière qui fait foi).
 *
 * Cf. spike `spike-wallpaper-overlay-tools-2026-06-09.md`.
 */
class OverlayService
{
    public const KIND_REMOTE_CONTROL = 'remote_control';

    /**
     * Kind réservé au cartouche d'identité synthétique émis par le serveur
     * (`OverlayStateProvider`) : un signal posté avec ce kind serait avalé en
     * silence côté poste (le handler garde le premier bloc identity — le vrai).
     * Review 24.4 #2.
     */
    public const KIND_RESERVED_IDENTITY = 'identity';

    private const SEVERITIES = [
        OverlayAlert::SEVERITY_INFO,
        OverlayAlert::SEVERITY_WARNING,
        OverlayAlert::SEVERITY_CRITICAL,
    ];

    public function __construct(
        private readonly OverlaySignalBuilder $builder,
    ) {}

    /**
     * Construit le payload de poll (identity + machine + alertes fusionnées).
     */
    public function pollPayload(string $workstationUuid, WallpaperContext $ctx): OverlayPayload
    {
        $alerts = $this->builder->buildDerived($ctx);

        $submarine = (bool) config('sambaedu.veyon_submarine', false);

        // Salles (groupes) du poste → match des signaux ciblés par salle.
        $groupIds = Workstation::query()
            ->where('uuid', $workstationUuid)
            ->first()?->groups->pluck('id')->all() ?? [];

        $posted = OverlaySignal::query()
            ->activeFor($workstationUuid, $ctx->userLogin, $groupIds)
            ->get();

        foreach ($posted as $signal) {
            // Garde-fou submarine au poll (autoritaire) — ne jamais trahir une
            // prise en main discrète, même si le signal a été posté avant le flag.
            if ($submarine && $signal->kind === self::KIND_REMOTE_CONTROL) {
                continue;
            }
            $alerts[] = $signal->toAlert();
        }

        return new OverlayPayload(
            schema: (string) config('overlay.schema', 'se5.wallpaper-overlay/v1'),
            // UTC explicite : cohérence avec expires_at (review finding M).
            generatedAt: Carbon::now('UTC')->toIso8601String(),
            ttlSeconds: (int) config('overlay.ttl_seconds', 60),
            identity: [
                'fullname' => $ctx->userFullname !== '' ? $ctx->userFullname : $ctx->userLogin,
                'login' => $ctx->userLogin,
                'is_admin' => $ctx->userIsAdmin,
                'main_type' => $ctx->mainUserType,
            ],
            machine: [
                'name' => $ctx->machineName,
                'room' => $ctx->salleName,
                'os' => $ctx->os,
            ],
            alerts: array_values($alerts),
        );
    }

    /**
     * Poste un signal dans le canal push→pull.
     *
     * @return OverlaySignal|null  null si filtré par le garde-fou submarine.
     */
    public function postSignal(
        string $kind,
        string $severity,
        string $title,
        string $text,
        ?string $workstationUuid = null,
        ?int $workstationGroupId = null,
        ?string $userLogin = null,
        ?\DateTimeInterface $expiresAt = null,
    ): ?OverlaySignal {
        if ($kind === self::KIND_REMOTE_CONTROL && (bool) config('sambaedu.veyon_submarine', false)) {
            return null;
        }

        // Validation défensive (review finding K) : sévérité bornée à
        // l'allowlist (fallback info), kind borné, title/text aplatis + clampés.
        $severity = in_array($severity, self::SEVERITIES, true)
            ? $severity
            : OverlayAlert::SEVERITY_INFO;

        // Kind réservé reclassé (review 24.4 #2) : le message reste visible
        // comme alerte au lieu de disparaître en silence côté poste.
        if ($kind === self::KIND_RESERVED_IDENTITY) {
            $kind = 'notice';
        }

        return OverlaySignal::create([
            'kind' => mb_substr($kind, 0, 32),
            'severity' => $severity,
            'title' => $this->sanitizeText($title, 255),
            'text' => $this->sanitizeText($text, 2000),
            // Normalise '' → null (review finding F) : un joker explicite, jamais
            // une chaîne vide qui fuiterait vers les polls sans session.
            'workstation_uuid' => ($workstationUuid ?? '') !== '' ? $workstationUuid : null,
            'workstation_group_id' => $workstationGroupId,
            'user_login' => ($userLogin ?? '') !== '' ? $userLogin : null,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Aplati retours-ligne / espaces multiples puis clampe la longueur.
     * Protège le parsing regex Rainmeter et le rendu mono-ligne Conky
     * (review findings C / F-04 / K).
     */
    private function sanitizeText(string $value, int $max): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_substr($value, 0, $max);
    }
}
