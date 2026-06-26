<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\StateScope;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;

/**
 * Story 28.3 — Adaptateur AMONT pour le type `shortcuts` (aggregate / union).
 *
 * Convention minimale (bridge, déféré Epic 33) : `key` = nom du raccourci,
 * `value` = cible (exe/URL). Le candidat amont S'AJOUTE à l'union des raccourcis
 * locaux (sémantique `aggregate`) ; il n'efface jamais un raccourci local
 * distinct (la dédup par contenu du `StateCompiler` ne fusionne que des payloads
 * IDENTIQUES). Portée `MachineUser` (iso `ShortcutsStateProvider`).
 *
 * Démontre que le bridge est **type-agnostique** : la même mécanique d'injection
 * sert un type aggregate comme un type exclusif par clé (registry), sans
 * toucher le compilateur (D2 intact, maille `Upstream` au compilateur seul).
 *
 * ⚠️ **NON CÂBLÉ EN PROD (décision review 28.3, finding #1)** : cet adaptateur
 * n'est **pas** enregistré dans `AgentServiceProvider` (cf. binding
 * `UpstreamContractSource`). Le payload minimal `{name, target}` est INCOMPLET
 * pour l'agent : `ShortcutsStateProvider::payloadFor()` émet `{name, target, args,
 * icon, place}` (+`desktop_path` si `place=desktop`) et `handler_shortcuts.go`
 * **rejette en bloc** tout spec sans `place` (échec de TOUTE la convergence
 * `shortcuts` du poste). Sert UNIQUEMENT de démonstration unitaire du bridge
 * aggregate jusqu'à ce qu'Epic 33 fige le schéma d'échange et l'expansion
 * par-type. Avant réenregistrement : aligner `toPayload()` sur `payloadFor()`.
 *
 * ⚠️ GARDE-FOU R3 : aucun « central ». Vocabulaire « amont » / `Upstream`.
 */
final class ShortcutsUpstreamAdapter implements UpstreamPayloadAdapter
{
    public function upstreamType(): string
    {
        return Shortcut::TYPE_SHORTCUTS; // 'shortcuts'
    }

    public function providerType(): string
    {
        return Shortcut::TYPE_SHORTCUTS; // 'shortcuts'
    }

    public function scopeFor(ControlHubContractItem $item): StateScope
    {
        return StateScope::MachineUser;
    }

    /**
     * Payload minimal `{name, target}` (toujours des strings, §4.1). Forme
     * volontairement réduite : le bridge ne sur-spécifie pas l'expansion par-type
     * (Epic 33). Distinct des raccourcis locaux par contenu ⇒ s'accumule.
     *
     * @return array<string,mixed>
     */
    public function toPayload(ControlHubContractItem $item): array
    {
        return [
            'name' => (string) $item->key,
            'target' => (string) ($item->value ?? ''),
        ];
    }
}
