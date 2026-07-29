<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 56.4 — garde-fous architecturaux de l'**API extensions `/api/ext/v1/`**.
 *
 * Calque de {@see OidcRoutesTest} et de {@see ScriptsOsNamespaceTest} : scan
 * TEXTUEL, `PHPUnit\Framework\TestCase` pur, aucune application bootstrapée.
 *
 * Trois propriétés, chacune apprise à ses dépens par une story antérieure :
 *
 *  1. **Le contrat v1 est FERMÉ** — exactement deux routes, en GET seul.
 *     Le contrat est public et gelé (NFR11) : ajouter une route ou un verbe
 *     doit être un acte DÉLIBÉRÉ, pas un ajout qui passe en revue.
 *  2. **Le bloc vit APRÈS le groupe 16.12** — la fenêtre de 1500 caractères
 *     qu'inspecte `ScriptsOsNamespaceTest` précède `script-execution-logs` :
 *     tout bloc inséré avant la casse (fiche mémoire du projet).
 *  3. **`routes/api.php` ignore le fournisseur d'identité** — ni son préfixe
 *     d'URL, ni son namespace PHP. C'est ce que garantissent l'alias de
 *     middleware et le placement du contrôleur hors de ce namespace. La
 *     redondance avec `OidcRoutesTest` est VOULUE : si l'un des deux est un
 *     jour affaibli, l'autre tient.
 */
