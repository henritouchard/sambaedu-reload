<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\FsAclAuthoringException;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Services\Agent\Providers\FsAclAuthoringGuard;

/**
 * Story 36.1 (corr. review #2b) — rend le garde-fou d'authoring `fs_acl` RÉEL
 * au runtime serveur : sans cet observer, {@see FsAclAuthoringGuard::violations()}
 * n'avait AUCUN appelant hors tests, et la décision Q2 (deny descendant sur
 * racine protégée interdit, deny sur principal système, nom court 8.3, deny sans
 * warning, enums/chemins invalides) restait inopérante en production.
 *
 * **Portée stricte `fs_acl`.** L'observer n'agit QUE sur les projections dont le
 * `mechanism` est `fs_acl` (les autres mécanismes — registry, registry_list,
 * firewall… — ne sont PAS concernés et retournent immédiatement).
 *
 * **Événement `saving`** (couvre create ET update) : le spec est validé AVANT
 * écriture ; une violation lève {@see FsAclAuthoringException} (l'INSERT/UPDATE
 * est annulé). Ce garde-fou protège aussi le futur formulaire 36.4 (toute
 * création de projection fs_acl par Eloquent passe par ici).
 *
 * **Le seed `program_files_browse_denied` passe** : il est propre (validé) ET
 * écrit via `DB::table()->updateOrInsert()` (Query Builder — n'émet pas
 * d'événement Eloquent), donc l'observer n'est de toute façon pas déclenché par
 * la migration.
 */
class CapabilityProjectionObserver
{
    public function saving(CapabilityProjection $projection): void
    {
        if ($projection->mechanism !== CapabilityProjection::MECHANISM_FS_ACL) {
            return;
        }

        $capability = Capability::find($projection->capability_id);

        $violations = (new FsAclAuthoringGuard())->violations([[
            'capability' => $capability->key ?? (string) $projection->capability_id,
            'warning' => $capability?->warning,
            'spec' => $projection->spec,
        ]]);

        if ($violations !== []) {
            throw new FsAclAuthoringException($violations);
        }
    }
}
