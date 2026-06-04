<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Collection;

/**
 * Story 3.3 — D5 / AC4.1.
 *
 * Construit les **variables Blade** consommées par les 5 templates
 * `resources/views/ipxe/enrollment/*.blade.php` :
 *
 *  - {@see buildNameMenuVariables()}      → `enrollment/name.blade.php`.
 *  - {@see buildByodMenuVariables()}      → `enrollment/byod.blade.php`.
 *  - {@see buildRoomMenuVariables()}      → `enrollment/room.blade.php`.
 *  - {@see buildParcAddMenuVariables()}   → `enrollment/parc-add.blade.php`.
 *  - {@see buildParcRemoveMenuVariables()} → `enrollment/parc-remove.blade.php`.
 *
 * **Stateless** (singleton enregistré dans `IpxeServiceProvider`).
 *
 * **Sanitisation** : applique le même `sanitizeAscii()` que
 * {@see IpxeMenuRenderer} sur les noms de salles / parcs (un firmware iPXE
 * rejette l'ASCII étendu — accents fr cassent le menu). Cf. iso 3.1 D9.
 *
 * **Cap volumétrique** : limite les listes affichées via
 * `config('ipxe.enrollment.max_rooms_in_menu')` / `max_parcs_in_menu`. Au-delà,
 * un item informatif `** voir UI admin SE5 **` est ajouté.
 */
final class IpxeEnrollmentMenuBuilder
{
    /**
     * Build variables pour le menu `name.blade.php`.
     *
     * @return array<string,mixed>
     */
    public function buildNameMenuVariables(
        ?Workstation $ws,
        string $mac,
        string $uuid,
        string $platform,
        string $ip,
        string $serverBaseUrl,
    ): array {
        return [
            'mac' => $this->sanitizeAscii($mac),
            'uuid' => $this->sanitizeAscii(strtolower($uuid)),
            'platform' => $this->sanitizeAscii($platform),
            'ip' => $ip,
            'currentName' => $ws !== null
                ? $this->sanitizeAscii((string) ($ws->name ?? ''))
                : '',
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.menu.background_png', 'png/ipxe-se4.png'),
            'menuTimeoutMs' => (int) config('ipxe.enrollment.menu_timeout_ms', 10000),
        ];
    }

    /**
     * Build variables pour le menu `byod.blade.php`.
     *
     * Variant simplifié de `name` — pas de gestion `NAME_TAKEN`, pas d'AD.
     *
     * @return array<string,mixed>
     */
    public function buildByodMenuVariables(
        ?Workstation $ws,
        string $mac,
        string $uuid,
        string $platform,
        string $ip,
        string $serverBaseUrl,
    ): array {
        return $this->buildNameMenuVariables($ws, $mac, $uuid, $platform, $ip, $serverBaseUrl);
    }

    /**
     * Build variables pour le menu `room.blade.php`.
     *
     * Liste les salles physiques actives non archivées. Marque la salle
     * actuelle (`physicalRoom`) avec `is_current=true` pour permettre
     * l'affichage `** deja dans <name> **` côté template.
     *
     * @return array<string,mixed>
     */
    public function buildRoomMenuVariables(
        Workstation $ws,
        string $serverBaseUrl,
    ): array {
        $maxRooms = max(1, (int) config('ipxe.enrollment.max_rooms_in_menu', 50));

        $rooms = WorkstationGroup::query()
            ->where('is_physical', true)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->limit($maxRooms + 1)
            ->get();

        $truncated = $rooms->count() > $maxRooms;
        if ($truncated) {
            $rooms = $rooms->take($maxRooms);
        }

        // Story 4.11 — la salle courante se lit via le pivot (accessor
        // `physicalRoom`), plus via la FK `physical_room_id`.
        $current = $ws->physicalRoom;
        $currentRoomId = $current?->id;

        return [
            'mac' => $this->sanitizeAscii((string) ($ws->mac ?? '')),
            'uuid' => $this->sanitizeAscii(strtolower((string) ($ws->uuid ?? ''))),
            'workstationName' => $this->sanitizeAscii((string) ($ws->name ?? '')),
            'availableRooms' => $this->normalizeGroups($rooms, (int) ($currentRoomId ?? 0)),
            'currentRoom' => $current !== null
                ? [
                    'id' => (int) $current->id,
                    'name' => $this->sanitizeAscii((string) ($current->name ?? '')),
                ]
                : null,
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.menu.background_png', 'png/ipxe-se4.png'),
            'menuTimeoutMs' => (int) config('ipxe.enrollment.menu_timeout_ms', 10000),
            'truncated' => $truncated,
        ];
    }

