<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubEnforcementState;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Services\ControlHub\Data\ShortcutMaterializationResult;
use App\Services\Shortcuts\ShortcutIconAssetService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Matérialise en bibliothèque locale les raccourcis qu'impose le contrat amont.
 *
 * Un item `shortcuts` du contrat ne décrivait jusqu'ici qu'une clé et une cible :
 * il restait dans les tables du contrat sans jamais devenir un raccourci que
 * l'administrateur puisse voir, ni que l'agent puisse poser. Ce service comble ce
 * trou — il crée la ligne `shortcuts`, l'aligne sur le contrat à chaque réception,
 * et retire celles que le contrat ne demande plus.
 *
 * Le prune est borné par `shortcuts.controlhub_contract_key` : seules les lignes
 * que ce service a lui-même créées sont candidates. Les raccourcis locaux et ceux
 * du canal de tâches historique ({@see \App\Jobs\SyncShortcutJob}, marqués
 * `is_global`) ne sont jamais touchés.
 *
 * L'ICÔNE ne transite pas dans le payload : elle suit le canal des binaires imposés
 * (URL signée + sha256 vérifié serveur). Ce service ne fait que l'ADOPTER quand elle
 * est déjà sur disque ; c'est {@see ArtifactPullService} qui la tire, et qui recolle
 * `icon_asset` sur le raccourci quand le téléchargement aboutit après lui.
 *
 * L'ASSIGNATION (quels postes reçoivent le raccourci) ne vit pas ici : elle est
 * portée par {@see ContractAssignmentReconciler}, commune aux quatre types d'items.
 */
class ShortcutContractReconciler
{
    public function __construct(
        private readonly ShortcutIconAssetService $iconAssets,
    ) {
    }

