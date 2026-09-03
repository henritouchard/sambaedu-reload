<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Wpkg;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

/**
 * Vérifie que les postes peuvent DÉPOSER leur rapport WPKG sur le partage
 * `[rapports]`.
 *
 * `wpkg-client.vbs` recopie `%WinDir%\wpkg.log` et `wpkg.txt` vers
 * `\\<se4fs>\rapports\<POSTE>.{log,txt}` en fin de run. Il tourne en SYSTEM,
 * donc sous le compte machine `<poste>$`, que Samba mappe sur la classe POSIX
 * « other » — le même mappage que documente `scripts/verify-install-permissions.sh`
 * pour la lecture. Sans `o+wx` sur la boîte de dépôt, la copie échoue SANS
 * message : le poste finit son run, et le serveur n'a jamais ni le log complet
 * du moteur WPKG, ni le rapport `.txt` qu'ingère
 * `php artisan wpkg:process-reports`. Le diagnostic d'un échec d'installation se
 * réduit alors à la liste d'`app_id` que l'agent rapporte manquantes.
 *
 * Le sous-dossier `archive/` n'est PAS une boîte de dépôt : seul PHP-FPM y
 * déplace les rapports traités. Il doit donc être inscriptible par l'utilisateur
 * web, et surtout pas par les postes.
 *
 * Read-only : lit les modes, n'écrit rien. La réparation vit dans
 * `scripts/update.sh:ensure_wpkg_reports_inbox()`.
 */
final class WpkgReportsInboxCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'wpkg';
    }

    public function name(): string
    {
        return 'Boîte de dépôt rapports WPKG';
    }

    public function run(): CheckResult
    {
        $inbox = rtrim((string) config('sambaedu.wpkg.reports_inbox'), '/');
        $archive = rtrim((string) config('sambaedu.wpkg.reports_archive'), '/');

        if ($inbox === '' || ! is_dir($inbox)) {
            return CheckResult::warn(
                sprintf('Boîte de dépôt absente (%s) — hôte sans partage [install] ?', $inbox),
                'Sur le serveur de fichiers, lancer `scripts/update.sh` pour la créer.',
            );
        }

        $mode = $this->mode($inbox);
        if ($mode === null) {
            return CheckResult::warn(sprintf('Mode de %s illisible.', $inbox));
        }

        // 0003 = o+wx : le compte machine doit traverser ET écrire.
        if (($mode & 0o003) !== 0o003) {
            return CheckResult::error(
                sprintf('%s est en %04o : les postes ne peuvent pas y déposer leur rapport.', $inbox, $mode),
                'Samba mappe le compte machine sur « other ». Lancer `scripts/update.sh` '
                . '(étape ensure_wpkg_reports_inbox), ou en ponctuel : '
                . sprintf('chown www-admin:www-admin %s && chmod 1777 %s. ', $inbox, $inbox)
                . 'Sans ce droit la copie du log échoue en silence, et wpkg:process-reports ne voit jamais rien.',
            );
        }

        $warnings = [];

        // Sticky : un poste réécrit son propre rapport, jamais celui d'un autre.
        if (($mode & 0o1000) === 0) {
            $warnings[] = sprintf(
                '%s est ouvert en écriture sans sticky bit — un poste peut effacer le rapport d\'un autre',
                $inbox,
            );
        }

        if ($archive !== '' && is_dir($archive)) {
            $archiveMode = $this->mode($archive);
            if ($archiveMode !== null && ($archiveMode & 0o002) !== 0) {
                $warnings[] = sprintf(
                    '%s est inscriptible par les postes alors que seul PHP-FPM y archive',
                    $archive,
                );
            }
            if (! is_writable($archive)) {
                $warnings[] = sprintf(
                    '%s n\'est pas inscriptible par cet utilisateur — wpkg:process-reports ne pourra pas archiver',
                    $archive,
                );
            }
        }

        if ($warnings !== []) {
            return CheckResult::warn(
                implode(' ; ', $warnings).'.',
                'Lancer `scripts/update.sh` (étape ensure_wpkg_reports_inbox) pour réaligner les modes.',
            );
        }

        return CheckResult::ok(
            sprintf('Dépôt %s en %04o — les postes peuvent y écrire leur rapport', $inbox, $mode),
        );
    }

    private function mode(string $path): ?int
    {
        clearstatcache(true, $path);
        $perms = @fileperms($path);

        return $perms === false ? null : ($perms & 0o7777);
    }
}
