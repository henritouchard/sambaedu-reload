<?php

declare(strict_types=1);

namespace Tests\Unit\Extensions;

use App\Enums\ExtensionType;
use App\Exceptions\InvalidExtensionManifestException;
use App\Services\Extensions\ExtensionManifestValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 54.1 (AC2) — Validation du manifest v1.
 *
 * Le validateur est PUR (aucune DB, aucun FS) : ce test étend le TestCase
 * PHPUnit nu, sans application Laravel.
 *
 * Exigence clé de l'AC : une erreur doit **nommer le champ en cause** —
 * chaque cas d'échec assert le `field` porté par l'exception, pas seulement le
 * fait qu'elle soit levée.
 */
class ExtensionManifestValidatorTest extends TestCase
{
    private ExtensionManifestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ExtensionManifestValidator();
    }

    /** Le manifest bundled canonique (tuile Documentation). */
    private function validManifest(array $overrides = []): array
    {
        return array_merge([
            'manifest_version' => 1,
            'id' => 'doc',
            'type' => 'link',
            'name' => 'Documentation',
            'version' => '1.0.0',
            'entry_url' => '/doc',
            'icon' => 'fa-solid fa-book-open',
            'publisher' => 'SambaEdu',
            'description' => 'Documentation publique SambaEdu (guides admin et poste).',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => ['admin', 'prof', 'eleve']],
        ], $overrides);
    }

    // ── Chemin heureux ────────────────────────────────────────────────────

    #[Test]
    public function validates_the_canonical_documentation_manifest(): void
    {
        $normalized = $this->validator->validate($this->validManifest());

        self::assertSame(1, $normalized['manifest_version']);
        self::assertSame('doc', $normalized['id']);
        self::assertSame(ExtensionType::Link, $normalized['type']);
        self::assertSame('Documentation', $normalized['name']);
        self::assertSame('1.0.0', $normalized['version']);
        self::assertSame('/doc', $normalized['entry_url']);
        self::assertSame('SambaEdu', $normalized['publisher']);
        self::assertSame([], $normalized['scopes']);
        self::assertSame([], $normalized['dependencies']);
        self::assertSame(['admin', 'prof', 'eleve'], $normalized['visibility']['roles']);
    }

    #[Test]
    public function applies_defaults_to_optional_fields(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['icon'], $manifest['publisher'], $manifest['description'],
            $manifest['scopes'], $manifest['dependencies']);

        $normalized = $this->validator->validate($manifest);

        self::assertSame('', $normalized['icon']);
        self::assertSame('', $normalized['publisher']);
        self::assertSame('', $normalized['description']);
        self::assertSame([], $normalized['scopes']);
        self::assertSame([], $normalized['dependencies']);
    }

    #[Test]
    public function accepts_the_app_type_and_non_empty_scopes_and_dependencies(): void
    {
        $normalized = $this->validator->validate($this->validManifest([
            'id' => 'bbb',
            'type' => 'app',
            // Story 56.2 (AR3) : une `app` DOIT déclarer `/ext/<id>` — c'est le
            // chemin que SE5 provisionne lui-même. La fixture suit le contrat.
            'entry_url' => '/ext/bbb',
            'scopes' => ['profile', 'groups'],
            'dependencies' => ['doc'],
        ]));

        self::assertSame(ExtensionType::App, $normalized['type']);
        self::assertSame(['profile', 'groups'], $normalized['scopes']);
        self::assertSame(['doc'], $normalized['dependencies']);
    }

    // ── Champs obligatoires manquants : le champ est NOMMÉ ─────────────────

    /** @return list<array{0:string}> */
    public static function requiredFieldProvider(): array
    {
        return [
            ['manifest_version'],
            ['id'],
            ['type'],
            ['name'],
            ['version'],
            ['entry_url'],
        ];
    }

    #[Test]
    #[DataProvider('requiredFieldProvider')]
    public function missing_required_field_is_rejected_naming_that_field(string $field): void
    {
        $manifest = $this->validManifest();
        unset($manifest[$field]);

        try {
            $this->validator->validate($manifest);
            self::fail("Le manifest sans « {$field} » aurait dû être rejeté.");
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame($field, $e->field);
            self::assertStringContainsString($field, $e->getMessage());
        }
    }

    #[Test]
    public function missing_visibility_roles_is_rejected_naming_the_dotted_field(): void
    {
        $manifest = $this->validManifest();
        unset($manifest['visibility']);

        try {
            $this->validator->validate($manifest);
            self::fail('Le manifest sans visibility.roles aurait dû être rejeté.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('visibility.roles', $e->field);
        }
    }

    #[Test]
    public function empty_visibility_roles_is_rejected(): void
    {
        $this->expectException(InvalidExtensionManifestException::class);
        $this->validator->validate($this->validManifest(['visibility' => ['roles' => []]]));
    }

    #[Test]
    public function non_string_visibility_role_is_rejected(): void
    {
        try {
            $this->validator->validate($this->validManifest(['visibility' => ['roles' => ['admin', 42]]]));
            self::fail('Un rôle non-chaîne aurait dû être rejeté.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('visibility.roles', $e->field);
        }
    }

    // ── Type inconnu ──────────────────────────────────────────────────────

    #[Test]
    public function unknown_type_is_rejected_naming_the_type_field(): void
    {
        try {
            $this->validator->validate($this->validManifest(['type' => 'widget']));
            self::fail('Un type inconnu aurait dû être rejeté.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('type', $e->field);
            self::assertStringContainsString('widget', $e->getMessage());
            self::assertStringContainsString('link', $e->getMessage(), 'les types connus sont listés');
        }
    }

    // ── Version de manifest : rejet STRICT, aucun repli ────────────────────

    /** @return list<array{0:mixed}> */
    public static function unsupportedVersionProvider(): array
    {
        return [
            [2],
            ['2'],
            ['1.0'],   // « 1.0 » n'est PAS la version 1 (pas de repli tolérant)
            ['v1'],
            [1.0],     // un float JSON ne doit pas être coercé silencieusement
            [true],
            [[1]],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedVersionProvider')]
    public function unsupported_manifest_version_is_rejected(mixed $declared): void
    {
        try {
            $this->validator->validate($this->validManifest(['manifest_version' => $declared]));
            self::fail('Une version non supportée aurait dû être rejetée.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('manifest_version', $e->field);
        }
    }

    #[Test]
    public function numeric_string_version_one_is_accepted(): void
    {
        $normalized = $this->validator->validate($this->validManifest(['manifest_version' => '1']));

        self::assertSame(1, $normalized['manifest_version']);
    }

    #[Test]
    public function version_is_checked_before_content(): void
    {
        // Manifest à la fois hors version ET hors domaine de contenu : la cause
        // rapportée doit être la VERSION, pas le contenu (sinon la vraie cause
        // est masquée — iso-décision 33.2).
        try {
            $this->validator->validate($this->validManifest([
                'manifest_version' => 99,
                'type' => 'widget',
            ]));
            self::fail('Le manifest aurait dû être rejeté.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('manifest_version', $e->field);
        }
    }

    // ── Slug d'identifiant ────────────────────────────────────────────────

    /** @return list<array{0:string}> */
    public static function invalidIdProvider(): array
    {
        return [
            ['Doc'],          // majuscule
            ['-doc'],         // commence par un tiret
            ['mon doc'],      // espace
            ['doc/../etc'],   // traversée
            ['doc.json'],     // point
        ];
    }

    #[Test]
    #[DataProvider('invalidIdProvider')]
    public function invalid_id_slug_is_rejected(string $id): void
    {
        try {
            $this->validator->validate($this->validManifest(['id' => $id]));
            self::fail("L'identifiant « {$id} » aurait dû être rejeté.");
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('id', $e->field);
        }
    }

    #[Test]
    public function valid_id_slugs_are_accepted(): void
    {
        foreach (['doc', 'bbb2', 'mon-ext', 'mon_ext', '3d-viewer'] as $id) {
            $normalized = $this->validator->validate($this->validManifest(['id' => $id]));
            self::assertSame($id, $normalized['id']);
        }
    }

    // ── Champs optionnels mal typés ───────────────────────────────────────

    #[Test]
    public function non_array_scopes_is_rejected_naming_scopes(): void
    {
        try {
            $this->validator->validate($this->validManifest(['scopes' => 'profile']));
            self::fail('Des scopes non-tableau auraient dû être rejetés.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('scopes', $e->field);
        }
    }

    #[Test]
    public function non_string_dependency_is_rejected_naming_dependencies(): void
    {
        try {
            $this->validator->validate($this->validManifest(['dependencies' => [['id' => 'doc']]]));
            self::fail('Une dépendance non-chaîne aurait dû être rejetée.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('dependencies', $e->field);
        }
    }

    #[Test]
    public function blank_required_string_is_treated_as_missing(): void
    {
        try {
            $this->validator->validate($this->validManifest(['name' => '   ']));
            self::fail('Un nom vide aurait dû être rejeté.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('name', $e->field);
        }
    }

    // ── Correctifs de review 54.1 — un OBJET JSON n'est pas une LISTE ──────
    //
    // Finding #1 : `{"roles": {"a": "admin"}}` décode en tableau ASSOCIATIF PHP.
    // Avec un simple `is_array()`, il passait la validation et était ré-indexé
    // silencieusement en `["admin"]` — le repli tolérant que la décision #1 du
    // validateur refuse explicitement. Sans effet sur le dépôt (source
    // embarquée contrôlée), décisif dès l'Epic 56 (sources DISTANTES).

    #[Test]
    public function an_object_shaped_visibility_roles_is_rejected(): void
    {
        try {
            $this->validator->validate($this->validManifest([
                'visibility' => ['roles' => ['a' => 'admin', 'b' => 'prof']],
            ]));
            self::fail('Un objet JSON en visibility.roles aurait dû être rejeté, pas ré-indexé.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('visibility.roles', $e->field);
        }
    }

    #[Test]
    public function object_shaped_scopes_and_dependencies_are_rejected(): void
    {
        foreach (['scopes', 'dependencies'] as $field) {
            try {
                $this->validator->validate($this->validManifest([$field => ['x' => 'profile']]));
                self::fail("Un objet JSON en {$field} aurait dû être rejeté, pas ré-indexé.");
            } catch (InvalidExtensionManifestException $e) {
                self::assertSame($field, $e->field);
            }
        }
    }

    // ── Correctif de review 54.3 — schéma d'`entry_url` borné ─────────────

    #[Test]
    public function a_dangerous_entry_url_scheme_is_rejected(): void
    {
        // La Story 54.3 fait d'`entry_url` un href CLIQUABLE dans le lanceur,
        // exposé à tous les rôles visés. Décisif dès l'Epic 56 (sources
        // distantes, manifests non contrôlés).
        foreach ([
            'javascript:alert(document.cookie)',
            'data:text/html,<script>alert(1)</script>',
            'file:///etc/passwd',
            'doc',            // relatif : ambigu selon la page courante
            '//evil.example', // protocol-relative : sort de l'instance
        ] as $bad) {
            try {
                $this->validator->validate($this->validManifest(['entry_url' => $bad]));
                self::fail("L'entry_url « {$bad} » aurait dû être rejetée.");
            } catch (InvalidExtensionManifestException $e) {
                self::assertSame('entry_url', $e->field);
            }
        }
    }

    #[Test]
    public function absolute_paths_and_http_urls_remain_accepted(): void
    {
        foreach (['/doc', '/ext/bbb', 'https://visio.example.test', 'http://intra.local/app'] as $ok) {
            $normalized = $this->validator->validate($this->validManifest(['entry_url' => $ok]));
            self::assertSame($ok, $normalized['entry_url']);
        }
    }

    #[Test]
    public function a_sparse_list_is_rejected_as_an_object(): void
    {
        // Piège subtil : `[0 => 'a', 2 => 'b']` n'est pas une liste non plus.
        try {
            $this->validator->validate($this->validManifest([
                'visibility' => ['roles' => [0 => 'admin', 2 => 'prof']],
            ]));
            self::fail('Un tableau à index trous aurait dû être rejeté.');
        } catch (InvalidExtensionManifestException $e) {
            self::assertSame('visibility.roles', $e->field);
        }
    }
}
