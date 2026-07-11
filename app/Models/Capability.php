<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Carbon;

/**
 * Epic 27 — Capacité : intention métier OS-agnostique donnée aux postes
 * (rewrite « capability-first » du registre, décision 2026-06-17).
 *
 * Source d'autorité de l'authoring (remplace l'ancien modèle `RegistrySetting`,
 * superseded en 27.12). Une
 * capacité porte son modèle de valeur (toggle/enum/scalar), son défaut diffusé,
 * ses métadonnées (warning, applicabilité OS) ; sa MATÉRIALISATION par OS/
 * mécanisme vit dans {@see CapabilityProjection}. Le `key`/`id` ne fuite JAMAIS
 * au payload du contrat (invariant central).
 *
 * @property int $id
 * @property string $key Clé technique unique — jamais émise au payload
 * @property string $label
 * @property string|null $description
 * @property string|null $category
 * @property string $value_type toggle | enum | scalar
 * @property array<int,array{value:string,label:string}>|null $options
 * @property string $default_value Valeur par défaut diffusée (Broadcast)
 * @property string|null $warning
 * @property list<string> $applies_to_os
 * @property bool $is_active
 * @property bool $overrides_locked
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Capability extends Model
{
    use HasFactory;

    protected $table = 'capabilities';

    public const VALUE_TYPE_TOGGLE = 'toggle';

    public const VALUE_TYPE_ENUM = 'enum';

    public const VALUE_TYPE_SCALAR = 'scalar';

    /** Valeur canonique d'un toggle « activé » (le défaut/override stocke ce texte). */
    public const TOGGLE_ON = 'on';

    public const TOGGLE_OFF = 'off';

    public const OS_WINDOWS = 'windows';

    public const OS_LINUX = 'linux';

    protected $fillable = [
        'key',
        'label',
        'description',
        'category',
        'value_type',
        'options',
        'default_value',
        'warning',
        'applies_to_os',
        'is_active',
        'overrides_locked',
    ];

    protected $casts = [
        'options' => 'array',
        'applies_to_os' => 'array',
        'is_active' => 'boolean',
        'overrides_locked' => 'boolean',
    ];

    /**
     * Projections (mécanismes de matérialisation) de cette capacité, par OS.
     *
     * @return HasMany<CapabilityProjection>
     */
    public function projections(): HasMany
    {
        return $this->hasMany(CapabilityProjection::class);
    }

    /**
     * Parcs (salles physiques + parcs logiques) portant un override de valeur —
     * geste UI v1 (par PARC). Pivot polymorphe `capability_assignments`.
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'capability_assignments',
            'capability_id',
            'assignable_id',
        )->withPivot('value')->withTimestamps();
    }

    /** Postes individuels (extensible sans migration, hors UI v1). */
    public function workstations(): MorphToMany
    {
        return $this->morphedByMany(
            Workstation::class,
            'assignable',
            'capability_assignments',
            'capability_id',
            'assignable_id',
        )->withPivot('value')->withTimestamps();
    }

    /** Groupes utilisateur (extensible sans migration, hors UI v1). */
    public function userGroups(): MorphToMany
    {
        return $this->morphedByMany(
            UserGroup::class,
            'assignable',
            'capability_assignments',
            'capability_id',
            'assignable_id',
        )->withPivot('value')->withTimestamps();
    }

    /** Utilisateurs (extensible sans migration, hors UI v1). */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'assignable',
            'capability_assignments',
            'capability_id',
            'assignable_id',
        )->withPivot('value')->withTimestamps();
    }

    /** La capacité a-t-elle du sens sur cet OS ? (déclaration d'authoring). */
    public function appliesToOs(string $os): bool
    {
        return in_array($os, $this->applies_to_os ?? [], true);
    }

    public function isToggle(): bool
    {
        return $this->value_type === self::VALUE_TYPE_TOGGLE;
    }

    public function isEnum(): bool
    {
        return $this->value_type === self::VALUE_TYPE_ENUM;
    }

    /** Choix fermé (enum) → sélecteur en UI, validation des valeurs autorisées. */
    public function hasOptions(): bool
    {
        return is_array($this->options) && $this->options !== [];
    }

    /**
     * Valeurs de capacité autorisées (validation override/défaut saisi).
     *
     * @return list<string>
     */
    public function allowedOptionValues(): array
    {
        if (! $this->hasOptions()) {
            return [];
        }

        return array_values(array_map(
            static fn (array $opt): string => (string) ($opt['value'] ?? ''),
            $this->options ?? [],
        ));
    }

    /** Libellé lisible d'une valeur via `options` ; repli sur la valeur brute. */
    public function optionLabel(string $value): string
    {
        foreach ($this->options ?? [] as $opt) {
            if ((string) ($opt['value'] ?? '') === $value) {
                return (string) ($opt['label'] ?? $value);
            }
        }

        return $value;
    }

    /** Porte-t-elle un message d'implications à confirmer ? */
    public function hasWarning(): bool
    {
        return is_string($this->warning) && trim($this->warning) !== '';
    }

    /**
     * Story 43.2 (D6) — hint de rafraîchissement le plus FORT parmi les
     * projections windows `registry`/`registry_list` dont le `spec` porte un
     * `refresh` VALIDE (vocabulaire fermé) — la bi-projection (ex.
     * `blocked_executables`) prend le max. `null` si aucune projection ne porte
     * de hint valide (comportement legacy : effet au prochain logon).
     *
     * Lit la relation `projections` DÉJÀ eager-loaded par l'appelant (zéro
     * requête ajoutée, D6) — un appelant qui n'a chargé qu'un sous-ensemble de
     * mécanismes ne verra que les hints de ce sous-ensemble (sans impact
     * pratique aujourd'hui : le retrofit 43.2 pose le MÊME hint dans les deux
     * specs d'une bi-projection).
     */
    public function refreshHint(): ?string
    {
        $best = null;
        $bestRank = -1;

        foreach ($this->projections as $projection) {
            if (! in_array($projection->mechanism, [
                CapabilityProjection::MECHANISM_REGISTRY,
                CapabilityProjection::MECHANISM_REGISTRY_LIST,
            ], true)) {
                continue;
            }

            $spec = $projection->spec;
            $hint = is_array($spec) ? ($spec['refresh'] ?? null) : null;
            if (! is_string($hint)) {
                continue;
            }

            $rank = array_search($hint, CapabilityProjection::REFRESH_HINTS, true);
            if ($rank === false) {
                continue; // valeur hors vocabulaire (donnée corrompue hypothétique) : ignorée.
            }

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $hint;
            }
        }

        return $best;
    }

    /**
     * Une projection windows registry/registry_list de cette capacité porte-t-elle
     * AU MOINS une clé/conteneur `hive: HKCU` ? (D5 — condition d'affichage d'un
     * badge : une capacité 100 % machine/HKLM/HKU — firewall, fs_acl, machine-only…
     * — n'a AUCUNE clé HKCU registre et n'affiche donc jamais de badge de
     * temporalité, sous peine de mensonge inverse.)
     */
    private function hasHkcuRegistryKey(): bool
    {
        foreach ($this->projections as $projection) {
            if (! in_array($projection->mechanism, [
                CapabilityProjection::MECHANISM_REGISTRY,
                CapabilityProjection::MECHANISM_REGISTRY_LIST,
            ], true)) {
                continue;
            }

            $spec = $projection->spec;
            $keys = is_array($spec) && isset($spec['keys']) && is_array($spec['keys'])
                ? $spec['keys']
                : [];

            foreach ($keys as $key) {
                if (is_array($key)
                    && strcasecmp((string) ($key['hive'] ?? ''), CapabilityProjection::HIVE_USER) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Story 43.2 (D5/D6, FR-A3) — temporalité d'effet affichée en UI. `null` =
     * AUCUN badge (D5 : capacité sans clé HKCU registre — machine-only, firewall,
     * fs_acl… — afficher « à la prochaine session » y serait un mensonge inverse).
     * Sinon : `shell_notify`/`policy_broadcast` → « Immédiat » ; `explorer_restart`
     * → « Immédiat (le bureau redémarre) » ; hint ABSENT (mais ≥ 1 clé HKCU) →
     * « À la prochaine session » (comportement legacy honnête). Tooltip courte,
     * sans jargon (ni « logon », ni « HKCU », ni « broadcast »).
     *
     * @return array{label:string, tooltip:string}|null
     */
    public function effectTiming(): ?array
    {
        if (! $this->hasHkcuRegistryKey()) {
            return null;
        }

        return match ($this->refreshHint()) {
            CapabilityProjection::REFRESH_EXPLORER_RESTART => [
                'label' => 'Immédiat (le bureau redémarre)',
                'tooltip' => 'Effectif en session ouverte dès que le poste applique le réglage '
                    .'(au plus tard à son prochain contact serveur). Les fenêtres de l\'Explorateur '
                    .'sont rouvertes.',
            ],
            CapabilityProjection::REFRESH_SHELL_NOTIFY, CapabilityProjection::REFRESH_POLICY_BROADCAST => [
                'label' => 'Immédiat',
                'tooltip' => 'Effectif en session ouverte dès que le poste applique le réglage '
                    .'(au plus tard à son prochain contact serveur).',
            ],
            default => [
                'label' => 'À la prochaine session',
                'tooltip' => 'Prendra effet à la prochaine ouverture de session Windows.',
            ],
        };
    }
}
