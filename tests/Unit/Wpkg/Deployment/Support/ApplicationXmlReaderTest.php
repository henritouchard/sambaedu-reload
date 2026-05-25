<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Support;

use App\Models\Application;
use App\Wpkg\Deployment\Support\ApplicationXmlReader;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 17.6 / correctif post-review #2 — Tests Unit dédiés au helper
 * `ApplicationXmlReader`.
 *
 * Couvre l'extraction XML pure (aucun accès DB) :
 *   - `aptPackageFor()` : noeud apt simple, multi-noeud (dernière occurrence
 *     l'emporte, parité legacy `linux_out.php:30-34`), fallback strtolower(app_id).
 *   - `wingetEntriesFor()` : Id/Source/Version/Custom/Override, défaut Source,
 *     multi-package indépendant (#7 — pas de pollution inter-packages),
 *     noeud non-winget ignoré.
 *   - parsing XML invalide (skip + log, pas de crash) et XML NULL/vide.
 *
 * Les instances `Application` sont créées en mémoire (`new`, sans persistance) :
 * ces méthodes lisent uniquement `$application->xml` / `$application->app_id`.
 *
 * Parité référence : `sambaedu/wpkg/linux_out.php:26-43` +
 * `sambaedu/wpkg/winget_out.php:70-100`.
 */
class ApplicationXmlReaderTest extends TestCase
{
    private ApplicationXmlReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reader = new ApplicationXmlReader();
    }

    /**
     * Construit un Application en mémoire avec un app_id + un fragment xml.
     */
    private function app(string $appId, ?string $xml): Application
    {
        $app = new Application();
        $app->app_id = $appId;
        $app->xml = $xml;

        return $app;
    }

    /* ----------------------------------------------------------------
     * aptPackageFor()
     * ---------------------------------------------------------------- */

    /**
     * #2 — Noeud apt explicite : l'attribut `package` est retourné.
     */
    #[Test]
    public function apt_package_for_returns_explicit_package(): void
    {
        $app = $this->app('firefox', '<package id="firefox"><linux type="apt" package="firefox-esr"/></package>');

        self::assertSame('firefox-esr', $this->reader->aptPackageFor($app));
    }

    /**
     * #2 — Multi-noeud apt : la DERNIÈRE occurrence l'emporte (parité legacy
     * `foreach` sans `break`, `linux_out.php:30-34`).
     */
    #[Test]
    public function apt_package_for_last_node_wins_on_multiple(): void
    {
        $xml = '<package id="x">'
            . '<linux type="apt" package="premier"/>'
            . '<linux type="snap" package="ignore-snap"/>'
            . '<linux type="apt" package="dernier"/>'
            . '</package>';
        $app = $this->app('x', $xml);

        self::assertSame('dernier', $this->reader->aptPackageFor($app));
    }

    /**
     * #2 — Pas de noeud apt → fallback strtolower(app_id) (parité `:36-38`).
     */
    #[Test]
    public function apt_package_for_falls_back_to_lowercase_app_id(): void
    {
        $app = $this->app('VLC', '<package id="VLC"><windows type="winget" id="VideoLAN.VLC"/></package>');

        self::assertSame('vlc', $this->reader->aptPackageFor($app));
    }

    /**
     * #2 — Noeud apt présent mais `package` vide → fallback strtolower(app_id).
     */
    #[Test]
    public function apt_package_for_falls_back_when_package_attr_empty(): void
    {
        $app = $this->app('GIMP', '<package id="GIMP"><linux type="apt" package=""/></package>');

        self::assertSame('gimp', $this->reader->aptPackageFor($app));
    }

    /**
     * #2 — XML invalide → skip + log + fallback strtolower(app_id) (pas de crash).
     */
    #[Test]
    public function apt_package_for_invalid_xml_logs_and_falls_back(): void
    {
        Log::shouldReceive('channel')->with('wpkg-deploy')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $app = $this->app('BrokenApp', '<package id="broken"><linux type="apt" package="x"</package>');

        self::assertSame('brokenapp', $this->reader->aptPackageFor($app));
    }

    /**
     * #2 — XML NULL / vide → fallback strtolower(app_id) sans log d'erreur
     * (xml absent ≠ xml cassé : pas de warning).
     */
    #[Test]
    public function apt_package_for_null_or_empty_xml_falls_back(): void
    {
        Log::shouldReceive('channel')->with('wpkg-deploy')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        self::assertSame('appnull', $this->reader->aptPackageFor($this->app('AppNull', null)));
        self::assertSame('appvide', $this->reader->aptPackageFor($this->app('AppVide', '')));
    }

    /* ----------------------------------------------------------------
     * wingetEntriesFor()
     * ---------------------------------------------------------------- */

    /**
     * #2 — Entrée winget complète : Id, Source, Version, Custom, Override.
     */
    #[Test]
    public function winget_entries_for_extracts_all_attributes(): void
    {
        $xml = '<package id="firefox">'
            . '<windows type="winget" id="Mozilla.Firefox" version="125.0" source="msstore" '
            . 'custom="--silent" override="/qn"/>'
            . '</package>';
        $entries = $this->reader->wingetEntriesFor($this->app('firefox', $xml));

        self::assertCount(1, $entries);
        $e = $entries[0];
        self::assertSame('Mozilla.Firefox', $e['Id']);
        self::assertSame('125.0', $e['Version']);
        self::assertSame('msstore', $e['Source']);
        self::assertSame('--silent', $e['Custom']);
        self::assertSame('/qn', $e['Override']);
    }

    /**
     * #2 — Source par défaut `winget` quand l'attribut source est absent ;
     * Version/Custom/Override omis si vides.
     */
    #[Test]
    public function winget_entries_for_defaults_source_and_omits_empty(): void
    {
        $entries = $this->reader->wingetEntriesFor(
            $this->app('x', '<package id="x"><windows type="winget" id="Foo.Bar"/></package>')
        );

        self::assertCount(1, $entries);
        $e = $entries[0];
        self::assertSame('Foo.Bar', $e['Id']);
        self::assertSame('winget', $e['Source']);
        self::assertArrayNotHasKey('Version', $e);
        self::assertArrayNotHasKey('Custom', $e);
        self::assertArrayNotHasKey('Override', $e);
    }

    /**
     * #2 / #7 — Multi-package : chaque entrée est indépendante (`$app` réinitialisé
     * à chaque noeud — corrige la pollution inter-packages du legacy). La 2e
     * entrée ne doit PAS hériter du Version/Custom de la 1re.
     */
    #[Test]
    public function winget_entries_for_does_not_leak_attributes_between_nodes(): void
    {
        $xml = '<package id="multi">'
            . '<windows type="winget" id="A.App" version="1.0" custom="--a"/>'
            . '<windows type="winget" id="B.App"/>'
            . '</package>';
        $entries = $this->reader->wingetEntriesFor($this->app('multi', $xml));

        self::assertCount(2, $entries);
        // 1re entrée : Version + Custom présents.
        self::assertSame('A.App', $entries[0]['Id']);
        self::assertSame('1.0', $entries[0]['Version']);
        self::assertSame('--a', $entries[0]['Custom']);
        // 2e entrée : aucune fuite de Version/Custom de la 1re.
        self::assertSame('B.App', $entries[1]['Id']);
        self::assertArrayNotHasKey('Version', $entries[1]);
        self::assertArrayNotHasKey('Custom', $entries[1]);
        self::assertSame('winget', $entries[1]['Source']);
    }

    /**
     * #2 — Noeud `<windows>` non-winget (ex. type msi) ignoré.
     */
    #[Test]
    public function winget_entries_for_ignores_non_winget_windows_nodes(): void
    {
        $xml = '<package id="x">'
            . '<windows type="msi" id="Some.Msi"/>'
            . '<windows type="winget" id="Real.Winget"/>'
            . '</package>';
        $entries = $this->reader->wingetEntriesFor($this->app('x', $xml));

        self::assertCount(1, $entries);
        self::assertSame('Real.Winget', $entries[0]['Id']);
    }

    /**
     * #2 — XML invalide → skip + log + [] (pas de crash).
     */
    #[Test]
    public function winget_entries_for_invalid_xml_logs_and_returns_empty(): void
    {
        Log::shouldReceive('channel')->with('wpkg-deploy')->andReturnSelf();
        Log::shouldReceive('warning')->once();

        $entries = $this->reader->wingetEntriesFor(
            $this->app('broken', '<package id="broken"><windows type="winget" id="x"</package>')
        );

        self::assertSame([], $entries);
    }

    /**
     * #2 — XML NULL / vide → [] sans log d'erreur.
     */
    #[Test]
    public function winget_entries_for_null_or_empty_xml_returns_empty(): void
    {
        Log::shouldReceive('channel')->with('wpkg-deploy')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        self::assertSame([], $this->reader->wingetEntriesFor($this->app('a', null)));
        self::assertSame([], $this->reader->wingetEntriesFor($this->app('b', '')));
    }
}
