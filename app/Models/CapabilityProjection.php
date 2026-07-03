<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Epic 27 — Projection d'une {@see Capability} : comment l'intention se
 * matérialise sur un `os` via un `mechanism` (= type du contrat desired-state).
 *
 * La `spec` est interprétée par le provider/compilateur du mécanisme concerné.
 * Le `mechanism` est un identifiant FIGÉ (NFR12) aligné sur
 * `StateContract::RESOURCE_TYPES` : `registry` est déjà publié ; un nouveau
 * mécanisme (firewall, localgroup…) implique un ajout au contrat + handler agent.
 *
 * @property int $id
 * @property int $capability_id
 * @property string $os windows | linux
 * @property string $mechanism registry | firewall | localgroup | …
 * @property array<string,mixed> $spec
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class CapabilityProjection extends Model
{
    use HasFactory;

    protected $table = 'capability_projections';

    /** Mécanisme registre — déjà publié au contrat (gratuit). */
    public const MECHANISM_REGISTRY = 'registry';

    /**
     * Mécanisme liste registre à sous-valeurs indexées `\1..\N` (Story 35.2,
     * contrat §7.6 — type `registry_list`). La `spec` porte des CONTENEURS
     * `{hive, path, entry_type, values}` : l'agent possède les valeurs au nom
     * numérique de la clé-conteneur (réconciliation D3). Une capacité peut
     * porter registry ET registry_list sur le même OS (bi-projection D5 —
     * l'unique `(capability_id, os, mechanism)` le permet).
     */
    public const MECHANISM_REGISTRY_LIST = 'registry_list';

    /** Mécanisme pare-feu — slice B (ajout contrat + handler agent requis). */
    public const MECHANISM_FIREWALL = 'firewall';

    /** Mécanisme membership de groupe local — slice C (idem). */
    public const MECHANISM_LOCALGROUP = 'localgroup';

    /**
     * Ruche machine (portée machine / service SYSTEM) — clé de `spec.keys[].hive`
     * du mécanisme `registry`. Foyer canonique de la constante depuis le rewrite
     * capability-first (le modèle `RegistrySetting` est superseded — Story 27.12).
     */
    public const HIVE_MACHINE = 'HKLM';

    /** Ruche utilisateur (portée session / compagnon) — idem. */
    public const HIVE_USER = 'HKCU';

    protected $fillable = [
        'capability_id',
        'os',
        'mechanism',
        'spec',
    ];

    protected $casts = [
        'spec' => 'array',
    ];

    /**
     * @return BelongsTo<Capability, CapabilityProjection>
     */
    public function capability(): BelongsTo
    {
        return $this->belongsTo(Capability::class);
    }
}