    /**
     * Aligne la bibliothèque de raccourcis sur le contrat amont actif.
     *
     * Sans contrat actif, no-op total : la table `shortcuts` n'est pas même lue.
     */
    public function reconcile(): ShortcutMaterializationResult
    {
        $result = new ShortcutMaterializationResult();

        $contract = ControlHubContract::active();
        if ($contract === null) {
            return $result;
        }

        $items = $contract->items()
            ->where('type', Shortcut::TYPE_SHORTCUTS)
            ->where('enforcement_state', '!=', ControlHubEnforcementState::Absent->value)
            ->get();

        $keptKeys = [];

        foreach ($items as $item) {
            $key = (string) $item->key;

            try {
                if ($this->materialize($item, $result)) {
                    $keptKeys[] = $key;
                }
            } catch (Throwable $e) {
                $result->errors[] = "Raccourci imposé '{$key}': ".$e->getMessage();
                Log::error('[ShortcutContractReconciler] Échec de matérialisation d\'un raccourci imposé', [
                    'shortcut_key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->pruneRemovedShortcuts($keptKeys, $result);

        Log::info('[ShortcutContractReconciler] Matérialisation terminée', [
            'contract_id' => $contract->id,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * Crée ou aligne le raccourci d'un item. Retourne `false` si l'item n'était pas
     * matérialisable (aucune cible), auquel cas il ne protège aucune ligne du prune.
     */
    private function materialize(ControlHubContractItem $item, ShortcutMaterializationResult $result): bool
    {
        $spec = is_array($item->spec) ? $item->spec : [];
        $key = (string) $item->key;

        // La cible peut venir du `spec` (forme riche) ou du `value` de l'item (forme
        // courte des premiers contrats) ; sans l'une ni l'autre, il n'y a rien à poser.
        $target = $this->stringOrNull($spec['windows_link'] ?? null) ?? $this->stringOrNull($item->value);

        if ($target === null) {
            $result->skipped++;
            Log::warning('[ShortcutContractReconciler] Raccourci imposé sans cible — non matérialisé', [
                'shortcut_key' => $key,
            ]);

            return false;
        }

        $shortcut = Shortcut::query()->where('controlhub_contract_key', $key)->first();
        $isNew = $shortcut === null;

        if ($shortcut === null) {
            $shortcut = new Shortcut();
            $shortcut->controlhub_contract_key = $key;
            $shortcut->key = $this->availableLibraryKey($key);
        }

        $shortcut->name = $this->stringOrNull($spec['name'] ?? null) ?? $key;
        $shortcut->place = $this->stringOrNull($spec['place'] ?? null) ?? Shortcut::PLACE_DESKTOP;
        $shortcut->windows_link = $target;
        $shortcut->windows_args = $this->stringOrNull($spec['windows_args'] ?? null);
        $shortcut->windows_workdir = $this->stringOrNull($spec['windows_workdir'] ?? null);
        $shortcut->windows_path = $this->stringOrNull($spec['windows_path'] ?? null);
        $shortcut->linux_link = $this->stringOrNull($spec['linux_link'] ?? null);
        $shortcut->linux_args = $this->stringOrNull($spec['linux_args'] ?? null);
        $shortcut->linux_workdir = $this->stringOrNull($spec['linux_workdir'] ?? null);
        $shortcut->linux_path = $this->stringOrNull($spec['linux_path'] ?? null);
        $shortcut->linux_startupwmclass = $this->stringOrNull($spec['linux_startupwmclass'] ?? null);
        $shortcut->category = $this->stringOrNull($spec['category'] ?? null);
        $shortcut->description = $this->stringOrNull($spec['description'] ?? null);
        $shortcut->is_url = (bool) ($spec['is_url'] ?? $shortcut->looksLikeUrlShortcut());
        $shortcut->is_active = true;

        $this->adoptIconAlreadyOnDisk($shortcut, $item);

        if ($isNew) {
            $shortcut->save();
            $result->created++;

            return true;
        }

        if (! $shortcut->isDirty()) {
            $result->unchanged++;

            return true;
        }

        $shortcut->save();
        $result->updated++;

        return true;
    }

    /**
     * Recolle l'icône du contrat quand son `.ico` est déjà content-adressé sur disque.
     *
     * Le pull est asynchrone : à la première réception l'icône n'est pas encore là et
     * cette adoption ne fait rien — c'est le service de pull qui posera les colonnes.
     * Aux réceptions suivantes, en revanche, le fichier est présent et l'adoption
     * évite de re-télécharger ce que la bibliothèque contient déjà.
     */
    private function adoptIconAlreadyOnDisk(Shortcut $shortcut, ControlHubContractItem $item): void
    {
        $checksum = $this->stringOrNull($item->artifact_checksum);

        if ($checksum === null) {
            return;
        }

        $filename = $checksum.'.ico';

        if (! is_file($this->iconAssets->servedDir().DIRECTORY_SEPARATOR.$filename)) {
            return;
        }

        $shortcut->icon_asset = $filename;
        $shortcut->icon_checksum = $checksum;
    }

    /**
     * Supprime les raccourcis issus du contrat que le contrat ne demande plus.
     *
     * @param  array<int, string>  $keptKeys
     */
    private function pruneRemovedShortcuts(array $keptKeys, ShortcutMaterializationResult $result): void
    {
        $obsolete = Shortcut::query()
            ->whereNotNull('controlhub_contract_key')
            ->when($keptKeys !== [], fn ($q) => $q->whereNotIn('controlhub_contract_key', $keptKeys))
            ->get();

        foreach ($obsolete as $shortcut) {
            // Les assignations pivot partent avec le raccourci : sans ce détachement,
            // `shortcut_assignables` garderait des lignes pointant dans le vide.
            $shortcut->workstationGroups()->detach();
            $shortcut->delete();
            $result->removed++;
        }
    }

    /**
     * Clé de bibliothèque libre pour un nouveau raccourci.
     *
     * `shortcuts.key` est l'identifiant fonctionnel côté SE5 et peut déjà être pris
     * par un raccourci local homonyme ; on préfixe alors plutôt que d'écraser le
     * travail de l'administrateur.
     */
    private function availableLibraryKey(string $contractKey): string
    {
        if (! Shortcut::query()->where('key', $contractKey)->exists()) {
            return $contractKey;
        }

        return 'controlhub-'.$contractKey;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }
}
