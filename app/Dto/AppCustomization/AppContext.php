<?php

declare(strict_types=1);

namespace App\Dto\AppCustomization;

/**
 * DTO immuable représentant le contexte runtime d'un AppCustomization à résoudre.
 *
 * Hydraté depuis le dict APCu `apps.$id` posé par le legacy
 * `applications.inc.php::get_apps()` (TTL 1800s) — seule source de vérité
 * pour l'état courant (user connecté, machine, salle, groupes AD, OS).
 *
 * Story 4.8 — AC 9.
 *
 * Structure similaire à `Wallpaper\WallpaperContext` — factorisable avec un
 * repository commun (voir vigilance 5 story 4.8).
 */
final readonly class AppContext
{
    /**
     * @param  string  $userLogin  sam/login AD de l'utilisateur connecté.
     * @param  string  $machineName  nom NetBIOS de la machine.
     * @param  string  $salleName  nom de la salle (première parent physique) — peut être vide.
     * @param  string[]  $groupsUser  liste des groupes AD de l'utilisateur (`list_u`).
     * @param  string|null  $mainUserType  premier match parmi `['Profs','Eleves','Administratifs']`.
     * @param  string  $os  « linux » / « windows ».
     * @param  int  $timestamp  timestamp APCu (debug / expiration soft).
     * @param  array<string,mixed>  $raw  dict APCu complet.
     */
    public function __construct(
        public string $userLogin,
        public string $machineName,
        public string $salleName,
        public array $groupsUser,
        public ?string $mainUserType,
        public string $os,
        public int $timestamp,
        public array $raw = [],
    ) {}

    /**
     * Hydrate depuis le dict APCu legacy.
     *
     * @param  array<string,mixed>  $apcu
     */
    public static function fromApcuArray(array $apcu): self
    {
        /** @var string[] $groupsUser */
        $groupsUser = array_values(array_filter(
            (array) ($apcu['list_u'] ?? []),
            static fn($v): bool => is_string($v) && $v !== '',
        ));

        $mainTypes = ['Profs', 'Eleves', 'Administratifs'];
        $mainUserType = null;
        foreach ($mainTypes as $candidate) {
            if (in_array($candidate, $groupsUser, true)) {
                $mainUserType = $candidate;
                break;
            }
        }

        // user/machine sont normalement des arrays LDAP. Fallback string pour
        // la compat tests + contextes synthétiques.
        $userLogin = is_array($apcu['user'] ?? null)
            ? (string) ($apcu['user']['cn'] ?? '')
            : (string) ($apcu['user'] ?? '');

        $machineName = is_array($apcu['machine'] ?? null)
            ? (string) ($apcu['machine']['cn'] ?? '')
            : (string) ($apcu['machine'] ?? '');

        return new self(
            userLogin: $userLogin,
            machineName: $machineName,
            salleName: (string) ($apcu['salle'] ?? ''),
            groupsUser: $groupsUser,
            mainUserType: $mainUserType,
            os: (string) ($apcu['os'] ?? 'linux'),
            timestamp: (int) ($apcu['time'] ?? time()),
            raw: $apcu,
        );
    }
}
