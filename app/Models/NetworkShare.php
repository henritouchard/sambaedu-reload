<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Story 34.1 — répertoire réseau nommé (« lecteur réseau géré »).
 *
 * Fondation backend du module : persistance d'un répertoire matérialisé sous
 * `/var/sambaedu/Partages/<directory_name>` ({@see App\Services\Filesystem\NetworkShareService})
 * et projeté en montage de lecteur via le canal `drives` natif de l'agent
 * ({@see App\Services\Agent\Providers\DrivesStateProvider}).
 *
 * **Modèle d'accès à DEUX axes orthogonaux** (décision Henri 2026-06-29) sur le
 * MÊME jeu d'assignations polymorphes (`network_share_assignables`) :
 *  - **Visibilité (montage)** : N'IMPORTE QUELLE maille (`User` / `UserGroup` /
 *    `WorkstationGroup`) fait apparaître la lettre — l'union/dédup/précédence du
 *    `StateCompiler` gère tout (zéro modif compilateur).
 *  - **ACL POSIX (RO/RW réel)** : dérivée des seules assignations `User` /
 *    `UserGroup` (`user:<login>` / `group:<unix>`). Une assignation
 *    `WorkstationGroup` est MONTAGE-SEUL (aucune ACL — invariant).
 *
 * @property int $id
 * @property string $name
 * @property string $directory_name
 * @property string|null $label
 * @property string|null $description
 * @property string|null $letter
 * @property int|null $created_by_user_id
 * @property \DateTime|null $created_at
 * @property \DateTime|null $updated_at
 */
class NetworkShare extends Model
{
    use HasFactory;

    /**
     * Identifiant FIGÉ du type de ressource desired-state (contrat §7, NFR12) —
     * iso `Shortcut::TYPE_SHORTCUTS`, consommé par {@see DrivesStateProvider}.
     * Le répertoire réseau se projette dans le type `drives` DÉJÀ figé (l'agent
     * monte n'importe quelle lettre→UNC sans modification).
     */
    public const TYPE_DRIVES = 'drives';

    /**
     * Types polymorphes autorisés sur le pivot (validés applicativement, calqué
     * sur `shortcut_assignables`). FQCN stockés en clair (pas de morph map).
     */
    public const ALLOWED_ASSIGNABLE_TYPES = [
        User::class,
        UserGroup::class,
        WorkstationGroup::class,
    ];

    protected $table = 'network_shares';

    protected $fillable = [
        'name',
        'directory_name',
        'label',
        'description',
        'letter',
        'created_by_user_id',
    ];

    /**
     * Toutes les lignes d'assignation (pivot polymorphe brut) — itérable côté
     * provisioning pour dériver les ACLs (avec leur `access`).
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(NetworkShareAssignable::class, 'network_share_id');
    }

    /**
     * Utilisateurs assignés (porte le pivot `access`). Maille `User`.
     */
    public function users(): MorphToMany
    {
        return $this->morphedByMany(
            User::class,
            'assignable',
            'network_share_assignables',
            'network_share_id',
            'assignable_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Groupes d'utilisateurs assignés (porte le pivot `access`). Maille
     * `UserGroup`.
     */
    public function userGroups(): MorphToMany
    {
        return $this->morphedByMany(
            UserGroup::class,
            'assignable',
            'network_share_assignables',
            'network_share_id',
            'assignable_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Parcs/salles assignés (MONTAGE-SEUL — `access` ignoré pour l'ACL, la
     * lettre s'affiche mais l'accès réel vient des grants user/group).
     */
    public function workstationGroups(): MorphToMany
    {
        return $this->morphedByMany(
            WorkstationGroup::class,
            'assignable',
            'network_share_assignables',
            'network_share_id',
            'assignable_id',
        )->withPivot('access')->withTimestamps();
    }

    /**
     * Libellé EFFECTIF du lecteur dans l'explorateur : `label` si défini, sinon
     * le `name` (AC1/AC2).
     */
    public function effectiveLabel(): string
    {
        $label = $this->label;

        return ($label === null || $label === '') ? (string) $this->name : $label;
    }
}
