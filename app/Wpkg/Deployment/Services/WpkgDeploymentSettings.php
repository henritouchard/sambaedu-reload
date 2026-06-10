<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Models\SystemSetting;
use App\Wpkg\Deployment\Rules\SafeIpCidrRule;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.6 — Résolveur centralisé des réglages de déploiement WPKG.
 *
 * Précédence stricte : **DB (`SystemSetting`) > env (`config()`) > défaut codé**.
 *
 * Clés `SystemSetting` gérées :
 *   - `wpkg.winget_enabled` (bool) — gate du canal winget
 *   - `wpkg.allowed_ips`    (array<string>) — allowlist IP/CIDR additionnelle
 *
 * **Non-régression** : si aucune clé DB n'est définie, les méthodes retournent
 * exactement les valeurs `config()` actuelles (comportement strictement
 * inchangé par rapport à la situation pré-15.6).
 *
 * **Pas de cache en v1** : `SystemSetting::get` lit la DB à chaque appel
 * (effet immédiat garanti). Si le profilage montrait un coût, ajouter un
 * cache request-scoped invalidé à l'écriture (cf. D6 story 15.6).
 *
 * @see app/Wpkg/Deployment/README.md — section « Réglages runtime (Story 15.6) »
 */
final class WpkgDeploymentSettings
{
    /**
     * Retourne l'état d'activation du canal winget.
     *
     * Précédence :
     *   1. `SystemSetting::get('wpkg.winget_enabled')` si la clé est en BDD.
     *   2. `config('sambaedu.wpkg.winget_enabled')` (env `WPKG_WINGET_ENABLED`).
     *   3. `false` (défaut fail-closed).
     */
    public function wingetEnabled(): bool
    {
        return (bool) SystemSetting::get('wpkg.winget_enabled', config('sambaedu.wpkg.winget_enabled', false));
    }

    /**
     * Retourne la liste des IP/CIDR additionnels autorisés (hors localhost en dur).
     *
     * Précédence :
     *   1. `SystemSetting::get('wpkg.allowed_ips')` (array) si la clé est en BDD.
     *   2. `config('sambaedu.wpkg.report_ingestion_allowed_ips')` (env `WPKG_ALLOWED_IPS`).
     *   3. `[]` (défaut vide — localhost reste toujours autorisé en dur dans
     *      `EnsureLocalRequest::ALWAYS_ALLOWED`, indépendamment de cette liste).
     *
     * Entrées vides ou non-string filtrées automatiquement.
     *
     * **Fail-closed (Story 15.6 / correction post-review)** : chaque entrée est
     * repassée par `SafeIpCidrRule::isSafe()` en lecture. Toute entrée invalide ou
     * trop large (ex. `0.0.0.0/0` injecté directement en DB) est silencieusement
     * écartée avec un warning loggé. `127.0.0.1` et `::1` sont préservés s'ils
     * figurent dans la liste (ils restent aussi en dur dans `EnsureLocalRequest::ALWAYS_ALLOWED`).
     *
     * @return array<int, string>
     */
    public function allowedIps(): array
    {
        $default = config('sambaedu.wpkg.report_ingestion_allowed_ips', []);

        // Normalise le défaut config : peut être une string CSV (legacy) ou un tableau.
        if (is_string($default)) {
            $default = array_filter(array_map('trim', explode(',', $default)));
        }
        if (! is_array($default)) {
            $default = [];
        }

        $value = SystemSetting::get('wpkg.allowed_ips', $default);

        $raw = is_array($value)
            ? array_filter($value, static fn ($e) => is_string($e) && $e !== '')
            : array_filter($default, static fn ($e) => is_string($e) && $e !== '');

        // Localhost est toujours sûr (jamais rejeté par SafeIpCidrRule, mais protégé explicitement).
        $alwaysSafe = ['127.0.0.1', '::1'];

        $result = [];
        foreach ($raw as $entry) {
            if (in_array($entry, $alwaysSafe, true) || SafeIpCidrRule::isSafe($entry)) {
                $result[] = $entry;
            } else {
                Log::channel('wpkg-deploy')->warning('[WpkgDeploymentSettings] entrée IP rejetée (fail-closed)', [
                    'event' => 'wpkg_allowed_ip_rejected',
                    'entry' => $entry,
                ]);
            }
        }

        return array_values($result);
    }
}
