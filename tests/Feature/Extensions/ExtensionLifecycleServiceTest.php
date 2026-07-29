<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\ExtensionLifecycleException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\User;
use App\Services\Extensions\ExtensionLifecycleService;
use App\Services\Extensions\ExtensionCatalogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.2 (AC1/AC2/AC3) — `ExtensionLifecycleService` : les deux
 * transitions du type `link` (`available ⇄ integrated`), leur trace d'audit,
 * l'idempotence NFR8 et le fail-closed.
 */
class ExtensionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::query()->create([
            'login' => 'lifecycle-actor',
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function service(): ExtensionLifecycleService
    {
        return app(ExtensionLifecycleService::class);
    }

    // ── AC1 — intégrer ───────────────────────────────────────────────────

    #[Test]
    public function integrate_transitions_available_to_integrated_and_writes_one_audit_line(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->link()->create([
            'key' => 'doc',
            'name' => 'Documentation',
        ]);

        $result = $this->service()->integrate($extension->id, $actor);

        self::assertSame(['changed' => true, 'status' => 'integrated'], $result);
        self::assertSame(ExtensionStatus::Integrated, $extension->fresh()->status);

        self::assertSame(1, ExtensionAuditLog::query()->count());
        $log = ExtensionAuditLog::query()->first();
        self::assertSame($extension->id, $log->extension_id);
        self::assertSame('doc', $log->extension_key);
        self::assertSame('Documentation', $log->extension_name);
        self::assertSame(ExtensionAuditLog::ACTION_INTEGRATE, $log->action);
        self::assertSame($actor->id, $log->actor_user_id);
        self::assertSame('lifecycle-actor', $log->actor_login);
        self::assertNotNull($log->created_at);
    }

    // ── AC2 — désinstaller ───────────────────────────────────────────────

    #[Test]
    public function uninstall_transitions_integrated_to_available_and_writes_one_audit_line(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->link()->integrated()->create([
            'key' => 'doc',
            'name' => 'Documentation',
        ]);

        $result = $this->service()->uninstall($extension->id, $actor);

        self::assertSame(['changed' => true, 'status' => 'available'], $result);
        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);

        self::assertSame(1, ExtensionAuditLog::query()->count());
        $log = ExtensionAuditLog::query()->first();
        self::assertSame(ExtensionAuditLog::ACTION_UNINSTALL, $log->action);
        self::assertSame($extension->id, $log->extension_id);
    }

    // ── AC3 — idempotence NFR8 : no-op propre ────────────────────────────

    #[Test]
    public function integrating_an_already_integrated_extension_is_a_clean_noop(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->link()->integrated()->create();
        $updatedAtBefore = $extension->fresh()->updated_at;

        $result = $this->service()->integrate($extension->id, $actor);

        self::assertSame(['changed' => false, 'status' => 'integrated'], $result);
        self::assertEquals($updatedAtBefore, $extension->fresh()->updated_at, 'aucune écriture, pas même updated_at');
        self::assertSame(0, ExtensionAuditLog::query()->count(), 'no-op ⇒ zéro ligne d\'audit');
    }

    #[Test]
    public function uninstalling_an_already_available_extension_is_a_clean_noop(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->link()->create();
        $updatedAtBefore = $extension->fresh()->updated_at;

        $result = $this->service()->uninstall($extension->id, $actor);

        self::assertSame(['changed' => false, 'status' => 'available'], $result);
        self::assertEquals($updatedAtBefore, $extension->fresh()->updated_at);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── AC3 — fail-closed ─────────────────────────────────────────────────

    #[Test]
    public function integrating_a_non_link_type_is_refused_without_mutation_or_audit(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->create(['type' => ExtensionType::App]);
        $statusBefore = $extension->fresh()->status;

        $this->expectException(ExtensionLifecycleException::class);

        try {
            $this->service()->integrate($extension->id, $actor);
        } finally {
            self::assertSame($statusBefore, $extension->fresh()->status);
            self::assertSame(0, ExtensionAuditLog::query()->count());
        }
    }

    #[Test]
    public function uninstalling_a_non_link_type_is_refused_without_mutation_or_audit(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->create([
            'type' => ExtensionType::App,
            'status' => ExtensionStatus::Integrated,
        ]);

        $this->expectException(ExtensionLifecycleException::class);

        try {
            $this->service()->uninstall($extension->id, $actor);
        } finally {
            self::assertSame(ExtensionStatus::Integrated, $extension->fresh()->status);
            self::assertSame(0, ExtensionAuditLog::query()->count());
        }
    }

    #[Test]
    public function an_unknown_id_is_refused_cleanly(): void
    {
        $actor = $this->actor();

        $this->expectException(ExtensionLifecycleException::class);

        try {
            $this->service()->integrate(999_999, $actor);
        } finally {
            self::assertSame(0, ExtensionAuditLog::query()->count());
        }
    }

    // ── AC3 — atomicité acte ↔ trace ─────────────────────────────────────

    #[Test]
    public function when_the_audit_table_is_gone_the_transaction_rolls_back_the_status_mutation(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->link()->create();
        $updatedAtBefore = $extension->fresh()->updated_at;

        Schema::drop('extension_audit_logs');

        try {
            // ⚠️ `QueryException` et non `\Throwable` : la contrainte la plus
            // large laisserait le test vert si le service échouait AVANT
            // d'atteindre la mutation (id inconnu, garde de type déplacée par un
            // refactor) — `status` serait alors trivialement `available` et
            // l'assertion d'atomicité serait devenue vide sans que rien ne le
            // signale. On exige l'échec qui vient bien de l'écriture d'audit.
            $this->expectException(QueryException::class);
            $this->service()->integrate($extension->id, $actor);
        } finally {
            // La transaction a tout annulé : l'acte sans la trace n'existe pas.
            self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
            self::assertEquals(
                $updatedAtBefore,
                $extension->fresh()->updated_at,
                'aucune écriture ne survit au rollback, pas seulement `status`',
            );
        }
    }

    // ── AC3 — la trace SURVIT à la disparition de l'extension ─────────────

    #[Test]
    public function the_audit_trail_survives_the_catalog_prune_of_its_extension(): void
    {
        // C'est LE scénario qui justifie `nullOnDelete()` et les colonnes
        // dénormalisées `extension_key`/`extension_name` — il n'était couvert
        // par aucun test, alors que c'est le seul endroit où 54.2 peut casser un
        // comportement de 54.1 : `pruneDisappeared()` fait `$extension->delete()`
        // sans try/catch, et une extension intégrée PUIS désinstallée est
        // `available` (donc prunable) TOUT EN portant 2 lignes d'audit. Si la
        // clause `ON DELETE SET NULL` n'était pas correctement émise, ou
        // réécrite un jour en `restrict`, `syncBundled()` — rejoué par
        // `db:seed` et `scripts/update.sh` — lèverait une QueryException sur
        // toute extension ayant un historique. Silencieux en test, bruyant en
        // production, sur le chemin de mise à jour.
        $root = sys_get_temp_dir().'/se5-ext-prune-'.uniqid('', true);
        mkdir($root.'/doc', 0o777, true);
        file_put_contents($root.'/doc/manifest.json', json_encode([
            'manifest_version' => 1,
            'id' => 'doc',
            'type' => 'link',
            'name' => 'Documentation',
            'version' => '1.0.0',
            'entry_url' => '/doc',
            'visibility' => ['roles' => ['admin']],
        ]));
        config(['extensions.bundled_path' => $root]);

        $catalog = app(ExtensionCatalogService::class);
        $catalog->syncBundled();

        $extension = Extension::query()->where('key', 'doc')->firstOrFail();
        $actor = $this->actor();

        $this->service()->integrate($extension->id, $actor);
        $this->service()->uninstall($extension->id, $actor);
        self::assertSame(2, ExtensionAuditLog::query()->count());
        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);

        // Le manifest disparaît : le prune de 54.1 emporte la ligne `available`.
        unlink($root.'/doc/manifest.json');
        rmdir($root.'/doc');

        $stats = $catalog->syncBundled();

        self::assertSame(1, $stats['pruned']);
        self::assertSame(0, Extension::query()->count(), 'l\'extension est bien prunée');

        // …et la TRACE reste exploitable sans elle.
        $logs = ExtensionAuditLog::query()->orderBy('id')->get();
        self::assertCount(2, $logs, 'la trace survit à la suppression de l\'entité');
        foreach ($logs as $log) {
            self::assertNull($log->extension_id, 'FK dénouée par ON DELETE SET NULL');
            self::assertSame('doc', $log->extension_key, 'la clé dénormalisée reste lisible');
            self::assertSame('Documentation', $log->extension_name);
        }

        @rmdir($root);
    }

    // ── AC3 — journal append-only ─────────────────────────────────────────

    #[Test]
    public function updating_an_existing_audit_log_row_throws(): void
    {
        $log = ExtensionAuditLog::log(
            extensionId: null,
            extensionKey: 'doc',
            extensionName: 'Documentation',
            action: ExtensionAuditLog::ACTION_INTEGRATE,
            actorUserId: null,
            actorLogin: null,
        );

        $this->expectException(LogicException::class);

        $log->action = ExtensionAuditLog::ACTION_UNINSTALL;
        $log->save();
    }
}
