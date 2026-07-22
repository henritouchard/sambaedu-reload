<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Garde-fou : plus AUCUNE directive du vhost SER ne doit pointer dans l'arbre
 * legacy `/var/www/sambaedu`.
 *
 * Le cas constaté : jusqu'à la Story 38.1 le vhost SER portait
 * `Alias /ipxe /var/www/sambaedu/ipxe`. Sur une instance dont le vhost date
 * d'avant, éteindre le legacy (`se4:unplug`, ou un simple `mv` de test) fait
 * disparaître la cible de l'Alias ET le bloc `<Directory>` qui portait le
 * `FallbackResource /index.php`. Ce ne sont alors pas seulement les statiques
 * iPXE qui tombent (boot.ipxe, undionly.kpxe, snponly_x64.efi) : ce sont TOUTES
 * les routes Laravel `/ipxe/*` — boot, admin, maintenance, enrollment — parce
 * que l'Alias court-circuite le DocumentRoot avant que Laravel soit atteint.
 * Plus aucun poste du parc ne démarre en PXE, en silence, et `se4:replug`
 * répare par accident (ce qui masque la cause).
 *
 * La 38.1 avait corrigé les templates et les a verrouillés
 * ({@see \Tests\Architecture\IpxeStaticAliasTest}) ; ce qui manquait, c'est le
 * cas de l'instance DÉJÀ DÉPLOYÉE, dont le vhost sur disque reste en arrière et
 * qu'aucun update ne réécrivait. D'où trois verrous :
 *   T1 : aucun template livré ne porte de directive legacy — assertion
 *        généralisée (toute directive, pas seulement `/ipxe`) ;
 *   T2 : la sentinelle `update.sh` compte un vhost déployé resté en arrière
 *        comme incomplet et relance setupApache.sh — l'ancien check ne
 *        regardait que le DocumentRoot et les alias /wpkg/* ;
 *   T3 : la détection elle-même (fonction bash RÉELLE, extraite de update.sh et
 *        exécutée) sépare bien `sambaedu` de `sambaedu-reload` et ignore les
 *        lignes commentées.
 *
 * Pendant côté commandes : le préflight de `se4:unplug`
 * ({@see \Tests\Feature\Console\Se4ExtinctionCommandsTest}).
 */
#[Group('ipxe')]
#[Group('story-38-1')]
class IpxeVhostLegacyAliasGuardTest extends TestCase
{
    /**
     * Directives Apache susceptibles de pointer un chemin, telles que la
     * sentinelle les reconnaît.
     */
    private const LEGACY_DIRECTIVE_PATTERN =
        '#^\s*(?:Alias|AliasMatch|ScriptAlias|ScriptAliasMatch|DocumentRoot|<Directory)\s.*/var/www/sambaedu(?![\w.-])#mi';

    private function read(string $relative): string
    {
        $path = base_path($relative);
        self::assertFileExists($path, "Fichier d'infra attendu absent : {$relative}");

        return (string) file_get_contents($path);
    }

    /**
     * Le heredoc du vhost SER dans setupApache.sh, isolé du heredoc VOISIN qui
     * génère le vhost legacy (port 8082) — ce dernier pointe légitimement dans
     * `/var/www/sambaedu`, c'est sa raison d'être, et il disparaît avec lui.
     */
    private function serVhostTemplate(): string
    {
        $script = $this->read('scripts/setupApache.sh');

        $start = strpos($script, 'cat > "$APACHE_SITES_AVAILABLE/sambaedu.conf" << VHOST_SER');
        self::assertNotFalse($start, 'Heredoc du vhost SER introuvable dans setupApache.sh');

        $end = strpos($script, "\nVHOST_SER", $start);
        self::assertNotFalse($end, 'Fin du heredoc VHOST_SER introuvable');

        return substr($script, $start, $end - $start);
    }

    // ── T1 — les templates livrés ───────────────────────────────────────────

    #[Test]
    public function shipped_vhost_templates_never_point_into_the_legacy_tree(): void
    {
        $templates = [
            'config/apache/sambaedu.conf' => $this->read('config/apache/sambaedu.conf'),
            'config/apache/sambaedu-reload.conf' => $this->read('config/apache/sambaedu-reload.conf'),
            'scripts/setupApache.sh (heredoc VHOST_SER)' => $this->serVhostTemplate(),
        ];

        foreach ($templates as $label => $content) {
            self::assertDoesNotMatchRegularExpression(
                self::LEGACY_DIRECTIVE_PATTERN,
                $content,
                "{$label} porte une directive pointant dans /var/www/sambaedu — le legacy peut être éteint à tout moment"
            );
        }
    }

    // ── T2 — la sentinelle update.sh ────────────────────────────────────────

