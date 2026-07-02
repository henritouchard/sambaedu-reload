<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Acl;

use App\Services\Filesystem\Acl\AclFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Epic 34 — Tests PURS de {@see AclFormat} (aucune I/O, aucun conteneur).
 *
 * Cœur du point piégeux : le raccourci setfacl (`rx`) et la sortie getfacl
 * (`r-x`) doivent se comparer égaux après normalisation.
 */
class AclFormatTest extends TestCase
{
    #[Test]
    public function normalize_mode_canonicalises_setfacl_shorthand_and_getfacl_output(): void
    {
        self::assertSame('r-x', AclFormat::normalizeMode('rx'));
        self::assertSame('r-x', AclFormat::normalizeMode('r-x'));
        self::assertSame('rwx', AclFormat::normalizeMode('rwx'));
        self::assertSame('rw-', AclFormat::normalizeMode('rw'));
        self::assertSame('---', AclFormat::normalizeMode('---'));
        self::assertSame('---', AclFormat::normalizeMode('-'));
        self::assertSame('--x', AclFormat::normalizeMode('x'));
    }

    #[Test]
    public function normalize_line_canonicalises_only_the_mode(): void
    {
        self::assertSame('group:classe_6a:r-x', AclFormat::normalizeLine('group:classe_6a:rx'));
        self::assertSame('default:group:equipe_6a:rwx', AclFormat::normalizeLine('default:group:equipe_6a:rwx'));
        self::assertSame('user::rwx', AclFormat::normalizeLine('user::rwx'));
        self::assertSame('group:domain\040admins:rwx', AclFormat::normalizeLine('group:domain\040admins:rwx'));
    }

    #[Test]
    public function normalize_line_drops_comments_and_blanks(): void
    {
        self::assertSame('', AclFormat::normalizeLine('# file: foo'));
        self::assertSame('', AclFormat::normalizeLine('   '));
    }

    #[Test]
    public function shorthand_and_getfacl_output_compare_equal_as_sets(): void
    {
        // Set DÉSIRÉ (raccourci builder) vs set EFFECTIF (sortie getfacl).
        $desired = ['group:classe_6a:rx', 'user::rwx', 'other::---'];
        $effective = ['user::rwx', 'other::---', 'group:classe_6a:r-x'];

        self::assertSame(
            AclFormat::normalizeSet($desired),
            AclFormat::normalizeSet($effective),
        );
    }

    #[Test]
    public function parse_entries_splits_default_type_qualifier_and_mode(): void
    {
        $raw = <<<TXT
        user::rwx
        group:classe_6a:r-x
        group:domain\\040admins:rwx
        mask::rwx
        other::---
        default:user::rwx
        default:group:equipe_6a:r-x
        TXT;

        $entries = AclFormat::parseEntries($raw);

        // user:: (base)
        self::assertSame(['default' => false, 'type' => 'user', 'qualifier' => null, 'mode' => 'rwx', 'raw' => 'user::rwx'], $entries[0]);
        // named group
        self::assertSame('group', $entries[1]['type']);
        self::assertSame('classe_6a', $entries[1]['qualifier']);
        self::assertSame('r-x', $entries[1]['mode']);
        self::assertFalse($entries[1]['default']);
        // escaped qualifier preserved raw
        self::assertSame('domain\040admins', $entries[2]['qualifier']);
        // default entry flagged
        $lastDefault = $entries[array_key_last($entries)];
        self::assertTrue($lastDefault['default']);
        self::assertSame('equipe_6a', $lastDefault['qualifier']);
    }

    #[Test]
    public function mode_to_access_maps_rw_ro_and_null(): void
    {
        self::assertSame('rw', AclFormat::modeToAccess('rwx'));
        self::assertSame('rw', AclFormat::modeToAccess('rw'));
        self::assertSame('ro', AclFormat::modeToAccess('r-x'));
        self::assertSame('ro', AclFormat::modeToAccess('r'));
        self::assertNull(AclFormat::modeToAccess('---'));
        self::assertNull(AclFormat::modeToAccess('--x')); // exécution seule : non représentable
    }

    #[Test]
    public function unescape_restores_spaces(): void
    {
        self::assertSame('domain admins', AclFormat::unescape('domain\040admins'));
    }
}
