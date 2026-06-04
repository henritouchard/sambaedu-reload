<?php

declare(strict_types=1);

namespace App\SystemStatus;

/**
 * Inventaire de disponibilité des distros installables via iPXE.
 *
 * Pour chaque {@see Distro}, vérifie la présence de TOUS ses marqueurs
 * filesystem sous la racine `ipxe.iso_management.deployed_os_base_path`
 * (même source de vérité que {@see \App\Ipxe\Iso\Services\WindowsIsoSourcesReader}).
 *
 * Best-effort read-only : jamais d'exception si la racine est absente —
 * toutes les distros sont alors « indisponibles » avec le détail.
 */
class DistroInventoryService
{
    /**
     * @return array<int, array{
     *     distro: Distro,
     *     available: bool,
     *     missing: array<int, string>,
     *     installable: bool,
     * }>
     */
    public function list(): array
    {
        $basePath = rtrim($this->basePath(), '/');

        $items = [];
        foreach (Distro::cases() as $distro) {
            $missing = [];
            foreach ($distro->availabilityMarkers() as $marker) {
                if (! file_exists($basePath . '/' . $marker)) {
                    $missing[] = $marker;
                }
            }

            $items[] = [
                'distro' => $distro,
                'available' => $missing === [],
                'missing' => $missing,
                'installable' => $distro->installScriptPath() !== null,
            ];
        }

        return $items;
    }

    public function basePath(): string
    {
        return (string) config(
            'ipxe.iso_management.deployed_os_base_path',
            '/var/sambaedu/unattended/install/os',
        );
    }
}
