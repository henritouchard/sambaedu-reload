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

        // Story 62.3 — MISE À JOUR D'INVENTAIRE, pas d'affaiblissement. Les
        // libellés par type ÉTAIENT du code (une constante privée de `RoleCatalog`) : ils
        // survivaient donc à l'absence de toute table, et ce test l'épinglait.
        // Ils sont désormais des DÉCLARATIONS en base ; sur une base non migrée
        // il n'y en a aucune, et le régime de REPLI s'applique — libellés
        // génériques, exactement ce que la lecture défensive promet. C'est la
        // contrepartie assumée de la bascule code→donnée : ce qui est administrable
        // ne peut pas, par construction, survivre à l'absence de sa table.
        $this->assertSame('Membre', RoleCatalog::label('classe', 'member'));
        $this->assertSame('Gestionnaire', RoleCatalog::label('projet', 'manager'));
    }

    /**
     * Story 62.3 — sans déclarations, TOUT le catalogue reste attribuable.
     *
     * C'est le pendant exact de `the_floor_narrows_it_never_opens` : le repli des
     * déclarations ne doit jamais faire REFUSER une attribution qui marchait. Une
     * base non migrée, une panne de lecture, un test sur schéma nu — aucun de ces
     * états ne doit rendre un groupe non administrable.
     */
    #[Test]
    public function without_declarations_every_catalog_role_stays_assignable(): void
    {
        foreach ([null, 'classe', 'projet', 'equipe', 'inconnu'] as $type) {
            $this->assertSame(['member', 'manager', 'owner'], RoleCatalog::assignableKeys($type));
            RoleCatalog::assertAssignable($type, 'owner');
        }

        $this->assertSame([], RoleCatalog::declarationsFor('classe'));
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
