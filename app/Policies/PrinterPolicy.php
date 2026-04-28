<?php

namespace App\Policies;

use App\Models\Printer;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Story 7.2 (AC5) + Story 6.1 — Policy pour les imprimantes.
 *
 * Décision produit (fix #11) : seuls les administrateurs globaux (`server.admin`)
 * et les « Référents numériques » (qui reçoivent `server.admin` via leur profil)
 * peuvent modifier les imprimantes. Les délégués scopés n'ont pas ce droit.
 *
 * Gates :
 *  - `viewAny-printer` : `server.admin` global (consultation back-office admin).
 *  - `manage-printer`  : `server.admin` global uniquement.
 *    Toutes les instances (y compris orphan) sont accessibles à l'admin global.
 */
class PrinterPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    protected static array $gates = [
        'viewAny-printer' => 'viewAny',
        'manage-printer' => 'manage',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'server.admin');
    }

    /**
     * Gestion d'une imprimante — réservée aux administrateurs globaux.
     *
     * La vérification est intentionnellement simple : pas de logique scopée.
     * Les délégués (server.admin scopé sur un parc) peuvent VOIR les imprimantes
     * de leur parc via `viewAny-printer`/`hasAnyDelegation()`, mais ne peuvent
     * pas les modifier (décision produit 2026-04-28).
     */
    public function manage(?Authenticatable $user, ?Printer $printer = null): bool
    {
        return $this->hasPermission($user, 'server.admin');
    }
}
