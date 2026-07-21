<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Network;

use App\Services\Network\Data\DnsUpdateOutcome;
use App\Services\Network\DnsRecordService;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 8.4 — DDNS piloté par DHCP, level-triggered.
 *
 * `samba-tool` est faké au niveau `Process` (et non du runner) pour couvrir
 * aussi la commande réellement construite : c'est le seul moyen de prouver
 * qu'aucune écriture ne part sur un renouvellement de bail.
 */
class DnsRecordServiceTest extends TestCase
{
    private const SERVER = 'se4ad.test.fr';

    private const ZONE = 'test.fr';

    /** @var list<array<int, string>> Commandes samba-tool exécutées, dans l'ordre. */
    private array $ran = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sambaedu.se4ad_name', self::SERVER);
        config()->set('sambaedu.domain', self::ZONE);
        config()->set('sambaedu.etab_ou', '');
        config()->set('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
    }

    /**
     * Fake `samba-tool` : les `dns query <server> <zone> <name> A` répondent
     * selon `$zoneState` (nom => liste d'IP), les écritures répondent OK.
     *
     * @param  array<string, list<string>>  $zoneState
     */
    private function fakeSambaTool(array $zoneState, bool $writesFail = false, bool $queriesUnreadable = false): void
    {
        $this->ran = [];

        Process::fake(function ($process) use ($zoneState, $writesFail, $queriesUnreadable) {
            $cmd = $process->command;

            if (! is_array($cmd)) {
                return Process::result('', '', 1);
            }

            $this->ran[] = $cmd;

            // ['/usr/bin/samba-tool', 'dns', <verbe>, <server>, <zone>, <name>, 'A', …]
            $verb = $cmd[2] ?? '';
            $name = $cmd[5] ?? '';

            if ($verb !== 'query') {
                return $writesFail
                    ? Process::result('', 'accès refusé', 1)
                    : Process::result('Record added successfully', '', 0);
            }

            // Panne de lecture (DC injoignable, auth refusée, timeout) : sortie
            // non-zéro SANS le marqueur « does not exist ».
            if ($queriesUnreadable) {
                return Process::result('', 'ERROR: Failed to connect to DC: NT_STATUS_IO_TIMEOUT', 1);
            }

            if ($name === '@') {
                return Process::result($this->renderZoneDump($zoneState), '', 0);
            }

            $addresses = $zoneState[$name] ?? null;

            // Nom absent : samba-tool sort en non-zéro (« Record or zone does
            // not exist ») — c'est l'état « aucun record », pas une panne.
            return $addresses === null
                ? Process::result('', 'ERROR: Record or zone does not exist.', 1)
                : Process::result($this->renderRecordDump($name, $addresses), '', 0);
        });
    }

    /** @param  list<string>  $addresses */
    private function renderRecordDump(string $name, array $addresses): string
    {
        $out = sprintf("  Name=%s, Records=%d, Children=0\n", $name, count($addresses));
        foreach ($addresses as $address) {
            $out .= sprintf("    A: %s (flags=f0, serial=110, ttl=900)\n", $address);
        }

        return $out;
    }

    /** @param  array<string, list<string>>  $zoneState */
    private function renderZoneDump(array $zoneState): string
    {
        $out = '';
        foreach ($zoneState as $name => $addresses) {
            $out .= $this->renderRecordDump($name, $addresses);
        }

        return $out;
    }

    /** Commandes d'écriture (tout sauf les lectures `dns query`). */
    private function writeCommands(): array
    {
        return array_values(array_filter($this->ran, fn (array $cmd): bool => ($cmd[2] ?? '') !== 'query'));
    }

    private function service(): DnsRecordService
    {
        return app(DnsRecordService::class);
    }

    /**
     * Le suffixe d'établissement est lu depuis `SambaEduConfig` (donc
     * `/etc/sambaedu/sambaedu.conf`), PAS depuis `config('sambaedu.etab_ou')`
     * qui n'est hydraté par rien sur une instance réelle.
     */
    private function bindEstablishmentUai(string $uai): void
    {
        $this->mock(\App\Config\SambaEduConfig::class, function ($mock) use ($uai) {
            $mock->shouldReceive('get')->with('etab_ou', '')->andReturn($uai);
            $mock->shouldReceive('get')->andReturnUsing(
                fn (string $key, mixed $default = null): mixed => $default,
            );
        });
    }

    // ── Le test pivot de la story ───────────────────────────────────────────

