<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Story 27.3 — Catalogue de réglages de registre Windows (premier type `registry`
 * sans table métier existante : table DÉDIÉE, D1 architecture).
 *
 * Chaque ligne est un réglage PRÉDÉTERMINÉ activable par parc. Le
 * {@see \App\Services\Agent\Providers\RegistryMachineStateProvider} /
 * {@see \App\Services\Agent\Providers\RegistryUserStateProvider} le COMPILENT en
 * un item de contrat CONCRET `{hive, path, name, type, value}`. Le `key` du
 * catalogue (ou l'`id`) ne fuite JAMAIS au payload — c'est l'invariant central
 * qui garde l'option « éditeur de clés brutes » gratuite plus tard (v2).
 *
 * @property int $id
 * @property string $key Clé technique unique du réglage de catalogue
 * @property string $label Libellé affichable
 * @property string|null $description Aide UI
 * @property string $hive HKLM | HKCU
 * @property string $path Chemin de clé sous la ruche
 * @property string $name Nom de la valeur
 * @property string $type REG_SZ | REG_DWORD | REG_EXPAND_SZ | REG_MULTI_SZ | REG_QWORD
 * @property string $value Valeur cible sérialisée en texte
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class RegistrySetting extends Model
{
    use HasFactory;

    protected $table = 'registry_settings';

    /**
     * Identifiant FIGÉ du type de ressource desired-state (contrat §7, NFR12),
     * iso `Shortcut::TYPE_SHORTCUTS`/`Printer::TYPE_PRINTERS`. Consommé par les
     * providers registry. snake_case, jamais renommé une fois publié.
     */
    public const TYPE_REGISTRY = 'registry';

    /** Ruche machine (portée machine / service SYSTEM). */
    public const HIVE_MACHINE = 'HKLM';

    /** Ruche utilisateur (portée session / compagnon). */
    public const HIVE_USER = 'HKCU';

    protected $fillable = [
        'key',
        'label',
        'description',
        'hive',
        'path',
        'name',
        'type',
        'value',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Groupes de postes (salles physiques, parcs logiques) auxquels ce réglage
     * est assigné — geste UI v1 (par PARC). Pivot polymorphe calqué shortcuts.
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'registry_setting_assignables',
            'registry_setting_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Postes individuels assignés (pivot complet, extensible sans migration —
     * non exposé en UI v1).
     */
    public function workstations(): MorphToMany
    {
        return $this->morphedByMany(
            Workstation::class,
            'assignable',
            'registry_setting_assignables',
            'registry_setting_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Groupes utilisateur assignés (pivot complet, non exposé en UI v1).
     */
    public function userGroups(): MorphToMany
    {
        return $this->morphedByMany(
            UserGroup::class,
            'assignable',
            'registry_setting_assignables',
            'registry_setting_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Utilisateurs assignés (pivot complet, non exposé en UI v1).
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'assignable',
            'registry_setting_assignables',
            'registry_setting_id',
            'assignable_id',
        )->withTimestamps();
    }

    /**
     * Le réglage cible-t-il la ruche machine (HKLM) ? Détermine la portée
     * d'enveloppe (machine vs session) et le moteur Go (SYSTEM vs compagnon).
     */
    public function isMachineHive(): bool
    {
        return $this->hive === self::HIVE_MACHINE;
    }
}
