<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypes;

use App\Services\Filesystem\Plan\GroupNameNormalizer;
use App\Support\GroupTypeCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.2 — LE PLANCHER, sur une base où la table n'existe MÊME PAS.
 *
 * Ce cas n'est pas théorique : une bonne partie de la suite fabrique son schéma à
 * la main, sans migrations, et une instance en cours de mise à jour est dans le
 * même état pendant quelques secondes. La validation d'un groupe, la garde
 * d'accrochage d'une recette et le rendu de trois écrans sont sollicités là aussi.
 *
 * Ce que ce fichier épingle : **le vocabulaire des neuf types recensés ne
 * disparaît jamais**, et le repli ne fait qu'ÉTROITIR — il n'autorise rien de plus.
 * Il épingle aussi le PIÈGE D'ORDRE : sans plancher, une base migrée mais pas
 * encore seedée ferait refuser l'accrochage `classe` de la recette d'arbre du
 * catalogue de répertoires.
 *
 * Pas de `RefreshDatabase` : c'est le but. La table `group_types` est absente.
 */
class GroupTypeCatalogFloorTest extends TestCase
{
    private const STATIC_KEYS = [
        'custom', 'classe', 'cours', 'matiere', 'matiere_classe', 'projet', 'equipe', 'role', 'function',
    ];

    #[Test]
    public function an_unmigrated_database_still_yields_the_nine_static_keys(): void
    {
        $this->assertSame(self::STATIC_KEYS, GroupTypeCatalog::keys());
    }

    #[Test]
    public function the_generic_labels_and_icons_hold_without_any_catalog_row(): void
    {
        $this->assertSame('Classe', GroupTypeCatalog::label('classe'));
        $this->assertSame('Matière / Classe', GroupTypeCatalog::label('matiere_classe'));
        $this->assertSame('Rôle', GroupTypeCatalog::label('role'));
        $this->assertSame('Fonction', GroupTypeCatalog::label('function'));
        $this->assertSame('Personnalisé', GroupTypeCatalog::label('custom'));

        $this->assertSame('fa-solid fa-graduation-cap', GroupTypeCatalog::icon('classe'));
    }

    /**
     * Le piège d'ORDRE : la garde d'accrochage doit connaître `classe` même sur
     * une base nue, sinon l'ordre migration/seeder deviendrait porteur.
     */
    #[Test]
    public function the_attachment_vocabulary_holds_before_any_seed(): void
    {
        $this->assertTrue(GroupTypeCatalog::isKnown('classe'));
        $this->assertTrue(GroupTypeCatalog::isKnown('matiere_classe'));
    }

    #[Test]
    public function the_floor_narrows_it_never_opens(): void
    {
        // Le repli n'est PAS un fail-open : une valeur hors vocabulaire reste
        // inconnue, et le sera pour la garde d'accrochage comme pour le service.
        $this->assertFalse(GroupTypeCatalog::isKnown('club'));
        $this->assertNotContains('club', GroupTypeCatalog::keys());
    }

    /**
     * Le namespace PUR du plan de fichiers ne connaît PAS le catalogue.
     *
     * `TYPE_PREFIXES` est une table de préfixes de NOMMAGE, pas un vocabulaire
     * d'affichage : elle reste la connaissance locale du normalizer, et aucun
     * `use` ne la relie au catalogue (le test d'architecture le verrouille sur le
     * texte des fichiers ; celui-ci constate le comportement).
     */
    #[Test]
    public function the_pure_plan_namespace_does_not_read_the_catalog(): void
    {
        $this->assertArrayHasKey('classe', GroupNameNormalizer::TYPE_PREFIXES);
        $this->assertStringNotContainsString(
            'GroupTypeCatalog',
            (string) file_get_contents(base_path('app/Services/Filesystem/Plan/GroupNameNormalizer.php')),
        );
    }
}
