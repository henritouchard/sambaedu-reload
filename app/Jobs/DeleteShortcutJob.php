<?php

namespace App\Jobs;

use App\Models\Shortcut;
use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "delete_shortcut" ordonnée par le ControlHub.
 * Supprime un raccourci en base de données via controlhub_id.
 */
class DeleteShortcutJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la suppression de raccourci.
     * 
     * @return array Le résultat de l'exécution
     * @throws \Exception En cas d'erreur
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('DeleteShortcutJob: Processing shortcut deletion', [
            'task_id' => $this->task->id,
            'payload' => $payload,
        ]);

        // Validation du payload
        if (empty($payload['controlhub_id'])) {
            throw new \InvalidArgumentException('Le controlhub_id du raccourci est requis');
        }

        $controlhubId = $payload['controlhub_id'];
        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();

        if (! $shortcut) {
            throw new \RuntimeException("Raccourci non trouvé avec controlhub_id: {$controlhubId}");
        }

        // Vérifier que c'est un raccourci ControlHub
        if (! $shortcut->is_global) {
            throw new \RuntimeException("Le raccourci '{$shortcut->name}' n'est pas géré par le ControlHub");
        }

        $shortcutKey = $shortcut->key;
        $shortcutName = $shortcut->name;
        $deletedControlhubId = $shortcut->controlhub_id;

        // Détacher les relations pivot avant suppression
        $shortcut->workstationGroups()->detach();
        $shortcut->workstations()->detach();

        $shortcut->delete();

        Log::info('DeleteShortcutJob: Shortcut deleted successfully from DB', [
            'task_id' => $this->task->id,
            'asset_id' => $shortcutKey,
            'controlhub_id' => $deletedControlhubId,
            'shortcut_name' => $shortcutName,
        ]);

        return [
            'deleted' => true,
            'asset_id' => $shortcutKey,
            'controlhub_id' => $deletedControlhubId,
            'shortcut_name' => $shortcutName,
            'message' => 'Raccourci supprimé avec succès',
        ];
    }
}
