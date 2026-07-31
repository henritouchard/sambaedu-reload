<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\ExtensionLifecycleException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
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

    // ── 56.1 review #1 — le fail-closed n'est pas qu'un filtre d'affichage ─
    //
    // La bibliothèque MASQUE les extensions `available` d'une source gelée ou
    // en `error`. Mais `integrate(<id>)` est une méthode Livewire publique qui
    // prend un identifiant arbitraire : si la garde ne vivait que dans la vue,
    // il suffisait de connaître l'identifiant pour intégrer quand même — et
    // pour contourner au passage la modale d'avertissement « source tierce ».
    // Ces trois tests verrouillent la garde côté SERVICE.

    #[Test]
    public function integrating_an_extension_of_a_disabled_source_is_refused_even_by_a_direct_call(): void
    {
        $actor = $this->actor();
        $source = ExtensionSource::factory()->remote()->disabled()->create();
        $extension = Extension::factory()->link()->create([
            'extension_source_id' => $source->id,
            'status' => ExtensionStatus::Available,
        ]);

        // La bibliothèque la masque déjà…
        self::assertNull(app(ExtensionCatalogService::class)->find($extension->id));

        // …et le service la refuse AUSSI, sans mutation ni audit.
        try {
            $this->service()->integrate($extension->id, $actor);
            self::fail('Une extension d\'une source gelée ne doit pas pouvoir être intégrée.');
        } catch (ExtensionLifecycleException) {
            self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
            self::assertSame(0, ExtensionAuditLog::query()->count());
        }
    }

    #[Test]
    public function integrating_an_extension_of_a_source_in_signature_error_is_refused(): void
    {
        $actor = $this->actor();
        $source = ExtensionSource::factory()->remote()->syncError()->create();
        $extension = Extension::factory()->link()->create([
            'extension_source_id' => $source->id,
            'status' => ExtensionStatus::Available,
        ]);

        try {
            $this->service()->integrate($extension->id, $actor);
            self::fail('Une source dont le catalogue n\'est pas vérifié ne propose plus rien (NFR2).');
        } catch (ExtensionLifecycleException) {
            self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
            self::assertSame(0, ExtensionAuditLog::query()->count());
        }
    }

    #[Test]
    public function an_unreachable_source_still_lets_its_last_verified_catalog_be_integrated(): void
    {
        // NFR7 — le registre EST le cache : `unreachable` n'invalide pas ce qui
        // a DÉJÀ été vérifié. Contre-épreuve des deux tests ci-dessus : la
        // garde refuse le non-vérifié, pas le hors-ligne.
        $actor = $this->actor();
        $source = ExtensionSource::factory()->remote()->unreachable()->create();
        $extension = Extension::factory()->link()->create([
            'extension_source_id' => $source->id,
            'status' => ExtensionStatus::Available,
        ]);

        $result = $this->service()->integrate($extension->id, $actor);

        self::assertSame(['changed' => true, 'status' => 'integrated'], $result);
    }

    #[Test]
    public function uninstalling_stays_open_even_when_the_source_is_frozen(): void
    {
        // Rompre le lien FIGE l'état, il ne piège pas l'admin : ce qui est
        // déjà intégré doit rester désinstallable quoi qu'il arrive à sa source.
        $actor = $this->actor();
        $source = ExtensionSource::factory()->remote()->disabled()->syncError()->create();
        $extension = Extension::factory()->link()->create([
            'extension_source_id' => $source->id,
            'status' => ExtensionStatus::Integrated,
        ]);

        $result = $this->service()->uninstall($extension->id, $actor);

        self::assertSame(['changed' => true, 'status' => 'available'], $result);
        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
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

    // =====================================================================
    // Story 56.2 (AC6) — transitions `app`, levée MAÎTRISÉE du filtre `link`
    // =====================================================================

    #[Test]
    public function mark_app_installed_transitions_and_records_what_was_actually_posed(): void
    {
        $extension = Extension::factory()->app()->create(['key' => 'hello', 'name' => 'Hello']);

        $result = $this->service()->markAppInstalled($extension->id, '1.2.3', 8600, null);

        self::assertSame(['changed' => true, 'status' => 'integrated'], $result);

        $fresh = $extension->fresh();
        self::assertSame(ExtensionStatus::Integrated, $fresh->status);
        self::assertSame('1.2.3', $fresh->installed_version);
        self::assertSame(8600, $fresh->installed_port);
        self::assertNotNull($fresh->installed_at);

        $log = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_INSTALL)->firstOrFail();
        self::assertSame('hello', $log->extension_key);
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $log->actor_login);
        self::assertNull($log->actor_user_id);
    }

    #[Test]
    public function mark_app_installed_records_the_acting_admin_when_there_is_one(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->app()->create(['key' => 'hello']);

        $this->service()->markAppInstalled($extension->id, '1.0.0', 8600, $actor);

        $log = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_INSTALL)->firstOrFail();
        self::assertSame($actor->id, $log->actor_user_id);
        self::assertSame('lifecycle-actor', $log->actor_login);
    }

    #[Test]
    public function mark_app_removed_resets_every_installed_column(): void
    {
        $extension = Extension::factory()->app()->installed(8600)->create(['key' => 'hello']);

        $result = $this->service()->markAppRemoved($extension->id, null);

        self::assertSame(['changed' => true, 'status' => 'available'], $result);

        $fresh = $extension->fresh();
        self::assertSame(ExtensionStatus::Available, $fresh->status);
        self::assertSame('', $fresh->installed_version);
        self::assertNull($fresh->installed_port, 'un port non libéré serait perdu pour l\'allocateur');
        self::assertNull($fresh->installed_at);
        self::assertSame(1, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_REMOVE)->count());
    }

    #[Test]
    public function mark_app_installed_on_an_already_installed_app_is_a_silent_no_op(): void
    {
        $extension = Extension::factory()->app()->installed(8600)->create(['key' => 'hello']);

        $result = $this->service()->markAppInstalled($extension->id, '9.9.9', 8699, null);

        self::assertSame(['changed' => false, 'status' => 'integrated'], $result);
        self::assertSame('1.0.0', $extension->fresh()->installed_version, 'aucune écriture sur un no-op');
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function mark_app_removed_on_an_available_app_is_a_silent_no_op(): void
    {
        $extension = Extension::factory()->app()->create(['key' => 'hello']);

        $result = $this->service()->markAppRemoved($extension->id, null);

        self::assertSame(['changed' => false, 'status' => 'available'], $result);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function mark_app_installed_refuses_a_link(): void
    {
        // Symétrique du refus historique : les deux familles de transitions ne
        // peuvent pas se croiser.
        $extension = Extension::factory()->link()->create(['key' => 'doc']);

        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->markAppInstalled($extension->id, '1.0.0', 8600, null);
    }

    #[Test]
    public function mark_app_removed_refuses_a_link(): void
    {
        $extension = Extension::factory()->link()->integrated()->create(['key' => 'doc']);

        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->markAppRemoved($extension->id, null);
    }

    #[Test]
    public function mark_app_installed_refuses_an_unknown_extension(): void
    {
        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->markAppInstalled(999_999, '1.0.0', 8600, null);
    }

    #[Test]
    public function integrate_still_refuses_an_app_verbatim(): void
    {
        // Régression 54.2 : la levée du filtre `link` est MAÎTRISÉE — elle passe
        // par de nouvelles méthodes, pas par un assouplissement d'`integrate()`.
        // Un clic dans l'UI ne peut donc pas déclencher une installation `app`.
        $actor = $this->actor();
        $extension = Extension::factory()->app()->create(['key' => 'hello']);

        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->integrate($extension->id, $actor);
    }

    #[Test]
    public function uninstall_still_refuses_an_app_verbatim(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->app()->installed(8600)->create(['key' => 'hello']);

        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->uninstall($extension->id, $actor);
    }

    // =====================================================================
    // Story 56.3 — `markAppUpdated()` et l'empreinte du paquet posé
    // =====================================================================

    #[Test]
    public function mark_app_installed_records_the_fingerprint_of_the_posted_package(): void
    {
        $extension = Extension::factory()->app()->create(['key' => 'hello']);
        $sha = hash('sha256', 'paquet-1.0.0');

        $this->service()->markAppInstalled($extension->id, '1.0.0', 8600, null, $sha);

        self::assertSame($sha, $extension->fresh()->installed_sha256);
    }

    #[Test]
    public function mark_app_removed_clears_the_fingerprint(): void
    {
        // Le staging vient d'être purgé par le moteur : garder l'empreinte
        // désignerait un paquet qui n'existe plus.
        $extension = Extension::factory()->app()->installed(8600)->create(['key' => 'hello']);
        self::assertNotSame('', $extension->installed_sha256);

        $this->service()->markAppRemoved($extension->id, null);

        self::assertSame('', $extension->fresh()->installed_sha256);
    }

    #[Test]
    public function mark_app_updated_moves_the_version_and_the_fingerprint_and_nothing_else(): void
    {
        $extension = Extension::factory()->app()
            ->installed(8600, '1.0.0', hash('sha256', 'v1'))
            ->create(['key' => 'hello', 'name' => 'Hello']);
        $installedAt = $extension->installed_at;

        $result = $this->service()->markAppUpdated($extension->id, '2.0.0', hash('sha256', 'v2'), null);

        self::assertSame(['changed' => true, 'status' => 'integrated'], $result);

        $fresh = $extension->fresh();
        self::assertSame('2.0.0', $fresh->installed_version);
        self::assertSame(hash('sha256', 'v2'), $fresh->installed_sha256);
        // Invariants de la CLÉ : ils ne bougent pas d'une version à l'autre.
        self::assertSame(ExtensionStatus::Integrated, $fresh->status);
        self::assertSame(8600, $fresh->installed_port);
        self::assertSame(
            $installedAt?->toDateTimeString(),
            $fresh->installed_at?->toDateTimeString(),
            'installed_at date la POSE de l\'extension, pas la dernière version',
        );

        $log = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_UPDATE)->firstOrFail();
        self::assertSame('hello', $log->extension_key);
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $log->actor_login);
        self::assertStringContainsString('2.0.0', (string) $log->details);
    }

    #[Test]
    public function mark_app_updated_records_the_acting_admin_when_there_is_one(): void
    {
        $actor = $this->actor();
        $extension = Extension::factory()->app()
            ->installed(8600, '1.0.0', hash('sha256', 'v1'))
            ->create(['key' => 'hello']);

        $this->service()->markAppUpdated($extension->id, '2.0.0', hash('sha256', 'v2'), $actor);

        $log = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_UPDATE)->firstOrFail();
        self::assertSame($actor->id, $log->actor_user_id);
        self::assertSame('lifecycle-actor', $log->actor_login);
    }

    #[Test]
    public function mark_app_updated_to_the_same_version_is_a_silent_no_op(): void
    {
        $sha = hash('sha256', 'v1');
        $extension = Extension::factory()->app()->installed(8600, '1.0.0', $sha)->create(['key' => 'hello']);

        $result = $this->service()->markAppUpdated($extension->id, '1.0.0', $sha, null);

        self::assertSame(['changed' => false, 'status' => 'integrated'], $result);
        self::assertSame(0, ExtensionAuditLog::query()->count(), 'le journal trace des transitions RÉELLES');
    }

    #[Test]
    public function mark_app_updated_on_an_extension_that_is_not_installed_is_a_no_op(): void
    {
        $extension = Extension::factory()->app()->create(['key' => 'hello']);

        $result = $this->service()->markAppUpdated($extension->id, '2.0.0', hash('sha256', 'v2'), null);

        self::assertSame(['changed' => false, 'status' => 'available'], $result);
        self::assertSame('', $extension->fresh()->installed_version);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function mark_app_updated_refuses_a_link(): void
    {
        $extension = Extension::factory()->link()->integrated()->create(['key' => 'doc']);

        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->markAppUpdated($extension->id, '2.0.0', hash('sha256', 'v2'), null);
    }

    #[Test]
    public function mark_app_updated_refuses_an_unknown_extension(): void
    {
        $this->expectException(ExtensionLifecycleException::class);

        $this->service()->markAppUpdated(999_999, '2.0.0', hash('sha256', 'v2'), null);
    }
}
