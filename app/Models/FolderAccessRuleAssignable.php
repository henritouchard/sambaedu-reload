<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Story 36.4 — ligne du pivot polymorphe `folder_access_rule_assignables`.
 *
 * Calque de {@see NetworkShareAssignable} SANS colonne `access` (une règle
 * d'accès porte son niveau dans `rights`, pas un ro/rw POSIX). v1 : le seul type
 * autorisé est `WorkstationGroup` (parc), validé applicativement
 * ({@see FolderAccessRule::ALLOWED_ASSIGNABLE_TYPES}) — le mécanisme est machine
 * (un override User/UserGroup serait sans effet, piège #10 36.1).
 *
 * @property int $id
 * @property int $folder_access_rule_id
 * @property int $assignable_id
 * @property string $assignable_type
 */
class FolderAccessRuleAssignable extends Model
{
    protected $table = 'folder_access_rule_assignables';

    protected $fillable = [
        'folder_access_rule_id',
        'assignable_id',
        'assignable_type',
    ];

    /**
     * Cible polymorphe (FQCN stocké en clair, iso `network_share_assignables` —
     * pas de morph map projet).
     */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(FolderAccessRule::class, 'folder_access_rule_id');
    }
}
