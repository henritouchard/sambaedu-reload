<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Dto\GpoLink;
use App\Gpo\Services\GpoService;
use App\Gpo\Support\SambaToolRunner;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakesGpoService;
use Tests\TestCase;

/**
 * Story 16.5 — AC6.1 / Volet 6.
 *
 * Tests unitaires des méthodes d'écriture {@see GpoService::setLink},
 * `removeLink`, `setInheritance` et `reorderLinks` (Story 16.5).
 *
 * Stratégie : `Process::fake()` Laravel sur `SambaToolRunner` — `SambaToolRunner`
 * est final et non-mockable sans uopz/runkit (pattern iso Story 16.7
 * `AdMachineManagerTest`).
 *
 * Couvre :
 * - succès / flags `--enforce` / `--disable`
 * - idempotence (already exists / does not exist)
 * - validation regex GUID + DN AVANT toute exec (AC5.1 / shouldNotReceive)
 * - reorderLinks succès complet + rollback partiel
 */
class GpoServiceWriteTest extends TestCase
{
    private const VALID_GUID_A = '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}';
    private const VALID_GUID_B = '{12345678-1234-1234-1234-123456789012}';
    private const VALID_DN = 'OU=Salles,DC=example,DC=org';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
        config()->set('sambaedu.gpo.kerb_option', '');
        config()->set('sambaedu.gpo.samba_tool_timeout', 30);
    }

    private function makeService(): GpoService
    {
        return FakesGpoService::makeService();
    }

    // =====================================================================
    // AC1.1 — setLink
    // =====================================================================

    #[Test]
    public function set_link_invokes_samba_tool_setlink_with_basic_args(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        self::assertTrue($this->makeService()->setLink(self::VALID_DN, self::VALID_GUID_A));

        Process::assertRan(function ($p) {
            $cmd = is_array($p->command) ? $p->command : [];
            return in_array('gpo', $cmd, true)
                && in_array('setlink', $cmd, true)
                && in_array(self::VALID_DN, $cmd, true)
                && in_array(self::VALID_GUID_A, $cmd, true)
                && ! in_array('--enforce', $cmd, true)
                && ! in_array('--disable', $cmd, true);
        });
    }

    #[Test]
    public function set_link_adds_enforce_flag_when_requested(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        $this->makeService()->setLink(self::VALID_DN, self::VALID_GUID_A, enforce: true);

        Process::assertRan(fn ($p) => is_array($p->command) && in_array('--enforce', $p->command, true));
    }

    #[Test]
    public function set_link_adds_disable_flag_when_requested(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        $this->makeService()->setLink(self::VALID_DN, self::VALID_GUID_A, disable: true);

        Process::assertRan(fn ($p) => is_array($p->command) && in_array('--disable', $p->command, true));
    }

    #[Test]
    public function set_link_treats_already_linked_as_idempotent_success(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'ERROR: GPO is already linked to this container', exitCode: 1),
        ]);

        self::assertTrue($this->makeService()->setLink(self::VALID_DN, self::VALID_GUID_A));
    }

    #[Test]
    public function set_link_throws_runtime_exception_on_other_failure(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'NT_STATUS_ACCESS_DENIED', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('samba-tool gpo setlink failed');

        $this->makeService()->setLink(self::VALID_DN, self::VALID_GUID_A);
    }

    #[Test]
    public function set_link_rejects_malformed_guid_before_side_effect(): void
    {
        Process::fake();

        try {
            $this->makeService()->setLink(self::VALID_DN, 'INJECTION_ATTACK');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException) {
            // OK
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function set_link_rejects_malformed_dn_before_side_effect(): void
    {
        Process::fake();

        try {
            $this->makeService()->setLink('; rm -rf /', self::VALID_GUID_A);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException) {
            // OK
        }
        try {
            $this->makeService()->setLink('', self::VALID_GUID_A);
            $this->fail('InvalidArgumentException expected on empty DN');
        } catch (InvalidArgumentException) {
            // OK
        }

        Process::assertNothingRan();
    }

    // =====================================================================
    // AC1.2 — removeLink
    // =====================================================================

    #[Test]
    public function remove_link_invokes_samba_tool_dellink(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        self::assertTrue($this->makeService()->removeLink(self::VALID_DN, self::VALID_GUID_A));

        Process::assertRan(fn ($p) => is_array($p->command)
            && in_array('dellink', $p->command, true)
            && in_array(self::VALID_DN, $p->command, true));
    }

    #[Test]
    public function remove_link_treats_not_linked_as_idempotent_success(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'ERROR: GPO does not exist in container links', exitCode: 1),
        ]);

        self::assertTrue($this->makeService()->removeLink(self::VALID_DN, self::VALID_GUID_A));
    }

    #[Test]
    public function remove_link_throws_on_real_failure(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'NT_STATUS_ACCESS_DENIED', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);

        $this->makeService()->removeLink(self::VALID_DN, self::VALID_GUID_A);
    }

    #[Test]
    public function remove_link_rejects_malformed_guid_before_side_effect(): void
    {
        Process::fake();

        try {
            $this->makeService()->removeLink(self::VALID_DN, 'NOT-A-GUID');
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException) {
            // OK
        }

        Process::assertNothingRan();
    }

    // =====================================================================
    // AC1.3 — setInheritance
    // =====================================================================

    #[Test]
    public function set_inheritance_passes_inherit_when_enabled(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        self::assertTrue($this->makeService()->setInheritance(self::VALID_DN, true));

        Process::assertRan(fn ($p) => is_array($p->command)
            && in_array('setinheritance', $p->command, true)
            && in_array('inherit', $p->command, true));
    }

    #[Test]
    public function set_inheritance_passes_block_when_disabled(): void
    {
        Process::fake(['*' => Process::result(output: '', exitCode: 0)]);

        self::assertTrue($this->makeService()->setInheritance(self::VALID_DN, false));

        Process::assertRan(fn ($p) => is_array($p->command)
            && in_array('setinheritance', $p->command, true)
            && in_array('block', $p->command, true));
    }

    #[Test]
    public function set_inheritance_throws_on_failure(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'NT_STATUS_ACCESS_DENIED', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);

        $this->makeService()->setInheritance(self::VALID_DN, true);
    }

    #[Test]
    public function set_inheritance_rejects_malformed_dn(): void
    {
        Process::fake();

        try {
            $this->makeService()->setInheritance('not a dn at all', true);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException) {
            // OK
        }

        Process::assertNothingRan();
    }

    // =====================================================================
    // AC1.4 — reorderLinks
    // =====================================================================

    #[Test]
    public function reorder_links_succeeds_when_all_steps_pass(): void
    {
        // Sequence : 1 getlink, 2 dellink, 2 setlink → tous OK.
        $getLinkOutput = FakesGpoService::getLinkOutput(); // GUID_DEFAULT + GUID_AAAA enforced
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(output: $getLinkOutput, exitCode: 0))   // getlink
                ->push(Process::result(output: '', exitCode: 0))                // dellink 1
                ->push(Process::result(output: '', exitCode: 0))                // dellink 2
                ->push(Process::result(output: '', exitCode: 0))                // setlink (réordonné)
                ->push(Process::result(output: '', exitCode: 0)),               // setlink (réordonné)
        ]);

        $service = $this->makeService();

        // L'ordre cible inverse les 2 GPOs présents dans getLinkOutput.
        $orderTarget = [
            self::VALID_GUID_A, // {AAAA...} d'abord
            '{31B2F340-016D-11D2-945F-00C04FB984F9}', // default Domain Policy ensuite
        ];

        self::assertTrue($service->reorderLinks(self::VALID_DN, $orderTarget));
    }

    #[Test]
    public function reorder_links_rejects_guid_not_currently_linked(): void
    {
        $getLinkOutput = FakesGpoService::getLinkOutput();
        Process::fake([
            '*' => Process::result(output: $getLinkOutput, exitCode: 0),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('n\'est pas actuellement liée');

        $this->makeService()->reorderLinks(self::VALID_DN, [self::VALID_GUID_B]);
    }

    #[Test]
    public function reorder_links_rolls_back_when_setlink_fails_mid_way(): void
    {
        // Setup : 2 GPOs liées initialement. dellink OK, setlink premier OK,
        // setlink deuxième KO → rollback (dellink applied + re-setlink initial).
        $getLinkOutput = FakesGpoService::getLinkOutput();
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(output: $getLinkOutput, exitCode: 0))   // getLinks
                ->push(Process::result(output: '', exitCode: 0))                // dellink 1
                ->push(Process::result(output: '', exitCode: 0))                // dellink 2
                ->push(Process::result(output: '', exitCode: 0))                // setlink (cible) #1 OK
                ->push(Process::result(output: '', errorOutput: 'NT_STATUS_FAIL', exitCode: 1)) // setlink #2 KO
                // rollback : dellink applied (1 lien) + setlink initial (2 liens)
                ->push(Process::result(output: '', exitCode: 0))                // dellink applied #1
                ->push(Process::result(output: '', exitCode: 0))                // setlink initial #1
                ->push(Process::result(output: '', exitCode: 0)),               // setlink initial #2
        ]);

        $orderTarget = [
            self::VALID_GUID_A,
            '{31B2F340-016D-11D2-945F-00C04FB984F9}',
        ];

        // false = rollback réussi (état initial restauré).
        self::assertFalse($this->makeService()->reorderLinks(self::VALID_DN, $orderTarget));
    }

    #[Test]
    public function reorder_links_rejects_malformed_guid_in_target_order(): void
    {
        Process::fake();

        try {
            $this->makeService()->reorderLinks(self::VALID_DN, ['NOT-A-GUID']);
            $this->fail('InvalidArgumentException expected');
        } catch (InvalidArgumentException) {
            // OK
        }

        Process::assertNothingRan();
    }

    // =====================================================================
    // Story 16.5 review #S3 — Garde permutation complète
    // =====================================================================

    #[Test]
    public function reorder_links_rejects_truncated_list(): void
    {
        // getLink retourne 2 liens — passer 1 seul GUID provoquerait une
        // suppression silencieuse de l'autre. Le service doit refuser.
        $getLinkOutput = FakesGpoService::getLinkOutput();
        Process::fake([
            '*' => Process::result(output: $getLinkOutput, exitCode: 0),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('permutation complète');

        // On passe UN SEUL des 2 GUIDs initialement liés.
        $this->makeService()->reorderLinks(self::VALID_DN, [self::VALID_GUID_A]);
    }

    #[Test]
    public function reorder_links_rejects_list_with_foreign_guid(): void
    {
        // getLink retourne 2 liens — passer un GUID étranger doit échouer
        // sur le check "GPO non liée" en amont (et donc pas atteindre la
        // garde permutation, mais on couvre quand même la défense).
        $getLinkOutput = FakesGpoService::getLinkOutput();
        Process::fake([
            '*' => Process::result(output: $getLinkOutput, exitCode: 0),
        ]);

        $this->expectException(InvalidArgumentException::class);

        // 2 GUIDs dont 1 étranger — taille égale mais GUID inconnu.
        $this->makeService()->reorderLinks(self::VALID_DN, [
            self::VALID_GUID_A,
            self::VALID_GUID_B, // pas dans getLinkOutput
        ]);
    }
}