    /**
     * Build variables pour le menu `parc-add.blade.php`.
     *
     * Liste les parcs logiques non archivés **non encore attachés** au poste
     * (`exclude_ids = $ws->groups->pluck('id')`).
     *
     * @return array<string,mixed>
     */
    public function buildParcAddMenuVariables(
        Workstation $ws,
        string $serverBaseUrl,
    ): array {
        $maxParcs = max(1, (int) config('ipxe.enrollment.max_parcs_in_menu', 50));
        $attachedIds = $ws->groups->pluck('id')->all();

        $parcs = WorkstationGroup::query()
            ->where('is_physical', false)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->when($attachedIds !== [], fn ($q) => $q->whereNotIn('id', $attachedIds))
            ->orderBy('name')
            ->limit($maxParcs + 1)
            ->get();

        $truncated = $parcs->count() > $maxParcs;
        if ($truncated) {
            $parcs = $parcs->take($maxParcs);
        }

        return [
            'mac' => $this->sanitizeAscii((string) ($ws->mac ?? '')),
            'uuid' => $this->sanitizeAscii(strtolower((string) ($ws->uuid ?? ''))),
            'workstationName' => $this->sanitizeAscii((string) ($ws->name ?? '')),
            'availableParcs' => $this->normalizeGroups($parcs),
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.menu.background_png', 'png/ipxe-se4.png'),
            'menuTimeoutMs' => (int) config('ipxe.enrollment.menu_timeout_ms', 10000),
            'truncated' => $truncated,
        ];
    }

    /**
     * Build variables pour le menu `parc-remove.blade.php`.
     *
     * Liste **uniquement les parcs logiques actuellement attachés** au poste.
     *
     * @return array<string,mixed>
     */
    public function buildParcRemoveMenuVariables(
        Workstation $ws,
        string $serverBaseUrl,
    ): array {
        $maxParcs = max(1, (int) config('ipxe.enrollment.max_parcs_in_menu', 50));

        // F6 (review 3.3) : calcul du total AVANT troncature pour exposer `truncated` réel
        // (parité avec buildRoomMenuVariables / buildParcAddMenuVariables).
        $candidates = $ws->groups->filter(function (WorkstationGroup $g): bool {
            return $g->is_physical === false
                && $g->is_active === true
                && $g->archived_at === null;
        })->values();
        $totalParcs = $candidates->count();
        $truncated = $totalParcs > $maxParcs;
        $currentParcs = $candidates->take($maxParcs)->values();

        return [
            'mac' => $this->sanitizeAscii((string) ($ws->mac ?? '')),
            'uuid' => $this->sanitizeAscii(strtolower((string) ($ws->uuid ?? ''))),
            'workstationName' => $this->sanitizeAscii((string) ($ws->name ?? '')),
            'currentParcs' => $this->normalizeGroups($currentParcs),
            'serverBaseUrl' => rtrim($serverBaseUrl, '/'),
            'resolutionX' => (int) config('ipxe.menu.resolution_x', 1024),
            'resolutionY' => (int) config('ipxe.menu.resolution_y', 768),
            'resolutionPng' => (string) config('ipxe.menu.background_png', 'png/ipxe-se4.png'),
            'menuTimeoutMs' => (int) config('ipxe.enrollment.menu_timeout_ms', 10000),
            'truncated' => $truncated,
        ];
    }

    /**
     * Normalise les groupes en tableau plat ASCII-safe pour Blade.
     *
     * @param  Collection<int,WorkstationGroup>  $groups
     * @return list<array{id:int,name:string,display_name:string,is_current:bool}>
     */
    private function normalizeGroups(Collection $groups, int $currentId = 0): array
    {
        return $groups->map(function (WorkstationGroup $g) use ($currentId): array {
            $name = $this->sanitizeAscii((string) ($g->name ?? ''));
            $display = $this->sanitizeAscii((string) ($g->display_name ?? $g->name ?? ''));
            return [
                'id' => (int) $g->id,
                'name' => $name,
                'display_name' => $display !== '' ? $display : $name,
                'is_current' => (int) $g->id === $currentId,
            ];
        })->values()->all();
    }

    /**
     * Délègue à l'implémentation canonique {@see IpxeHostnameSanitizer::sanitizeForIpxeOutput()}
     * — Unicode-aware + fail-closed sur UTF-8 invalide (cf. F15 review).
     */
    private function sanitizeAscii(string $value): string
    {
        return IpxeHostnameSanitizer::sanitizeForIpxeOutput($value);
    }
}
