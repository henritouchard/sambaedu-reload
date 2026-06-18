<?php

declare(strict_types=1);

namespace Tests\Unit\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.11 — vérifie up()/down() idempotents des 2 migrations (SQLite) :
 * `applications.executable` (nullable) + `native_applications` (table dédiée).
 * Le `migrate --force` sur la VM/PostgreSQL reste une action humaine d'Henri.
 */
class Story2711MigrationsTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('native_applications');
        Schema::dropIfExists('applications');
        parent::tearDown();
    }

    #[Test]
    public function migrations_apply_and_reverse_idempotently(): void
    {
        if (! Schema::hasTable('applications')) {
            Schema::create('applications', function (Blueprint $t): void {
                $t->id();
                $t->string('installer_filename')->nullable();
                $t->timestamps();
            });
        }

        $root = base_path('database/migrations');
        $m1 = require $root . '/2026_06_18_120000_add_executable_to_applications.php';
        $m2 = require $root . '/2026_06_18_120100_create_native_applications_table.php';

        $m1->up();
        $m2->up();
        self::assertTrue(Schema::hasColumn('applications', 'executable'));
        self::assertTrue(Schema::hasTable('native_applications'));

        // Idempotent : re-up ne casse rien (gardes Schema::hasColumn/hasTable).
        $m1->up();
        $m2->up();
        self::assertTrue(Schema::hasColumn('applications', 'executable'));

        // down() symétrique.
        $m2->down();
        $m1->down();
        self::assertFalse(Schema::hasColumn('applications', 'executable'));
        self::assertFalse(Schema::hasTable('native_applications'));
    }
}
