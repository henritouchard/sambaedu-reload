<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SambaEdu\ExtBbb\Http\Request;
use SambaEdu\ExtBbb\Http\Response;
use SambaEdu\ExtBbb\Http\Router;

/**
 * Story 57.1 — Le routeur reçoit des chemins **NUS**, et ne laisse jamais rien
 * s'échapper.
 */
final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router(static fn (int $status, string $code): Response => new Response($code, $status));

        $router->add('GET', '/', static fn (): Response => new Response('accueil'));
        $router->add('GET', '/admin/servers', static fn (): Response => new Response('serveurs'));
        $router->add('POST', '/admin/servers', static fn (): Response => new Response('enregistré'));
        $router->add('GET', '/boom', static function (): Response {
            throw new RuntimeException('base corrompue');
        });

        return $router;
    }

    #[Test]
    public function the_backend_matches_bare_paths(): void
    {
        // Le proxy a retiré `/ext/bbb` : c'est `/admin/servers` qui arrive ici.
        self::assertSame('serveurs', $this->router()->dispatch(new Request('GET', '/admin/servers'))->body);
        self::assertSame('accueil', $this->router()->dispatch(new Request('GET', '/'))->body);
    }

    #[Test]
    public function a_path_still_carrying_the_public_prefix_is_not_a_route(): void
    {
        // Contre-épreuve du piège n°1 : si un jour le fragment Apache cessait de
        // retirer le préfixe, on veut un 404 franc — pas une extension qui
        // « marche à moitié » avec des URL doublées.
        $response = $this->router()->dispatch(new Request('GET', '/ext/bbb/admin/servers'));

        self::assertSame(404, $response->status);
    }

    #[Test]
    public function trailing_and_duplicated_slashes_lead_to_the_same_route(): void
    {
        self::assertSame('serveurs', $this->router()->dispatch(new Request('GET', '/admin/servers/'))->body);
        self::assertSame('serveurs', $this->router()->dispatch(new Request('GET', '/admin//servers'))->body);
    }

    #[Test]
    public function a_known_path_with_the_wrong_method_is_405_not_404(): void
    {
        $response = $this->router()->dispatch(new Request('DELETE', '/admin/servers'));

        self::assertSame(405, $response->status);
    }

    #[Test]
    public function the_same_path_can_carry_two_methods(): void
    {
        self::assertSame('enregistré', $this->router()->dispatch(new Request('POST', '/admin/servers'))->body);
    }

    #[Test]
    public function an_unhandled_exception_becomes_a_response_never_a_crash(): void
    {
        // `GET /` est la sonde de santé de l'extension : TOUTE réponse HTTP vaut
        // « joignable ». Une exception qui remonterait jusqu'à PHP donnerait,
        // elle, une trace en clair — et selon la configuration, une connexion
        // fermée sans réponse.
        $response = $this->router()->dispatch(new Request('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertSame('internal.error', $response->body);
        self::assertStringNotContainsString('base corrompue', $response->body);
    }

    #[Test]
    public function the_query_string_is_not_part_of_the_route(): void
    {
        $request = new Request('GET', '/admin/servers', ['edit' => '3']);

        self::assertSame('serveurs', $this->router()->dispatch($request)->body);
        self::assertSame('3', $request->query('edit'));
    }
}