class ExtApiRoutesTest extends TestCase
{
    /** Racine du dépôt (ce fichier vit dans `tests/Architecture/`). */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.ltrim($relative, '/');
    }

    private function apiRoutes(): string
    {
        $path = realpath(self::repoPath('routes/api.php'));
        self::assertNotFalse($path, 'routes/api.php doit exister');

        return (string) file_get_contents($path);
    }

    /** Le texte du groupe `ext/v1`, du `Route::prefix` à son `});` de clôture. */
    private function extBlock(string $content): string
    {
        // ⚠️ Review 56.4 #2 — toutes les assertions ci-dessous portent sur CE
        // bloc. Sans la garde d'unicité, un SECOND groupe `ext/v1` ajouté
        // ailleurs dans le fichier (par erreur ou par contournement) échappait
        // à l'intégralité du contrat fermé : « exactement deux routes »,
        // « lecture seule », « un scope par route » n'auraient plus rien
        // verrouillé.
        self::assertSame(
            1,
            substr_count($content, "Route::prefix('ext/v1')"),
            'Un SEUL groupe `ext/v1` dans routes/api.php : le contrat v1 se lit d\'un seul endroit.',
        );

        $start = strpos($content, "Route::prefix('ext/v1')");
        self::assertNotFalse($start, 'Le groupe de routes `ext/v1` est introuvable dans routes/api.php');

        $end = strpos($content, '});', $start);
        self::assertNotFalse($end, 'Le groupe `ext/v1` n\'est pas refermé');

        return substr($content, $start, $end - $start + 3);
    }

    #[Test]
    public function the_v1_contract_declares_exactly_two_routes(): void
    {
        $block = $this->extBlock($this->apiRoutes());

        self::assertSame(
            2,
            // Les DÉCLARATIONS d'endpoint (le `Route::prefix` du groupe n'en
            // est pas une).
            preg_match_all('/Route::(get|post|put|patch|delete|any|match|options)\s*\(/i', $block),
            'Le contrat v1 est FERMÉ : exactement deux endpoints. En ajouter un est une décision, '
            .'pas un détail — et une rupture se livre en `/api/ext/v2/`, à côté.',
        );

        self::assertStringContainsString("Route::get('/me',", $block);
        self::assertStringContainsString("Route::get('/me/groups',", $block);
    }

    #[Test]
    public function the_v1_contract_exposes_read_verbs_only(): void
    {
        $block = $this->extBlock($this->apiRoutes());

        foreach (['post', 'put', 'patch', 'delete', 'any', 'match', 'options'] as $verb) {
            self::assertSame(
                0,
                preg_match('/Route::'.$verb.'\s*\(/i', $block),
                sprintf('Verbe « %s » sur l\'API extensions : le v1 est en LECTURE seule.', $verb),
            );
        }
    }

    /**
     * Le scope requis est déclaré SUR LA ROUTE, par l'alias de middleware —
     * pas enfoui dans le contrôleur, où il ne se lirait ni ne se vérifierait.
     */
    #[Test]
    public function each_route_declares_its_required_scope_through_the_alias(): void
    {
        $block = $this->extBlock($this->apiRoutes());

        self::assertStringContainsString("'ext.token:profile'", $block);
        self::assertStringContainsString("'ext.token:groups'", $block);

        // Deux seaux (review 56.4 #3) : un garde-fou anonyme sur le groupe,
        // et le seau PAR EXTENSION sur chaque route — celui-là ne peut vivre
        // qu'après `ext.token`, seul à connaître le client.
        self::assertMatchesRegularExpression('/throttle:\d+,\d+/', $block);
        self::assertSame(
            2,
            preg_match_all("/'throttle:ext-api'/", $block),
            'Chaque route porte le seau par extension : sans lui, une extension bavarde met les autres en 429.',
        );

        // Autant de déclarations de scope que de routes : aucune route ne peut
        // se retrouver sans garde parce que la ligne aurait été oubliée.
        self::assertSame(2, preg_match_all("/'ext\.token:/", $block));
    }

    /**
     * ⚠️ LE piège du projet (fiche mémoire `routes/api.php` fenêtre 1500) :
     * `ScriptsOsNamespaceTest` cherche `auth.v1.workstation` dans les 1500
     * caractères qui PRÉCÈDENT `script-execution-logs`. Un bloc inséré avant
     * le groupe 16.12 repousse la fenêtre et casse ce test — pour une raison
     * que rien ne relierait au coupable.
     */
    #[Test]
    public function the_ext_block_is_declared_after_the_script_execution_logs_group(): void
    {
        $content = $this->apiRoutes();

        $logs = strpos($content, 'script-execution-logs');
        $ext = strpos($content, "Route::prefix('ext/v1')");

        self::assertNotFalse($logs);
        self::assertNotFalse($ext);

        self::assertGreaterThan(
            $logs,
            $ext,
            'Le bloc `ext/v1` DOIT rester après le groupe 16.12 (fenêtre 1500 de ScriptsOsNamespaceTest).',
        );
    }

    /**
     * L'API extensions ne fait entrer AUCUNE dépendance au namespace du
     * fournisseur d'identité dans `routes/api.php` : le middleware est aliasé
     * (Kernel), le contrôleur vit dans la surface API.
     *
     * ⚠️ Les aiguilles sont CONCATÉNÉES pour que ce fichier-ci ne les contienne
     * pas littéralement — un test qui cite la chaîne qu'il interdit finirait
     * par la voir apparaître dans le fichier surveillé par copier-coller.
     */
    #[Test]
    public function the_api_routes_file_never_names_the_identity_provider(): void
    {
        $content = $this->apiRoutes();

        self::assertStringNotContainsString('/oi'.'dc/', $content);
        self::assertStringNotContainsString('Auth\\Oi'.'dc', $content);
    }

    #[Test]
    public function the_ext_token_middleware_is_aliased_in_the_kernel(): void
    {
        $path = realpath(self::repoPath('app/Http/Kernel.php'));
        self::assertNotFalse($path, 'app/Http/Kernel.php doit exister');

        $kernel = (string) file_get_contents($path);

        self::assertMatchesRegularExpression(
            "/'ext\.token'\s*=>\s*\\\\App\\\\Auth\\\\Oidc\\\\Http\\\\Middleware\\\\EnsureExtensionApiToken::class/",
            $kernel,
            'Sans cet alias, `routes/api.php` devrait nommer le namespace du fournisseur.',
        );
    }

    /**
     * Le canal est ENTIÈREMENT en `routes/api.php` : rien n'a été ajouté au
     * fichier de routes web, où le catchall legacy et le guard de session
     * n'ont aucun sens pour un appel machine porteur d'un Bearer.
     */
    #[Test]
    public function the_web_routes_file_declares_nothing_of_the_extensions_api(): void
    {
        $path = realpath(self::repoPath('routes/web.php'));
        self::assertNotFalse($path);

        $web = (string) file_get_contents($path);

        self::assertStringNotContainsString('ext/v1', $web);
        self::assertStringNotContainsString('ext.token', $web);
        self::assertStringNotContainsString('Api\\Ext\\V1', $web);
    }

    /**
     * Le contrôleur vit dans la surface API, avec le `V` MAJUSCULE du plus
     * récent des canaux (`Api\V1\Agent`) — le repo contient aussi un
     * `Api\v1\ControlHub` historique qu'il ne faut pas imiter.
     */
    #[Test]
    public function the_controller_lives_in_the_api_surface_with_the_expected_casing(): void
    {
        $path = self::repoPath('app/Http/Controllers/Api/Ext/V1/MeController.php');

        self::assertFileExists($path);

        $content = (string) file_get_contents($path);

        self::assertStringContainsString('namespace App\\Http\\Controllers\\Api\\Ext\\V1;', $content);

        // Il ne résout JAMAIS d'utilisateur lui-même : l'identité de la requête
        // est le jeton, injecté par le middleware (doctrine 23.2).
        self::assertSame(
            0,
            preg_match('/User::query\s*\(/', $content),
            'Le contrôleur ne doit résoudre aucun utilisateur : son identité vient du jeton.',
        );
    }
}
