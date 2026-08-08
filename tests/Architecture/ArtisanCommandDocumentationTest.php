<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * **La documentation des commandes vit DANS les commandes.**
 *
 * Une liste de commandes tenue dans un fichier Markdown dérive : elle est juste le
 * jour où on l'écrit, fausse trois commandes plus tard. `php artisan list` et
 * `php artisan help <commande>` ne peuvent pas dériver — ils lisent le code. C'est
 * donc là que la documentation doit être, et ce test empêche qu'elle en reparte.
 *
 * Quatre règles, sur toute commande du dépôt :
 *
 *  1. **Une description non vide.** C'est la ligne que voit `artisan list` : sans
 *     elle, la commande existe sans qu'on sache pourquoi.
 *  2. **Une description sans vocabulaire de pilotage interne.** « Story 15.5 »,
 *     « NFR3 », « Phase 3+ STUB » ne veulent rien dire pour qui exploite le
 *     serveur — et cette sortie-là, contrairement à un document interne, lui est
 *     destinée.
 *  3. **Une aide non vide** (`$help`) : la forme longue, où vivent les exemples,
 *     les effets de bord et les codes de retour. La description dit QUOI, l'aide
 *     dit COMMENT et QUAND.
 *  4. **Tout argument et toute option décrits.** Une option sans description est
 *     une option qu'on n'ose pas utiliser.
 *
 * Le docblock de classe, lui, n'est pas concerné : il s'adresse au mainteneur
 * (pourquoi c'est fait ainsi, comment étendre), là où `$help` s'adresse à
 * l'exploitant. Les deux coexistent sans se dupliquer.
 *
 * La lecture se fait par RÉFLEXION, pas par expression régulière : une signature
 * concaténée sur plusieurs lignes ou une description en guillemets doubles sont
 * ainsi lues telles que PHP les voit, et non telles qu'un motif les devine.
 *
 * Un MÉTA-TEST ferme la porte de sortie : un scan qui ne trouverait plus aucune
 * commande passerait au vert en ne vérifiant rien.
 */
class ArtisanCommandDocumentationTest extends TestCase
{
    /** Vocabulaire de pilotage interne, proscrit dans une sortie destinée à l'exploitant. */
    private const FORBIDDEN_IN_DESCRIPTION = [
        '/\bstor(y|ies)\s+\d/i',
        '/\bepic\s+\d/i',
        '/\b(FR|NFR|AC)\d+\b/',
        '/\bphase\s+\d/i',
        '/\bSTUB\b/',
    ];

    /** Plancher du méta-test : bien en dessous du parc réel, mais assez haut pour qu'un scan cassé se voie. */
    private const MINIMUM_COMMANDS_EXPECTED = 60;

    /**
     * @return array<string, array{signature: string, description: string, help: string}>
     */
    private function commands(): array
    {
        // Scan restreint aux répertoires de commandes : inutile d'autocharger tout
        // `app/` pour lire trois propriétés, et une classe hors périmètre dont le
        // namespace ne suit pas son chemin ferait échouer le scan sans rapport avec
        // la règle vérifiée ici.
        $finder = (new Finder())
            ->files()
            ->in(dirname(__DIR__, 2).'/app')
            ->path('#Console/Commands#')
            ->name('*.php');

        $commands = [];

        foreach ($finder as $file) {
            $relative = str_replace('/', '\\', $file->getRelativePathname());
            $class = 'App\\'.substr($relative, 0, -4);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(Command::class) || $reflection->isAbstract()) {
                continue;
            }

            $defaults = $reflection->getDefaultProperties();
            $signature = (string) ($defaults['signature'] ?? '');

            // Une commande sans signature se déclare autrement (nom Symfony) : hors périmètre.
            if ($signature === '') {
                continue;
            }

            $commands[$class] = [
                'signature' => $signature,
                'description' => (string) ($defaults['description'] ?? ''),
                'help' => (string) ($defaults['help'] ?? ''),
            ];
        }

        return $commands;
    }

    #[Test]
    public function le_scan_trouve_bien_des_commandes(): void
    {
        // Méta-test : sans lui, un scan cassé validerait silencieusement tout le reste.
        $this->assertGreaterThanOrEqual(
            self::MINIMUM_COMMANDS_EXPECTED,
            count($this->commands()),
            'Le scan ne trouve plus les commandes artisan : les règles ci-dessous ne vérifient donc plus rien.',
        );
    }

    #[Test]
    public function chaque_commande_porte_une_description(): void
    {
        foreach ($this->commands() as $class => $command) {
            $this->assertNotSame(
                '',
                trim($command['description']),
                "{$class} n'a pas de \$description : elle apparaît sans explication dans `php artisan list`.",
            );
        }
    }

    #[Test]
    public function aucune_description_ne_laisse_fuiter_le_vocabulaire_de_pilotage(): void
    {
        foreach ($this->commands() as $class => $command) {
            foreach (self::FORBIDDEN_IN_DESCRIPTION as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $command['description'],
                    "{$class} : la \$description contient du vocabulaire de pilotage interne. "
                    .'Cette ligne est lue par qui exploite le serveur — énoncez la règle en clair, '
                    .'pas son code de suivi.',
                );
            }
        }
    }

    #[Test]
    public function chaque_commande_porte_une_aide(): void
    {
        foreach ($this->commands() as $class => $command) {
            $this->assertNotSame(
                '',
                trim($command['help']),
                "{$class} n'a pas de \$help : `php artisan help` n'a rien à montrer. "
                .'Décrivez-y les exemples, les effets de bord et les codes de retour.',
            );
        }
    }

    #[Test]
    public function chaque_argument_et_option_est_decrit(): void
    {
        foreach ($this->commands() as $class => $command) {
            preg_match_all('/\{([^}]+)\}/', $command['signature'], $matches);

            foreach ($matches[1] as $parameter) {
                $this->assertStringContainsString(
                    ' : ',
                    $parameter,
                    "{$class} : le paramètre `{{$parameter}}` n'a pas de description. "
                    .'Ajoutez-la après ` : ` — c\'est ce que lit `php artisan help`.',
                );
            }
        }
    }
}
