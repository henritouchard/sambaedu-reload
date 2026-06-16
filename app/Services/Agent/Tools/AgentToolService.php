<?php

declare(strict_types=1);

namespace App\Services\Agent\Tools;

use App\Models\AgentTool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Story 25.6 — Cycle de vie du catalogue d'outils agent côté serveur (D2, D5,
 * AC1-AC3).
 *
 * SEUL écrivain de la table `agent_tools` (pattern {@see \App\Services\Agent\Releases\ReleaseCreationService}) :
 * l'UI Livewire et toute autre façade passent par lui — jamais de `save()`
 * direct sur le modèle. Deux opérations :
 *
 *  - {@see upload()} — ingestion VALIDÉE du `.zip` portable Rainmeter :
 *    extension `.zip`, taille ≤ borne config, version au domaine fermé,
 *    filename STRICTEMENT DÉRIVÉ de la version (matchant la regex de
 *    {@see \App\Http\Controllers\Api\V1\Agent\ToolController} — anti-traversal,
 *    jamais le nom client brut), STRUCTURE du ZIP (`Rainmeter.exe` + `Skins/`
 *    à la racine = portable réel attendu par l'agent). Le SHA-256 et la taille
 *    sont CALCULÉS SERVEUR (`hash_file`, `filesize` — jamais un hash déclaré
 *    par le client). Mono-version (D5) : un nouvel upload du même `key`
 *    REMPLACE l'archive active (ancien fichier purgé). Tout échec =
 *    {@see AgentToolException}, AUCUNE écriture DB, AUCUN fichier orphelin.
 *  - {@see toggle()} — bascule `enabled` (toggle GLOBAL — D3). Activé →
 *    l'outil est exposé actif dans le manifest et déployé ; désactivé →
 *    no-op côté agent, SANS désinstaller (D4).
 *
 * NFR7 (critère Keycloak) : aucune dépendance AD/LDAP/APCu ici — pose d'un
 * asset vérifié + bascule d'un drapeau, rien d'autre. Aucune écriture hors
 * `agent_tools`.
 */
class AgentToolService
{
    /** Clé fonctionnelle du seul outil du MVP (généralise 27.1bis). */
    public const RAINMETER_KEY = 'rainmeter';

    /**
     * Domaine fermé de `version` (iso `ReleaseCreationService::VERSION_PATTERN`)
     * — caractères sûrs uniquement, jamais de séparateur de chemin.
     */
    public const VERSION_PATTERN = '/^[0-9A-Za-z.+~-]{1,32}$/';

    /**
     * Forme du filename d'un artefact d'outil de rendu, ISO la regex stricte de
     * {@see \App\Http\Controllers\Api\V1\Agent\ToolController::FILENAME_PATTERN}
     * (le serving aval réutilise ce nom). Dérivé serveur, jamais le nom client.
     */
    public const FILENAME_PATTERN = '/^sambaedu-rainmeter-[0-9A-Za-z.+~-]+\.zip$/';

    /** Sentinelle de structure portable attendue par l'agent (rainmeter.go). */
    private const REQUIRED_EXE = 'Rainmeter.exe';

    private const REQUIRED_SKINS_DIR = 'Skins/';

    /**
     * Ingère le portable Rainmeter uploadé pour la clé `rainmeter` (D5).
     * Le `filename` est dérivé de `$version` (anti-traversal) ; le SHA-256 et
     * la taille sont calculés SERVEUR sur le fichier réellement stocké.
     *
     * @throws AgentToolException refus (aucune écriture, aucun orphelin)
     */
    public function upload(UploadedFile $file, string $version, ?int $uploadedBy = null): AgentTool
    {
        $version = trim($version);

        // 1. Version au domaine fermé (avant toute dérivation de filename).
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw $this->reject('invalid_version', sprintf(
                'Version « %s » malformée (attendu : lettres/chiffres/.+~- , 32 max).',
                Str::limit($version, 64),
            ));
        }

        // 2. Filename DÉRIVÉ serveur (jamais le nom client brut) + re-vérifié
        //    contre la regex du serving (défense en profondeur).
        $filename = sprintf('sambaedu-rainmeter-%s.zip', $version);
        if (strlen($filename) > 255 || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw $this->reject('invalid_filename', sprintf(
                'Filename dérivé « %s » non conforme — version invalide.',
                Str::limit($filename, 128),
            ));
        }

        // 3. Extension `.zip` (nom client) + sanity MIME. On ne fait pas
        //    confiance au seul nom : la structure ZIP est vérifiée en 5.
        $clientExt = strtolower((string) $file->getClientOriginalExtension());
        if ($clientExt !== 'zip') {
            throw $this->reject('invalid_extension', sprintf(
                'Extension « .%s » refusée : un portable d\'outil de rendu est une archive .zip.',
                Str::limit($clientExt, 16),
            ));
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
            throw $this->reject('invalid_mime', sprintf(
                'Type de contenu « %s » refusé (attendu : archive ZIP).',
                Str::limit($mime, 64),
            ));
        }

        // 4. Taille ≤ borne config (avant toute copie/hachage).
        $maxBytes = (int) config('agent.tool_max_upload_bytes');
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > $maxBytes) {
            throw $this->reject('too_large', sprintf(
                'Archive de %s — au-delà de la borne autorisée (%s).',
                $this->humanBytes($size),
                $this->humanBytes($maxBytes),
            ));
        }

        // 5. Structure ZIP : Rainmeter.exe + Skins/ à la racine (portable réel
        //    attendu par l'agent — rainmeter.go). Lecture sur le fichier
        //    temporaire de l'upload, AVANT tout stockage définitif.
        $this->assertPortableStructure($file->getRealPath() ?: $file->getPathname());

        // 6. Stockage confiné sous tools_path + SHA-256/taille CALCULÉS SERVEUR
        //    sur le fichier réellement écrit. Mono-version (D5) : on remplace
        //    l'archive active de la même key.
        $toolsPath = $this->toolsPath();
        if (! is_dir($toolsPath) && ! @mkdir($toolsPath, 0o755, true) && ! is_dir($toolsPath)) {
            throw $this->reject('storage_unavailable', sprintf(
                'Répertoire des outils indisponible : %s (déposé ? lisible/écrivable www-admin ?).',
                $toolsPath,
            ));
        }

        $existing = AgentTool::query()->where('key', self::RAINMETER_KEY)->first();
        $previousFilename = $existing?->filename;

        $destination = $toolsPath . DIRECTORY_SEPARATOR . $filename;
        // Le filename est dérivé d'une version au domaine fermé : pas de
        // séparateur possible. realpath du parent confirme le confinement.
        $this->moveConfined($file, $toolsPath, $filename);

        $computed = hash_file('sha256', $destination);
        if ($computed === false) {
            // Impossible de hacher ce qu'on vient d'écrire : on nettoie pour
            // ne pas laisser d'orphelin et on refuse.
            @unlink($destination);
            throw $this->reject('hash_failed', sprintf(
                'Lecture du fichier stocké impossible pour hachage : %s.',
                $destination,
            ));
        }
        $storedSize = filesize($destination);
        if ($storedSize === false) {
            $storedSize = $size;
        }

        // Le fichier est désormais posé sur disque (`moveConfined`). Si
        // l'écriture DB échoue (QueryException, contrainte, DB down), le `.zip`
        // tout juste déplacé serait orphelin : on le nettoie avant de relancer
        // pour ne laisser AUCUN fichier orphelin (P1). On ne purge l'ancienne
        // archive qu'APRÈS le succès de l'écriture (jamais avant).
        try {
            $tool = AgentTool::query()->updateOrCreate(
                ['key' => self::RAINMETER_KEY],
                [
                    'name' => 'Rainmeter (overlay)',
                    'filename' => $filename,
                    'sha256' => $computed,
                    'size' => (int) $storedSize,
                    'uploaded_at' => now(),
                    'uploaded_by' => $uploadedBy,
                    // L'upload ne (ré)active pas tout seul : l'état `enabled`
                    // précédent est conservé (un re-upload d'une version corrigée
                    // d'un outil déjà actif reste actif ; un premier upload reste
                    // désactivé par défaut — l'admin active explicitement).
                ],
            );
        } catch (\Throwable $e) {
            @unlink($destination);
            Log::channel('agent')->error('[AgentToolService] agent.tool.upload_db_failed', [
                'action_type' => 'agent.tool.upload_db_failed',
                'key' => self::RAINMETER_KEY,
                'filename' => $filename,
                'detail' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Mono-version : purge de l'ancienne archive si le filename a changé
        // (version différente). Jamais avant le succès de l'écriture/hachage.
        if ($previousFilename !== null && $previousFilename !== $filename) {
            $old = $toolsPath . DIRECTORY_SEPARATOR . $previousFilename;
            if (is_file($old)) {
                @unlink($old);
            }
        }

        Log::channel('agent')->info('[AgentToolService] agent.tool.uploaded', [
            'action_type' => 'agent.tool.uploaded',
            'key' => self::RAINMETER_KEY,
            'filename' => $filename,
            'sha256' => $computed,
            'size' => (int) $storedSize,
            'uploaded_by' => $uploadedBy,
        ]);

        return $tool->refresh();
    }

    /**
     * Bascule le drapeau `enabled` (toggle GLOBAL — D3). SEUL écrivain.
     */
    public function toggle(AgentTool $tool, bool $enabled): AgentTool
    {
        $tool->enabled = $enabled;
        $tool->save();

        Log::channel('agent')->info('[AgentToolService] agent.tool.toggled', [
            'action_type' => 'agent.tool.toggled',
            'key' => $tool->key,
            'enabled' => $enabled,
        ]);

        return $tool->refresh();
    }

    /**
     * Vérifie que l'archive contient bien `Rainmeter.exe` ET un dossier
     * `Skins/` À LA RACINE (structure portable réelle attendue par l'agent —
     * sinon l'agent extrait un ZIP inutile/hostile). Lecture seule de l'index
     * du ZIP (jamais d'extraction ici).
     *
     * @throws AgentToolException structure invalide ou archive illisible
     */
    private function assertPortableStructure(string $path): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::RDONLY);
        if ($opened !== true) {
            throw $this->reject('invalid_zip', 'Archive ZIP illisible ou corrompue.');
        }

        $hasExe = false;
        $hasSkinsDir = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            // Normalise les éventuels séparateurs Windows pour la comparaison
            // de chemin racine (un portable produit par Windows peut utiliser
            // `\`). On compare au niveau RACINE uniquement (pas de sous-dossier
            // englobant — c'est le format que l'agent extrait à plat).
            $normalized = str_replace('\\', '/', $entry);

            if ($normalized === self::REQUIRED_EXE) {
                $hasExe = true;
            }
            if ($normalized === self::REQUIRED_SKINS_DIR || str_starts_with($normalized, self::REQUIRED_SKINS_DIR)) {
                $hasSkinsDir = true;
            }
            if ($hasExe && $hasSkinsDir) {
                break;
            }
        }
        $zip->close();

        if (! $hasExe || ! $hasSkinsDir) {
            throw $this->reject('invalid_structure', sprintf(
                'Structure portable invalide : %s%s attendu(s) à la racine de l\'archive.',
                $hasExe ? '' : 'Rainmeter.exe ',
                $hasSkinsDir ? '' : 'dossier Skins/',
            ));
        }
    }

    /**
     * Déplace le fichier uploadé sous `$dir/$filename` avec confinement
     * realpath (le filename est dérivé d'une version au domaine fermé — pas de
     * séparateur ; cette vérif est la seconde ligne de défense).
     *
     * @throws AgentToolException destination hors du répertoire confiné
     */
    private function moveConfined(UploadedFile $file, string $dir, string $filename): void
    {
        $base = realpath($dir);
        if ($base === false) {
            throw $this->reject('storage_unavailable', sprintf(
                'Répertoire des outils introuvable : %s.',
                $dir,
            ));
        }
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        $target = $base . DIRECTORY_SEPARATOR . $filename;
        // Le dossier parent du target DOIT être exactement $base (anti-traversal).
        if (dirname($target) !== $base) {
            throw $this->reject('invalid_filename', 'Filename hors du répertoire confiné.');
        }

        $file->move($base, $filename);
    }

    /**
     * Refus AC2 : log warning `agent.tool.rejected` (raison machine + détail,
     * chemins disque inclus — réservé au log serveur) puis exception, AUCUNE
     * écriture DB, aucun orphelin. Le `$message` détaillé n'est PAS destiné au
     * toast UI (P6) : la façade Livewire n'expose que `reason`, le détail reste
     * ici dans le log.
     */
    private function reject(string $reason, string $message): AgentToolException
    {
        Log::channel('agent')->warning('[AgentToolService] agent.tool.rejected', [
            'action_type' => 'agent.tool.rejected',
            'reason' => $reason,
            'detail' => $message,
        ]);

        return AgentToolException::rejected($reason, $message);
    }

    private function toolsPath(): string
    {
        return rtrim((string) config('agent.tools_path'), '/\\');
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' Mio';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' Kio';
        }

        return $bytes . ' o';
    }
}
