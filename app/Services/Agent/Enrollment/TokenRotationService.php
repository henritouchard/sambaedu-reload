<?php

declare(strict_types=1);

namespace App\Services\Agent\Enrollment;

use App\Models\Workstation;
use Illuminate\Support\Facades\Log;

/**
 * Story 23.2 — Cycle de vie du token agent (FR12-FR15).
 *
 * Service unique d'écriture des colonnes `agent_*` de `workstations` :
 * émission, rotation glissante (D5), confirmation de rotation, révocation
 * par événement, quarantaine anti-clonage. Aucun autre code ne doit écrire
 * ces colonnes (règle d'enforcement, cf. docs/agent/token-lifecycle.md).
 *
 * Conventions :
 *
 *  - Token = 64 hex (`bin2hex(random_bytes(32))`), seul le SHA-256 hex est
 *    persisté (iso `WorkstationRefreshToken`). Le clair est retourné une
 *    seule fois au caller et n'est JAMAIS loggé.
 *  - Fenêtre de grâce D5 : à la rotation, l'ancien hash glisse en
 *    `agent_previous_token_hash` et reste valide jusqu'au premier usage du
 *    nouveau token ({@see confirmRotation()}). Pas d'expiration calendaire
 *    sèche : un token jamais rotaté côté poste s'authentifie toujours et
 *    déclenche une rotation au check-in suivant.
 *  - Toutes les transitions sont loggées channel `agent`, actions
 *    namespacées `agent.token.*`, contexte `workstation_id`.
 *
 * Consommateurs : middleware {@see \App\Http\Middleware\AuthenticateAgentToken}
 * (rotation/confirmation au check-in), UI page machine (révocation),
 * Story 23.3 (enrôlement iPXE → `issueFor()`, réinstall → `revokeFor()`).
 */
class TokenRotationService
{
    /**
     * Émet un token neuf pour le poste (enrôlement ou ré-enrôlement).
     *
     * Repart d'un état propre : efface la grâce et lève la quarantaine
     * (un ré-enrôlement légitime — réinstallation 23.3 — réhabilite le poste).
     *
     * @return string le token en clair (64 hex) — à transmettre au poste,
     *                jamais persisté, jamais loggé.
     */
    public function issueFor(Workstation $workstation): string
    {
        $token = bin2hex(random_bytes(32));

        $workstation->agent_token_hash = hash('sha256', $token);
        $workstation->agent_previous_token_hash = null;
        $workstation->agent_token_rotated_at = now();
        $workstation->agent_quarantined_at = null;
        $workstation->save();

        $this->log('debug', 'agent.token.issued', $workstation);

        return $token;
    }

    /**
     * Rotation glissante (D5) : le hash courant glisse en previous, un token
     * neuf devient courant.
     *
     * Si une grâce est déjà ouverte (réponse de rotation perdue, le poste
     * re-check-in avec l'ancien token), `previous` reste l'ancien hash — le
     * seul token que le poste détient réellement — pour ne jamais le
     * lock-outer ; seul le courant est remplacé.
     *
     * @return string le nouveau token en clair (64 hex).
     */
    public function rotateFor(Workstation $workstation): string
    {
        $token = bin2hex(random_bytes(32));

        if ($workstation->agent_previous_token_hash === null) {
            $workstation->agent_previous_token_hash = $workstation->agent_token_hash;
        }
        $workstation->agent_token_hash = hash('sha256', $token);
        $workstation->agent_token_rotated_at = now();
        $workstation->save();

        $this->log('debug', 'agent.token.rotated', $workstation);

        return $token;
    }

    /**
     * Premier usage du nouveau token : la fenêtre de grâce se ferme,
     * l'ancien token cesse d'être valide.
     */
    public function confirmRotation(Workstation $workstation): void
    {
        $workstation->agent_previous_token_hash = null;
        $workstation->save();

        $this->log('debug', 'agent.token.rotation_confirmed', $workstation);
    }

    /**
     * Révocation par événement (FR14) : bouton UI, réinstallation (23.3).
     *
     * Efface les deux hash (le prochain appel du poste → 401, indistinct
     * d'un token inconnu — pas d'oracle) et lève la quarantaine.
     */
    public function revokeFor(Workstation $workstation, string $reason): void
    {
        $workstation->agent_token_hash = null;
        $workstation->agent_previous_token_hash = null;
        $workstation->agent_token_rotated_at = null;
        $workstation->agent_quarantined_at = null;
        $workstation->save();

        $this->log('info', 'agent.token.revoked', $workstation, ['reason' => $reason]);
    }

    /**
     * Quarantaine anti-clonage (FR15) : le token reste en place mais toute
     * requête authentifiée répond 403 AGENT_QUARANTINED (check-ins légers,
     * le poste reste visible). Levée via ré-enrôlement ({@see issueFor()})
     * ou révocation ({@see revokeFor()}) — outillage dédié → Story 25.3.
     */
    public function quarantine(Workstation $workstation, string $reason): void
    {
        $workstation->agent_quarantined_at = now();
        $workstation->save();

        $this->log('error', 'agent.token.clone_detected', $workstation, ['reason' => $reason]);
    }

    /**
     * Log channel `agent`, action namespacée, contexte `workstation_id` —
     * jamais de token en clair ni de hash (iso-convention auth-v1).
     *
     * @param array<string,mixed> $context
     */
    private function log(string $level, string $action, Workstation $workstation, array $context = []): void
    {
        Log::channel('agent')->{$level}('[TokenRotationService] ' . $action, array_merge([
            'action_type' => $action,
            'workstation_id' => $workstation->id,
        ], $context));
    }
}
