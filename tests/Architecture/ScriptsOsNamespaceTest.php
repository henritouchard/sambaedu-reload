<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionSource;
use App\ScriptsOs\Enums\ScriptExecutionStatus;
use App\ScriptsOs\Http\Controllers\ScriptExecutionLogIngestionController;
use App\ScriptsOs\Http\Requests\IngestScriptExecutionLogRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;

/**
 * Story 16.12 — AC8.3 / D14 / D15.
 *
 * Garde-fous architecturaux pour le namespace `App\ScriptsOs\*` :
 *
 *  1. Pas d'import `App\Legacy\*` ni `require '/sambaedu/...'`.
 *  2. `ScriptExecutionLogIngestionController::store` accepte une
 *     `IngestScriptExecutionLogRequest` typée (pas `Request`).
 *  3. La route `/api/v1/script-execution-logs` est protégée par
 *     `'auth.v1.workstation'` (textuel dans routes/api.php).
 *  4. Les routes legacy `/api/v1/agent/*` 16.10 / 16.11 ne sont pas
 *     impactées (non-régression — chaîne `agent.v1.` toujours présente).
 *  5. Les 4 enums sont `BackedEnum` avec backing `string`.
 */
class ScriptsOsNamespaceTest extends TestCase
{
    #[Test]
    public function no_legacy_import_in_scripts_os_namespace(): void
    {
        $root = realpath(__DIR__ . '/../../app/ScriptsOs');
        if ($root === false) {
            self::fail('app/ScriptsOs introuvable — namespace doit exister.');
        }

        $finder = (new Finder())->files()->in($root)->name('*.php');
        $violations = [];

        foreach ($finder as $file) {
            $code = $file->getContents();
            // require/include de legacy/*
            if (preg_match('/(?:require|include)(?:_once)?\s*\(?[\'"][^\'"]*legacy\//i', $code) === 1) {
                $violations[] = sprintf('%s include legacy/*', $file->getRelativePathname());
            }
            // import use App\Legacy\
            if (preg_match('/^use\s+App\\\\Legacy\\\\/m', $code) === 1) {
                $violations[] = sprintf('%s importe App\\Legacy\\*', $file->getRelativePathname());
            }
        }

        self::assertSame([], $violations);
    }

    #[Test]
    public function controller_uses_form_request_typehint(): void
    {
        $rc = new ReflectionClass(ScriptExecutionLogIngestionController::class);
        $method = $rc->getMethod('store');
        $params = $method->getParameters();

        self::assertNotEmpty($params, 'store() doit avoir au moins un param.');

        $type = $params[0]->getType();
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(IngestScriptExecutionLogRequest::class, $type->getName());
    }

    #[Test]
    public function route_is_protected_by_workstation_jwt_middleware(): void
    {
        $apiRoutes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');

        self::assertStringContainsString(
            'script-execution-logs',
            $apiRoutes,
            'Route /script-execution-logs absente de routes/api.php.',
        );

        // Doit apparaitre `auth.v1.workstation` au moins une fois dans le fichier
        // ET dans le bloc qui contient script-execution-logs (heuristique : on
        // cherche le middleware dans le 1500 chars précédant la déclaration).
        $pos = strpos($apiRoutes, 'script-execution-logs');
        self::assertNotFalse($pos);

        $context = substr($apiRoutes, max(0, $pos - 1500), 1500);
        self::assertStringContainsString(
            'auth.v1.workstation',
            $context,
            'Le bloc déclarant /script-execution-logs ne mentionne pas auth.v1.workstation.',
        );
    }

    #[Test]
    public function it_does_not_affect_existing_v1_routes(): void
    {
        $apiRoutes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');

        // Routes 16.10 doivent rester présentes
        self::assertStringContainsString("agent.v1.", $apiRoutes);
        self::assertStringContainsString('/enroll', $apiRoutes);
        self::assertStringContainsString('/refresh', $apiRoutes);
        self::assertStringContainsString('/ping', $apiRoutes);

        // Routes 16.13bis : /bootstrap.{cmd,sh} ont été remplacées par
        // /api/v1/workstation-config/* (MigrationController). On asserte la
        // présence du nouveau prefix workstation-config.
        self::assertStringContainsString('v1/workstation-config', $apiRoutes);
    }

    #[Test]
    public function enums_are_backed_string(): void
    {
        foreach ([
            ScriptExecutionAction::class,
            ScriptExecutionOs::class,
            ScriptExecutionStatus::class,
            ScriptExecutionSource::class,
        ] as $enumClass) {
            $rc = new ReflectionClass($enumClass);
            self::assertTrue($rc->isEnum(), $enumClass . ' doit être un enum.');

            // Backed type
            self::assertTrue(
                method_exists($enumClass, 'tryFrom'),
                $enumClass . ' doit être un BackedEnum (tryFrom).',
            );

            // Backing string
            $cases = $enumClass::cases();
            self::assertNotEmpty($cases);
            self::assertIsString($cases[0]->value);
        }
    }
}
