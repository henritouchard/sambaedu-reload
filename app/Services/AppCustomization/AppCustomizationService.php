<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Enums\AppKind;
use App\Models\AppCustomization;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Services\AppCustomization\Contracts\AppPolicyAdapter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service principal — résolution hiérarchique + persistence + export FS.
 *
 * Story 4.8 — AC 4, 12, 13.
 *
 * Chaîne de résolution (priorité croissante, dernier match gagne) :
 *   1. Template système (FS via adapter::getTemplate)
 *   2. Auto : injection proxy/DNS/popups (in-memory via adapter::applyAuto)
 *   3. Default étab (AppCustomization scope NULL/NULL is_default=true)
 *   4. WorkstationGroup (salle)
 *   5. UserGroups (AD, merge dans l'ordre)
 *   6. User (écrase tous les niveaux précédents)
 *
 * Cible performance : ≤ 4 queries DB par résolution (template/auto = 0, default=1,
 * WG=1, userGroups whereIn=1, user=1).
 */
class AppCustomizationService
{
    public function __construct(
        private readonly AppPolicyRegistry $registry,
    ) {}

    /**
     * Résolution complète pour un contexte machine donné.
     *
     * @return array<string,mixed>
     */
    public function resolvePoliciesForMachine(
        ?WorkstationGroup $wg,
        ?User $user,
        AppKind $kind,
        string $os,
    ): array {
        $adapter = $this->registry->resolve($kind);
        $systemConfig = $this->systemConfig($os);

        // Level 1 : template
        $policies = $adapter->getTemplate();

        // Level 2 : auto (proxy, DNS, popups)
        $policies = $adapter->applyAuto($policies, $systemConfig);

        // Level 3 : default étab — 1 query DB
        $default = AppCustomization::ofKind($kind)->defaults()->first();
        if ($default !== null) {
            $policies = $adapter->mergeOverrides($policies, (array) $default->policies_json);
        }

        // Level 4 : WorkstationGroup — 1 query DB
        if ($wg !== null) {
            $wgOverride = AppCustomization::query()
                ->ofKind($kind)
                ->where('customizable_type', WorkstationGroup::class)
                ->where('customizable_id', $wg->getKey())
                ->first();
            if ($wgOverride !== null) {
                $policies = $adapter->mergeOverrides($policies, (array) $wgOverride->policies_json);
            }
        }

        // Level 5 : UserGroups (AD) — 1 query DB whereIn
        if ($user !== null) {
            $userGroupIds = $user->userGroups()->pluck('user_groups.id')->all();
            if ($userGroupIds !== []) {
                $groupOverrides = AppCustomization::query()
                    ->ofKind($kind)
                    ->where('customizable_type', UserGroup::class)
                    ->whereIn('customizable_id', $userGroupIds)
                    ->orderBy('customizable_id') // merge déterministe
                    ->get();
                foreach ($groupOverrides as $override) {
                    $policies = $adapter->mergeOverrides($policies, (array) $override->policies_json);
                }
            }

            // Level 6 : User — 1 query DB
            $userOverride = AppCustomization::query()
                ->ofKind($kind)
                ->where('customizable_type', User::class)
                ->where('customizable_id', $user->getKey())
                ->first();
            if ($userOverride !== null) {
                $policies = $adapter->mergeOverrides($policies, (array) $userOverride->policies_json);
            }
        }

        return $policies;
    }

    /**
     * Persiste une personnalisation — updateOrCreate sur la clé composite.
     *
     * @param  array<string,mixed>  $policies  format `['policies' => [...]]`
     *
     * @throws \InvalidArgumentException  si les policies échouent la validation.
     */
    public function savePolicies(
        AppKind $kind,
        ?Model $scope,
        array $policies,
        ?User $author = null,
    ): AppCustomization {
        $adapter = $this->registry->resolve($kind);

        // Strip clés non-whitelistées avant validation (évite qu'un champ
        // illégal déclenche une erreur inutilement — seules les clés
        // whitelist sont persistées).
        $sanitized = $adapter->stripNonWhitelistedOverrides($policies);

        $errors = $adapter->validatePolicies($sanitized);
        if ($errors !== []) {
            throw new \InvalidArgumentException(
                'Validation échouée : ' . implode('; ', $errors),
            );
        }

        return DB::transaction(function () use ($kind, $scope, $sanitized, $author, $adapter) {
            $authorId = $author?->getKey();

            $matchCriteria = [
                'app_kind' => $kind->value,
                'customizable_type' => $scope?->getMorphClass(),
                'customizable_id' => $scope?->getKey(),
            ];

            if ($scope === null) {
                $matchCriteria['is_default'] = true;
            }

            /** @var AppCustomization $customization */
            $customization = AppCustomization::updateOrCreate(
                $matchCriteria,
                [
                    'policies_json' => $sanitized,
                    'is_default' => $scope === null,
                    'updated_by' => $authorId,
                ],
            );

            if ($customization->wasRecentlyCreated && $authorId !== null) {
                $customization->created_by = $authorId;
                $customization->save();
            }

            // Export FS on save (si activé en config)
            if ((bool) config('app-customizations.export_fs_on_save', true)) {
                $this->exportOneToFs($customization, $adapter);
            }

            return $customization;
        });
    }

    public function deleteCustomization(AppKind $kind, ?Model $scope): bool
    {
        $query = AppCustomization::query()
            ->ofKind($kind);

        if ($scope === null) {
            $query->whereNull('customizable_type')
                ->whereNull('customizable_id')
                ->where('is_default', true);
        } else {
            $query->where('customizable_type', $scope->getMorphClass())
                ->where('customizable_id', $scope->getKey());
        }

        return $query->delete() > 0;
    }

    /**
     * Export FS d'une seule customization vers `/etc/sambaedu/applications/{kind}/<key>.json`.
     */
    public function exportOneToFs(AppCustomization $customization, ?AppPolicyAdapter $adapter = null): bool
    {
        $kind = $customization->app_kind instanceof AppKind
            ? $customization->app_kind
            : AppKind::from((string) $customization->app_kind);

        $adapter = $adapter ?? $this->registry->resolve($kind);
        $path = $this->fsPathFor($customization, $kind);

        return $adapter->exportToFs((array) $customization->policies_json, $path);
    }

    /**
     * Export FS de toutes les customizations d'un AppKind.
     * Retourne le nombre de fichiers écrits avec succès.
     */
    public function exportAllToFs(AppKind $kind): int
    {
        $adapter = $this->registry->resolve($kind);
        $count = 0;

        AppCustomization::query()
            ->ofKind($kind)
            ->with('customizable')
            ->chunk(100, function ($rows) use ($adapter, $kind, &$count) {
                foreach ($rows as $customization) {
                    $path = $this->fsPathFor($customization, $kind);
                    if ($adapter->exportToFs((array) $customization->policies_json, $path)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Détermine le chemin FS pour une customization donnée.
     */
    private function fsPathFor(AppCustomization $customization, AppKind $kind): string
    {
        $base = rtrim((string) config('app-customizations.fs_base_path', '/etc/sambaedu/applications'), '/');
        $key = $this->keyFor($customization);
        return $base . '/' . $kind->alias() . '/' . $key . '.json';
    }

    private function keyFor(AppCustomization $customization): string
    {
        if ($customization->customizable_id === null) {
            return 'default';
        }

        $owner = $customization->customizable;
        if ($owner === null) {
            Log::warning('[AppCustomizationService] owner orphelin', [
                'id' => $customization->id,
                'customizable_type' => $customization->customizable_type,
                'customizable_id' => $customization->customizable_id,
            ]);
            return 'orphan-' . $customization->customizable_id;
        }

        $key = match (true) {
            $owner instanceof User => (string) ($owner->login ?? $owner->getKey()),
            default => (string) ($owner->getAttribute('name') ?? $owner->getKey()),
        };

        // Sanitize : les noms peuvent contenir des caractères de control
        return preg_replace('/[^\p{L}\p{N}_\-.]+/u', '_', $key) ?: (string) $customization->customizable_id;
    }

    /**
     * Config système utilisée par `applyAuto` — fusion config + contexte (OS).
     *
     * @return array<string,mixed>
     */
    private function systemConfig(string $os): array
    {
        return array_merge(
            (array) config('app-customizations.system_config', []),
            ['os' => $os],
        );
    }
}
