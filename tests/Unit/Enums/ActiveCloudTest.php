<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * Story 63.1 — AC1 : le cloud actif est UNE VALEUR, et l'état « les deux »
 * est irreprésentable.
 *
 * `TestCase` PUR, aucune application, aucune base — c'est ce qui prouve que
 * le vocabulaire ne dépend de rien d'autre que de lui-même.
 */
class ActiveCloudTest extends TestCase
{
    #[Test]
    public function le_vocabulaire_est_exactement_trois_cases_aux_valeurs_figees(): void
    {
        self::assertSame(
            ['aucun', 'nextcloud', 'opencloud'],
            array_map(static fn (ActiveCloud $c): string => $c->value, ActiveCloud::cases()),
        );

        self::assertSame('aucun', ActiveCloud::Aucun->value);
        self::assertSame('nextcloud', ActiveCloud::Nextcloud->value);
        self::assertSame('opencloud', ActiveCloud::OpenCloud->value);
    }

    #[Test]
    public function values_rend_la_liste_des_trois_litteraux(): void
    {
        self::assertSame(['aucun', 'nextcloud', 'opencloud'], ActiveCloud::values());
    }

    #[Test]
    public function label_rend_un_libelle_fr_sans_aucune_valeur_technique_brute(): void
    {
        self::assertSame('Aucun cloud', ActiveCloud::Aucun->label());
        self::assertSame('Nextcloud', ActiveCloud::Nextcloud->label());
        self::assertSame('OpenCloud', ActiveCloud::OpenCloud->label());
    }

    #[Test]
    public function backend_associe_chaque_case_au_backend_de_fichiers_correspondant(): void
    {
        self::assertNull(ActiveCloud::Aucun->backend());
        self::assertSame(FileBackendName::Nextcloud, ActiveCloud::Nextcloud->backend());
        self::assertSame(FileBackendName::OpenCloud, ActiveCloud::OpenCloud->backend());
    }

    #[Test]
    public function is_known_reconnait_les_trois_valeurs_et_refuse_le_reste(): void
    {
        self::assertTrue(ActiveCloud::isKnown('aucun'));
        self::assertTrue(ActiveCloud::isKnown('nextcloud'));
        self::assertTrue(ActiveCloud::isKnown('opencloud'));

        self::assertFalse(ActiveCloud::isKnown('les_deux'));
        self::assertFalse(ActiveCloud::isKnown(''));
        self::assertFalse(ActiveCloud::isKnown(null));
        self::assertFalse(ActiveCloud::isKnown(42));
        self::assertFalse(ActiveCloud::isKnown('Nextcloud'));
    }

    /**
     * AC1 — aucune combinaison de valeurs ne représente « les deux clouds » :
     * le type ne porte qu'UNE valeur, il n'existe ni setter ni tableau de
     * booléens. On tente d'écrire « les deux » sous toutes les formes
     * plausibles et on constate `null` à chaque fois — pas une case
     * supplémentaire, pas un repli.
     */
    #[Test]
    public function aucune_combinaison_de_valeurs_ne_represente_les_deux_clouds(): void
    {
        foreach (['nextcloud,opencloud', 'les_deux', 'nextcloud opencloud', 'nextcloud+opencloud', 'nextcloud|opencloud'] as $attempt) {
            self::assertNull(ActiveCloud::tryFrom($attempt), sprintf('« %s » ne doit résoudre aucune case', $attempt));
        }
    }

    /**
     * Le type n'offre AUCUN mutateur — ni `setX()`, ni `withX()`. Le test
     * balaie toutes les méthodes plutôt que d'interroger deux noms littéraux :
     * `hasMethod('set')` ne dirait rien d'un `setCloud()` ajouté demain, et
     * `hasProperty()` sur une enum ne peut de toute façon jamais échouer (une
     * enum ne déclare pas de propriété). Ce qu'on veut épingler, c'est que le
     * cloud actif se CHOISIT parmi trois cases, il ne se compose pas.
     */
    #[Test]
    public function le_type_ne_porte_aucun_mutateur_et_reste_a_trois_cases(): void
    {
        $reflection = new ReflectionEnum(ActiveCloud::class);

        self::assertTrue($reflection->isEnum());
        self::assertCount(3, ActiveCloud::cases());

        foreach ($reflection->getMethods() as $method) {
            $name = $method->getName();

            self::assertFalse(
                str_starts_with(strtolower($name), 'set') || str_starts_with(strtolower($name), 'with'),
                sprintf('« %s » ressemble à un mutateur : le cloud actif est une valeur, pas un état qu\'on compose.', $name),
            );
        }
    }
}
