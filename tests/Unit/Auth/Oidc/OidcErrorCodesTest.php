<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Oidc;

use App\Auth\Oidc\Support\OidcErrorCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Story 56.4 — `OidcErrorCodes::all()` est le catalogue EXHAUSTIF des codes
 * internes (55.1). Il n'a d'utilité — audits, tests d'invariance,
 * documentation d'exploitation — que s'il est réellement exhaustif : une
 * constante ajoutée sans y être reportée en ferait une liste qui MENT, et
 * personne ne s'en apercevrait avant d'en avoir besoin.
 *
 * Le test est adossé à la réflexion plutôt qu'à une liste recopiée : recopier
 * la liste ici reviendrait à créer un troisième endroit à maintenir.
 */
class OidcErrorCodesTest extends TestCase
{
    #[Test]
    public function the_catalogue_lists_every_declared_code(): void
    {
        $declared = array_values((new ReflectionClass(OidcErrorCodes::class))->getConstants());
        $listed = OidcErrorCodes::all();

        sort($declared);
        $sortedListed = $listed;
        sort($sortedListed);

        self::assertSame(
            $declared,
            $sortedListed,
            'Toute constante de OidcErrorCodes doit figurer dans all() — et réciproquement.',
        );

        // Méta-garde : sans elle, une classe vidée de ses constantes ferait
        // passer l'assertion à vide.
        self::assertGreaterThan(20, count($listed));

        // Aucun doublon : `all()` sert à énumérer, pas à compter deux fois.
        self::assertSame(count($listed), count(array_unique($listed)));
    }

    #[Test]
    public function every_code_is_namespaced_and_never_leaks_as_an_oauth_error(): void
    {
        foreach (OidcErrorCodes::all() as $code) {
            self::assertStringStartsWith('oidc.', $code, 'code interne non préfixé : '.$code);

            // Les codes OAuth publics (`invalid_token`, `insufficient_scope`…)
            // sont d'un autre registre : les confondre finirait par faire
            // sortir un code interne dans une réponse HTTP.
            self::assertStringNotContainsString('invalid_token', $code);
            self::assertStringNotContainsString('insufficient_scope', $code);
        }
    }
}
