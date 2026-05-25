<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Http;

use App\Models\Application;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 17.6 / AC1 / AC6.2 — Endpoint `/wpkg/linux_out.php`.
 *
 * Parité iso-legacy stricte (correctif post-review #1, décision Henri
 * « aligner sur les 6 siblings ») : le controller lit le contexte pré-calculé
 * `apps.<md5>` (store `app_context`, posé par `CacheAppContextWriter`), extrait
 * `raw['liste_applications']` (liste plate d'`app_id` lowercase pré-résolue à
 * l'assembly du script — équivalent natif de
 * `ApplicationScriptsGenerator::resolveInstalledApplications`), puis pour chaque
 * app applicable lit le nom du paquet APT (`<linux type="apt">@package`, fallback
 * strtolower(app_id)), `implode(" ", ...)`, Content-Type text/plain.
 *
 * **Plus de résolution par hostname** : le script `startup.linux` envoie un md5
 * (pas le hostname), donc on seede le contexte `apps.<md5>` comme les siblings.
 *
 * Les endpoints sont sous `local.request` ; les tests HTTP Laravel tournent
 * avec REMOTE_ADDR=127.0.0.1 (toujours autorisé par EnsureLocalRequest).
 */
class LinuxOutEndpointTest extends TestCase
{
    /** md5 valide (32 hex) — clé `apps.<md5>` du store `app_context`. */
    private const VALID_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::store('app_context')->flush();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    /**
     * Seede le contexte `apps.<md5>` (store `app_context`, TTL 1800s),
     * iso-runtime `CacheAppContextWriter::write()`. `liste_applications` est une
     * liste plate d'`app_id` lowercase (passthrough conservé dans `raw`).
     *
     * @param  list<string>  $listeApplications
     */
    private function seedContext(string $id, array $listeApplications): void
    {
        Cache::store('app_context')->put('apps.' . $id, [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'pclinux'],
            'salle' => 'salle1',
            'list' => [],
            'list_u' => [],
            'os' => 'linux',
            'time' => time(),
            // Passthrough iso-legacy (= `array_map('strtolower', ...id_nom_app)`).
            'liste_applications' => $listeApplications,
        ], 1800);
    }

    /**
     * AC1.4 — Parité plain-text : apt explicite + fallback app_id.
     *
     * Contexte avec 3 apps applicables (liste lowercase) :
     *   - firefox  : <linux type="apt" package="firefox-esr"/> → "firefox-esr"
     *   - vlc       : pas de noeud apt (app_id DB "VLC") → fallback "vlc"
     *   - chromium  : <linux type="apt" package="chromium"/> → "chromium"
     * Ordre = ordre de `liste_applications` (chromium, firefox, vlc).
     */
    #[Test]
    public function it_returns_apt_packages_plain_text_with_fallback(): void
    {
        Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => 'installed',
            'xml' => '<package id="firefox" name="Firefox"><linux type="apt" package="firefox-esr"/></package>',
        ]);
        // app_id en casse mixte côté DB — le matching est case-insensitive (parité legacy).
        Application::create([
            'app_id' => 'VLC',
            'name' => 'VLC',
            'status' => 'installed',
            'xml' => '<package id="VLC" name="VLC"><windows type="winget" id="VideoLAN.VLC"/></package>',
        ]);
        Application::create([
            'app_id' => 'chromium',
            'name' => 'Chromium',
            'status' => 'installed',
            'xml' => '<package id="chromium" name="Chromium"><linux type="apt" package="chromium"/></package>',
        ]);

        // liste_applications lowercase (ordre = ordre du contexte).
        $this->seedContext(self::VALID_ID, ['chromium', 'firefox', 'vlc']);

        $response = $this->get('/wpkg/linux_out.php?id=' . self::VALID_ID);

        $response->assertOk();
        // Parité mimetype text/plain (Laravel ajoute ; charset=utf-8 — pattern
        // natif accepté, iso AssociationsOutEndpointTest 16.13 ; le client
        // `for p in $packages` ignore le charset).
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));

        // chromium → "chromium" ; firefox → "firefox-esr" ; VLC → fallback "vlc".
        self::assertSame('chromium firefox-esr vlc', (string) $response->getContent());
    }

    /**
     * S1 (Henri) — Une app présente dans `liste_applications` mais au statut
     * non `Installed` (Available / UpdateAvailable) est EXCLUE (parité packages.xml
     * legacy qui ne contient que les apps Installed).
     */
    #[Test]
    public function it_excludes_applications_not_installed(): void
    {
        Application::create([
            'app_id' => 'firefox',
            'name' => 'Firefox',
            'status' => 'installed',
            'xml' => '<package id="firefox"><linux type="apt" package="firefox-esr"/></package>',
        ]);
        // Assignée au poste mais NON installée → exclue de la sortie.
        Application::create([
            'app_id' => 'gimp',
            'name' => 'Gimp',
            'status' => 'available',
            'xml' => '<package id="gimp"><linux type="apt" package="gimp"/></package>',
        ]);

        $this->seedContext(self::VALID_ID, ['firefox', 'gimp']);

        $response = $this->get('/wpkg/linux_out.php?id=' . self::VALID_ID);

        $response->assertOk();
        // Seul firefox (Installed) sort ; gimp (Available) est filtré.
        self::assertSame('firefox-esr', (string) $response->getContent());
    }

    /**
     * AC1.5 — id absent → 200 body "" (parité `linux_out.php:14-16`).
     */
    #[Test]
    public function it_returns_empty_body_when_id_is_missing(): void
    {
        $response = $this->get('/wpkg/linux_out.php');

        $response->assertOk();
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertSame('', (string) $response->getContent());
    }

    /**
     * #1 — id invalide (pas un md5 32 hex) → 200 body "" (le legacy
     * `apcu_fetch` sur une clé non posée retourne false → bloc non exécuté).
     */
    #[Test]
    public function it_returns_empty_body_when_id_is_not_md5(): void
    {
        $response = $this->get('/wpkg/linux_out.php?id=NOTANMD5');

        $response->assertOk();
        self::assertSame('', (string) $response->getContent());
    }

    /**
     * #1 — contexte expiré/absent (md5 valide mais pas de clé `apps.<md5>`) →
     * 200 body "" (parité : le legacy n'exécute pas le bloc `if ($info)`).
     */
    #[Test]
    public function it_returns_empty_body_when_context_absent(): void
    {
        // Aucun seedContext → store vide.
        $response = $this->get('/wpkg/linux_out.php?id=' . self::VALID_ID);

        $response->assertOk();
        self::assertSame('', (string) $response->getContent());
    }

    /**
     * AC1.1 — Accepte POST (parité `$_POST["id"]` legacy).
     */
    #[Test]
    public function it_accepts_post_with_id(): void
    {
        Application::create([
            'app_id' => 'gimp',
            'name' => 'Gimp',
            'status' => 'installed',
            'xml' => '<package id="gimp" name="Gimp"><linux type="apt" package="gimp"/></package>',
        ]);

        $this->seedContext(self::VALID_ID, ['gimp']);

        $response = $this->post('/wpkg/linux_out.php', ['id' => self::VALID_ID]);

        $response->assertOk();
        self::assertSame('gimp', (string) $response->getContent());
    }

    /**
     * AC4.2 — Un appel depuis une IP hors allowlist `local.request` est rejeté
     * (403), parité comportement `wpkg/reports/*`. On force REMOTE_ADDR à une
     * IP publique non whitelistée et on vide l'allowlist config.
     */
    #[Test]
    public function it_rejects_request_from_non_local_ip(): void
    {
        config()->set('sambaedu.wpkg.report_ingestion_allowed_ips', '');

        $response = $this->call(
            'GET',
            '/wpkg/linux_out.php',
            parameters: ['id' => self::VALID_ID],
            server: ['REMOTE_ADDR' => '8.8.8.8'],
        );

        self::assertSame(403, $response->getStatusCode());
    }
}