    #[Test]
    public function update_sh_treats_a_residual_legacy_directive_as_an_incomplete_vhost(): void
    {
        $update = $this->read('scripts/update.sh');

        self::assertStringContainsString(
            'ser_vhost_legacy_directives() {',
            $update,
            'La fonction de détection des directives legacy est absente de update.sh'
        );

        // La sentinelle doit peser dans le test de complétude de update_apache(),
        // sinon un vhost pré-38.1 est déclaré « déjà configuré » et jamais réécrit.
        self::assertStringContainsString(
            '&& [[ -z "$LEGACY_DIRECTIVES" ]]; then',
            $update,
            'update_apache() ne bloque pas sur une directive legacy résiduelle'
        );

        // Le fichier réellement servi par Apache (sites-enabled) peut diverger de
        // sites-available : les deux doivent être inspectés.
        self::assertStringContainsString(
            'APACHE_CONF_ENABLED="/etc/apache2/sites-enabled/sambaedu.conf"',
            $update,
            'update.sh doit connaître le vhost sites-enabled (fichier réellement servi)'
        );
        self::assertMatchesRegularExpression(
            '#for file in "\$APACHE_CONF_TARGET" "\$APACHE_CONF_ENABLED"#',
            $update,
            'La sentinelle doit inspecter sites-available ET sites-enabled'
        );
    }

    // ── T3 — la détection bash réelle ───────────────────────────────────────

    /**
     * Exécute la VRAIE fonction de update.sh (extraite à la volée) sur un vhost
     * fixture — pas une copie de la regex dans le test, qui ne prouverait rien.
     *
     * @return list<string>
     */
    private function runSentinel(string $vhostContent): array
    {
        $update = base_path('scripts/update.sh');
        $function = shell_exec(sprintf(
            'sed -n %s %s',
            escapeshellarg('/^ser_vhost_legacy_directives() {/,/^}/p'),
            escapeshellarg($update),
        ));

        self::assertNotEmpty($function, 'Extraction de ser_vhost_legacy_directives() impossible');

        $fixture = tempnam(sys_get_temp_dir(), 'vhost-');
        file_put_contents($fixture, $vhostContent);

        try {
            $result = Process::run(sprintf(
                'bash -c %s',
                escapeshellarg(
                    'LEGACY_ROOT="/var/www/sambaedu"' . "\n"
                    . $function . "\n"
                    . 'ser_vhost_legacy_directives ' . escapeshellarg($fixture)
                ),
            ));

            self::assertTrue($result->successful(), 'La sentinelle a échoué : ' . $result->errorOutput());

            return array_values(array_filter(
                preg_split('/\R/', trim($result->output())) ?: [],
                static fn (string $line): bool => $line !== '',
            ));
        } finally {
            @unlink($fixture);
        }
    }

    #[Test]
    public function sentinel_flags_a_pre_38_1_vhost(): void
    {
        $found = $this->runSentinel(<<<'CONF'
        <VirtualHost *:80>
            DocumentRoot /var/www/sambaedu-reload/public
            Alias /ipxe /var/www/sambaedu/ipxe
            <Directory /var/www/sambaedu/ipxe>
                FallbackResource /index.php
            </Directory>
        </VirtualHost>
        CONF);

        self::assertCount(2, $found, 'Les deux directives legacy doivent être relevées');
        self::assertStringContainsString('Alias /ipxe /var/www/sambaedu/ipxe', $found[0]);
        self::assertStringContainsString('<Directory /var/www/sambaedu/ipxe>', $found[1]);
    }

    #[Test]
    public function sentinel_stays_silent_on_a_current_vhost(): void
    {
        $found = $this->runSentinel(<<<'CONF'
        <VirtualHost *:80>
            DocumentRoot /var/www/sambaedu-reload/public
            Alias /ipxe /var/www/sambaedu-reload/storage/ipxe/static
            <Directory /var/www/sambaedu-reload/storage/ipxe/static>
                FallbackResource /index.php
            </Directory>
            Alias /wpkg/files /var/sambaedu/unattended/install/packages
            Alias /wpkg/tools /var/sambaedu/unattended/install/wpkg/tools
        </VirtualHost>
        CONF);

        self::assertSame([], $found, 'Aucune directive ne pointe le legacy dans un vhost 38.1');
    }

    /**
     * Le piège de préfixe : `sambaedu-reload` commence par `sambaedu`. Une
     * détection naïve relèverait tout le vhost SER et relancerait setupApache.sh
     * à chaque update. Et une ligne commentée documente un ancien chemin — elle
     * n'a aucun effet sur Apache.
     */
    #[Test]
    public function sentinel_ignores_reload_paths_var_sambaedu_and_comments(): void
    {
        $found = $this->runSentinel(<<<'CONF'
        <VirtualHost *:80>
            DocumentRoot /var/www/sambaedu-reload/public
            # historique : Alias /ipxe /var/www/sambaedu/ipxe
            #   <Directory /var/www/sambaedu/ipxe>
            Alias /assets/wallpaper /var/www/sambaedu-reload/storage/app/wallpaper
            Alias /wpkg/files /var/sambaedu/unattended/install/packages
            Alias /images /var/sambaedu/Docs/images
        </VirtualHost>
        CONF);

        self::assertSame([], $found, 'Faux positif : sambaedu-reload, /var/sambaedu ou une ligne commentée ont été relevés');
    }
}
