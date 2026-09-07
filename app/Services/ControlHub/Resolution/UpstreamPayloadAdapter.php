<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\StateScope;
use App\Models\ControlHubContractItem;

/**
 * Adaptateur de payload AMONT (controlHub) → candidat d'état SE5.
 *
 * Le modèle stocke un item comme `{type, key, value (scalaire texte)}`. Le
 * `StateCompiler` attend, lui, un payload de la **forme du provider cible** (ex.
 * `registry` : `{hive, path, name, type, value}` pour que `exclusiveKey()`
 * matche un candidat local sur la MÊME clé). Cet adaptateur fait le pont
 * **minimal et type-agnostique** entre les deux représentations.
 *
 * Le bridge est **minimal** : il n'est démontré que sur `registry` (exclusive par
 * clé) et `shortcuts` (aggregate). L'expansion à tous les types et la
 * **représentation canonique** du payload amont attendent que le schéma d'échange
 * soit figé. Il reste **extensible** : ajouter un type plus tard = enregistrer un
 * nouvel adaptateur dans {@see \App\Providers\AgentServiceProvider}, sans refondre
 * la machinerie.
 *
 * ⚠️ COUTURE (types non démontrés) : un item amont dont le `type` n'a AUCUN
 * adaptateur enregistré est **ignoré proprement** par {@see UpstreamContractSource}
 * (il n'est pas injecté) — pas d'exception, pas de candidat fantôme.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » / `Upstream` / `ControlHub*`,
 * JAMAIS « central ».
 *
 * Discipline de non-arbitrage : l'adaptateur ne fait QUE produire un payload BRUT et
 * router vers une portée — il n'arbitre AUCUNE précédence (celle-ci vit dans
 * `StateCompiler::specificity()` seul, via la maille `Upstream`).
 */
interface UpstreamPayloadAdapter
{
    /**
     * Vocabulaire d'item amont géré (== `ControlHubContractItem::$type`). Libre
     * côté contrat — pour le scope démontré, aligné sur le `type` du
     * provider cible (`registry`, `shortcuts`).
     */
    public function upstreamType(): string;

    /**
     * `type()` du provider cible (== `StateProvider::type()`) auquel le candidat
     * amont sera adjoint par le décorateur.
     */
    public function providerType(): string;

    /**
     * Portée d'enveloppe vers laquelle router ce candidat. Un même `providerType`
     * peut couvrir DEUX providers (ex. `registry` HKLM/machine + HKCU/session) :
     * la portée discrimine lequel reçoit le candidat (routage d'enveloppe,
     * pas une précédence de maille).
     */
    public function scopeFor(ControlHubContractItem $item): StateScope;

    /**
     * Transforme l'item amont en payload de candidat compatible avec le provider
     * cible (même forme que ses candidats locaux — pour entrer en concurrence sur
     * la même `exclusiveKey()` côté exclusif, ou s'ajouter à l'union côté
     * aggregate). Jamais de float (contrat §4.1).
     *
     * @return array<string,mixed>
     */
    public function toPayload(ControlHubContractItem $item): array;
}