    /**
     * Renouvellement de bail (~toutes les 5 min et par poste) : l'état DNS est
     * déjà conforme → AUCUNE commande d'écriture ne doit partir. C'est le
     * défaut legacy que la story corrige.
     */
    #[Test]
    public function add_on_identical_state_writes_nothing(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103']]);

        $outcome = $this->service()->apply('add', 'pc-salle-01', '192.168.122.103');

        $this->assertSame(DnsUpdateOutcome::UNCHANGED, $outcome);
        $this->assertFalse($outcome->isWrite());
        $this->assertSame([], $this->writeCommands(), 'Un renouvellement de bail ne doit produire aucune écriture DNS.');
    }

    /** Cent renouvellements successifs restent à zéro écriture. */
    #[Test]
    public function repeated_renewals_never_write(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103']]);

        for ($i = 0; $i < 100; $i++) {
            $this->service()->apply('add', 'pc-salle-01', '192.168.122.103');
        }

        $this->assertSame([], $this->writeCommands());
    }

    // ── add ─────────────────────────────────────────────────────────────────

    #[Test]
    public function add_creates_record_when_name_is_absent(): void
    {
        $this->fakeSambaTool([]);

        $outcome = $this->service()->apply('add', 'pc-neuf-02', '192.168.122.50');

        $this->assertSame(DnsUpdateOutcome::CREATED, $outcome);
        $this->assertCount(1, $this->writeCommands());
        $this->assertSame(
            ['/usr/bin/samba-tool', 'dns', 'add', self::SERVER, self::ZONE, 'pc-neuf-02', 'A', '192.168.122.50'],
            array_slice($this->writeCommands()[0], 0, 8),
        );
    }

    /** Changement d'IP : purge du A périmé PUIS ajout (parité legacy). */
    #[Test]
    public function add_replaces_stale_address_when_ip_changed(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103']]);

        $outcome = $this->service()->apply('add', 'pc-salle-01', '192.168.122.200');

        $this->assertSame(DnsUpdateOutcome::UPDATED, $outcome);

        $writes = $this->writeCommands();
        $this->assertCount(2, $writes);
        $this->assertSame('delete', $writes[0][2]);
        $this->assertSame('192.168.122.103', $writes[0][7]);
        $this->assertSame('add', $writes[1][2]);
        $this->assertSame('192.168.122.200', $writes[1][7]);
    }

    /** Doublons (A multiples dont le bon) : purge des surnuméraires, pas de ré-ajout. */
    #[Test]
    public function add_purges_duplicates_without_re_adding_current_ip(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103', '192.168.122.199']]);

        $outcome = $this->service()->apply('add', 'pc-salle-01', '192.168.122.103');

        $this->assertSame(DnsUpdateOutcome::UPDATED, $outcome);

        $writes = $this->writeCommands();
        $this->assertCount(1, $writes);
        $this->assertSame('delete', $writes[0][2]);
        $this->assertSame('192.168.122.199', $writes[0][7]);
    }

    #[Test]
    public function add_reports_failed_when_samba_tool_write_fails(): void
    {
        $this->fakeSambaTool([], writesFail: true);

        $outcome = $this->service()->apply('add', 'pc-neuf-02', '192.168.122.50');

        $this->assertSame(DnsUpdateOutcome::FAILED, $outcome);
        $this->assertFalse($outcome->isWrite());
    }

    // ── delete ──────────────────────────────────────────────────────────────

    #[Test]
    public function delete_removes_matching_record_by_name(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103']]);

        $outcome = $this->service()->apply('delete', 'pc-salle-01', '192.168.122.103');

        $this->assertSame(DnsUpdateOutcome::DELETED, $outcome);
        $this->assertCount(1, $this->writeCommands());
        $this->assertSame('delete', $this->writeCommands()[0][2]);
    }

    #[Test]
    public function delete_writes_nothing_when_no_record_matches(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103']]);

        $outcome = $this->service()->apply('delete', 'pc-salle-01', '192.168.122.222');

        $this->assertSame(DnsUpdateOutcome::UNCHANGED, $outcome);
        $this->assertSame([], $this->writeCommands());
    }

