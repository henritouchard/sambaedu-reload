<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 17.5 — Helper d'écriture du flag `SAMBAEDU_SCRIPTS_LOGGING_ENABLED`
 * dans le fichier `.env`.
 *
 * Mutualisé entre `winscript-logs:enable` et `winscript-logs:disable`.
 *
 * Décisions SM (D1, D2, D5) :
 *  - D1 : la seule source mutable du flag est le `.env` (l'Assembler 17.2 lit
 *    `config('sambaedu.scripts.logging.enabled')` ← `env('SAMBAEDU_SCRIPTS_LOGGING_ENABLED')`).
 *  - D2 : écriture **non destructive** ancrée ligne par ligne via
 *    `preg_replace('/^SAMBAEDU_SCRIPTS_LOGGING_ENABLED=.*$/m', …)`.
 *    Si la variable est absente, elle est appendée proprement à la fin du
 *    fichier (gestion du `\n` final). Les autres lignes sont préservées byte-pour-byte.
 *  - D5 : le chemin `.env` est **injectable** (`$envPathOverride`) pour que les
 *    tests pointent vers un fichier de fixture temporaire — JAMAIS le `.env` réel.
 */
trait ManagesScriptLoggingFlag
{
    /**
     * Nom canonique de la variable d'environnement pilotant le wrapper 17.2.
     */
    private const ENV_KEY = 'SAMBAEDU_SCRIPTS_LOGGING_ENABLED';

    /**
     * Chemin `.env` surchargeable (tests). Si null → `base_path('.env')`.
     */
    protected ?string $envPathOverride = null;

    /**
     * Permet aux tests d'injecter un `.env` de fixture isolé.
     */
    public function setEnvPath(?string $path): void
    {
        $this->envPathOverride = $path;
    }

    /**
     * Chemin effectif du `.env` à muter.
     */
    protected function envPath(): string
    {
        return $this->envPathOverride ?? base_path('.env');
    }

    /**
     * Écrit le flag (`true`/`false`) dans le `.env` de façon non destructive.
     *
     * @return bool true si l'écriture a réussi, false sinon (fichier introuvable
     *              ou erreur I/O — le caller décide du code de sortie).
     */
    protected function writeLoggingFlag(bool $enabled): bool
    {
        $path = $this->envPath();

        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);
        $value = $enabled ? 'true' : 'false';
        $line = self::ENV_KEY.'='.$value;

        // `[^\r\n]*` (et non `.*$`) borne le match à la valeur SANS consommer le
        // terminateur de ligne (`\n` ou `\r\n`) : il est donc préservé
        // byte-pour-byte, y compris sur un `.env` en CRLF (AC2.2).
        $pattern = '/^'.preg_quote(self::ENV_KEY, '/').'=[^\r\n]*/m';

        if (preg_match($pattern, $contents)) {
            // Remplacement ancré ligne par ligne (préserve le reste byte-pour-byte).
            $replaced = preg_replace($pattern, $line, $contents, 1);

            // Garde-fou : preg_replace() retourne null en cas d'erreur PCRE
            // (ex. limite de backtrack). Ne JAMAIS écrire un `.env` vidé sur un
            // échec silencieux — c'est un fichier de config critique.
            if ($replaced === null) {
                Log::channel('scriptsos')->error('winscript-logs.env_write_pcre_error', [
                    'env_path' => $path,
                ]);

                return false;
            }

            $contents = $replaced;
        } else {
            // Append propre : garantir exactement un `\n` séparateur avant la ligne.
            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $contents .= "\n";
            }
            $contents .= $line."\n";
        }

        File::put($path, $contents);

        return true;
    }

    /**
     * Lit l'état effectif du flag via la config (source de vérité partagée
     * avec l'Assembler 17.2). Clé exacte : `sambaedu.scripts.logging.enabled`.
     */
    protected function loggingFlagEnabled(): bool
    {
        return (bool) config('sambaedu.scripts.logging.enabled', false);
    }

    /**
     * Invalide le cache de configuration en best-effort (D3/D4) afin que la
     * prochaine lecture de config relise la valeur `.env`. Ne relance PAS
     * `config:cache` (laissé à l'opérateur — D4).
     *
     * @return bool true si un cache config était présent et a été vidé.
     */
    protected function clearConfigCacheBestEffort(): bool
    {
        $cacheWasPresent = File::exists(base_path('bootstrap/cache/config.php'));

        try {
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            Log::channel('scriptsos')->warning('winscript-logs.config_clear_failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $cacheWasPresent;
    }

    /**
     * URL d'ingestion résolue (même logique que WrapperScriptRenderer::resolveEndpointUrl()).
     */
    protected function resolveIngestUrl(): string
    {
        try {
            return route('scriptsos.logs.ingest', [], true);
        } catch (Throwable) {
            $base = rtrim((string) config('app.url', 'https://localhost'), '/');

            return $base.'/api/v1/script-execution-logs';
        }
    }
}
