<?php

declare(strict_types=1);

/**
 * Racines canoniques des partages SambaEdu gérés par SE5 (ACLs POSIX).
 *
 * NE PAS confondre avec `config/filesystems.php` (pluriel — disques Laravel
 * Storage). Ce fichier porte les racines métier consommées par
 * {@see App\Services\Filesystem\AclService::classesRoot()} (clé `classes_root`,
 * jusqu'ici résolue sur le fallback statique faute de fichier) et par
 * {@see App\Services\Filesystem\NetworkShareService::sharesRoot()} (clé
 * `shares_root`, Story 34.1 — répertoires réseau nommés).
 *
 * Convention iso `AclService::$classesRoot` : valeur statique par défaut,
 * surchargée ici (et donc par `.env` via un éventuel binding) pour le
 * multi-tenant ou les tests d'intégration.
 *
 * NOTE : on ne déclare VOLONTAIREMENT PAS `classes_root` ici. `AclService` /
 * `ShareService` le résolvent via `config('filesystem.classes_root', static)` ;
 * tant que la clé est ABSENTE, ils retombent sur leur propriété statique
 * (overridable en tests — `AclService::$classesRoot`). Déclarer `classes_root`
 * masquerait cet override et casserait `ShareServiceTest`/`AclServiceTest`.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Racine des RÉPERTOIRES RÉSEAU nommés (Story 34.1)
    |--------------------------------------------------------------------------
    | Racine dédiée des « lecteurs réseau gérés » : chaque `network_shares`
    | matérialise un sous-dossier `<directory_name>` ici. L'export SMB
    | `[partages]` → ce path est une tâche d'infra serveur (hors git, §[PROD]).
    */
    'shares_root' => env('SAMBAEDU_SHARES_ROOT', '/var/sambaedu/Partages'),

];