    /**
     * `on release` / `on expiry` : dhcpd ne transmet PAS le nom. Le nom porteur
     * de l'IP est retrouvé par balayage de zone (le legacy s'appuyait sur un
     * `gethostbyaddr()` inopérant faute de PTR — nettoyage jamais effectué).
     */
    #[Test]
    public function delete_without_name_resolves_holder_via_zone_scan(): void
    {
        $this->fakeSambaTool([
            'pc-salle-01' => ['192.168.122.103'],
            'pc-salle-02' => ['192.168.122.104'],
        ]);

        $outcome = $this->service()->apply('delete', '', '192.168.122.104');

        $this->assertSame(DnsUpdateOutcome::DELETED, $outcome);

        $writes = $this->writeCommands();
        $this->assertCount(1, $writes);
        $this->assertSame('pc-salle-02', $writes[0][5]);
        $this->assertSame('192.168.122.104', $writes[0][7]);
    }

    #[Test]
    public function delete_without_name_writes_nothing_when_ip_is_unknown(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.103']]);

        $outcome = $this->service()->apply('delete', '', '192.168.122.250');

        $this->assertSame(DnsUpdateOutcome::UNCHANGED, $outcome);
        $this->assertSame([], $this->writeCommands());
    }

    // ── Garde-fous (AC3) ────────────────────────────────────────────────────

    /** @return list<array{0: string}> */
    public static function ignoredNameProvider(): array
    {
        return [['l-poste12'], ['dhcp-192-168-1-5'], ['iphone-de-paul']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ignoredNameProvider')]
    #[Test]
    public function ignored_name_prefixes_are_skipped(string $name): void
    {
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('add', $name, '192.168.122.10'));
        $this->assertSame([], $this->ran, 'Un nom écarté ne doit même pas être interrogé.');
    }

    /**
     * Une lecture d'état IMPOSSIBLE (DC injoignable) ne doit JAMAIS être
     * confondue avec « aucun record » : sinon un `add` partirait sur un nom
     * déjà correct — échec logué toutes les 5 min, ou second A record et
     * résolution en round-robin. Panne ⇒ abandon, zéro écriture.
     */
    #[Test]
    public function unreadable_dns_state_aborts_without_writing(): void
    {
        $this->fakeSambaTool(['pc-01' => ['192.168.122.10']], queriesUnreadable: true);

        $this->assertSame(DnsUpdateOutcome::FAILED, $this->service()->apply('add', 'pc-01', '192.168.122.10'));
        $this->assertSame([], $this->writeCommands(), 'Une panne de lecture ne doit produire aucune écriture.');
    }

    #[Test]
    public function unreadable_dns_state_aborts_delete_too(): void
    {
        $this->fakeSambaTool(['pc-01' => ['192.168.122.10']], queriesUnreadable: true);

        $this->assertSame(DnsUpdateOutcome::FAILED, $this->service()->apply('delete', 'pc-01', '192.168.122.10'));
        $this->assertSame([], $this->writeCommands());
    }

    /**
     * Le balayage de zone du `delete` sans nom rend des données d'annuaire,
     * pas des entrées de confiance : elles doivent traverser les MÊMES
     * garde-fous, sinon on refuserait de créer le A d'un `l-*` mais on
     * accepterait de supprimer le sien — et un `on expiry` sur une IP réservée
     * pourrait effacer l'enregistrement d'un serveur.
     */
    #[Test]
    public function scanned_names_go_through_the_same_guards(): void
    {
        $this->fakeSambaTool([
            'l-terminal7' => ['192.168.122.50'],
            'iphone-de-paul' => ['192.168.122.50'],
        ]);

        $this->assertSame(DnsUpdateOutcome::UNCHANGED, $this->service()->apply('delete', '', '192.168.122.50'));
        $this->assertSame([], $this->writeCommands(), 'Aucun nom écarté ne doit être supprimé par le balayage.');
    }

    /**
     * Régression constatée en vérification réelle (2026-07-21) : un `delete`
     * sans nom sur l'IP du DC balayait la zone, trouvait `se4ad` et supprimait
     * son A record — le domaine cessait de résoudre. Les serveurs peuvent
     * avoir une réservation DHCP, donc un `on expiry` réel suffit.
     */
    #[Test]
    public function infrastructure_records_are_never_deleted_by_scan(): void
    {
        config()->set('sambaedu.se4ad_name', 'se4ad');
        config()->set('sambaedu.se4fs_name', 'se4fs');
        $this->fakeSambaTool(['se4ad' => ['192.168.122.60']]);

        $this->assertSame(DnsUpdateOutcome::UNCHANGED, $this->service()->apply('delete', '', '192.168.122.60'));
        $this->assertSame([], $this->writeCommands(), 'Le record du DC ne doit JAMAIS être supprimé par ce canal.');
    }

    #[Test]
    public function infrastructure_records_are_not_touched_by_add_either(): void
    {
        config()->set('sambaedu.se4ad_name', 'se4ad');
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('add', 'se4ad', '192.168.122.99'));
        $this->assertSame([], $this->ran, 'Un nom d\'infrastructure ne doit même pas être interrogé.');
    }

