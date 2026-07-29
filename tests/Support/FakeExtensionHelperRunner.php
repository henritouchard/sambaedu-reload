<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Extensions\Contracts\ExtensionHelperRunner;

/**
 * Story 56.2 — Doublure du SEUL seam privilégié du moteur d'installation.
 *
 * Elle n'exécute rien : elle ENREGISTRE chaque appel `(args, stdin)`. C'est ce
 * qui rend l'intégralité du moteur — ordre des étapes, fail-closed avant toute
 * exécution, compensations en ordre inverse, idempotence — prouvable sur
 * l'HÔTE, sans root, sans apt, sans systemd et sans Apache.
 *
 * Deux modes de panne programmables, qui couvrent les deux formes de la
 * réalité :
 *  - {@see self::failOnSubcommand()} : « cette opération privilégiée échoue »
 *    (apt qui ne résout pas une dépendance, `configtest` KO, service qui refuse
 *    de démarrer) ;
 *  - {@see self::failAtCall()} : « le Nième appel échoue », pour paramétrer un
 *    test qui balaie TOUTES les étapes du plan.
 *
 * ⚠️ Le `stdin` est conservé tel quel : c'est ce qui permet d'affirmer que le
 * secret OIDC est bien arrivé par ce canal — et {@see self::allArguments()} de
 * prouver qu'il n'est apparu dans AUCUN argument (NFR3).
 */
class FakeExtensionHelperRunner implements ExtensionHelperRunner
{
    /** @var list<array{args: list<string>, stdin: string|null}> */
    public array $calls = [];

    /** @var array<string, int> sous-commande ⇒ code retour non nul */
    private array $failingSubcommands = [];

    /** @var list<int> rangs (1-based) d'appels qui doivent échouer */
    private array $failingCallIndexes = [];

    /** Compte TOUS les appels, y compris ceux des compensations. */
    private int $callCount = 0;

    /** {@inheritdoc} */
    public function run(array $args, ?string $stdin = null): array
    {
        $this->callCount++;
        $this->calls[] = ['args' => array_values(array_map(static fn ($a): string => (string) $a, $args)),
            'stdin' => $stdin];

        $subcommand = (string) ($args[0] ?? '');

        if (isset($this->failingSubcommands[$subcommand])) {
            return [
                'stdout' => [],
                'stderr' => ['fake: '.$subcommand.' en échec'],
                'exitCode' => $this->failingSubcommands[$subcommand],
            ];
        }

        if (in_array($this->callCount, $this->failingCallIndexes, true)) {
            return [
                'stdout' => [],
                'stderr' => ['fake: appel #'.$this->callCount.' en échec'],
                'exitCode' => 1,
            ];
        }

        return ['stdout' => [], 'stderr' => [], 'exitCode' => 0];
    }

    /** Fait échouer TOUS les appels à cette sous-commande. */
    public function failOnSubcommand(string $subcommand, int $exitCode = 1): self
    {
        $this->failingSubcommands[$subcommand] = $exitCode;

        return $this;
    }

    /** Fait échouer le Nième appel (1-based), quelle que soit la sous-commande. */
    public function failAtCall(int $index): self
    {
        $this->failingCallIndexes[] = $index;

        return $this;
    }

    /** Rétablit un fake sain (utile pour prouver qu'une relance réussit). */
    public function heal(): self
    {
        $this->failingSubcommands = [];
        $this->failingCallIndexes = [];

        return $this;
    }

    /** Oublie l'historique sans toucher aux pannes programmées. */
    public function forget(): self
    {
        $this->calls = [];

        return $this;
    }

    /**
     * Story 56.3 — Oublie l'historique **et remet le compteur d'appels à
     * zéro**, pour que {@see self::failAtCall()} porte sur la séquence qui
     * commence maintenant.
     *
     * {@see self::forget()} ne touche volontairement pas au compteur (les tests
     * 56.2 s'appuient sur cette sémantique) ; il fallait donc une seconde
     * méthode plutôt qu'un changement de comportement. Utile quand une fixture
     * a DÉJÀ fait tourner le moteur (installer avant de mettre à jour) et qu'on
     * veut faire échouer « le premier appel de l'opération étudiée ».
     */
    public function forgetAll(): self
    {
        $this->calls = [];
        $this->callCount = 0;

        return $this;
    }

    /**
     * Séquence des sous-commandes appelées, dans l'ordre — l'assertion
     * centrale de cette story.
     *
     * @return list<string>
     */
    public function sequence(): array
    {
        return array_map(static fn (array $call): string => $call['args'][0] ?? '', $this->calls);
    }

    /**
     * TOUS les arguments de TOUS les appels, aplatis : de quoi affirmer qu'un
     * secret n'apparaît dans aucun argv.
     *
     * @return list<string>
     */
    public function allArguments(): array
    {
        $flat = [];
        foreach ($this->calls as $call) {
            foreach ($call['args'] as $arg) {
                $flat[] = $arg;
            }
        }

        return $flat;
    }

    /** Le stdin du premier appel à cette sous-commande (`null` si jamais appelée). */
    public function stdinFor(string $subcommand): ?string
    {
        foreach ($this->calls as $call) {
            if (($call['args'][0] ?? '') === $subcommand) {
                return $call['stdin'];
            }
        }

        return null;
    }

    /** Les arguments du premier appel à cette sous-commande. */
    public function argsFor(string $subcommand): ?array
    {
        foreach ($this->calls as $call) {
            if (($call['args'][0] ?? '') === $subcommand) {
                return $call['args'];
            }
        }

        return null;
    }

    /** Nombre d'appels à une sous-commande donnée. */
    public function countOf(string $subcommand): int
    {
        return count(array_filter($this->sequence(), static fn (string $s): bool => $s === $subcommand));
    }
}
