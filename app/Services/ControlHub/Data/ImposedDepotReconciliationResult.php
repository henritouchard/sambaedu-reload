<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Story 51.1 — DTO de résultat de la réconciliation du dépôt IMPOSÉ par le contrat
 * amont (controlHub).
 *
 * Retourné par {@see \App\Services\ControlHub\ImposedDepotReconciler::reconcile()}.
 * Sert aux assertions de test, au `Log::info` final et à l'affichage de la commande
 * artisan.
 *
 * Compteurs :
 *  - `materialized`  : `depot_applications` du dépôt imposé (up)sertées depuis le catalogue.
 *  - `purged`        : `depot_applications` du dépôt imposé purgées (absentes du catalogue).
 *  - `transferred`   : `Application` communes re-pointées vers le dépôt imposé (sans réinstall).
 *  - `uninstalled`   : `Application` hors-catalogue désinstallées en cascade (destructif).
 *  - `depotsDeleted` : anciens dépôts non imposés réellement supprimés (`Depot::delete()`).
 *  - `duplicatesRemoved` : doublons d'`app_id` DÉTRUITS en cascade car le même `app_id`
 *    était déjà représenté sur le dépôt imposé (miroir/dépôt redondant) — le re-pointage
 *    aurait violé l'unicité, la ligne redondante est purgée pour libérer son dépôt
 *    d'origine (AC7 — review 51.1 #5, décision Henri).
 *  - `failed`        : opérations en exception (résilience par app/dépôt — AC11).
 *  - `errors`        : messages d'échec (la boucle n'abandonne jamais).
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « imposé » / « amont » / `Imposed` / `Upstream`
 * exclusivement, terme prohibé proscrit. [Source: prd-contrat-manage-se5.md#R3]
 */
class ImposedDepotReconciliationResult
{
    public int $materialized = 0;

    public int $purged = 0;

    public int $transferred = 0;

    public int $uninstalled = 0;

    public int $depotsDeleted = 0;

    public int $duplicatesRemoved = 0;

    public int $failed = 0;

    /** @var array<int, string> */
    public array $errors = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'materialized' => $this->materialized,
            'purged' => $this->purged,
            'transferred' => $this->transferred,
            'uninstalled' => $this->uninstalled,
            'depots_deleted' => $this->depotsDeleted,
            'duplicates_removed' => $this->duplicatesRemoved,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }
}
