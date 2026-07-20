<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Enums\AgentResourceStatus;
use App\Services\Agent\StateContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Story 24.1 — payload de `POST /api/v1/agent/report` (schéma report FIGÉ
 * 23.1, `docs/agent/contract-v1.md` §6 + golden `report.v1.json`).
 *
 * C'est ICI que se résout le defer review 23.1 : l'entrée agent est validée
 * AVANT tout traitement — l'ingestion ne hashe JAMAIS le payload (un body
 * UTF-8 invalide / NAN / INF ne peut donc plus produire de `JsonException`
 * 500 ; un JSON malformé décode en `[]` → 422 de validation).
 *
 * Bornes structurelles (piège SQLite : les varchar ne sont pas appliqués en
 * test — la validation est la SEULE garde réelle) :
 *  - `items.*.type` ∈ liste FERMÉE des 9 identifiants publiés (§7) +
 *    `distinct` → volume borné, un agent forgé ne gonfle pas la table ;
 *  - `items.*.hash` = hex-64 strict (hash opaque StateHasher, jamais
 *    recalculé ni interprété) ;
 *  - `items.*.detail` obligatoire et non vide quand `status = error` (§6).
 *
 * Champs inconnus IGNORÉS (règle d'évolution §9 : champ ajouté = version
 * mineure — le serveur tolère l'inconnu, pas de validation « exactement ces
 * clés »). Le bloc `workstation` est déclaratif (debug agent) : validé en
 * forme, JAMAIS utilisé pour résoudre le poste (identité = token).
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'auth est portée par le middleware `agent.token` (bearer
        // per-poste) — le poste authentifié est dans les attributs requête.
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'schema' => ['required', 'string', Rule::in([StateContract::SCHEMA])],
            'generated_at' => ['required', 'date'],
            'agent_version' => ['required', 'string', 'max:32'],
            'workstation' => ['nullable', 'array'],
            'workstation.hostname' => ['nullable', 'string', 'max:255'],
            'workstation.uuid' => ['nullable', 'string', 'max:64'],
            // `present` (pas `required`) : `items: []` est un rapport valide
            // (agent sans rien à rapporter — décision n° 9).
            'items' => ['present', 'array'],
            // `reportableTypes()` et NON `RESOURCE_TYPES` : le serveur accepte
            // aussi les canaux de signalement de l'agent (`agent_update`,
            // `companion`), qui n'ont pas de provider et ne sont jamais SERVIS.
            // Avec la seule liste servie, un échec d'auto-update recalait le
            // rapport ENTIER en 422 — le signal détruisait son porteur.
            'items.*.type' => ['required', 'string', Rule::in(StateContract::reportableTypes()), 'distinct'],
            'items.*.status' => ['required', Rule::enum(AgentResourceStatus::class)],
            // /D : sans lui, `$` PCRE tolère un \n traînant → 65 octets
            // passeraient la validation (varchar(64) PG = 22001/500,
            // comparaison de hash jamais vraie). Review 24.1 #1.
            'items.*.hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'items.*.detail' => [
                'nullable',
                'string',
                'max:2000',
                'required_if:items.*.status,' . AgentResourceStatus::Error->value,
            ],
            // Story 27.5 — AC4 : champ ADDITIF optionnel `inventory` sur l'item
            // `applications` (résultat PAR APP : {app_id, status, detail?}).
            // Évolution MINEURE §9 (champ ajouté = reste v1 ; un vieux serveur
            // l'ignorerait). Le verdict du TYPE reste PAR TYPE (items.*.status,
            // worst-status) — l'inventaire est une DONNÉE additive, pas un
            // verdict (grain 27.8 intact). `nullable` : les autres types ne
            // portent pas d'inventaire.
            'items.*.inventory' => ['nullable', 'array'],
            'items.*.inventory.*.app_id' => ['required', 'string', 'max:191'],
            'items.*.inventory.*.status' => ['required', Rule::enum(AgentResourceStatus::class)],
            'items.*.inventory.*.detail' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
