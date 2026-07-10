<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.5 — Garde-fous du débranchement des crons legacy.
 *
 * Tests par lecture textuelle des scripts (patron `IpxeStaticAliasTest`) :
 *
 *  1. `update.sh` déclare ET appelle `ensure_legacy_crons_retired`, avec la liste
 *     EXPLICITE des 3 cibles legacy (sambaedu-web-common / -shares / -wpkg).
 *  2. Anti-glob : la fonction de retrait ne contient AUCUN glob `sambaedu-*` et ne
 *     retire jamais `sambaedu-{scheduler,system,boot-server}` (crons SE5 + gating 8.3).
 *  3. `update.sh` provisionne le cron système AVANT le retrait (ensure_system_cron
 *     déclarée + appelée avant ensure_legacy_crons_retired).
 *  4. `scripts/config/sambaedu-system.cron` existe et contient renew_ticket.sh (×2
 *     dont @reboot) + smbstatus.sh.
 *  5. Aucun `rm -rf` dans la fonction de retrait (retrait réversible par `mv`).
 */
class LegacyCronRetirementTest extends TestCase
{
    private function repoRoot(): string
    {
        $root = realpath(__DIR__ . '/../..');
        self::assertNotFalse($root, 'Racine du repo introuvable');

        return $root;
    }

    private function fileContent(string $relative): string
    {
        $path = $this->repoRoot() . '/' . $relative;
        self::assertFileExists($path, "$relative introuvable");

        return (string) file_get_contents($path);
    }

    /**
     * Extrait le corps de la fonction bash `$name` depuis `$content`
     * (de `name() {` jusqu'à la première ligne `}` en colonne 0).
     */
    private function functionBody(string $content, string $name): string
    {
        self::assertMatchesRegularExpression(
            '/^' . preg_quote($name, '/') . '\(\)\s*\{/m',
            $content,
            "La fonction $name() doit être déclarée.",
        );

        $start = (int) preg_match(
            '/^' . preg_quote($name, '/') . '\(\)\s*\{/m',
            $content,
            $m,
            PREG_OFFSET_CAPTURE,
        );
        self::assertSame(1, $start, "Déclaration de $name() introuvable.");
        $offset = $m[0][1];

        // Corps = jusqu'à la première accolade fermante en début de ligne.
        $rest = substr($content, $offset);
        $end = preg_match('/^\}/m', $rest, $mm, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $end, "Fin de la fonction $name() introuvable.");

        return substr($rest, 0, $mm[0][1]);
    }

    /** AC1 — ensure_legacy_crons_retired déclarée ET appelée, 3 cibles explicites. */
    #[Test]
    public function update_script_declares_and_calls_legacy_crons_retirement(): void
    {
        $content = $this->fileContent('scripts/update.sh');

        self::assertMatchesRegularExpression(
            '/^ensure_legacy_crons_retired\(\)\s*\{/m',
            $content,
            'La fonction ensure_legacy_crons_retired() doit être déclarée dans update.sh.',
        );

        self::assertMatchesRegularExpression(
            '/^\s*ensure_legacy_crons_retired\s*$/m',
            $content,
            'La fonction ensure_legacy_crons_retired doit être appelée dans le bloc principal.',
        );

        $body = $this->functionBody($content, 'ensure_legacy_crons_retired');
        foreach (['sambaedu-web-common', 'sambaedu-shares', 'sambaedu-wpkg'] as $target) {
            self::assertStringContainsString(
                $target,
                $body,
                "La fonction de retrait doit cibler explicitement $target.",
            );
        }
    }

    /**
     * AC1 (garde-fou epic) — anti-glob : la fonction de retrait ne doit JAMAIS
     * contenir de glob `sambaedu-*` ni retirer les crons SE5 / boot-server.
     */
    #[Test]
    public function retirement_function_has_no_glob_and_spares_se5_and_boot_server(): void
    {
        $content = $this->fileContent('scripts/update.sh');
        $body = $this->functionBody($content, 'ensure_legacy_crons_retired');

        self::assertStringNotContainsString(
            'sambaedu-*',
            $body,
            'ANTI-GLOB : la fonction de retrait ne doit jamais employer un glob sambaedu-* '
                . '(il avalerait scheduler / system / boot-server).',
        );

        foreach (['sambaedu-scheduler', 'sambaedu-system', 'sambaedu-boot-server'] as $spared) {
            self::assertStringNotContainsString(
                $spared,
                $body,
                "La fonction de retrait ne doit JAMAIS mentionner $spared "
                    . '(cron SE5 ou gating Story 8.3).',
            );
        }
    }

