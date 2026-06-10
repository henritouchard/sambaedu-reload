<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Story 15.6 — Règle de validation d'une entrée IP/CIDR pour l'allowlist WPKG.
 *
 * L'allowlist IP est la frontière de sécurité primaire (endpoints non authentifiés,
 * auth iso-legacy). Cette règle applique une validation stricte :
 *
 *   - L'entrée doit être une IP valide (v4 ou v6) ou un CIDR valide.
 *   - **Rejet dur** de `0.0.0.0/0` et `::/0` (ouvrir à tout Internet).
 *   - **Rejet** des préfixes trop larges :
 *       IPv4 : préfixe `/N` avec N < 16 (ex. `10.0.0.0/8` rejeté)
 *       IPv6 : préfixe `/N` avec N < 32 (ex. `2001::/16` rejeté)
 *   - Syntaxe invalide → rejet.
 *
 * @see D3 story 15.6
 */
final class SafeIpCidrRule implements ValidationRule
{
    /** Préfixe minimum autorisé pour IPv4 (inclus). */
    public const MIN_PREFIX_V4 = 16;

    /** Préfixe minimum autorisé pour IPv6 (inclus). */
    public const MIN_PREFIX_V6 = 32;

    /** @var list<string> Adresses universellement rejetées (toute la plage internet). */
    private const DENY_ALL = ['0.0.0.0/0', '::/0'];

    /**
     * Vérifie statiquement qu'une entrée IP/CIDR est sûre (réutilisable sans instancier la règle complète).
     *
     * Retourne true si l'entrée est valide selon les critères de SafeIpCidrRule, false sinon.
     * Utilisé par WpkgDeploymentSettings::allowedIps() pour filtrer les entrées suspectes en lecture.
     */
    public static function isSafe(string $entry): bool
    {
        $rule = new self();
        $failed = false;
        $rule->validate('ip', $entry, static function () use (&$failed): void {
            $failed = true;
        });
        return ! $failed;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('L\'entrée doit être une adresse IP ou un CIDR valide.');
            return;
        }

        $entry = trim($value);

        // Rejet dur des wildcards Internet.
        if (in_array($entry, self::DENY_ALL, true)) {
            $fail("L'entrée \":input\" ouvre l'accès à tout Internet — saisie non autorisée.");
            return;
        }

        // Décompose en IP et préfixe éventuel.
        if (str_contains($entry, '/')) {
            [$ip, $prefix] = explode('/', $entry, 2);
            $prefix = (int) $prefix;

            // Validation syntaxique : l'IP doit être valide et le préfixe numérique.
            if (! filter_var($ip, FILTER_VALIDATE_IP) || (string) $prefix !== explode('/', $entry, 2)[1]) {
                $fail("\":input\" n'est pas un CIDR valide (adresse ou masque incorrect).");
                return;
            }

            $isV6 = str_contains($ip, ':');

            if ($isV6) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $fail("\":input\" n'est pas un CIDR IPv6 valide.");
                    return;
                }
                if ($prefix < 0 || $prefix > 128) {
                    $fail("Le préfixe CIDR IPv6 doit être compris entre 0 et 128 (reçu :input).");
                    return;
                }
                if ($prefix < self::MIN_PREFIX_V6) {
                    $fail(sprintf(
                        "Le préfixe CIDR IPv6 /%d est trop large (minimum /%d) — garde-fou de sécurité.",
                        $prefix,
                        self::MIN_PREFIX_V6,
                    ));
                    return;
                }
            } else {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $fail("\":input\" n'est pas un CIDR IPv4 valide.");
                    return;
                }
                if ($prefix < 0 || $prefix > 32) {
                    $fail("Le préfixe CIDR IPv4 doit être compris entre 0 et 32 (reçu :input).");
                    return;
                }
                if ($prefix < self::MIN_PREFIX_V4) {
                    $fail(sprintf(
                        "Le préfixe CIDR IPv4 /%d est trop large (minimum /%d) — garde-fou de sécurité.",
                        $prefix,
                        self::MIN_PREFIX_V4,
                    ));
                    return;
                }
            }

            // Validation finale : IpUtils confirme la syntaxe CIDR complète.
            if (! $this->isValidCidrViaIpUtils($ip, $prefix, $isV6)) {
                $fail("\":input\" n'est pas un CIDR valide.");
                return;
            }
        } else {
            // IP simple (pas un CIDR) : validation stricte.
            if (! filter_var($entry, FILTER_VALIDATE_IP)) {
                $fail("\":input\" n'est pas une adresse IP valide (format non reconnu).");
                return;
            }
        }
    }

    /**
     * Vérifie qu'un CIDR est syntaxiquement valide via IpUtils.
     * IpUtils::checkIp() accepte les CIDRs et retourne false sur les formats invalides.
     */
    private function isValidCidrViaIpUtils(string $ip, int $prefix, bool $isV6): bool
    {
        // Construire une IP test dans la plage pour valider l'acceptation IpUtils.
        $testIp = $isV6 ? '::1' : '127.0.0.1';
        try {
            // Si le CIDR est syntaxiquement valide, IpUtils peut l'évaluer sans lever.
            IpUtils::checkIp($testIp, "$ip/$prefix");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