    #[Test]
    public function scanned_eligible_name_is_still_deleted(): void
    {
        $this->fakeSambaTool(['pc-salle2' => ['192.168.122.50']]);

        $this->assertSame(DnsUpdateOutcome::DELETED, $this->service()->apply('delete', '', '192.168.122.50'));
        $this->assertCount(1, $this->writeCommands());
    }

    /** Suffixe d'établissement : UAI `0991229y` → `-1229y` (port `etab_suffix()`). */
    #[Test]
    public function names_outside_establishment_are_skipped(): void
    {
        $this->bindEstablishmentUai('0991229y');
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('add', 'pc-autre-etab', '192.168.122.10'));
        $this->assertSame([], $this->ran);
    }

    #[Test]
    public function names_inside_establishment_are_processed(): void
    {
        $this->bindEstablishmentUai('0991229y');
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::CREATED, $this->service()->apply('add', 'pc-01-1229y', '192.168.122.10'));
    }

    /** @return list<array{0: string}> */
    public static function injectionNameProvider(): array
    {
        return [
            ['pc-01; rm -rf /'],
            ['pc-01 && reboot'],
            ['pc$(whoami)'],
            ["pc-01\nmalicious"],
            ['pc 01'],
        ];
    }

    /**
     * Le `client-hostname` d'un bail est choisi par le client : tout nom
     * dangereux doit être refusé AVANT samba-tool (le legacy concaténait
     * la commande — vecteur d'injection `samba-tool.inc.php:54`).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('injectionNameProvider')]
    #[Test]
    public function shell_injection_attempts_are_rejected(string $name): void
    {
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('add', $name, '192.168.122.10'));
        $this->assertSame([], $this->ran);
    }

    /** @return list<array{0: string}> */
    public static function invalidIpProvider(): array
    {
        return [[''], ['999.1.1.1'], ['not-an-ip'], ['192.168.1.5; id'], ['::1']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIpProvider')]
    #[Test]
    public function invalid_ip_is_skipped(string $ip): void
    {
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('add', 'pc-salle-01', $ip));
        $this->assertSame([], $this->ran);
    }

    #[Test]
    public function unknown_action_is_skipped(): void
    {
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('flush', 'pc-salle-01', '192.168.122.10'));
        $this->assertSame([], $this->ran);
    }

    /** Instance non configurée : ne rien tenter plutôt que cibler une zone vide. */
    #[Test]
    public function unconfigured_instance_is_skipped(): void
    {
        config()->set('sambaedu.domain', '');
        $this->fakeSambaTool([]);

        $this->assertSame(DnsUpdateOutcome::SKIPPED, $this->service()->apply('add', 'pc-salle-01', '192.168.122.10'));
        $this->assertSame([], $this->ran);
    }

    /** Aucun PTR n'est touché — parité legacy (appels commentés). */
    #[Test]
    public function no_ptr_record_is_ever_written(): void
    {
        $this->fakeSambaTool([]);

        $this->service()->apply('add', 'pc-salle-01', '192.168.122.10');
        $this->service()->apply('delete', 'pc-salle-01', '192.168.122.10');

        foreach ($this->ran as $cmd) {
            $this->assertNotContains('PTR', $cmd);
        }
    }

    /** Le nom est normalisé en minuscules avant d'atteindre samba-tool. */
    #[Test]
    public function name_is_lowercased_before_samba_tool(): void
    {
        $this->fakeSambaTool([]);

        $this->service()->apply('add', 'PC-Salle-01', '192.168.122.10');

        $this->assertSame('pc-salle-01', $this->writeCommands()[0][5]);
    }

    /** Aucune commande ne doit porter `-H` (rejeté par `samba-tool dns`). */
    #[Test]
    public function no_command_carries_directory_url_option(): void
    {
        $this->fakeSambaTool(['pc-salle-01' => ['192.168.122.1']]);

        $this->service()->apply('add', 'pc-salle-01', '192.168.122.10');

        $this->assertNotEmpty($this->ran);
        foreach ($this->ran as $cmd) {
            $this->assertNotContains('-H', $cmd);
        }
    }
}
