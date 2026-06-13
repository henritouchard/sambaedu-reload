<?php

declare(strict_types=1);

namespace App\Services\Agent\Enrollment;

use App\Models\SystemSetting;
use Illuminate\Support\Carbon;

/**
 * Story 25.3 — Mode campagne d'enrôlement porte 2 (décision n° 6, FR16).
 *
 * Réglage admin BORNÉ DANS LE TEMPS et DÉSACTIVABLE, stocké dans
 * `system_settings` (pattern K/V existant) plutôt qu'en `.env` figé : l'admin
 * l'active/désactive depuis l'UI sans déploiement (pas de `config:cache`).
 *
 * La campagne est active ssi une échéance `agent_enroll_campaign_until` est
 * persistée ET dans le futur. Une borne dépassée = retour au manuel PAR
 * CONSTRUCTION (vérifié à chaque `redeem()`, aucune tâche planifiée requise
 * pour la sécurité — piège n° 9, AC3).
 *
 * Même campagne active, l'auto-approbation reste conditionnée à la concordance
 * du faisceau avec un poste connu non enrôlé (anti-usurpation jamais débrayé —
 * c'est {@see EnrollmentMatchService::isConcordant()} + candidat unique qui
 * décide, pas cette classe).
 */
class EnrollmentCampaign
{
    public const SETTING_UNTIL = 'agent_enroll_campaign_until';

    /**
     * Vrai si une campagne d'auto-approbation est active à l'instant présent.
     */
    public function isActive(): bool
    {
        $until = $this->until();

        return $until !== null && $until->isFuture();
    }

    /**
     * Échéance courante de la campagne (null si jamais activée ou réglage vide).
     */
    public function until(): ?Carbon
    {
        $raw = SystemSetting::get(self::SETTING_UNTIL);

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            // Réglage corrompu = pas de campagne (fail-safe vers le manuel).
            return null;
        }
    }

    /**
     * Active la campagne jusqu'à l'échéance donnée (écrit `system_settings`).
     */
    public function enableUntil(Carbon $until): void
    {
        SystemSetting::set(self::SETTING_UNTIL, $until->toIso8601String());
    }

    /**
     * Désactive immédiatement la campagne (retour au manuel).
     */
    public function disable(): void
    {
        SystemSetting::forget(self::SETTING_UNTIL);
    }
}
