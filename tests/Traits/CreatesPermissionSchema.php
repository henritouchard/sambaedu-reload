<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trait mutualisant la création du schéma SQLite `:memory:` pour les tests
 * Spatie (permissions, rôles, délégations, userGroups, workstation_groups).
 *
 * Story 7.2 — évite de dupliquer les ~10 Schema::create dans chaque test
 * Feature/Unit qui touche aux Policies ou au PermissionService.
 *
 * Correction review 7.2 #4 / #M5 :
 *  - schéma `users` aligné sur la migration prod (firstname, lastname,
 *    school_code, school_name, email, ad_guid, dn) pour éviter les bugs
 *    silencieux observer + requêtes qui référencent des colonnes absentes ;
 *  - flag `$createdPermissionSchema` (bool) remplacé par `$createdTables`
 *    (array) — `dropPermissionSchema` ne drop que les tables que le trait a
 *    effectivement créées, évitant de casser des tables présentes avant
 *    l'appel au trait.
 */
trait CreatesPermissionSchema
{
    /**
     * Liste des tables créées par ce trait dans le test courant.
     *
     * Ne contient QUE les tables que ce trait a créées (autrement dit : celles
     * qui n'existaient pas avant l'appel à `createPermissionSchema`). Permet
     * à `dropPermissionSchema` de ne dropper que ce qu'il a effectivement
     * créé.
     *
     * @var string[]
     */
    protected array $createdTables = [];

    protected function createPermissionSchema(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('firstname', 255)->nullable();
                $table->string('lastname', 255)->nullable();
                $table->string('email', 255)->nullable();
                $table->text('dn')->nullable();
                $table->string('ad_guid', 36)->nullable();
                $table->string('role', 50)->default('autre');
                $table->string('school_code', 255)->nullable();
                $table->string('school_name', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->integer('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamp('pwd_reset_at')->nullable();
                // Story 14.4 — AC1 : colonne password_changed_at pour les filtres audit
                $table->timestamp('password_changed_at')->nullable();
                $table->json('quota_snapshot')->nullable();
                // Story 26.3 — snapshot taille profil itinérant (badge tableau).
                $table->json('profile_snapshot')->nullable();
                $table->timestamps();
            });
            $this->createdTables[] = 'users';
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type');
                $table->text('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
            $this->createdTables[] = 'user_groups';
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table) {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('user_id');
                // Story 4.14 — attribut d'arête « professeur principal ».
                $table->boolean('is_head_teacher')->default(false);
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables[] = 'user_group_user';
        }

        if (!Schema::hasTable('workstation_groups')) {
            Schema::create('workstation_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('display_name')->nullable();
                $table->boolean('is_physical')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('locked')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
            });
            $this->createdTables[] = 'workstation_groups';
        } elseif (!Schema::hasColumn('workstation_groups', 'display_name')) {
            // Cas où la table a été créée par un autre test sans display_name :
            // on la complète pour permettre les factories `WorkstationGroupFactory`.
            Schema::table('workstation_groups', function (Blueprint $table) {
                $table->string('display_name')->nullable();
            });
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables[] = 'permissions';
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->createdTables[] = 'roles';
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['permission_id', 'model_id', 'model_type'], 'mhp_primary');
            });
            $this->createdTables[] = 'model_has_permissions';
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type'], 'mhr_primary');
            });
            $this->createdTables[] = 'model_has_roles';
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->createdTables[] = 'role_has_permissions';
        }

        if (!Schema::hasTable('delegations')) {
            Schema::create('delegations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('workstation_group_id');
                $table->unsignedBigInteger('permission_id');
                $table->boolean('is_negative')->default(false);
                $table->unsignedBigInteger('granted_by')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['user_id', 'workstation_group_id', 'permission_id', 'is_negative'],
                    'delegations_unique'
                );
            });
            $this->createdTables[] = 'delegations';
        }

        if (!Schema::hasTable('delegation_history')) {
            Schema::create('delegation_history', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->unsignedBigInteger('target_user_id')->nullable();
                $table->unsignedBigInteger('workstation_group_id')->nullable();
                $table->string('permission_name', 255);
                $table->string('action', 32);
                $table->boolean('is_negative')->default(false);
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
            $this->createdTables[] = 'delegation_history';
        }

        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
            $this->createdTables[] = 'system_settings';
        }

        // Story 5.2 — la table polyvalente d'audit accueille aussi les
        // opérations partages (target_type='share'). Story 5.1c+5.1d
        // l'utilisaient déjà pour les quotas. On la crée localement pour
        // les tests qui touchent aux services Filesystem partages.
        if (!Schema::hasTable('quota_audit_logs')) {
            Schema::create('quota_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('quota_rule_id')->nullable();
                // Review 5.2 #9 — alignement test/prod : la migration
                // `2026_02_20_100000_create_quota_tables.php` utilise
                // `action(20)` et `target_type(20)`. Les actions Story 5.2
                // tiennent toutes dans 20 caractères (`create_share`=12,
                // `toggle_echange`=14, `archive_share`=13, `resync_class`=12,
                // `sync_user`=9). Aligner évite la dette de divergence.
                $table->string('action', 20);
                $table->string('performed_by', 255);
                $table->string('target_type', 20);
                $table->string('target_name', 255)->nullable();
                $table->string('partition', 50);
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->boolean('fs_applied')->default(false);
                $table->text('fs_error')->nullable();
                $table->timestamp('created_at')->nullable();
            });
            $this->createdTables[] = 'quota_audit_logs';
        }
    }

    protected function dropPermissionSchema(): void
    {
        // Review 7.2 #4/M5 — on drop uniquement les tables que le trait a
        // effectivement créées dans ce test. Le drop s'effectue en ordre
        // inverse de création pour respecter les FK (delegation_history →
        // delegations → model_has_* / roles / permissions → workstation_groups
        // → user_group_user → user_groups → users).
        $dropOrder = [
            'quota_audit_logs',
            'system_settings',
            'delegation_history',
            'delegations',
            'model_has_permissions',
            'model_has_roles',
            'role_has_permissions',
            'permissions',
            'roles',
            'workstation_groups',
            'user_group_user',
            'user_groups',
            'users',
        ];

        foreach ($dropOrder as $table) {
            if (in_array($table, $this->createdTables, true)) {
                Schema::dropIfExists($table);
            }
        }
        $this->createdTables = [];
    }
}
