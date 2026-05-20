<?php

declare(strict_types=1);

namespace App\Dto\Gpo;

/**
 * DTO immuable représentant le contexte runtime reconstruit côté serveur
 * à partir du `workstation_uuid` JWT (claim `sub`) injecté par le middleware
 * `auth.v1.workstation` (Story 16.10) + des paramètres query non-sensibles
 * (os, user, userprofile) envoyés par le poste migré.
 *
 * Story 16.13 — AC4.2 (D3).
 *
 * Cette source remplace la lecture APCu legacy `apps.$id` (posée par
 * `gpo/applications.php`) pour les postes migrés qui n'utilisent plus la
 * chaîne legacy md5/APCu. Le contexte est désormais source-of-truth DB :
 *  - `workstation_uuid` ← claim JWT `sub`
 *  - `machineName` ← `Workstation::name`
 *  - `salleName` ← `WorkstationGroup` principal du poste (heuristique
 *    `is_physical=true` first, sinon premier groupe attaché)
 *  - `userLogin` ← query param `?user=...` (cross-checké via `User`)
 *  - `userId` ← `User::where('login', $userLogin)->first()->id` ou `null`
 *  - `os`, `userProfile` ← query params (parité legacy)
 *
 * Sécurité : `workstation_uuid` **doit** provenir EXCLUSIVEMENT du JWT
 * (jamais d'un input user-controlled). C'est garanti par
 * `WorkstationConfigContextResolver::resolve()` qui n'accepte le `uuid`
 * que comme paramètre passé explicitement par le controller (lui-même
 * tenu de lire `$request->attributes->get('auth_v1.workstation_uuid')`).
 *
 * @see \App\Gpo\Services\WorkstationConfigContextResolver
 */
final readonly class WorkstationConfigContext
{
    /**
     * @param  string  $workstationUuid  UUID du poste extrait du JWT (claim `sub`).
     * @param  string  $machineName  Nom NetBIOS du poste (= `Workstation::name`).
     * @param  string  $salleName  Nom du WorkstationGroup principal (vide si poste sans groupe physique).
     * @param  string  $userLogin  Login (samaccountname) du user courant — vide si non fourni en query.
     * @param  string  $os  « linux » | « windows ».
     * @param  string  $userProfile  Chemin profil utilisateur (Windows `%USERPROFILE%`). Vide en pratique.
     * @param  int|null  $machineId  `Workstation::id` (toujours non-null en succès).
     * @param  int|null  $userId  `User::id` si user trouvé en DB, sinon null.
     * @param  int|null  $groupId  `WorkstationGroup::id` du groupe principal, ou null si aucun.
     */
    public function __construct(
        public string $workstationUuid,
        public string $machineName,
        public string $salleName,
        public string $userLogin,
        public string $os,
        public string $userProfile,
        public ?int $machineId,
        public ?int $userId,
        public ?int $groupId,
    ) {}
}
