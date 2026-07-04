<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\FirewallAuthoringException;
use App\Exceptions\FsAclAuthoringException;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Services\Agent\Providers\FirewallAuthoringGuard;
use App\Services\Agent\Providers\FsAclAuthoringGuard;

/**
 * Rend les garde-fous d'authoring des mécanismes HORS-REGISTRE RÉELS au runtime
 * serveur : sans cet observer, les guards n'auraient AUCUN appelant hors tests,
 * et les décisions Henri (Q2 fs_acl, Q3 firewall) resteraient inopérantes en
 * production (leçon review 36.1 #2b — pas de guard « testé mais inopérant »).
 *
 * **Dispatch PAR MÉCANISME (Story 36.2).** L'observer route la projection vers
 * le guard de SON mécanisme (`fs_acl` → {@see FsAclAuthoringGuard} ; `firewall` →
 * {@see FirewallAuthoringGuard}) ; les autres mécanismes (`registry`,
 * `registry_list`, `localgroup`…) ne sont PAS concernés et retournent
 * immédiatement. Le comportement `fs_acl` de la Story 36.1 est INCHANGÉ.
 *
 * **Événement `saving`** (couvre create ET update) : le spec est validé AVANT
 * écriture ; une violation lève l'exception du mécanisme (l'INSERT/UPDATE est
 * annulé). Protège aussi le futur formulaire 36.4 (toute création de projection
 * par Eloquent passe par ici).
 *
 * **Les seeds passent** : ils sont propres (validés) ET écrits via
 * `DB::table()->updateOrInsert()` (Query Builder — n'émet pas d'événement
 * Eloquent), donc l'observer n'est de toute façon pas déclenché par les
 * migrations.
 */
class CapabilityProjectionObserver
{
    public function saving(CapabilityProjection $projection): void
    {
        match ($projection->mechanism) {
            CapabilityProjection::MECHANISM_FS_ACL => $this->guardFsAcl($projection),
            CapabilityProjection::MECHANISM_FIREWALL => $this->guardFirewall($projection),
            default => null, // mécanisme non concerné.
        };
    }

    private function guardFsAcl(CapabilityProjection $projection): void
    {
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

    private function guardFirewall(CapabilityProjection $projection): void
    {
        $capability = Capability::find($projection->capability_id);

        $violations = (new FirewallAuthoringGuard())->violations([[
            'capability' => $capability->key ?? (string) $projection->capability_id,
            'warning' => $capability?->warning,
            'spec' => $projection->spec,
        ]]);

        if ($violations !== []) {
            throw new FirewallAuthoringException($violations);
        }
    }
}
