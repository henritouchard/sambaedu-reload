<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Resolution;

use App\Enums\StateScope;
use App\Models\ControlHubContractItem;

/**
 * Story 28.3 — Adaptateur de payload AMONT (controlHub) → candidat d'état SE5.
 *
 * Le modèle 28.1 stocke un item comme `{type, key, value (scalaire texte)}`. Le
 * `StateCompiler` attend, lui, un payload de la **forme du provider cible** (ex.
 * `registry` : `{hive, path, name, type, value}` pour que `exclusiveKey()`
 * matche un candidat local sur la MÊME clé). Cet adaptateur fait le pont
 * **minimal et type-agnostique** entre les deux représentations.
 *
 * 🔒 DÉCISION HENRI (2026-06-26) — approche (i) : bridge minimal démontré sur
 * `registry` (exclusive par clé) ET `shortcuts` (aggregate). L'expansion par-type
 * complète et la **représentation canonique** du payload amont sont DÉFÉRÉES à
 * l'**Epic 33** (schéma d'échange figé). Le bridge est **extensible** : ajouter
 * un type plus tard = enregistrer un nouvel adaptateur dans
 * {@see \App\Providers\AgentServiceProvider}, sans refondre la machinerie.
 *
 * ⚠️ COUTURE (types non démontrés) : un item amont dont le `type` n'a AUCUN
 * adaptateur enregistré est **ignoré proprement** par {@see UpstreamContractSource}
 * (il n'est pas injecté) — pas d'exception, pas de candidat fantôme. C'est le
 * point d'extension d'Epic 33.
 *
 * ⚠️ GARDE-FOU R3 : vocabulaire « amont » / `Upstream` / `ControlHub*`,
 * JAMAIS « central ». [Source: prd-contrat-manage-se5.md#R3]
 *
 * Discipline D2 PRÉSERVÉE : l'adaptateur ne fait QUE produire un payload BRUT et
 * router vers une portée — il n'arbitre AUCUNE précédence (celle-ci vit dans
 * `StateCompiler::specificity()` seul, via la maille `Upstream`).
 */
interface UpstreamPayloadAdapter
{
    /**
     * Vocabulaire d'item amont géré (== `ControlHubContractItem::$type`). Libre
     * côté contrat (28.1) — pour le scope démontré, aligné sur le `type()` du
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
     * orthogonal à D2 — pas une précédence de maille).
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
