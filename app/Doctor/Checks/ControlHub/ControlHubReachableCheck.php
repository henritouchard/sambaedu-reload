<?php

declare(strict_types=1);

namespace App\Doctor\Checks\ControlHub;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Models\ControlHubConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Vérifie qu'un controlHub est connecté (handshake actif en DB) et joignable.
 *
 * Read-only strict (contrat Doctor) : on ne déclenche PAS de heartbeat réel
 * (qui écrirait `last_heartbeat_at`) — juste un HEAD HTTP court sur la
 * base_url. N'importe quelle réponse HTTP (même 4xx) prouve la joignabilité ;
 * seule une erreur réseau/timeout compte comme injoignable.
 *
 * **Choix assumé (review F4)** : auto-découvert par `sambaedu:doctor` — la
 * commande CLI peut émettre un HEAD HTTP sortant (uniquement si un hub est
 * connecté en DB). Timeouts courts (2s connect / 3s total).
 */
final class ControlHubReachableCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'controlhub';
    }

    public function name(): string
    {
        return 'controlHub';
    }

    public function run(): CheckResult
    {
        try {
            $connection = ControlHubConnection::current();
        } catch (Throwable $e) {
            // Table absente (migrations pas à jour) ou DB down — ne pas
            // doubler le check database : warn explicite.
            return CheckResult::warn(
                sprintf('état controlHub illisible : %s', substr($e->getMessage(), 0, 120)),
                'Vérifier les migrations (php artisan migrate) et la connexion DB.',
            );
        }

        if ($connection === null) {
            return CheckResult::warn(
                'aucun controlHub connecté (pas de handshake actif).',
                'Connecter l\'instance depuis controlHub (handshake) si une supervision centrale est souhaitée.',
            );
        }

        $baseUrl = (string) ($connection->base_url ?? '');
        if ($baseUrl === '') {
            return CheckResult::error(
                'connexion controlHub active mais base_url vide.',
                'Relancer le handshake depuis controlHub.',
            );
        }

        try {
            // HEAD court — une réponse HTTP quelconque suffit (joignable).
            Http::connectTimeout(2)->timeout(3)->head($baseUrl);
        } catch (Throwable $e) {
            return CheckResult::error(
                sprintf('controlHub injoignable (%s) : %s', $baseUrl, substr($e->getMessage(), 0, 120)),
                'Vérifier le réseau et que le controlHub est démarré.',
            );
        }

        $lastHeartbeat = $connection->last_heartbeat_at?->diffForHumans() ?? 'jamais';

        return CheckResult::ok(sprintf(
            'connecté à %s (dernier heartbeat : %s)',
            $baseUrl,
            $lastHeartbeat,
        ));
    }
}
