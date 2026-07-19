<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Se4PurgeCommand;
use App\Console\Commands\Se4ReplugCommand;
use App\Console\Commands\Se4StatusCommand;
use App\Console\Commands\Se4UnplugCommand;
use App\Models\LegacyCatchallLog;
use App\Services\LegacyGpoNeutralizationInspector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Story 38.6 — Tests des commandes d'extinction `se4:*`.
 *
 * Système fake de bout en bout : `Process::fake()` pour a2query/a2dissite/
 * a2ensite/systemctl/mv/trash, garde root contournée via le seam statique
 * `$assumeRoot`, chemin legacy pointé vers un répertoire temporaire via
 * `config('sambaedu.legacy_path')`. La table `legacy_catchall_logs` est créée
 * en SQLite comme dans LegacyMonitorDashboardTest.
 */
class Se4ExtinctionCommandsTest extends TestCase
{
    private string $tmpBase = '';

    /** @var list<string> Commandes système exécutées, dans l'ordre. */
    private array $ranCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('legacy_catchall_logs')) {
            Schema::create('legacy_catchall_logs', function (Blueprint $table) {
                $table->id();
                $table->string('source', 16)->default('catchall')->nullable();
                $table->string('method', 10);
                $table->string('path', 2048);
                $table->string('ip', 45);
                $table->string('machine', 255)->nullable();
                $table->string('user_login', 255)->nullable();
                $table->text('query_string')->nullable();
                $table->text('referer')->nullable();
                $table->timestamp('created_at');
            });
        }

        $this->tmpBase = sys_get_temp_dir() . '/se4-extinction-test-' . uniqid('', true);
        @mkdir($this->tmpBase, 0755, true);
        config(['sambaedu.legacy_path' => $this->legacyDir()]);

        // JAMAIS le .env réel : fixture isolée (sans scorie par défaut).
        file_put_contents($this->envFixture(), "APP_NAME=test\n");
        Se4StatusCommand::$envPath = $this->envFixture();
        Se4UnplugCommand::$envPath = $this->envFixture();
        Se4ReplugCommand::$envPath = $this->envFixture();
        Se4PurgeCommand::$envPath = $this->envFixture();
    }

    protected function tearDown(): void
    {
        LegacyCatchallLog::query()->delete();

        Se4StatusCommand::$assumeRoot = null;
        Se4UnplugCommand::$assumeRoot = null;
        Se4ReplugCommand::$assumeRoot = null;
        Se4PurgeCommand::$assumeRoot = null;
        Se4StatusCommand::$envPath = null;
        Se4UnplugCommand::$envPath = null;
        Se4ReplugCommand::$envPath = null;
        Se4PurgeCommand::$envPath = null;

        @unlink($this->envFixture());
        @rmdir($this->legacyDir());
        @rmdir($this->offDir());
        @rmdir($this->tmpBase);

        parent::tearDown();
    }

    private function envFixture(): string
    {
        return $this->tmpBase . '/.env';
    }

    private function legacyDir(): string
    {
        return $this->tmpBase . '/sambaedu';
    }

    private function offDir(): string
    {
        return $this->tmpBase . '/sambaedu.off';
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedHit(array $overrides = []): LegacyCatchallLog
    {
        return LegacyCatchallLog::create(array_merge([
            'source' => 'catchall',
            'method' => 'GET',
            'path' => 'gpo/applications.php',
            'ip' => '192.168.0.10',
            'created_at' => now()->subHours(2),
        ], $overrides));
    }

    /**
     * Fake Process global qui enregistre l'ordre des commandes.
     * `$failingPrefixes` : les commandes commençant par un de ces préfixes
     * sortent en exit 1 (ex: a2query → vhost inactif, command -v → absent).
     * `$missingPrefixes` : exit 127 (binaire introuvable, ex: a2query absent).
     *
     * @param  list<string>  $failingPrefixes
     * @param  list<string>  $missingPrefixes
     */
    private function fakeProcesses(array $failingPrefixes = [], array $missingPrefixes = []): void
    {
        $this->ranCommands = [];

        Process::fake(function ($process) use ($failingPrefixes, $missingPrefixes) {
            $this->ranCommands[] = $process->command;

            foreach ($missingPrefixes as $prefix) {
                if (str_starts_with($process->command, $prefix)) {
                    return Process::result('', '', 127);
                }
            }

            foreach ($failingPrefixes as $prefix) {
                if (str_starts_with($process->command, $prefix)) {
                    return Process::result('', '', 1);
                }
            }

            return Process::result();
        });
    }

    private function fakeVhostDisabled(array $extraFailingPrefixes = []): void
    {
        $this->fakeProcesses(['a2query -s sambaedu-legacy', ...$extraFailingPrefixes]);
    }

    /**
     * Vérifie que les commandes matchant `$expectedPrefixes` sont apparues
     * dans cet ordre relatif parmi les runs enregistrés.
     *
     * @param  list<string>  $expectedPrefixes
     */
    private function assertRanInOrder(array $expectedPrefixes): void
    {
        $cursor = 0;

        foreach ($expectedPrefixes as $prefix) {
            $found = false;
            for (; $cursor < count($this->ranCommands); $cursor++) {
                if (str_starts_with($this->ranCommands[$cursor], $prefix)) {
                    $found = true;
                    $cursor++;
                    break;
                }
            }

            $this->assertTrue($found, sprintf(
                "Commande attendue non trouvée dans l'ordre : [%s]. Runs : %s",
                $prefix,
                implode(' | ', $this->ranCommands),
            ));
        }
    }

    // ── se4:status ──────────────────────────────────────────────────────────

    public function test_status_is_go_with_only_tombstone_noise_and_old_hits(): void
    {
        $this->fakeVhostDisabled();

        // Tombstone (inerte), bruit SE5 (pas de .php), hit legacy HORS fenêtre.
        $this->seedHit(['source' => 'tombstone']);
        $this->seedHit(['path' => 'admin/ipxe']);
        $this->seedHit(['created_at' => now()->subDays(10)]);

        $this->artisan('se4:status', ['--days' => 7])
            ->expectsOutputToContain('Verdict : GO')
            ->assertExitCode(0);
    }

    public function test_status_is_no_go_on_recent_legacy_hit(): void
    {
        $this->fakeVhostDisabled();

        $this->seedHit();

        $this->artisan('se4:status', ['--days' => 7])
            ->expectsOutputToContain('Verdict : NO-GO')
            ->assertExitCode(1);
    }

    public function test_status_counts_null_source_php_hit_as_legacy(): void
    {
        $this->fakeVhostDisabled();

        // Lignes pré-38.2 : source null → même traitement que catchall.
        $this->seedHit(['source' => null]);

        $this->artisan('se4:status')
            ->expectsOutputToContain('Verdict : NO-GO')
            ->assertExitCode(1);
    }

    public function test_status_classifies_scanner_probes_as_noise(): void
    {
        $this->fakeVhostDisabled();

        // Sondes de scanners : .php top-level ou hors répertoires du canal
        // legacy → bruit, ne bloquent pas le GO.
        $this->seedHit(['path' => 'wp-login.php']);
        $this->seedHit(['path' => 'phpmyadmin/index.php']);
        $this->seedHit(['path' => 'wp-admin/setup-config.php']);

        $this->artisan('se4:status')
            ->expectsOutputToContain('Verdict : GO')
            ->assertExitCode(0);
    }

    public function test_status_classifies_channel_dirs_hits_as_legacy(): void
    {
        $this->fakeVhostDisabled();

        $this->seedHit(['path' => 'partages/cloud_out.php']);

        $this->artisan('se4:status')
            ->expectsOutputToContain('Verdict : NO-GO')
            ->assertExitCode(1);
    }

    public function test_status_renders_indeterminate_vhost_when_a2query_missing(): void
    {
        $this->fakeProcesses([], ['a2query']);

        $this->artisan('se4:status')
            ->expectsOutputToContain('INDÉTERMINÉ (a2query introuvable)')
            ->assertExitCode(0);
    }

    public function test_status_reports_pre_go_checks(): void
    {
        $this->fakeVhostDisabled();
        file_put_contents($this->envFixture(), "APP_NAME=test\nLEGACY_CONFIG_CHANNEL_ENABLED=true\n");
        $this->mockGpo(LegacyGpoNeutralizationInspector::STATUS_NEUTRALIZED, 'héritage bloqué');

        $this->artisan('se4:status')
            ->expectsOutputToContain('scorie .env LEGACY_CONFIG_CHANNEL_ENABLED : PRÉSENTE')
            ->expectsOutputToContain('GPO domaine « applications » : neutralisée pour ce collège')
            ->assertExitCode(0);
    }

    private function mockGpo(string $status, string $detail = ''): void
    {
        $this->mock(LegacyGpoNeutralizationInspector::class, function ($mock) use ($status, $detail) {
            $mock->shouldReceive('inspect')->andReturn(['status' => $status, 'detail' => $detail]);
        });
    }

    // ── se4:unplug ──────────────────────────────────────────────────────────

    public function test_unplug_refuses_without_root(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = false;

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('root')
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_unplug_runs_full_sequence_in_order_when_go(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('se4:replug')
            ->assertExitCode(0);

        $this->assertRanInOrder([
            'a2dissite sambaedu-legacy',
            'systemctl reload apache2',
            'mv ',
        ]);
        Process::assertRan(fn ($process) => str_starts_with($process->command, 'mv ')
            && str_contains($process->command, $this->legacyDir())
            && str_contains($process->command, $this->offDir()));
    }

    public function test_unplug_aborts_on_no_go_without_force(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->seedHit();

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('Préflight NO-GO')
            ->assertExitCode(1);

        Process::assertDidntRun('a2dissite sambaedu-legacy');
        Process::assertDidntRun('systemctl reload apache2');
    }

    public function test_unplug_force_overrides_no_go(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->seedHit();

        $this->artisan('se4:unplug', ['--force' => true])
            ->assertExitCode(0);

        Process::assertRan('a2dissite sambaedu-legacy');
    }

    public function test_unplug_noop_still_reloads_apache_defensively(): void
    {
        $this->fakeVhostDisabled();
        Se4UnplugCommand::$assumeRoot = true;
        // FS legacy absent (jamais créé), vhost inactif : converge quand même
        // via un reload (rattrape un run précédent interrompu avant reload).

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('déjà en place')
            ->assertExitCode(0);

        Process::assertDidntRun('a2dissite sambaedu-legacy');
        Process::assertRan('systemctl reload apache2');
    }

    public function test_unplug_fails_when_reload_fails_and_mv_is_not_reached(): void
    {
        $this->fakeProcesses(['systemctl reload apache2']);
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('Échec systemctl reload apache2')
            ->assertExitCode(1);

        Process::assertRan('a2dissite sambaedu-legacy');
        Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'mv '));
    }

    public function test_unplug_retry_after_reload_failure_replays_reload_then_mv(): void
    {
        // État post-interruption : a2dissite a réussi (vhost désormais
        // inactif), le reload avait échoué, le FS legacy est toujours là.
        $this->fakeVhostDisabled();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:unplug')->assertExitCode(0);

        Process::assertDidntRun('a2dissite sambaedu-legacy');
        $this->assertRanInOrder([
            'systemctl reload apache2',
            'mv ',
        ]);
    }

    public function test_unplug_fails_when_mv_fails(): void
    {
        $this->fakeProcesses(['mv ']);
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('Échec du déplacement')
            ->assertExitCode(1);

        $this->assertDirectoryExists($this->legacyDir());
    }

    public function test_unplug_aborts_when_a2query_is_missing(): void
    {
        $this->fakeProcesses([], ['a2query']);
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('a2query introuvable')
            ->assertExitCode(1);

        Process::assertDidntRun('a2dissite sambaedu-legacy');
        Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'mv '));
    }

    public function test_unplug_aborts_when_gpo_applications_still_applies(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);
        $this->mockGpo(LegacyGpoNeutralizationInspector::STATUS_APPLIES, 'liée racine sans blocage');

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('GPO de domaine « applications » s\'applique encore')
            ->expectsOutputToContain('JAMAIS vider/délier/supprimer la GPO')
            ->assertExitCode(1);

        Process::assertDidntRun('a2dissite sambaedu-legacy');
    }

    public function test_unplug_force_overrides_gpo_gate(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);
        $this->mockGpo(LegacyGpoNeutralizationInspector::STATUS_APPLIES, 'liée racine sans blocage');

        $this->artisan('se4:unplug', ['--force' => true])->assertExitCode(0);

        Process::assertRan('a2dissite sambaedu-legacy');
    }

    public function test_unplug_gpo_indeterminate_does_not_block(): void
    {
        // LDAP injoignable (inspecteur réel sans annuaire en test) → warning
        // affiché mais extinction non bloquée.
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('INDÉTERMINÉ')
            ->assertExitCode(0);
    }

    public function test_unplug_removes_env_scorie_and_recaches_config(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);
        file_put_contents($this->envFixture(), "APP_NAME=test\nLEGACY_CONFIG_CHANNEL_ENABLED=true\nAPP_ENV=production\n");

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('Scorie .env LEGACY_CONFIG_CHANNEL_ENABLED retirée')
            ->assertExitCode(0);

        $this->assertSame("APP_NAME=test\nAPP_ENV=production\n", file_get_contents($this->envFixture()));
        Process::assertRan(fn ($process) => str_contains($process->command, 'config:cache'));
        Process::assertRan(fn ($process) => str_starts_with($process->command, 'chown -R www-admin'));
    }

    public function test_unplug_aborts_when_legacy_path_not_configured(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        config(['sambaedu.legacy_path' => '']);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('sambaedu.legacy_path')
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_unplug_aborts_on_off_collision(): void
    {
        $this->fakeProcesses();
        Se4UnplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:unplug')
            ->expectsOutputToContain('Collision')
            ->assertExitCode(1);

        Process::assertDidntRun('a2dissite sambaedu-legacy');
    }

    // ── se4:replug ──────────────────────────────────────────────────────────

    public function test_replug_refuses_without_root(): void
    {
        $this->fakeProcesses();
        Se4ReplugCommand::$assumeRoot = false;

        $this->artisan('se4:replug')->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_replug_runs_inverse_sequence_in_order(): void
    {
        $this->fakeVhostDisabled();
        Se4ReplugCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $legacy = $this->legacyDir();
        $off = $this->offDir();

        $this->artisan('se4:replug')
            ->expectsOutputToContain('rebranché')
            ->assertExitCode(0);

        $this->assertRanInOrder([
            'mv ',
            'a2ensite sambaedu-legacy',
            'systemctl reload apache2',
        ]);
        Process::assertRan(fn ($process) => str_starts_with($process->command, 'mv ')
            && str_contains($process->command, $off)
            && str_contains($process->command, $legacy));
    }

    public function test_replug_noop_still_reloads_apache_defensively(): void
    {
        // Couvre aussi l'état post-interruption a2ensite OK / reload KO :
        // vhost actif, pas de .off → seul un reload inconditionnel converge.
        $this->fakeProcesses(); // a2query successful → vhost actif
        Se4ReplugCommand::$assumeRoot = true;
        // Pas de .off.

        $this->artisan('se4:replug')
            ->expectsOutputToContain('déjà branché')
            ->assertExitCode(0);

        Process::assertDidntRun('a2ensite sambaedu-legacy');
        Process::assertRan('systemctl reload apache2');
    }

    public function test_replug_fails_when_reload_fails(): void
    {
        $this->fakeVhostDisabled(['systemctl reload apache2']);
        Se4ReplugCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:replug')
            ->expectsOutputToContain('Échec systemctl reload apache2')
            ->assertExitCode(1);

        $this->assertRanInOrder([
            'mv ',
            'a2ensite sambaedu-legacy',
            'systemctl reload apache2',
        ]);
    }

    public function test_replug_aborts_when_a2query_is_missing(): void
    {
        $this->fakeProcesses([], ['a2query']);
        Se4ReplugCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:replug')
            ->expectsOutputToContain('a2query introuvable')
            ->assertExitCode(1);

        Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'mv '));
    }

    public function test_replug_aborts_on_collision(): void
    {
        $this->fakeVhostDisabled();
        Se4ReplugCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:replug')
            ->expectsOutputToContain('Collision')
            ->assertExitCode(1);

        Process::assertDidntRun('a2ensite sambaedu-legacy');
    }

    // ── se4:purge ───────────────────────────────────────────────────────────

    public function test_purge_refuses_without_confirm(): void
    {
        $this->fakeProcesses();
        Se4PurgeCommand::$assumeRoot = true;

        $this->artisan('se4:purge')
            ->expectsOutputToContain('--confirm')
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_purge_refuses_without_root(): void
    {
        $this->fakeProcesses();
        Se4PurgeCommand::$assumeRoot = false;

        $this->artisan('se4:purge', ['--confirm' => true])->assertExitCode(1);

        Process::assertNothingRan();
    }

    public function test_purge_refuses_when_legacy_dir_still_present(): void
    {
        $this->fakeProcesses();
        Se4PurgeCommand::$assumeRoot = true;
        @mkdir($this->legacyDir(), 0755, true);

        $this->artisan('se4:purge', ['--confirm' => true])
            ->expectsOutputToContain('extinction à blanc n\'est pas en place')
            ->assertExitCode(1);
    }

    public function test_purge_refuses_when_vhost_still_enabled(): void
    {
        $this->fakeProcesses(); // a2query successful → vhost actif
        Se4PurgeCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:purge', ['--confirm' => true])
            ->expectsOutputToContain('vhost sambaedu-legacy est encore actif')
            ->assertExitCode(1);
    }

    public function test_purge_trashes_off_dir_with_trash_cli(): void
    {
        $this->fakeVhostDisabled();
        Se4PurgeCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $off = $this->offDir();

        $this->artisan('se4:purge', ['--confirm' => true])
            ->expectsOutputToContain('corbeille')
            ->assertExitCode(0);

        Process::assertRan(fn ($process) => str_starts_with($process->command, 'trash ')
            && str_contains($process->command, $off));
    }

    public function test_purge_falls_back_to_gio_trash(): void
    {
        $this->fakeVhostDisabled(['command -v trash']);
        Se4PurgeCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $off = $this->offDir();

        $this->artisan('se4:purge', ['--confirm' => true])->assertExitCode(0);

        Process::assertRan(fn ($process) => str_starts_with($process->command, 'gio trash ')
            && str_contains($process->command, $off));
    }

    public function test_purge_aborts_when_no_trash_utility(): void
    {
        $this->fakeVhostDisabled(['command -v trash', 'command -v gio']);
        Se4PurgeCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:purge', ['--confirm' => true])
            ->expectsOutputToContain('JAMAIS de rm -rf')
            ->assertExitCode(1);

        Process::assertDidntRun(fn ($process) => str_starts_with($process->command, 'rm '));
        $this->assertDirectoryExists($this->offDir());
    }

    public function test_purge_aborts_when_a2query_is_missing(): void
    {
        $this->fakeProcesses([], ['a2query']);
        Se4PurgeCommand::$assumeRoot = true;
        @mkdir($this->offDir(), 0755, true);

        $this->artisan('se4:purge', ['--confirm' => true])
            ->expectsOutputToContain('a2query introuvable')
            ->assertExitCode(1);

        $this->assertDirectoryExists($this->offDir());
    }

    public function test_purge_is_noop_when_off_dir_absent(): void
    {
        $this->fakeVhostDisabled();
        Se4PurgeCommand::$assumeRoot = true;

        $this->artisan('se4:purge', ['--confirm' => true])
            ->expectsOutputToContain('Rien à purger')
            ->assertExitCode(0);
    }
}
