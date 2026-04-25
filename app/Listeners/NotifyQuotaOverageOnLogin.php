<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Log;

/**
 * Story 5.1c — Listener Login event : émet un toast warning si l'utilisateur
 * connecté est en dépassement (soft ou hard) d'au moins une partition.
 *
 * Décision D5=A : listener sur `Illuminate\Auth\Events\Login` plutôt que
 * logique inline dans `SambaEduAuthGuard::handle()`. Avantages :
 *   - Idempotence naturelle : Laravel n'émet `Login` qu'au PREMIER `Auth::login()`
 *     d'une session — pas à chaque requête où le cookie est revalidé.
 *   - Découplage middleware ↔ logique métier (testable via `Event::fake`).
 *   - Pattern Laravel standard.
 *
 * Source de données : colonne `users.quota_snapshot` (alimentée par la
 * commande `quota:snapshot` 5.1b à 03h00). Aucun shellout depuis ce listener.
 *
 * Sécurité défensive : tout échec ici (BDD down, snapshot corrompu, ToastMagic
 * facade indisponible…) est loggé silencieusement — un échec listener NE DOIT
 * PAS empêcher le login.
 */
class NotifyQuotaOverageOnLogin
{
    /**
     * Mapping clé snapshot → label humain pour le message toast.
     *
     * @var array<string, string>
     */
    private const PARTITION_LABELS = [
        'home' => 'Espace personnel',
        'sambaedu' => 'Partages',
    ];

    public function handle(Login $event): void
    {
        try {
            $authUser = $event->user;

            // L'event Login expose `Authenticatable` — on n'agit que sur des
            // User Eloquent (cas SambaEduAuthGuard). Si autre source d'auth :
            // pas de snapshot dispo → no-op silencieux.
            if (!$authUser instanceof User) {
                return;
            }

            // Lecture directe de la colonne JSON. `quota_snapshot` est cast
            // 'array' sur User — `null` si jamais snapshoté.
            $snapshot = $authUser->quota_snapshot;

            if (!is_array($snapshot) || $snapshot === []) {
                return;
            }

            $overPartitions = $this->collectOverPartitions($snapshot);

            if ($overPartitions === []) {
                return;
            }

            $this->emitWarningToast($overPartitions);
        } catch (\Throwable $e) {
            // CRITIQUE : ne JAMAIS laisser une exception remonter ici. Un
            // échec listener ne doit pas casser le login (le toast n'est
            // qu'un bonus UX, pas un mécanisme de sécurité).
            Log::warning('QuotaService: listener NotifyQuotaOverageOnLogin échoué', [
                'login' => $event->user instanceof User ? $event->user->login : null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrait les partitions en dépassement (soft OU hard) depuis le snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{key:string, label:string, used_mb:int, soft_mb:int}>
     */
    private function collectOverPartitions(array $snapshot): array
    {
        $result = [];

        foreach (self::PARTITION_LABELS as $key => $label) {
            $part = $snapshot[$key] ?? null;
            if (!is_array($part)) {
                continue;
            }

            $isOverSoft = (bool) ($part['is_over_soft'] ?? false);
            $isOverHard = (bool) ($part['is_over_hard'] ?? false);
            if (!$isOverSoft && !$isOverHard) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $label,
                'used_mb' => (int) ($part['used_mb'] ?? 0),
                'soft_mb' => (int) ($part['soft_mb'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Émet le toast warning. Si plusieurs partitions over : 1 SEUL toast avec
     * description listant les partitions ligne à ligne (UX moins bruyante que
     * 2 toasts séparés — décision SM 5.1c AC 9).
     *
     * @param  array<int, array{key:string, label:string, used_mb:int, soft_mb:int}>  $overPartitions
     */
    private function emitWarningToast(array $overPartitions): void
    {
        if (count($overPartitions) === 1) {
            $p = $overPartitions[0];
            $title = "Votre espace {$p['label']} est dépassé.";
            $description = "{$p['used_mb']} Mo utilisés / {$p['soft_mb']} Mo autorisés. Libérez de l'espace pour éviter les blocages.";
        } else {
            $title = 'Plusieurs espaces de stockage sont dépassés.';
            $lines = [];
            foreach ($overPartitions as $p) {
                $lines[] = "- {$p['label']} : {$p['used_mb']} Mo utilisés / {$p['soft_mb']} Mo autorisés.";
            }
            $description = implode("\n", $lines) . "\nLibérez de l'espace pour éviter les blocages.";
        }

        ToastMagic::warning($title, $description);
    }
}
