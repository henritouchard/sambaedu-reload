<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Trait factorisant le bootstrap des tables nécessaires aux tests Feature
 * qui mockent {@see \App\Gpo\Services\GpoService} mais doivent persister un
 * {@see \App\Models\User} muni d'une permission Spatie (ex. `server.admin`)
 * pour valider les ACs de permissions.
 *
 * Les 5 fichiers de tests Feature de la Story 16.2 dupliquaient ~70 lignes
 * de bootstrap chacun (Schema::create users + permissions + roles +
 * model_has_*) avec des index nommés à la main pour éviter les collisions.
 *
 * Cette factorisation :
 *
 * - rend la création idempotente (`Schema::hasTable` avant chaque create) —
 *   plus besoin d'index nommés ad hoc, Laravel les nomme automatiquement
 *   et `hasTable` empêche la double création ;
 * - centralise le drop dans `cleanupSpatieTables()` à appeler depuis
 *   `tearDown()` ;
 * - assure la création de la permission `server.admin` (la seule utilisée
 *   par toutes les pages GPO de l'Epic 16).
 *
 * Usage typique :
 *
 * ```php
 * class MyTest extends TestCase
 * {
 *     use BootstrapsSpatieTables;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->bootstrapSpatieTables();
 *     }
 *
 *     protected function tearDown(): void
 *     {
 *         $this->cleanupSpatieTables();
 *         parent::tearDown();
 *     }
 * }
 * ```
 */
trait BootstrapsSpatieTables
{
    /**
     * Marqueur interne : si true, `cleanupSpatieTables()` droppera les tables.
     */
    private bool $spatieTablesCreated = false;

    /**
     * Crée idempotamment les tables Spatie + users + la permission `server.admin`.
     */
    protected function bootstrapSpatieTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('login', 255)->unique();
                $table->string('password', 255)->nullable();
                $table->string('fullname', 255)->nullable();
                $table->string('role', 50)->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->spatieTablesCreated = true;
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->spatieTablesCreated = true;
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
            $this->spatieTablesCreated = true;
        }

        if (!Schema::hasTable('model_has_permissions')) {
            Schema::create('model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                // Pas d'index nommé manuellement : Laravel génère un nom
                // unique automatiquement, et `Schema::hasTable` empêche
                // toute double création donc plus de collision possible.
                $table->primary(['permission_id', 'model_id', 'model_type']);
            });
            $this->spatieTablesCreated = true;
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
            $this->spatieTablesCreated = true;
        }

        if (!Schema::hasTable('role_has_permissions')) {
            Schema::create('role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                $table->primary(['permission_id', 'role_id']);
            });
            $this->spatieTablesCreated = true;
        }

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
    }

    /**
     * Drop les tables créées par `bootstrapSpatieTables()` (no-op sinon).
     */
    protected function cleanupSpatieTables(): void
    {
        if (!$this->spatieTablesCreated) {
            return;
        }
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        $this->spatieTablesCreated = false;
    }
}
