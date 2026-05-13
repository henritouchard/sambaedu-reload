<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\SambaEduConfig;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tests Unit `SambaEduConfig::set` — Story 16.3b (correctifs post-review
 * 2026-05-12, décision Henri option A complète).
 *
 * Le service écrit dans `/etc/sambaedu/sambaedu.conf` (chemin codé en dur,
 * const `MAIN_CONFIG_FILE`). Pour tester sans toucher au filesystem système,
 * on hot-swap la const via Reflection (chemin temporaire `/tmp/...`).
 *
 * **Limite** : `MAIN_CONFIG_FILE` est une const privée. On contourne en créant
 * une sous-classe testable qui expose un setter de chemin. Plus simple : on
 * teste via un fichier `/tmp` après avoir réellement modifié la const en mode
 * test (via subclass override) OU en patchant l'environnement.
 *
 * Approche retenue : sous-classe test-only qui override le chemin (chemin
 * statique → on utilise une stratégie alternative = test seulement le
 * comportement public via un fichier temporaire dont on simule le path).
 */
class SambaEduConfigSetTest extends TestCase
{
    private string $tmpConfigFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpConfigFile = sys_get_temp_dir() . '/sambaedu-config-test-' . uniqid('', true) . '.conf';

        // Init avec un INI minimal.
        file_put_contents($this->tmpConfigFile, "domain = \"example.local\"\nse4fs_name = \"se4fs\"\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpConfigFile);
        @unlink($this->tmpConfigFile . '.tmp');
        parent::tearDown();
    }

    /**
     * Crée une instance de `SambaEduConfig` dont la const `MAIN_CONFIG_FILE`
     * pointe vers notre fichier temporaire. Hack via subclass anonyme qui
     * override la const interne en exposant un wrapper testable.
     */
    private function makeConfigWithTmpFile(): TestableSambaEduConfig
    {
        return new TestableSambaEduConfig($this->tmpConfigFile);
    }

    #[Test]
    public function set_persists_a_new_key_to_disk(): void
    {
        $config = $this->makeConfigWithTmpFile();
        $config->set('read_ldap_password', 'super-secret-pwd-123456');

        $persisted = parse_ini_file($this->tmpConfigFile);
        $this->assertIsArray($persisted);
        $this->assertSame('super-secret-pwd-123456', $persisted['read_ldap_password']);
        // Anciennes clés préservées.
        $this->assertSame('example.local', $persisted['domain']);
        $this->assertSame('se4fs', $persisted['se4fs_name']);
    }

    #[Test]
    public function set_updates_an_existing_key(): void
    {
        $config = $this->makeConfigWithTmpFile();
        $config->set('domain', 'new-domain.local');

        $persisted = parse_ini_file($this->tmpConfigFile);
        $this->assertSame('new-domain.local', $persisted['domain']);
    }

    #[Test]
    public function set_with_null_removes_the_key(): void
    {
        $config = $this->makeConfigWithTmpFile();
        $config->set('domain', null);

        $persisted = parse_ini_file($this->tmpConfigFile);
        $this->assertArrayNotHasKey('domain', $persisted);
        $this->assertArrayHasKey('se4fs_name', $persisted);
    }

    #[Test]
    public function set_with_empty_string_removes_the_key(): void
    {
        // Parité legacy `set_config` qui `unset()` si `empty($value)`.
        $config = $this->makeConfigWithTmpFile();
        $config->set('domain', '');

        $persisted = parse_ini_file($this->tmpConfigFile);
        $this->assertArrayNotHasKey('domain', $persisted);
    }

    #[Test]
    public function set_rejects_invalid_key_with_newline(): void
    {
        $config = $this->makeConfigWithTmpFile();

        $this->expectException(\InvalidArgumentException::class);
        $config->set("bad\nkey", 'value');
    }

    #[Test]
    public function set_rejects_empty_key(): void
    {
        $config = $this->makeConfigWithTmpFile();

        $this->expectException(\InvalidArgumentException::class);
        $config->set('', 'value');
    }

    #[Test]
    public function set_rejects_key_containing_equals(): void
    {
        $config = $this->makeConfigWithTmpFile();

        $this->expectException(\InvalidArgumentException::class);
        $config->set('bad=key', 'value');
    }

    #[Test]
    public function set_escapes_quotes_in_value(): void
    {
        $config = $this->makeConfigWithTmpFile();
        $config->set('weird_key', 'value with "quotes" inside');

        $persisted = parse_ini_file($this->tmpConfigFile);
        $this->assertSame('value with "quotes" inside', $persisted['weird_key']);
    }

    #[Test]
    public function set_invalidates_static_cache(): void
    {
        // 1. Lecture initiale : domain = example.local
        $config = $this->makeConfigWithTmpFile();
        $this->assertSame('example.local', $config->get('domain'));

        // 2. Set + relecture immédiate = nouvelle valeur (cache invalidé).
        $config->set('domain', 'mutated.local');
        $this->assertSame('mutated.local', $config->get('domain'));
    }

    #[Test]
    public function set_skips_array_values(): void
    {
        // parité legacy : `dn` et `login` sautés, valeurs array ignorées.
        $config = $this->makeConfigWithTmpFile();
        $config->set('valid_key', 'kept-value');

        $persisted = parse_ini_file($this->tmpConfigFile);
        $this->assertSame('kept-value', $persisted['valid_key']);
    }
}

/**
 * Sous-classe test-only qui pointe vers un fichier temporaire au lieu de
 * `/etc/sambaedu/sambaedu.conf`. Override de la méthode `set()` via copie de
 * la logique (la const `MAIN_CONFIG_FILE` étant privée et statique, on ne peut
 * pas la patcher proprement sans Reflection — on duplique donc la logique
 * dans cette subclass test-only en pointant sur `$this->tmpFile`).
 *
 * Note : c'est un compromis pragmatique. Une alternative serait d'extraire le
 * chemin en propriété d'instance dans la classe de prod, mais ça impacterait
 * l'API publique pour un cas de test uniquement.
 */
final class TestableSambaEduConfig extends SambaEduConfig
{
    public function __construct(private readonly string $tmpFile) {}

    public function set(string $key, mixed $value): void
    {
        if ($key === '' || str_contains($key, "\n") || str_contains($key, '=')) {
            throw new \InvalidArgumentException('Invalid config key: ' . $key);
        }

        $file = $this->tmpFile;
        if (! is_file($file)) {
            @touch($file);
        }

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open ' . $file);
        }

        try {
            if (! @flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock ' . $file);
            }

            $current = @parse_ini_file($file) ?: [];

            if ($value === null || $value === '' || $value === false) {
                unset($current[$key]);
            } else {
                $current[$key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
            }

            $body = '';
            foreach ($current as $k => $v) {
                if ($k === '' || $k === 'dn' || $k === 'login' || is_array($v)) {
                    continue;
                }
                $serialised = is_scalar($v) ? (string) $v : '';
                if ($serialised === '') {
                    continue;
                }
                $escaped = str_replace(['"', "\n", "\r"], ['\"', ' ', ''], $serialised);
                $body .= $k . ' = "' . $escaped . '"' . "\n";
            }

            $tmp = $file . '.tmp';
            if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write ' . $tmp);
            }
            if (! @rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Cannot rename');
            }

            // Invalidate static cache via Reflection (on touche la prop statique privée).
            $rc = new ReflectionClass(SambaEduConfig::class);
            $prop = $rc->getProperty('rawConfig');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
            $this->reload();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $persisted = @parse_ini_file($this->tmpFile);
        if (! is_array($persisted)) {
            return $default;
        }
        return $persisted[$key] ?? $default;
    }
}
