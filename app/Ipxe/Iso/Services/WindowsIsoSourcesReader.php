<?php

declare(strict_types=1);

namespace App\Ipxe\Iso\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.6 — D6 / AC3.* — Lecture filesystem best-effort des versions
 * Windows actuellement déployées sous `/var/sambaedu/unattended/install/os/`.
 *
 * Pour Win10 et Win11, lit (si présent) les fichiers
 * `{base}/Win{N}/version` (courante) + `{base}/Win{N}-old/version` (ancienne)
 * pour retourner le nom du fichier ISO source. Pattern best-effort —
 * **jamais d'exception** si filesystem inaccessible : log warning + null.
 *
 * Pourquoi un service dédié plutôt qu'un helper statique ?
 *  - Injection / mock dans les tests (`Storage::fake()` ou `Filesystem` mock).
 *  - Singleton dans le container (cf. `IpxeServiceProvider`).
 *  - Frontière D1 : sous-namespace `App\Ipxe\Iso\Services\*`.
 */
class WindowsIsoSourcesReader
{
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {}

    /**
     * Liste les 4 versions déployées (Win10 + Win10-old + Win11 + Win11-old).
     *
     * Si un fichier `version` est absent ou vide → la clé associée vaut `null`.
     * Si la base path est totalement absente → log warning + retourne la
     * structure complète avec toutes les clés à `null`.
     *
     * @return array{
     *     win10: array{current: ?string, old: ?string},
     *     win11: array{current: ?string, old: ?string},
     * }
     */
    public function list(): array
    {
        $basePath = (string) config('ipxe.iso_management.deployed_os_base_path', '/var/sambaedu/unattended/install/os');
        $versionFileName = (string) config('ipxe.iso_management.version_file_name', 'version');

        if (! $this->filesystem->isDirectory($basePath)) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->warning('ipxe.iso.sources.base_path_missing', [
                'base_path' => $basePath,
            ]);

            return [
                'win10' => ['current' => null, 'old' => null],
                'win11' => ['current' => null, 'old' => null],
            ];
        }

        return [
            'win10' => [
                'current' => $this->readVersion($basePath . '/Win10/' . $versionFileName),
                'old'     => $this->readVersion($basePath . '/Win10-old/' . $versionFileName),
            ],
            'win11' => [
                'current' => $this->readVersion($basePath . '/Win11/' . $versionFileName),
                'old'     => $this->readVersion($basePath . '/Win11-old/' . $versionFileName),
            ],
        ];
    }

    /**
     * Lit best-effort le contenu d'un fichier `version`. Retourne le texte
     * trimé, ou `null` si fichier absent / vide / unreadable.
     */
    private function readVersion(string $path): ?string
    {
        try {
            if (! $this->filesystem->isFile($path)) {
                return null;
            }
            $content = $this->filesystem->get($path);
            $trimmed = trim($content);

            return $trimmed === '' ? null : $trimmed;
        } catch (\Throwable $e) {
            Log::channel((string) config('ipxe.log.channel', 'ipxe'))->warning('ipxe.iso.sources.read_failed', [
                'path'    => $path,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
