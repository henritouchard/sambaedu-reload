<?php

declare(strict_types=1);

namespace App\Dto\Wallpaper;

/**
 * DTO immuable représentant le contexte runtime d'un wallpaper à composer.
 *
 * Hydraté depuis le dict APCu `apps.$id` posé par le legacy
 * `applications.inc.php::get_apps()` (TTL 1800s) — seule source de vérité
 * pour l'état courant (user connecté, machine, salle, groupes AD, etc.).
 *
 * Story 4.7 — AC 3.
 */
final readonly class WallpaperContext
{
    /**
     * @param  string  $userLogin  sam/login AD de l'utilisateur connecté.
     * @param  string  $userFullname  affichage « Prénom Nom ».
     * @param  bool  $userIsAdmin  flag admin local du poste (depuis APCu `admin`).
     * @param  string  $machineName  nom NetBIOS de la machine.
     * @param  string  $salleName  nom de la salle (première parent physique) — peut être vide.
     * @param  string[]  $groupsUser  liste des groupes AD de l'utilisateur (`list_u`).
     * @param  string|null  $mainUserType  premier match parmi `['Profs','Eleves','Administratifs']` dans `groupsUser`.
     * @param  string  $os  « linux » / « windows ».
     * @param  int  $timestamp  timestamp APCu (pour debug / expiration soft).
     * @param  array<string,mixed>  $raw  dict APCu complet (debug / future évolution).
     */
    public function __construct(
        public string $userLogin,
        public string $userFullname,
        public bool $userIsAdmin,
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

        $mainTypes = config('wallpapers.main_types', ['Profs', 'Eleves', 'Administratifs']);
        $mainUserType = null;
        foreach ($mainTypes as $candidate) {
            if (in_array($candidate, $groupsUser, true)) {
                $mainUserType = $candidate;
                break;
            }
        }

        // `user` et `machine` sont normalement des arrays LDAP (cf. legacy
        // `applications.inc.php::get_apps()` — `search_user` / `search_machine`).
        // On garde le fallback string pour rester défensif (tests + contextes
        // synthétiques), mais on extrait `cn`/`fullname` en priorité pour la
        // vraie structure runtime.
        $userLogin = is_array($apcu['user'] ?? null)
            ? (string) ($apcu['user']['cn'] ?? '')
            : (string) ($apcu['user'] ?? '');

        $userFullname = is_array($apcu['user'] ?? null)
            ? (string) ($apcu['user']['fullname'] ?? $apcu['user']['cn'] ?? '')
            : (string) ($apcu['fullname'] ?? ($apcu['cn'] ?? $apcu['user'] ?? ''));

        $machineName = is_array($apcu['machine'] ?? null)
            ? (string) ($apcu['machine']['cn'] ?? '')
            : (string) ($apcu['machine'] ?? '');

        return new self(
            userLogin: $userLogin,
            userFullname: $userFullname,
            userIsAdmin: (bool) ($apcu['admin'] ?? false),
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
