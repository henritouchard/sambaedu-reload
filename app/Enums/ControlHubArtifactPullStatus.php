<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État du pull d'un binaire imposé par le contrat amont (controlHub) — canal ④.
 *
 * Story 39.4 — Ingestion + pull des binaires amont (`artifact` sur les items
 * `wallpapers`/`agent_tools`). Porté par la colonne `controlhub_contract_items.pull_status`.
 *
 * - `Pending`    (FR: en attente)  : un job de pull a été dispatché (asset absent localement),
 *                                     le téléchargement/la vérification sha256 n'a pas encore abouti.
 * - `Downloaded` (FR: téléchargé)  : binaire tiré, sha256 vérifié serveur, matérialisé dans le
 *                                     foyer local (bibliothèque wallpaper / `agent.tools_path`).
 * - `Error`      (FR: en erreur)   : mismatch sha256 (ou échec de téléchargement) — AUCUNE
 *                                     matérialisation ; consommable par le canal ③ (conformité 39.2).
 *
 * NB : un item dont l'asset existe DÉJÀ localement (précédence locale) reste à `null` — aucune
 * action de pull n'est requise, ce n'est pas un état `pending`.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans cette enum ni dans ses valeurs.
 * Préfixe de classe imposé : `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
enum ControlHubArtifactPullStatus: string
{
    case Pending = 'pending';
    case Downloaded = 'downloaded';
    case Error = 'error';
}