    /**
     * AC1 And / T1.4 — le cron système est provisionné AVANT le retrait
     * (ensure_system_cron déclarée + appelée avant ensure_legacy_crons_retired).
     */
    #[Test]
    public function system_cron_is_provisioned_before_retirement(): void
    {
        $content = $this->fileContent('scripts/update.sh');

        self::assertMatchesRegularExpression(
            '/^ensure_system_cron\(\)\s*\{/m',
            $content,
            'La fonction ensure_system_cron() doit être déclarée dans update.sh.',
        );

        $callSystem = strpos($content, "\n    ensure_system_cron\n");
        $callRetire = strpos($content, "\n    ensure_legacy_crons_retired\n");
        self::assertNotFalse($callSystem, 'Appel de ensure_system_cron introuvable dans main().');
        self::assertNotFalse($callRetire, 'Appel de ensure_legacy_crons_retired introuvable dans main().');
        self::assertLessThan(
            $callRetire,
            $callSystem,
            'ensure_system_cron doit être appelée AVANT ensure_legacy_crons_retired '
                . '(zéro fenêtre sans ticket Kerberos).',
        );
    }

    /** AC1 And — le cron système versionné contient renew_ticket ×2 (@reboot) + smbstatus. */
    #[Test]
    public function system_cron_file_contains_vital_lines(): void
    {
        $cron = $this->fileContent('scripts/config/sambaedu-system.cron');

        // Compter uniquement les lignes cron ACTIVES (hors commentaires # et
        // directives SHELL/PATH) invoquant renew_ticket.sh.
        $activeRenew = 0;
        foreach (preg_split('/\R/', $cron) ?: [] as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            if (str_contains($trimmed, 'renew_ticket.sh')) {
                $activeRenew++;
            }
        }

        self::assertSame(
            2,
            $activeRenew,
            'Le cron système doit contenir DEUX lignes cron actives renew_ticket.sh (périodique + @reboot).',
        );
        self::assertMatchesRegularExpression(
            '/@reboot\s+www-admin\s+\S*renew_ticket\.sh/',
            $cron,
            'Le cron système doit contenir une ligne @reboot renew_ticket.sh.',
        );
        self::assertStringContainsString(
            'smbstatus.sh',
            $cron,
            'Le cron système doit contenir smbstatus.sh.',
        );
    }

    /**
     * Review 38.5 #1 — robustesse greenfield : avec `set -e`, les deux
     * fonctions doivent être appelées EN TÊTE de main() (avant update_composer,
     * première étape susceptible d'échouer) — sinon un échec d'une étape
     * antérieure laisse les crons legacy actifs en silence.
     */
    #[Test]
    public function cron_functions_run_before_any_fallible_step(): void
    {
        $content = $this->fileContent('scripts/update.sh');

        $callRetire = strpos($content, "\n    ensure_legacy_crons_retired\n");
        $callComposer = strpos($content, "\n    update_composer\n");
        self::assertNotFalse($callRetire, 'Appel de ensure_legacy_crons_retired introuvable.');
        self::assertNotFalse($callComposer, 'Appel de update_composer introuvable.');
        self::assertLessThan(
            $callComposer,
            $callRetire,
            'ensure_legacy_crons_retired doit être appelée AVANT update_composer '
                . '(set -e : un échec en aval ne doit pas laisser les crons legacy actifs).',
        );
    }

    /**
     * Review 38.5 #2 — install.sh (T5.1) : install_system_cron déclarée ET
     * appelée, et AUCUN retrait direct / glob sambaedu-* dans install.sh
     * (le retrait passe exclusivement par le replay update.sh).
     */
    #[Test]
    public function install_script_provisions_system_cron_and_never_retires_directly(): void
    {
        $content = $this->fileContent('scripts/install.sh');

        self::assertMatchesRegularExpression(
            '/^install_system_cron\(\)\s*\{/m',
            $content,
            'La fonction install_system_cron() doit être déclarée dans install.sh.',
        );
        self::assertMatchesRegularExpression(
            '/^\s*install_system_cron\s*$/m',
            $content,
            'install_system_cron doit être appelée dans le bloc principal d\'install.sh.',
        );

        self::assertStringNotContainsString(
            'sambaedu-*',
            $content,
            'ANTI-GLOB : install.sh ne doit jamais employer un glob sambaedu-*.',
        );
        foreach (['sambaedu-web-common', 'sambaedu-shares'] as $legacy) {
            self::assertStringNotContainsString(
                $legacy,
                $content,
                "install.sh ne doit pas retirer $legacy directement (retrait = replay update.sh).",
            );
        }
    }

    /** T5.1 — retrait réversible : aucun rm -rf dans la fonction de retrait. */
    #[Test]
    public function retirement_function_uses_no_rm_rf(): void
    {
        $content = $this->fileContent('scripts/update.sh');
        $body = $this->functionBody($content, 'ensure_legacy_crons_retired');

        self::assertDoesNotMatchRegularExpression(
            '/rm\s+-[a-z]*r[a-z]*f/',
            $body,
            'Le retrait doit être réversible (mv vers /var/backups) — jamais rm -rf.',
        );
    }
}
