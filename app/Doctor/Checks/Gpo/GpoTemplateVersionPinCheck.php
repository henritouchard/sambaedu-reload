<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Garde-fou « bump oublié » pour les templates GPO versionnés de
 * `resources/gpo/<name>/`.
 *
 * **Le piège.** `import_gpo` (legacy, `force=false`) ne re-spécialise + ne
 * réécrit le SYSVOL QUE si la `[General] Version` du `GPT.INI` est strictement
 * supérieure à la version déjà publiée (`/etc/sambaedu/applications/gpos.json`).
 * Un dev qui édite le contenu (`startup.cmd`, `scripts.ini`, …) SANS bumper la
 * Version ne déclenche donc AUCUNE republication : les postes restent sur
 * l'ancien artefact, silencieusement. Cf.
 * `project_gpo_template_edit_needs_version_bump`.
 *
 * **Le check (read-only, SANS DC).** On épingle, par template, le couple
 * (Version attendue, hash du contenu hors `GPT.INI`). À chaque passage on
 * recalcule le hash réel du contenu et on le compare au pin :
 *  - contenu modifié sans mise à jour du pin → **warn** (« tu as changé le
 *    contenu : bumpe la Version ET mets à jour le pin ») ;
 *  - Version GPT.INI désalignée du pin → **warn** (incohérence à corriger).
 *
 * Purement local (lecture de fichiers + sha256) : tourne aussi bien dans
 * `update.sh` (via `sambaedu:doctor`) que pour un dev en local
 * (`php artisan sambaedu:doctor --tag=gpo`) AVANT de committer — sans dépendre
 * de la joignabilité d'un contrôleur de domaine.
 *
 * **Étendre / maintenir.** À chaque modification LÉGITIME du contenu d'un
 * template épinglé : (1) bumper `[General] Version` dans son `GPT.INI`, (2)
 * reporter ici le nouveau couple (version, sha256). Le hash exact à coller est
 * affiché dans le détail du check quand il échoue.
 */
final class GpoTemplateVersionPinCheck implements EnvironmentCheck
{
    /** Fichier porteur de la version, EXCLU du hash de contenu. */
    private const VERSION_FILE = 'GPT.INI';

    /**
     * Pin par template : nom de répertoire sous `resources/gpo/` →
     * [version attendue, sha256 du contenu hors GPT.INI].
     *
     * @var array<string, array{version: int, sha256: string}>
     */
    private const PINS = [
        'SE_agent_bootstrap' => [
            'version' => 2,
            'sha256' => '89384c2b58e3e287b414c1660b39fd0e3f9fc52e8e314aafe8eaeb7a7bb03cfe',
        ],
    ];

    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'Template GPO : version épinglée';
    }

    public function run(): CheckResult
    {
        $problems = [];
        $okNames = [];

        foreach (self::PINS as $name => $pin) {
            $dir = base_path('resources/gpo/' . $name);

            if (! is_dir($dir)) {
                $problems[] = sprintf('%s : répertoire template introuvable (%s) — pin obsolète ?', $name, $dir);

                continue;
            }

            $declared = $this->declaredVersion($dir);
            $actualHash = $this->contentHash($dir);

            if ($actualHash !== $pin['sha256']) {
                $problems[] = sprintf(
                    '%s : le contenu a changé sans mise à jour du pin (attendu %s…, réel %s…). '
                    . 'Si c\'est voulu, BUMPE [General] Version dans resources/gpo/%s/GPT.INI '
                    . '(actuelle : %s) PUIS reporte le couple dans GpoTemplateVersionPinCheck::PINS : '
                    . '[\'version\' => <N+1>, \'sha256\' => \'%s\'].',
                    $name,
                    substr($pin['sha256'], 0, 8),
                    substr($actualHash, 0, 8),
                    $name,
                    $declared === null ? '?' : (string) $declared,
                    $actualHash,
                );

                continue;
            }

            if ($declared !== $pin['version']) {
                $problems[] = sprintf(
                    '%s : Version GPT.INI (%s) désalignée du pin (%d) alors que le contenu est inchangé — '
                    . 'réaligne l\'un des deux.',
                    $name,
                    $declared === null ? 'absente' : (string) $declared,
                    $pin['version'],
                );

                continue;
            }

            $okNames[] = sprintf('%s (v%d)', $name, $pin['version']);
        }

        if ($problems !== []) {
            return CheckResult::warn(
                implode(' | ', $problems),
                'Bumper [General] Version du GPT.INI concerné ET reporter (version, sha256) dans '
                . 'GpoTemplateVersionPinCheck::PINS. Sans bump, import_gpo (force=false) ne republie pas '
                . 'le SYSVOL : les postes restent sur l\'ancien artefact.',
            );
        }

        return CheckResult::ok(sprintf(
            'Tous les templates GPO épinglés sont alignés contenu↔version : %s.',
            implode(', ', $okNames),
        ));
    }

    /** `[General] Version` du GPT.INI, ou null si absent/illisible. */
    private function declaredVersion(string $dir): ?int
    {
        $gpt = $dir . '/' . self::VERSION_FILE;
        if (! is_file($gpt)) {
            return null;
        }
        $content = (string) file_get_contents($gpt);
        if (preg_match('/^\s*Version\s*=\s*(\d+)/mi', $content, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * sha256 du contenu du template HORS {@see self::VERSION_FILE}. Déterministe :
     * fichiers triés par chemin relatif (octets, `SORT_STRING`), chaque entrée
     * sérialisée en `<chemin>\0<octets>`. Le chemin est inclus pour qu'un
     * renommage de fichier change le hash.
     */
    private function contentHash(string $dir): string
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($rel === self::VERSION_FILE) {
                continue;
            }
            $files[$rel] = $file->getPathname();
        }
        ksort($files, SORT_STRING);

        $buffer = '';
        foreach ($files as $rel => $path) {
            $buffer .= $rel . "\0" . (string) file_get_contents($path);
        }

        return hash('sha256', $buffer);
    }
}
