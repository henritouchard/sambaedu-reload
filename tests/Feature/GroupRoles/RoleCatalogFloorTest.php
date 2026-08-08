<?php

declare(strict_types=1);

namespace Tests\Feature\GroupRoles;

use App\Models\Pivot\UserGroupUserPivot;
use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Support\RoleCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.1 — LE PLANCHER, sur une base où la table n'existe MÊME PAS.
 *
 * Ce cas n'est pas théorique : une bonne partie de la suite fabrique son schéma à
 * la main, sans migrations, et une instance en cours de mise à jour est dans le
 * même état pendant quelques secondes. La garde d'arête, la validation d'une
 * recette et le résolveur de plan sont sollicités là aussi.
 *
 * Ce que ce fichier épingle : **le vocabulaire minimal ne disparaît jamais**, et
 * le repli ne fait qu'ÉTROITIR — il n'autorise rien de plus.
 *
 * Pas de `RefreshDatabase` : c'est le but. La table `group_roles` est absente.
 */
class RoleCatalogFloorTest extends TestCase
{
    #[Test]
    public function an_unmigrated_database_still_yields_the_three_historical_keys(): void
    {
        $this->assertSame(['member', 'manager', 'owner'], RoleCatalog::keys());
        $this->assertSame(['member', 'manager', 'owner'], UserGroupUserPivot::roles());
    }

    #[Test]
    public function the_generic_labels_hold_without_any_catalog_row(): void
    {
        $this->assertSame('Membre', RoleCatalog::label(null, 'member'));
        $this->assertSame('Gestionnaire', RoleCatalog::label(null, 'manager'));
        $this->assertSame('Propriétaire', RoleCatalog::label(null, 'owner'));

        // Et les surcharges par type continuent de s'appliquer : elles sont encore
        // du code (donnée de 62.3).
        $this->assertSame('Élève', RoleCatalog::label('classe', 'member'));
        $this->assertSame('Porteur', RoleCatalog::label('projet', 'manager'));
    }

    #[Test]
    public function the_floor_narrows_it_never_opens(): void
    {
        // Le repli n'est PAS un fail-open : une valeur hors vocabulaire reste
        // refusée, exactement comme avec la constante d'avant.
        $this->expectException(\InvalidArgumentException::class);
        UserGroupUserPivot::assertValidRole('tuteur');
    }

    #[Test]
    public function the_plan_keeps_its_vocabulary_when_the_catalog_is_unreachable(): void
    {
        // Le résolveur est bien installé (application démarrée), mais il lit une
        // table absente : le plan retombe sur le plancher, pas sur le vide.
        $this->assertSame(['member', 'manager', 'owner'], GroupNameNormalizer::edgeRoles());
        $this->assertTrue(GroupNameNormalizer::isKnownEdgeRole('member'));
        $this->assertFalse(GroupNameNormalizer::isKnownEdgeRole('tuteur'));
    }
}
