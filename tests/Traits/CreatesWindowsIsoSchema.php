<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Story 3.6 — Trait de bootstrap SQLite :memory: pour les tests `windows_iso_downloads`.
 *
 * Pattern iso `CreatesPermissionSchema` / `CreatesDhcpSchema`.
 */
trait CreatesWindowsIsoSchema
{
    protected array $createdIsoTables = [];

    protected function createWindowsIsoSchema(): void
    {
        // `users` créée par CreatesPermissionSchema dans la plupart des tests Feature.
        // Pour les tests Unit qui ne posent pas la chaîne complète Spatie,
        // on crée une table users minimale.
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->id();
                $t->string('login', 255)->unique();
                $t->string('password', 255)->nullable();
                $t->string('fullname', 255)->nullable();
                $t->string('firstname', 255)->nullable();
                $t->string('lastname', 255)->nullable();
                $t->string('email', 255)->nullable();
                $t->string('role', 50)->default('autre');
                $t->boolean('is_active')->default(true);
                $t->integer('ad_rights_bitmask')->default(0);
                $t->timestamps();
            });
            $this->createdIsoTables[] = 'users';
        }

        if (! Schema::hasTable('windows_iso_downloads')) {
            Schema::create('windows_iso_downloads', function (Blueprint $t) {
                $t->id();
                $t->string('version', 10);
                $t->string('iso_name', 255);
                $t->string('source_url', 2048);
                $t->string('status', 20)->default('pending');
                $t->timestamp('started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->integer('exit_code')->nullable();
                $t->text('error')->nullable();
                // Q2 Henri 2026-05-21 — nullable + nullOnDelete (iso migration).
                $t->unsignedBigInteger('initiated_by_user_id')->nullable();
                $t->string('host_ip', 45)->nullable();
                $t->timestamps();
                $t->index(['status', 'created_at'], 'wid_status_created_idx');
                $t->index(['version', 'status'], 'wid_version_status_idx');
                // Opus-G — index sur created_at seul (iso migration).
                $t->index('created_at', 'wid_created_idx');
            });
            $this->createdIsoTables[] = 'windows_iso_downloads';
        }
    }

    protected function dropWindowsIsoSchema(): void
    {
        $order = ['windows_iso_downloads', 'users'];
        foreach ($order as $table) {
            if (in_array($table, $this->createdIsoTables, true)) {
                Schema::dropIfExists($table);
            }
        }
        $this->createdIsoTables = [];
    }
}
