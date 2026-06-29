<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Story 31.3 — DTO de résultat de l'approvisionnement des applications ordonnées
 * par le contrat amont (controlHub).
 *
 * Retourné par {@see \App\Services\ControlHub\OrderedApplicationProvisioner::provision()}.
 * Sert aux assertions de test, au `Log::info` final et à l'affichage de la commande artisan.
 *
 * Compteurs :
 *  - `provisioned`     : lignes `Application` matérialisées depuis la source (net-new).
 *  - `alreadyPresent`  : `app_id` ordonnés déjà présents en inventaire (no-op, AC3).
 *  - `skipped`         : ordres laissés non matérialisés (catalogue absent / source vide — AC6).
 *  - `failed`          : matérialisations en exception (résilience par app — AC6).
 *  - `errors`          : messages d'échec par `app_id` (la boucle n'abandonne jamais).
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » exclusivement, terme prohibé proscrit.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
class OrderedApplicationProvisioningResult
{
    public int $provisioned = 0;

    public int $alreadyPresent = 0;

    public int $skipped = 0;

    public int $failed = 0;

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provisioned' => $this->provisioned,
            'already_present' => $this->alreadyPresent,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}
