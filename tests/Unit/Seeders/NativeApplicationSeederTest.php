<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Models\NativeApplication;
use Database\Seeders\NativeApplicationSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.11 — `NativeApplicationSeeder` : référentiel curé des built-ins Win32.
 * Vérifie l'idempotence (rejouable, zéro doublon) et l'exclusion des UWP (seules
 * des apps Win32 à ProgId canonique connu, exe runtime, et extensions déclarées).
 */
class NativeApplicationSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('native_applications');
        parent::tearDown();
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('native_applications')) {
            Schema::create('native_applications', function (Blueprint $t): void {
                $t->id();
                $t->string('key')->unique();
                $t->string('label');
                $t->string('progid');
                $t->string('executable');
                $t->json('assoc_types');
                $t->string('icon_url')->nullable();
                $t->timestamps();
            });
        }
    }

    #[Test]
    public function seeds_curated_win32_builtins(): void
    {
        (new NativeApplicationSeeder())->run();

        // Le cas canonique de Henri : Bloc-notes → txtfile, .txt déclaré.
        $notepad = NativeApplication::query()->where('progid', 'txtfile')->firstOrFail();
        self::assertTrue($notepad->supportsIdentifier('.txt'));
        self::assertFalse($notepad->supportsIdentifier('.png'), 'le Bloc-notes ne déclare pas .png (piège n°2)');
        self::assertStringContainsStringIgnoringCase('notepad.exe', (string) $notepad->executable);

        // Pas d'UWP : tous les ProgId sont des built-ins classiques (pas de AppX…).
        foreach (NativeApplication::all() as $app) {
            self::assertStringStartsNotWith('AppX', (string) $app->progid, 'aucune UWP (D-Henri n°2)');
            self::assertNotEmpty($app->executable, 'chaque native curée porte un exe runtime');
            self::assertNotEmpty($app->assoc_types, 'chaque native curée déclare ses extensions');
        }
    }

    #[Test]
    public function is_idempotent(): void
    {
        $seeder = new NativeApplicationSeeder();
        $seeder->run();
        $count = NativeApplication::query()->count();
        $seeder->run();

        self::assertSame($count, NativeApplication::query()->count(), 'rejouable, zéro doublon');
        self::assertGreaterThanOrEqual(3, $count, 'au moins Bloc-notes/Paint/WordPad (Visionneuse retirée : exe rundll32.exe = générique non fonctionnel)');
    }
}
