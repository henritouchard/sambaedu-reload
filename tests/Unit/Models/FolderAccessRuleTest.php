<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\FolderAccessRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 36.4 — dérivation du trustee (D9, piège #4). Le nom SQL est FOLDÉ au nom
 * nu ; le payload doit porter le CN AD que la LSA du poste résout.
 */
class FolderAccessRuleTest extends TestCase
{
    #[Test]
    public function it_derives_the_cn_of_the_leftmost_rdn(): void
    {
        self::assertSame(
            'Classe_3A',
            FolderAccessRule::deriveTrustee('CN=Classe_3A,OU=Groups,DC=etab,DC=lan', '3A'),
        );
    }

    #[Test]
    public function it_keeps_an_escaped_comma_inside_the_cn(): void
    {
        // Correction review #4 : `CN=Salle B\, annexe,OU=Groups` (RFC 4514) — la
        // virgule échappée fait PARTIE du CN, elle ne le termine pas.
        self::assertSame(
            'Salle B, annexe',
            FolderAccessRule::deriveTrustee('CN=Salle B\\, annexe,OU=Groups', 'Salle B annexe'),
        );
    }

    #[Test]
    public function it_falls_back_to_the_bare_name_without_ad_dn(): void
    {
        self::assertSame('Profs', FolderAccessRule::deriveTrustee(null, 'Profs'));
        self::assertSame('Profs', FolderAccessRule::deriveTrustee('', 'Profs'));
    }
}
