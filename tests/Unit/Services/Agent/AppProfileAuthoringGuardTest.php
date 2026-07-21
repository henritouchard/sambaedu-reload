<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Services\Agent\Providers\AppProfileAuthoringGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 36.5 (AC1/AC4) — Tests Unit du garde-fou d'authoring `app_profile`.
 * Service PUR (aucune DB) : projections en entrée, violations nommées en sortie.
 */
class AppProfileAuthoringGuardTest extends TestCase
{
    private function guard(): AppProfileAuthoringGuard
    {
        return new AppProfileAuthoringGuard();
    }

    /**
     * @param  list<array<string,mixed>>  $apps
     * @return list<string>
     */
    private function violationsFor(array $apps): array
    {
        return $this->guard()->violations([[
            'capability' => 'roaming_app_profile',
            'warning' => null,
            'spec' => ['apps' => $apps],
        ]]);
    }

    private function validFirefox(): array
    {
        return [
            'app' => 'firefox',
            'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
            'server' => '.mozilla\\firefox\\managed.default',
            'profile_name' => 'managed.default',
            'install_hash' => '308046B0AF4A39CB',
            'cache_local' => 'cacheFirefox',
        ];
    }

    #[Test]
    public function valid_catalog_passes(): void
    {
        self::assertSame([], $this->violationsFor([$this->validFirefox()]));
    }

    #[Test]
    public function empty_minimal_fields_are_refused(): void
    {
        $v = $this->violationsFor([['app' => '', 'link' => '', 'server' => '', 'profile_name' => '']]);
        self::assertNotEmpty($v);
        self::assertStringContainsString('`app` vide', implode("\n", $v));
    }

    #[Test]
    public function absolute_link_is_refused(): void
    {
        $app = $this->validFirefox();
        $app['link'] = 'C:\\Users\\alice\\AppData\\Roaming\\Mozilla\\Firefox\\managed.default';
        $v = $this->violationsFor([$app]);
        self::assertStringContainsString('doit être RELATIF au profil Windows', implode("\n", $v));
    }

    #[Test]
    public function unc_server_is_refused(): void
    {
        $app = $this->validFirefox();
        $app['server'] = '\\\\srv\\users\\alice\\.mozilla';
        // profile_name doit rester cohérent avec le dernier segment de link.
        $v = $this->violationsFor([$app]);
        self::assertStringContainsString('doit être RELATIF au home réseau', implode("\n", $v));
    }

    #[Test]
    public function parent_segment_is_refused(): void
    {
        $app = $this->validFirefox();
        $app['server'] = '..\\..\\evasion\\managed.default';
        $v = $this->violationsFor([$app]);
        self::assertStringContainsString('RELATIF', implode("\n", $v));
    }

    #[Test]
    public function sambaedu_radical_is_refused_everywhere(): void
    {
        // piège n°1 (AC4) : un nom bâti sur sambaedu collisionnerait avec
        // legacy_cleanup (referencesSambaeduProfile).
        $app = [
            'app' => 'firefox',
            'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\sambaedu.default',
            'server' => '.mozilla\\firefox\\sambaedu.default',
            'profile_name' => 'sambaedu.default',
        ];
        $v = $this->violationsFor([$app]);
        $joined = implode("\n", $v);
        self::assertStringContainsString('radical interdit', $joined);
        // Signalé sur les trois champs.
        self::assertStringContainsString('`profile_name`', $joined);
        self::assertStringContainsString('`link`', $joined);
        self::assertStringContainsString('`server`', $joined);
    }

    #[Test]
    public function profile_name_mismatch_with_link_is_refused(): void
    {
        $app = $this->validFirefox();
        $app['profile_name'] = 'autre.default'; // ≠ dernier segment de link
        $v = $this->violationsFor([$app]);
        self::assertStringContainsString('≠ dernier segment de `link`', implode("\n", $v));
    }

    #[Test]
    public function non_hex_install_hash_is_refused(): void
    {
        $app = $this->validFirefox();
        $app['install_hash'] = 'ZZZZnothex';
        $v = $this->violationsFor([$app]);
        self::assertStringContainsString('non hexadécimal', implode("\n", $v));
    }

    #[Test]
    public function cache_local_with_separator_is_refused(): void
    {
        $app = $this->validFirefox();
        $app['cache_local'] = 'sub\\dir';
        $v = $this->violationsFor([$app]);
        self::assertStringContainsString('NOM de dossier simple', implode("\n", $v));
    }

    #[Test]
    public function absent_install_hash_and_cache_local_are_allowed(): void
    {
        $app = [
            'app' => 'firefox',
            'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
            'server' => '.mozilla\\firefox\\managed.default',
            'profile_name' => 'managed.default',
        ];
        self::assertSame([], $this->violationsFor([$app]));
    }

    #[Test]
    public function duplicate_app_in_same_projection_is_refused(): void
    {
        // C3 (post-review) : deux entrées pour la même `app` se battraient à
        // chaque logon.
        $a = $this->validFirefox();
        $b = $this->validFirefox();
        $b['link'] = 'AppData\\Roaming\\Mozilla\\Firefox\\autre.default';
        $b['server'] = '.mozilla\\firefox\\autre.default';
        $b['profile_name'] = 'autre.default';

        $v = $this->violationsFor([$a, $b]);
        self::assertStringContainsString("`app` 'firefox' en DOUBLON", implode("\n", $v));
    }

    #[Test]
    public function duplicate_link_case_insensitive_in_same_projection_is_refused(): void
    {
        // C3 (post-review) : même `link` (casse différente = même chemin Windows)
        // ⇒ même profiles.ini écrit deux fois.
        $a = $this->validFirefox();
        $b = $this->validFirefox();
        $b['app'] = 'thunderbird'; // app différente, mais MÊME link (casse ≠).
        $b['link'] = 'appdata\\roaming\\mozilla\\firefox\\managed.default';

        $v = $this->violationsFor([$a, $b]);
        self::assertStringContainsString('`link`', implode("\n", $v));
        self::assertStringContainsString('DOUBLON', implode("\n", $v));
    }
}
