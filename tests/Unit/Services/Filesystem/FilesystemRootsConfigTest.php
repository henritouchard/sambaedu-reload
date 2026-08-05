<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.5 — LES RACINES DÉCLARÉES, ET CELLE QUI NE L'EST PAS.
 *
 * Trois interdits, chacun avec son assertion. Ce ne sont pas des préférences de
 * rangement : chacun ferme un chemin par lequel l'arbre de classe historique — le
 * seul réellement servi aux établissements — se serait retrouvé écrit, déplacé ou
 * exposé par la chaîne neuve.
 */
class FilesystemRootsConfigTest extends TestCase
{
    /**
     * La configuration EXPÉDIÉE est celle du fichier, pas celle qu'un test
     * précédent a laissée. On la relit à la source.
     *
     * @return array<string, mixed>
     */
    private function shippedConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/filesystem.php');

        return $config;
    }

    /**
     * **Interdit n°1 — `classes_root` reste NON déclarée.**
     *
     * L'arbre historique se résout via `config('filesystem.classes_root', <statique>)`.
     * Tant que la clé est absente, les deux services retombent sur leur propriété
     * statique, que les tests surchargent. Déclarer la clé masquerait cet override
     * et changerait le comportement du chemin historique — précisément ce que
     * cette story existe pour ne PAS faire.
     */
    #[Test]
    public function the_legacy_class_root_key_is_deliberately_never_declared(): void
    {
        self::assertArrayNotHasKey(
            'classes_root',
            $this->shippedConfig(),
            'la clé « classes_root » ne doit PAS être déclarée : elle masquerait le repli statique du '
            . 'chemin historique, dont les tests dépendent.',
        );

        self::assertNull(
            config('filesystem.classes_root'),
            'la configuration expédiée doit rendre « null » pour cette clé.',
        );

        // Et le repli statique tient toujours, des deux côtés.
        self::assertSame('/var/sambaedu/Classes', app(ShareService::class)->classesRoot());
        self::assertSame('/var/sambaedu/Classes', app(AclService::class)->classesRoot());
    }

    /**
     * **Interdit n°2 — la racine neuve est DISTINCTE de l'arbre historique.**
     *
     * Comparaison avec le séparateur : la voisine `/var/sambaedu/ClassesSE5`
     * partage un préfixe avec `/var/sambaedu/Classes`, et un simple
     * `str_starts_with` aurait donné une garde fausse dans les deux sens.
     */
    #[Test]
    public function the_new_root_is_distinct_from_the_legacy_class_tree(): void
    {
        $legacy = '/var/sambaedu/Classes';
        $new = (string) config('filesystem.class_trees_root');

        self::assertNotSame('', $new, 'la racine des arbres de classe doit être déclarée');
        self::assertNotSame($legacy, $new);
        self::assertFalse(
            str_starts_with($new, $legacy . '/'),
            'la racine neuve ne doit pas vivre DANS l\'arbre historique',
        );
        self::assertFalse(
            str_starts_with($legacy, $new . '/'),
            'l\'arbre historique ne doit pas vivre DANS la racine neuve',
        );
    }

    /**
     * **Interdit n°3 — la racine neuve n'est PAS sous la racine des partages.**
     *
     * L'export SMB `[partages]` publie tout ce qui vit sous cette racine : y loger
     * les arbres de classe les ferait apparaître dans la liste des partages vue
     * par les utilisateurs, sans que personne l'ait demandé. Une racine dédiée est
     * aussi ce qui permet d'y monter un disque séparé sans toucher au code.
     */
    #[Test]
    public function the_new_root_never_lives_under_the_shares_root(): void
    {
        $shares = rtrim((string) config('filesystem.shares_root'), '/');
        $new = rtrim((string) config('filesystem.class_trees_root'), '/');

        self::assertNotSame($shares, $new);
        self::assertFalse(
            str_starts_with($new . '/', $shares . '/'),
            'la racine des arbres de classe ne doit pas être exposée en SMB par le partage des répertoires réseau',
        );
    }

    /** La surcharge par variable d'environnement existe bel et bien. */
    #[Test]
    public function the_new_root_is_overridable_from_the_environment(): void
    {
        $before = env('SAMBAEDU_CLASS_TREES_ROOT');

        try {
            putenv('SAMBAEDU_CLASS_TREES_ROOT=/srv/arbres-classe');
            $_ENV['SAMBAEDU_CLASS_TREES_ROOT'] = '/srv/arbres-classe';

            self::assertSame('/srv/arbres-classe', $this->shippedConfig()['class_trees_root']);
        } finally {
            if ($before === null) {
                putenv('SAMBAEDU_CLASS_TREES_ROOT');
                unset($_ENV['SAMBAEDU_CLASS_TREES_ROOT']);
            } else {
                putenv('SAMBAEDU_CLASS_TREES_ROOT=' . $before);
                $_ENV['SAMBAEDU_CLASS_TREES_ROOT'] = $before;
            }
        }
    }
}
