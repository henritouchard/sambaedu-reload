<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Story 15.4 / AC5 — Service de gestion des overrides options `.ini` per-poste.
 *
 * Ne stocke que les overrides (parité legacy `poste_maintenance_options.php`).
 * Toute valeur retombée à `false` (défaut) entraîne la suppression de la ligne BDD.
 *
 * Dispatch `WorkstationOptionsChanged` post-commit → listener
 * `RegenerateWorkstationIniOnOptionsChanged` régénère le `<hostname>.ini`
 * via `WorkstationIniGenerator` + `AtomicFileWriter` (15.1/15.2).
 */
final class WorkstationOptionsService
{
    /**
     * Met à jour 1+ option `.ini` du poste. Validation stricte : option_key
     * doit être dans `LEGACY_OPTIONS`, option_value doit être 'true' ou 'false'.
     *
     * Comportement : si la valeur cible est 'false' (défaut legacy), la ligne BDD
     * est supprimée — le generator applique le défaut. Sinon updateOrCreate.
     *
     * @param  array<string,bool|string>  $changes  ['debug' => true, 'force' => 'false', ...]
     * @return list<string>  Keys effectivement modifiées (= keys dispatched dans l'event).
     */
    public function update(int $workstationId, array $changes): array
    {
        if ($changes === []) {
            return [];
        }

        $workstation = Workstation::find($workstationId);
        if (! $workstation) {
            throw new InvalidArgumentException("Workstation #{$workstationId} introuvable.");
        }

        $allowedKeys = $this->allowedKeys();
        $changedKeys = [];

        DB::transaction(function () use ($workstationId, $changes, $allowedKeys, &$changedKeys) {
            foreach ($changes as $key => $value) {
                $key = (string) $key;
                $value = $this->normalizeValue($value);

                if (! in_array($key, $allowedKeys, true)) {
                    throw new InvalidArgumentException("Option `{$key}` inconnue.");
                }

                if ($value === 'false') {
                    // Retour au défaut → on supprime l'override pour rester
                    // cohérent avec le contrat legacy (ne stocker que les
                    // overrides effectifs).
                    $deleted = WpkgWorkstationOption::query()
                        ->where('workstation_id', $workstationId)
                        ->where('option_key', $key)
                        ->delete();

                    if ($deleted > 0) {
                        $changedKeys[] = $key;
                    }

                    continue;
                }

                WpkgWorkstationOption::updateOrCreate(
                    ['workstation_id' => $workstationId, 'option_key' => $key],
                    ['option_value' => $value],
                );
                $changedKeys[] = $key;
            }
        });

        $changedKeys = array_values(array_unique($changedKeys));

        Log::channel('wpkg-deploy')->info('[WorkstationOptionsService] Options mises à jour', [
            'workstation_id' => $workstationId,
            'changed_keys' => $changedKeys,
        ]);

        if ($changedKeys !== []) {
            event(new WorkstationOptionsChanged($workstationId, $changedKeys));
        }

        return $changedKeys;
    }

    /**
     * Réinitialise toutes les options du poste (suppression de tous les overrides).
     * L'event embarque l'ensemble des keys legacy → le generator regénère le
     * `.ini` au défaut.
     *
     * @return list<string>  Keys de l'event (= toutes les options legacy).
     */
    public function resetToDefaults(int $workstationId): array
    {
        $workstation = Workstation::find($workstationId);
        if (! $workstation) {
            throw new InvalidArgumentException("Workstation #{$workstationId} introuvable.");
        }

        $deleted = DB::transaction(
            fn () => WpkgWorkstationOption::query()
                ->where('workstation_id', $workstationId)
                ->delete()
        );

        $allKeys = $this->allowedKeys();

        Log::channel('wpkg-deploy')->info('[WorkstationOptionsService] Options réinitialisées', [
            'workstation_id' => $workstationId,
            'deleted_count' => $deleted,
        ]);

        if ($deleted > 0) {
            event(new WorkstationOptionsChanged($workstationId, $allKeys));
        }

        return $allKeys;
    }

    /**
     * @return list<string>
     */
    private function allowedKeys(): array
    {
        return array_map(
            fn (array $opt): string => $opt['name'],
            WorkstationIniGenerator::LEGACY_OPTIONS,
        );
    }

    private function normalizeValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === 1 || $value === '1') {
            return 'true';
        }
        if ($value === 0 || $value === '0' || $value === null) {
            return 'false';
        }

        $s = is_string($value) ? strtolower(trim($value)) : '';
        if ($s === 'true') {
            return 'true';
        }
        if ($s === 'false') {
            return 'false';
        }

        throw new InvalidArgumentException("Valeur invalide pour option WPKG : ".var_export($value, true));
    }
}
