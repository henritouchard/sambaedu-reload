<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\StateScope;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;

/**
 * Adaptateur AMONT pour le type `shortcuts` (aggregate / union).
 *
 * Convention minimale (bridge, déféré) : `key` = nom du raccourci,
 * `value` = cible (exe/URL). Le candidat amont S'AJOUTE à l'union des raccourcis
 * locaux (sémantique `aggregate`) ; il n'efface jamais un raccourci local
 * distinct (la dédup par contenu du `StateCompiler` ne fusionne que des payloads
 * IDENTIQUES). Portée `MachineUser` (iso `ShortcutsStateProvider`).
 *
 * Démontre que le bridge est **type-agnostique** : la même mécanique d'injection
 * sert un type aggregate comme un type exclusif par clé (registry), sans
 * toucher le compilateur (la maille `Upstream` reste l'affaire du compilateur seul).
 *
 * ⚠️ **NON CÂBLÉ EN PROD** : cet adaptateur
 * n'est **pas** enregistré dans `AgentServiceProvider` (cf. binding
 * `UpstreamContractSource`). Le payload minimal `{name, target}` est INCOMPLET
 * pour l'agent : `ShortcutsStateProvider::payloadFor()` émet `{name, target, args,
 * icon, place}` (+`desktop_path` si `place=desktop`) et `handler_shortcuts.go`
 * **rejette en bloc** tout spec sans `place` (échec de TOUTE la convergence
 * `shortcuts` du poste). Sert UNIQUEMENT de démonstration unitaire du bridge
 * aggregate tant que le schéma d'échange et l'expansion par-type ne sont pas figés.
 * Avant réenregistrement : aligner `toPayload()` sur `payloadFor()`.
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
     * . Distinct des raccourcis locaux par contenu ⇒ s'accumule.
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
