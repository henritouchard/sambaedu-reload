<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Services\ApplicationScriptsAssembler;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC4.2 (sécurité injection substitutions, audit F3 adressé).
 *
 * Vérifie que `ApplicationScriptsAssembler::applySubstitutions()` :
 *  - applique les clés whitelistées (`SE4FS_NAME`, etc.)
 *  - ignore les clés hors whitelist (no-op + warning loggué)
 *  - n'accepte aucun input user (`machine`, `user`, `action`) comme clé.
 */
class SubstitutionsTest extends TestCase
{
    private ApplicationScriptsAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sambaedu.se4fs_name', 'se4fs-test');
        config()->set('sambaedu.domain', 'test.local');
        config()->set('sambaedu.uai', '0000000A');
        config()->set('sambaedu.samba_domain', 'TESTDOM');
        // Force la lecture du fichier de config statique (clé non mergée
        // dans `config/sambaedu.php`).
        config()->set('sambaedu.gpo.applications.substitutions.whitelist', [
            'SE4FS_NAME' => static fn(): string => 'se4fs-test',
            'DOMAIN' => static fn(): string => 'test.local',
            'UAI' => static fn(): string => '0000000A',
            'SAMBA_DOMAIN' => static fn(): string => 'TESTDOM',
            'TMP_DIR' => static fn(): string => '/tmp',
        ]);
        $this->assembler = new ApplicationScriptsAssembler();
    }

    #[Test]
    public function it_substitutes_whitelisted_keys(): void
    {
        $tpl = 'curl http://###_SE4FS_NAME_###.###_DOMAIN_###/foo';
        $out = $this->assembler->applySubstitutions($tpl);
        self::assertSame('curl http://se4fs-test.test.local/foo', $out);
    }

    #[Test]
    public function it_does_not_substitute_unwhitelisted_keys(): void
    {
        $tpl = 'echo ###_USER_HOMEDIR_### ###_SE4FS_NAME_###';
        $out = $this->assembler->applySubstitutions($tpl);
        self::assertSame('echo ###_USER_HOMEDIR_### se4fs-test', $out);
    }

    #[Test]
    public function it_does_not_inject_via_user_controlled_placeholder(): void
    {
        // Un attaquant qui pourrait injecter un placeholder via un nom de
        // machine ou user ne doit PAS pouvoir provoquer de substitution :
        // les regex Controller bloquent en amont, et la whitelist statique
        // ici en aval.
        $tpl = 'echo ###_machine_### ###_user_### ###_$(rm -rf /)_###';
        $out = $this->assembler->applySubstitutions($tpl);
        self::assertSame($tpl, $out); // aucune substitution, output identique
    }

    #[Test]
    public function it_handles_empty_template(): void
    {
        self::assertSame('', $this->assembler->applySubstitutions(''));
    }

    #[Test]
    public function it_skips_resolvers_returning_null(): void
    {
        config()->set('sambaedu.gpo.applications.substitutions.whitelist', [
            'SE4FS_NAME' => static fn(): string => 'se4fs-test',
            'EMPTY_VAR' => static fn(): ?string => null,
        ]);
        $assembler = new ApplicationScriptsAssembler();
        $tpl = '###_SE4FS_NAME_###/###_EMPTY_VAR_###';
        $out = $assembler->applySubstitutions($tpl);
        // EMPTY_VAR doit rester inchangé (resolver retourne null).
        self::assertStringContainsString('se4fs-test', $out);
        self::assertStringContainsString('###_EMPTY_VAR_###', $out);
    }
}
