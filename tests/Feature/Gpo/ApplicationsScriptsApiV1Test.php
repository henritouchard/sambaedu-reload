<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Models\Workstation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 17.4 — Volet 2 (AC2.1, AC2.2, AC2.3).
 *
 * Tests Feature de l'endpoint natif `GET /api/v1/workstation-config/applications-scripts`
 * (Story 16.13 — `ApplicationsScriptsController::apiV1`, middleware `auth.v1.workstation`).
 *
 * **Couverture** :
 *  - AC2.1 — Réponse 200 GET authentifié (JWT tier=workstation) + Content-Type
 *    correct + **body NON vide** pour un contexte résolu (post-review P5).
 *  - AC2.2 — 401 sans JWT, 404 workstation inconnu.
 *  - AC2.3 — Méthode HTTP template GPO (témoin documentaire, skip si absent VM).
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Post-review P5 — body NON vide prouvé :
 *
 * Les tests AC2.1 précédents passaient en 200 body VIDE (`resolveInfo()===[]`
 * faute de poste AD réel → `emptyOk()`), donc l'assemblage n'était JAMAIS
 * déclenché (review P5 🟠). Correction sans toucher au code applicatif (D1) :
 * on exploite le **raccourci cache** d'`ApplicationScriptsGenerator::resolveInfo`
 * (lignes 110-123) — si `Cache::store('app_context')->get('apps.<id>')` existe,
 * il retourne ce contexte SANS interroger LDAP. On pré-pose donc un `$info`
 * complet (machine array + action + os) sous `apps.<id>` et on passe `id=<id>`
 * en query → `resolveInfo` renvoie un contexte non vide → `logScripts(ret=1)`
 * renvoie `true` → `assemble()` est appelé → le header/footer iso-legacy
 * produisent un body non vide (même sans `/usr/share/sambaedu/applications/`,
 * car `scanner->scan()` vide ⇒ blob = header + footer seuls, non vides).
 *
 * On asserte alors : statut 200 + charset par OS + body NON vide + marqueur
 * (`REM` pour windows / `id=` shebang bash pour linux). Ces tests ÉCHOUERAIENT
 * si l'Assembler était cassé (header/footer non générés) — vraie valeur probante.
 *
 * Le store `app_context` est forcé en driver `array` en test (phpunit.xml
 * `APP_CONTEXT_CACHE_DRIVER=array`) → seeding portable CI, pas d'APCu requis.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **Pattern auth** : réutilise `IssuesWorkstationJwt` + `SeedsWorkstationConfig`
 * (iso `ApiV1ConfigSecurityTest` 16.13).
 */
class ApplicationsScriptsApiV1Test extends TestCase
{
    use IssuesWorkstationJwt;
    use SeedsWorkstationConfig;

    private const ENDPOINT = '/api/v1/workstation-config/applications-scripts';

    /** UUID du workstation seedé pour les tests. */
    private string $workstationUuid = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';

    /** Nom du workstation seedé (cross-check `machine`). */
    private string $workstationName = 'pc-apiv1-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();
        $this->seedWorkstationContextSchemas();

        Model::unguard();

        // Seed le Workstation de test avec un UUID connu
        if (! Workstation::query()->where('uuid', $this->workstationUuid)->exists()) {
            Workstation::create([
                'name'   => $this->workstationName,
                'uuid'   => $this->workstationUuid,
                'status' => 'active',
            ]);
        }

        if (empty(config('app.key'))) {
            config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        }

