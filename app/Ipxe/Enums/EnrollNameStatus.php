<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.3 — T1.4.
 *
 * Statuts retournés par {@see \App\Ipxe\Services\WorkstationEnrollmentService::enrollName()}.
 *
 * 6 cases stricts couvrant les 4 cas fonctionnels iso-legacy
 * (`enregistrement.php:23-148`) + 2 cas d'erreur infrastructure :
 *
 *  - `CREATED`     : poste nouveau — Workstation::create + AD samba-tool computer create.
 *  - `RENAMED`     : poste existant — Workstation::save (nouveau nom) + AD rename via
 *                    {@see \App\Ldap\AdMachineManager::renameComputer()} (D14).
 *  - `SAME_NAME`   : poste existant avec le même nom déjà enregistré (idempotent).
 *  - `NAME_TAKEN`  : nouveau nom déjà occupé par un AUTRE poste.
 *  - `DB_ERROR`    : exception Eloquent durant create/save (transitoire).
 *  - `AD_ERROR`    : exception ou exit code non-zero sur samba-tool.
 *
 * Le template Blade `ipxe.enrollment.name` switch sur ces cases pour rendre
 * le bon `echo` + chain (iso pattern Blade `IpxeAdminAction::template()`).
 */
enum EnrollNameStatus: string
{
    case Created = 'created';
    case Renamed = 'renamed';
    case SameName = 'same_name';
    case NameTaken = 'name_taken';
    case DbError = 'db_error';
    case AdError = 'ad_error';

    /**
     * `true` si le statut correspond à un cas où le poste a été persisté
     * en DB (CREATED ou RENAMED) — utile pour conditionner l'audit
     * `MachineBootLog` côté template/controller.
     */
    public function isPersisted(): bool
    {
        return match ($this) {
            self::Created, self::Renamed => true,
            default => false,
        };
    }

    /**
     * `true` si le statut indique une erreur (échec à propager à
     * l'utilisateur via `echo ERREUR !` côté Blade).
     */
    public function isError(): bool
    {
        return match ($this) {
            self::NameTaken, self::DbError, self::AdError => true,
            default => false,
        };
    }
}
