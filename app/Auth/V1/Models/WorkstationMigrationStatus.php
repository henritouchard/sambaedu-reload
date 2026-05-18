<?php

declare(strict_types=1);

namespace App\Auth\V1\Models;

use Database\Factories\Auth\V1\WorkstationMigrationStatusFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Story 16.11 — AC6.1.
 *
 * Modèle Eloquent pour la table `workstations_migration_status`.
 *
 * Source de vérité pour le middleware `InjectBootstrapFragment` (un poste
 * dont l'`workstation_uuid` apparaît dans cette table est considéré comme
 * **déjà migré** — pas de re-injection du fragment).
 *
 * Conventions :
 *
 *  - **`id` autoincrement** (pas UUID — un status est une row interne, pas
 *    une référence exposée à l'extérieur).
 *  - **`workstation_uuid` unique** : un poste a au plus une row → upsert
 *    idempotent.
 *  - Scope `migrated()` trivial : aujourd'hui toute row a `migrated_at`
 *    non null (la migration est instantanée à la création). Le scope reste
 *    explicite pour préserver l'API si Phase 3+ introduit des états
 *    intermédiaires.
 *  - Override `newFactory()` pour pointer la factory sous sous-namespace
 *    `Database\Factories\Auth\V1` (parité 16.10 `WorkstationRefreshToken`).
 *
 * @property int $id
 * @property string $workstation_uuid
 * @property Carbon $migrated_at
 * @property string|null $access_token_emitted_jti
 * @property string|null $bootstrap_token_hash_prefix
 * @property string $os
 * @property string|null $se4fs_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class WorkstationMigrationStatus extends Model
{
    use HasFactory;

    /** @var string */
    protected $table = 'workstations_migration_status';

    /** @var array<int,string> */
    protected $fillable = [
        'workstation_uuid',
        'migrated_at',
        'access_token_emitted_jti',
        'bootstrap_token_hash_prefix',
        'os',
        'se4fs_name',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'migrated_at' => 'datetime',
    ];

    /**
     * Statuts migrés (migrated_at non null).
     *
     * @param Builder<self> $query
     */
    public function scopeMigrated(Builder $query): Builder
    {
        return $query->whereNotNull('migrated_at');
    }

    /**
     * Override : la factory vit sous le sous-namespace `Auth\V1`, pas la
     * convention racine `Database\Factories\WorkstationMigrationStatusFactory`.
     */
    protected static function newFactory(): Factory
    {
        return WorkstationMigrationStatusFactory::new();
    }
}