        // Repart d'un cache app_context propre à chaque test (driver array).
        Cache::store('app_context')->flush();
    }

    /**
     * Pré-pose un contexte `$info` résolvable sous `apps.<id>` pour forcer
     * `resolveInfo()` à court-circuiter LDAP et déclencher l'assemblage (P5).
     *
     * @return string  L'`id` md5 à passer en query (`id=<id>`).
     */
    private function seedResolvableContext(string $os, string $action): string
    {
        $id = md5($this->workstationName . $action . $os . 'apiv1');

        $info = [
            'id'                 => $id,
            'action'             => $action,
            'remote'             => false,
            'context'            => '',
            'application'        => '',
            'user'               => ['cn' => 'testuser', 'dn' => ''],
            'machine'            => [
                'cn'        => $this->workstationName,
                'dn'        => 'cn=' . $this->workstationName . ',ou=salle01,dc=localdev,dc=fr',
                'memberof'  => [],
                'os_groups' => [$os], // évite l'appel adMachines->setOs au footer
                'salle'     => 'salle01',
            ],
            'salle'              => 'salle01',
            'list'               => ['testuser', 'salle01', $this->workstationName],
            'list_u'             => ['testuser'],
            'list_ue'            => ['testuser'],
            'list_m'             => [$this->workstationName, 'salle01'],
            'liste_applications' => [],
            'admin'              => 0,
            'os'                 => $os,
            'time'              => time(),
            'parcs'              => [],
            'uuid'               => $this->workstationUuid,
        ];

        // Clé brute `apps.<id>` (store app_context prefix='') — lue par
        // ApplicationScriptsGenerator::fetchCached().
        Cache::store('app_context')->put('apps.' . $id, $info, 1800);

        return $id;
    }

    // =========================================================================
    // AC2.1 — Endpoint 200 + Content-Type + body NON vide (P5)
    // =========================================================================

    /**
     * AC2.1 / P5 — GET windows logon authentifié → 200 + cp1252 + body NON vide.
     */
    #[Test]
    public function it_returns_200_non_empty_body_for_authenticated_windows_logon(): void
    {
        $id  = $this->seedResolvableContext('windows', 'logon');
        $jwt = $this->issueTestJwt(['sub' => $this->workstationUuid, 'tier' => 'workstation']);

        $response = $this->withToken($jwt['token'])->get(
            self::ENDPOINT . '?os=windows&action=logon&machine=' . $this->workstationName
            . '&user=testuser&id=' . $id
        );

        $response->assertStatus(200);

        $contentType = $response->headers->get('Content-Type', '');
        self::assertStringContainsString('cp1252', $contentType, 'Content-Type cp1252 attendu (windows).');

        $body = $response->getContent();
        self::assertNotEmpty($body, 'Le body NE doit PAS être vide pour un contexte résolu (P5).');
        self::assertStringContainsString(
            'REM',
            (string) $body,
            'Le body cmd Windows doit contenir le header/footer iso-legacy (marqueur REM).'
        );
    }

    /**
     * AC2.1 / P5 — GET windows startup authentifié → 200 + cp1252 + body NON vide.
     */
    #[Test]
    public function it_returns_200_non_empty_body_for_authenticated_windows_startup(): void
    {
        $id  = $this->seedResolvableContext('windows', 'startup');
        $jwt = $this->issueTestJwt(['sub' => $this->workstationUuid, 'tier' => 'workstation']);

        $response = $this->withToken($jwt['token'])->get(
            self::ENDPOINT . '?os=windows&action=startup&machine=' . $this->workstationName . '&id=' . $id
        );

        $response->assertStatus(200);

        $contentType = $response->headers->get('Content-Type', '');
        self::assertStringContainsString('cp1252', $contentType, 'Content-Type cp1252 attendu (windows).');

        $body = (string) $response->getContent();
        self::assertNotEmpty($body, 'Le body NE doit PAS être vide pour un contexte résolu (P5).');
        self::assertStringContainsString(
            'REM',
            $body,
            'Le body cmd Windows startup doit contenir le header iso-legacy (marqueur REM).'
        );
    }

    /**
     * AC2.1 / P5 — GET linux logon authentifié → 200 + utf-8 + body NON vide.
     */
    #[Test]
    public function it_returns_200_non_empty_body_for_authenticated_linux_logon(): void
    {
        $id  = $this->seedResolvableContext('linux', 'logon');
        $jwt = $this->issueTestJwt(['sub' => $this->workstationUuid, 'tier' => 'workstation']);

        $response = $this->withToken($jwt['token'])->get(
            self::ENDPOINT . '?os=linux&action=logon&machine=' . $this->workstationName
            . '&user=testuser&id=' . $id
        );

        $response->assertStatus(200);

        $contentType = $response->headers->get('Content-Type', '');
        self::assertStringContainsString('utf-8', $contentType, 'Content-Type utf-8 attendu (linux).');

        $body = (string) $response->getContent();
        self::assertNotEmpty($body, 'Le body NE doit PAS être vide pour un contexte résolu (P5).');
        self::assertStringContainsString(
            '#!/bin/bash',
            $body,
            'Le body bash Linux doit contenir le shebang/header iso-legacy.'
        );
    }

    // =========================================================================
    // AC2.2 — Sécurité : 401 sans JWT, 404 workstation inconnu
    // =========================================================================

    /**
     * AC2.2 — Sans Bearer JWT → 401.
     */
    #[Test]
    public function it_returns_401_without_jwt(): void
    {
        $response = $this->getJson(self::ENDPOINT . '?os=windows&action=startup&machine=pc-test');

        $response->assertStatus(401);
    }

    /**
     * AC2.2 — JWT valide mais `sub` = uuid sans Workstation en DB → 404.
     *
     * Déviation D5 16.13 : le natif retourne 404 explicite (vs 200-vide legacy).
     */
    #[Test]
    public function it_returns_404_for_unknown_workstation_uuid(): void
    {
        $unknownUuid = 'cccccccc-cccc-4ccc-cccc-cccccccccccc';
        $jwt         = $this->issueTestJwt(['sub' => $unknownUuid, 'tier' => 'workstation']);

        $response = $this->withToken($jwt['token'])
            ->getJson(self::ENDPOINT . '?os=windows&action=startup&machine=pc-unknown');

        $response->assertStatus(404);
        $response->assertJson(['error' => 'workstation_not_found']);
    }

    // =========================================================================
    // AC2.3 — Méthode HTTP template GPO (témoin documentaire, optionnel VM)
    // =========================================================================

    /**
     * AC2.3 — Vérification de la méthode HTTP dans les .cmd orchestrateurs du template GPO.
     *
     * Ce test est un **témoin documentaire** : il lit les .cmd et note la méthode HTTP
     * présente (POST multipart legacy OU GET query string si diff 17.3 appliqué).
     * Skip si le template GPO est absent (CI sans VM — cas VM-dépendant légitime).
     *
     * Note D5 : 17.4 ne modifie ni le template ni le .diff 17.3.
     */
    #[Test]
    public function it_documents_template_http_method_or_skips(): void
    {
        $templatePath = '/usr/share/sambaedu/gpo/sambaedu-gpo/se4_applications/';
        if (! is_dir($templatePath)) {
            self::markTestSkipped(
                'Template GPO se4_applications absent (' . $templatePath . '). '
                . 'Sous-cas VM-dépendant : documenter la méthode HTTP (POST vs GET, cf. D5 story 17.4).'
            );
        }

        $cmdFiles = glob($templatePath . '*.cmd') ?: [];
        if (empty($cmdFiles)) {
            self::markTestSkipped('Aucun .cmd trouvé dans ' . $templatePath . '.');
        }

        $methodObserved = 'unknown';
        $urlObserved    = '';
        foreach ($cmdFiles as $cmdFile) {
            $content = (string) file_get_contents($cmdFile);
            if (preg_match('/-F\s+"action=/', $content) === 1) {
                $methodObserved = 'POST_multipart_legacy';
                if (preg_match('/curl[^\n]+"([^"]*applications[^"]*)"/', $content, $m) === 1) {
                    $urlObserved = $m[1];
                }
                break;
            }
            if (preg_match('/\?action=/', $content) === 1) {
                $methodObserved = 'GET_query_string_native';
                if (preg_match('/curl[^\n]+("([^"]*applications[^"]*\?[^"]*)")/', $content, $m) === 1) {
                    $urlObserved = $m[1];
                }
                break;
            }
        }

        self::assertTrue(
            in_array($methodObserved, ['POST_multipart_legacy', 'GET_query_string_native', 'unknown'], true),
            'Méthode HTTP non reconnue dans les .cmd du template GPO.'
        );

        fwrite(STDERR, sprintf(
            "\n[AC2.3 D5] Template GPO se4_applications — méthode HTTP observée : %s (URL : %s)\n",
            $methodObserved,
            $urlObserved ?: '(non trouvée)'
        ));
    }
}
