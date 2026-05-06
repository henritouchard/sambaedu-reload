<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Generators;

use App\Models\Workstation;
use App\Support\AtomicFileWriter;
use Illuminate\Support\Facades\Log;

/**
 * @legacy-port path="sambaedu/wpkg/poste_maintenance_options.php"
 * @legacy-port-fn="create_ini_poste / update_ini_poste / delete_ini_poste"
 * @see _bmad-output/implementation-artifacts/15-2-generators-xml-ini-par-poste.md
 *
 * Story 15.2 / AC5.3-AC5.7 — Génération du fichier `.ini` per-poste WPKG.
 *
 * Format ligne strict legacy : `{key}={value} ' {description}\r\n` — séparateur
 * **CRLF**, parité legacy `poste_maintenance_options.php:59,105`. 8 options
 * fixes, defaults `false`, override depuis `wpkg_workstation_options`.
 *
 * Idempotence binaire garantie par tri stable (constante `LEGACY_OPTIONS`).
 * Écriture atomique via `App\Support\AtomicFileWriter` (15.1).
 */
final class WorkstationIniGenerator
{
    /**
     * Liste des 8 options legacy + descriptions byte-identiques au legacy
     * (cf. `poste_maintenance_options.php:100-139`). Ordre fixe = idempotence.
     *
     * @var list<array{name: string, description: string}>
     */
    public const LEGACY_OPTIONS = [
        ['name' => 'debug',          'description' => "Permet d'avoir des logs plus détaillés."],
        ['name' => 'logdebug',       'description' => 'Pour avoir des logs en temps réel sur le serveur.'],
        ['name' => 'force',          'description' => "Pour tester la présence ou l'absence effective de chaque appli sur le poste."],
        ['name' => 'forceinstall',   'description' => "Pour installer ou désinstaller les applications même si les tests 'check' sont vérifiés."],
        ['name' => 'nonotify',       'description' => "Pour ne pas avertir l'utilisateur logué des opérations de wpkg."],
        ['name' => 'dryrun',         'description' => "Pour que wpkg simule une exécution mais n'installe ou ne désinstalle rien."],
        ['name' => 'nowpkg',         'description' => 'Pour ne pas exécuter wpkg sur le poste.'],
        ['name' => 'noforcedremove', 'description' => 'Pour ne pas retirer les applis zombies de la base de données du poste si les commandes de remove échouent.'],
    ];

    /**
     * Génère le `.ini` per-poste en atomic write.
     */
    public function generate(Workstation $workstation): bool
    {
        $iniPath = (string) config('sambaedu.wpkg.ini_path');
        $target = rtrim($iniPath, '/') . '/' . $workstation->name . '.ini';

        $content = $this->renderContent($workstation);

        $ok = AtomicFileWriter::write($target, $content);

        Log::channel('wpkg-deploy')->info('Génération .ini', [
            'workstation_id' => $workstation->id,
            'hostname' => $workstation->name,
            'target' => $target,
            'success' => $ok,
        ]);

        return $ok;
    }

    /**
     * Construit le contenu `.ini` (CRLF strict legacy). Public pour tests
     * d'idempotence sans toucher le filesystem.
     */
    public function renderContent(Workstation $workstation): string
    {
        // Eager-fetch des overrides ; si la relation n'est pas pré-chargée,
        // on déclenche un seul SELECT.
        $overrides = $workstation->wpkgOptions
            ->keyBy('option_key')
            ->map(fn ($row): string => (string) $row->option_value);

        $lines = [];
        foreach (self::LEGACY_OPTIONS as $option) {
            $key = $option['name'];
            $value = $overrides->get($key, 'false');
            $lines[] = sprintf("%s=%s ' %s\r\n", $key, $value, $option['description']);
        }

        return implode('', $lines);
    }
}
