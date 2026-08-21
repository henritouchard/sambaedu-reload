<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubArtifactPullStatus;
use App\Models\AgentTool;
use App\Models\ControlHubContractItem;
use App\Models\Shortcut;
use App\Models\WallpaperAsset;
use App\Services\Shortcuts\ShortcutIconAssetService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Story 39.4 — Canal ④ : téléchargement + VÉRIFICATION D'INTÉGRITÉ + matérialisation locale d'un
 * binaire imposé par le contrat amont (controlHub). Extrait de {@see \App\Jobs\ControlHub\PullContractArtifactJob}
 * (le job reste un thin wrapper `ShouldQueue`) pour la testabilité (patron repo).
 *
 * Discipline (iso {@see \App\Services\Agent\Tools\AgentToolService} / {@see \App\Services\Wallpaper\WallpaperUploadService}) :
 *  - téléchargement vers un fichier TEMPORAIRE (jamais directement dans le foyer final) ;
 *  - `hash_file('sha256')` calculé CÔTÉ SERVEUR (jamais un hash déclaré) puis comparaison STRICTE
 *    au `checksum` annoncé ;
 *  - **match** → matérialisation dans le foyer local, filename DÉRIVÉ SERVEUR (jamais
 *    `artifact.filename` brut — anti-traversal), `pull_status = downloaded` ;
 *  - **mismatch / échec** → AUCUNE écriture d'asset, fichier temporaire supprimé,
 *    `pull_status = error` + `pull_error` (message court, jamais l'URL signée en clair — NFR-A3) ;
 *  - **précédence locale re-vérifiée EN TÊTE** (garde contre double exécution / re-tentative :
 *    ré-pull au même checksum = no-op, aucun téléchargement).
 *
 * Le pull NE REMPLACE JAMAIS une source locale : il ne comble que l'absence (AC8).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » ; vocabulaire « amont » / `ControlHub*`.
 */
class ArtifactPullService
{
    /** Timeout du téléchargement (artefacts petits — wallpapers/outils, pas d'ISO multi-Go). */
    private const HTTP_TIMEOUT_SECONDS = 60;

    /** Longueur max du message d'erreur persisté. */
    private const ERROR_MAX = 500;

    /**
     * Extensions sûres autorisées pour un outil agent (dérivées, jamais du nom client brut).
     * Défaut `bin` si l'extension déclarée est hors liste (anti-traversal / anti-double-ext).
     */
    private const AGENT_TOOL_EXTENSIONS = ['zip', 'exe', 'msi', 'bin'];

    /**
     * Tire et matérialise le binaire d'un item imposé. Idempotent : un item déjà satisfait
     * localement (précédence) n'entraîne aucun téléchargement.
     *
     * @param  int          $itemId    id de l'item {@see ControlHubContractItem} à mettre à jour
     * @param  string       $type      `wallpapers` | `agent_tools`
     * @param  string       $key       clé fonctionnelle de l'item (identité par-clé des agent_tools)
     * @param  string       $url       URL SIGNÉE volatile (jamais persistée en colonne — AC5)
     * @param  string       $checksum  sha256 hex attendu (identité stable — base du no-op)
     * @param  string|null  $filename  nom informatif annoncé (JAMAIS utilisé pour le nommage disque)
     * @param  int|null     $size      taille attendue (informative)
     */
    public function pull(
        int $itemId,
        string $type,
        string $key,
        string $url,
        string $checksum,
        ?string $filename = null,
        ?int $size = null,
    ): void {
        // Review 39.4 #1 (défense en profondeur) — checksum toujours normalisé minuscule à l'entrée
        // du service, quel que soit l'appelant : garantit que precedence + materialize sont cohérents
        // avec l'écriture minuscule de WallpaperUploadService/AgentToolService (l'ingestion normalise
        // déjà au point canonique, mais ce service est public).
        $checksum = strtolower($checksum);

        /** @var ControlHubContractItem|null $item */
        $item = ControlHubContractItem::query()->find($itemId);
        if ($item === null) {
            // L'item a disparu (prune par une ré-émission entre-temps) : plus rien à matérialiser.
            return;
        }

        // Précédence locale re-vérifiée EN TÊTE (AC8/AC9) : si l'asset est apparu entre le dispatch
        // et l'exécution (concurrence, upload admin, job jumeau au même checksum), aucun
        // téléchargement n'est retenté — l'état désiré est déjà satisfait (ré-pull no-op).
        if ($this->presentLocally($type, $key, $checksum)) {
            $this->markDownloaded($item);

            return;
        }

        $destDir = $this->foyer($type);
        if ($destDir === null) {
            // Type non matérialisable (ne devrait pas arriver — le dispatch filtre déjà) : no-op sûr.
            return;
        }

        if (! is_dir($destDir) && ! @mkdir($destDir, 0o755, true) && ! is_dir($destDir)) {
            $this->markError($item, 'foyer de stockage indisponible');

            return;
        }

        // Fichier temporaire DANS le foyer cible (garantit un rename atomique même filesystem).
        $tmp = rtrim($destDir, '/\\') . DIRECTORY_SEPARATOR . '.chpull-' . bin2hex(random_bytes(8)) . '.tmp';

        try {
            // Review 39.4 #3 — téléchargement STREAMÉ vers le fichier temporaire (`sink`) plutôt que
            // `->body()` résumé en mémoire (un binaire anormalement volumineux ne gonfle plus la RAM du
            // worker), + BORNE de taille dure post-téléchargement (le sha256 seul ne borne pas la taille).
            $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)->sink($tmp)->get($url);
            if (! $response->successful()) {
                @unlink($tmp);
                $this->markError($item, 'téléchargement en échec (HTTP ' . $response->status() . ')');

                return;
            }

            $downloaded = @filesize($tmp);
            if ($downloaded === false) {
                @unlink($tmp);
                $this->markError($item, 'fichier temporaire illisible après téléchargement');

                return;
            }
            if ($downloaded > self::maxArtifactBytes()) {
                @unlink($tmp);
                $this->markError($item, 'binaire amont trop volumineux (dépasse la borne de sécurité)');

                return;
            }

            // Hash CÔTÉ SERVEUR sur le fichier réellement téléchargé (jamais le hash déclaré).
            $computed = hash_file('sha256', $tmp);
            if ($computed === false) {
                @unlink($tmp);
                $this->markError($item, 'calcul du sha256 impossible sur le fichier téléchargé');

                return;
            }

            // Comparaison STRICTE, insensible à la casse hex (hash_equals sur formes normalisées).
            if (! hash_equals(strtolower($checksum), strtolower($computed))) {
                @unlink($tmp);
                // NFR-A3 : ni l'URL signée, ni un secret ne figurent dans le message.
                $this->markError($item, 'sha256 non concordant : binaire rejeté (aucune matérialisation)');

                Log::warning('ArtifactPullService: sha256 mismatch, binary rejected', [
                    'item_id' => $item->id,
                    'type' => $type,
                    'key' => $key,
                    'expected' => strtolower($checksum),
                    'computed' => strtolower($computed),
                ]);

                return;
            }

            // Review 39.4 #2 — un sha256 concordant ne prouve PAS que le contenu est une image :
            // le chemin de pull ne re-normalise/recompresse PAS (préserver le checksum vérifié), donc
            // sans garde on stockerait un binaire arbitraire (bombe de décompression, non-image) comme
            // WallpaperAsset légitime — que du code aval (aperçu/thumbnail Imagick) rouvrirait, ré-
            // introduisant la classe de vuln « bombe pixel » corrigée dans WallpaperUploadService. On
            // valide donc EN LECTURE SEULE (getimagesize = en-têtes uniquement, pas de décompression
            // complète) le type réel avant matérialisation ; rejet propre sinon.
            if ($type === 'wallpapers' && ! $this->pulledFileIsSafeImage($tmp)) {
                @unlink($tmp);
                $this->markError($item, 'binaire wallpaper rejeté : contenu non reconnu comme image sûre');

                return;
            }

            // Intégrité vérifiée → matérialisation dans le foyer local (filename dérivé serveur).
            $byteSize = filesize($tmp);
            $byteSize = $byteSize === false ? $size : (int) $byteSize;

            match ($type) {
                'wallpapers' => $this->materializeWallpaper($tmp, $destDir, $checksum, $byteSize),
                'agent_tools' => $this->materializeAgentTool($tmp, $destDir, $key, $checksum, $filename, $byteSize),
                Shortcut::TYPE_SHORTCUTS => $this->materializeShortcutIcon($tmp, $destDir, $key, $checksum),
                default => @unlink($tmp),
            };

            $this->markDownloaded($item);

            Log::info('ArtifactPullService: artifact materialized', [
                'item_id' => $item->id,
                'type' => $type,
                'key' => $key,
                'checksum' => strtolower($checksum),
            ]);
        } catch (Throwable $e) {
            @unlink($tmp);
            // Review 39.4 #E11 (NFR-A3) — NE JAMAIS persister `$e->getMessage()` dans
            // `pull_error` : Guzzle suffixe l'URI complète (query string `?sig=…`) à ses
            // messages (ConnectException/RequestException), et cette colonne est remontée
            // à l'amont par le canal ③. On persiste une catégorie stable (classe d'exception) ;
            // le détail complet — URL incluse — reste dans le log serveur uniquement.
            $this->markError($item, 'échec du pull (' . class_basename($e) . ')');

            Log::error('ArtifactPullService: pull failed', [
                'item_id' => $item->id,
                'type' => $type,
                'key' => $key,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Review 39.4 #3 — borne de taille dure d'un binaire amont (wallpapers/outils = petits ;
     * pas d'ISO multi-Go par ce canal). Configurable, défaut 256 MiB.
     */
    private static function maxArtifactBytes(): int
    {
        $configured = (int) config('controlHub.artifact_max_bytes', 268435456);

        return $configured > 0 ? $configured : 268435456;
    }

    /**
     * Review 39.4 #2 — le fichier tiré est-il une image raster d'un type autorisé ? Validation EN
     * LECTURE SEULE : `getimagesize()` ne lit que les en-têtes (pas de décompression complète → sûr
     * face à une bombe), et l'on restreint au même ensemble de types que la bibliothèque wallpaper.
     * Si Imagick est disponible, `pingImage()` (métadonnées seules) sous les limites de ressources
     * déjà imposées par WallpaperUploadService renforce la garde sans re-encoder.
     */
    private function pulledFileIsSafeImage(string $tmp): bool
    {
        $info = @getimagesize($tmp);
        if ($info === false || ! isset($info[2])) {
            return false;
        }

        $allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP, IMAGETYPE_WEBP];
        if (! in_array($info[2], $allowedTypes, true)) {
            return false;
        }

        if (class_exists('Imagick')) {
            try {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
                $probe = new \Imagick();
                $ok = $probe->pingImage($tmp); // en-têtes seulement, pas de décodage complet
                $probe->clear();

                return $ok !== false;
            } catch (Throwable $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Précédence locale (identité par contenu pour les wallpapers, par clé pour les agent_tools).
     */
    private function presentLocally(string $type, string $key, string $checksum): bool
    {
        return match ($type) {
            'wallpapers' => WallpaperAsset::query()->where('checksum', $checksum)->exists(),
            'agent_tools' => AgentTool::query()->where('key', $key)->exists(),
            // L'icône est content-adressée sur disque : présente, il n'y a rien à tirer,
            // et c'est le réconciliateur de raccourcis qui recolle les colonnes.
            Shortcut::TYPE_SHORTCUTS => is_file(
                app(ShortcutIconAssetService::class)->servedDir().DIRECTORY_SEPARATOR.$checksum.'.ico'
            ),
            default => true,
        };
    }

    /** Foyer local de matérialisation (foyers EXISTANTS ; aucun nouveau chemin inventé). */
    private function foyer(string $type): ?string
    {
        return match ($type) {
            'wallpapers' => WallpaperAsset::libraryPath(),
            'agent_tools' => rtrim((string) config('agent.tools_path'), '/\\'),
            Shortcut::TYPE_SHORTCUTS => app(ShortcutIconAssetService::class)->servedDir(),
            default => null,
        };
    }

    /**
     * Matérialise un wallpaper content-addressé (`<checksum>.jpg`, filename DÉRIVÉ SERVEUR) SANS
     * re-normaliser/recompresser (une re-normalisation Imagick changerait le checksum vérifié —
     * le fichier tiré est DÉJÀ celui dont le sha256 a été validé). Dédup par checksum.
     */
    private function materializeWallpaper(string $tmp, string $libraryDir, string $checksum, ?int $byteSize): void
    {
        // Nommage content-addressé, iso convention de la bibliothèque (WallpaperUploadService).
        $filename = $checksum . '.jpg';
        $target = rtrim($libraryDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (is_file($target)) {
            // Contenu déjà présent sur disque : on jette le tmp, on (ré)assure l'asset DB.
            @unlink($tmp);
        } else {
            @chmod($tmp, 0o644);
            if (! @rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException("échec du dépôt du wallpaper dans la bibliothèque ({$target})");
            }
        }

        WallpaperAsset::query()->firstOrCreate(
            ['checksum' => $checksum],
            [
                'filename' => $filename,
                'byte_size' => $byteSize,
                'uploaded_by' => null,
            ],
        );
    }

    /**
     * Matérialise l'icône d'un raccourci imposé (`<checksum>.ico`, nommage dérivé du
     * seul contenu) puis la recolle sur le raccourci que le contrat a matérialisé.
     *
     * Le raccourci est retrouvé par la clé de l'item, pas par `shortcuts.key` : cette
     * dernière peut avoir été préfixée à la création pour ne pas écraser un raccourci
     * local homonyme.
     *
     * Une icône dont le raccourci n'existe pas (item sans cible, donc non matérialisé)
     * reste sur disque sans être rattachée — le fichier est content-adressé, il servira
     * si le contrat complète l'item plus tard.
     */
    private function materializeShortcutIcon(string $tmp, string $iconDir, string $key, string $checksum): void
    {
        $filename = $checksum.'.ico';
        $target = rtrim($iconDir, '/\\').DIRECTORY_SEPARATOR.$filename;

        if (is_file($target)) {
            @unlink($tmp);
        } else {
            @chmod($tmp, 0o644);
            if (! @rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException("échec du dépôt de l'icône de raccourci ({$target})");
            }
        }

        Shortcut::query()
            ->where('controlhub_contract_key', $key)
            ->update([
                'icon_asset' => $filename,
                'icon_checksum' => $checksum,
            ]);
    }

    /**
     * Matérialise un outil agent par-clé (mono-version, désactivé par défaut à la création — iso
     * `AgentToolService::registerEmbedded()`). Filename DÉRIVÉ SERVEUR (clé + checksum + extension
     * whitelistée), JAMAIS `artifact.filename` brut (anti-traversal).
     */
    private function materializeAgentTool(
        string $tmp,
        string $toolsDir,
        string $key,
        string $checksum,
        ?string $declaredFilename,
        ?int $byteSize,
    ): void {
        $safeKey = strtolower((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $key));
        $safeKey = trim(Str::limit($safeKey, 40, ''), '-');
        if ($safeKey === '') {
            $safeKey = 'tool';
        }
        $ext = $this->deriveSafeExtension($declaredFilename, self::AGENT_TOOL_EXTENSIONS, 'bin');
        $filename = sprintf('sambaedu-tool-%s-%s.%s', $safeKey, strtolower($checksum), $ext);

        $target = rtrim($toolsDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        // Confinement (anti-traversal) : le dossier parent du target DOIT être exactement le foyer.
        $base = realpath($toolsDir);
        if ($base === false || dirname($target) !== rtrim($base, DIRECTORY_SEPARATOR)) {
            @unlink($tmp);
            throw new \RuntimeException('filename d\'outil dérivé hors du foyer confiné');
        }

        if (is_file($target)) {
            @unlink($tmp);
        } else {
            @chmod($tmp, 0o644);
            if (! @rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException("échec du dépôt de l'outil agent ({$target})");
            }
        }

        try {
            AgentTool::query()->updateOrCreate(
                ['key' => $key],
                [
                    // `name` posé à la CRÉATION (on n'atteint ce point que si l'outil est absent
                    // par clé — la précédence en tête a court-circuité sinon). L'admin le renomme
                    // ensuite librement. `enabled` NON touché → défaut false (désactivé à la
                    // création, iso registerEmbedded : l'admin active explicitement).
                    'name' => $key,
                    'filename' => $filename,
                    'sha256' => strtolower($checksum),
                    'size' => $byteSize ?? 0,
                    'uploaded_at' => now(),
                    'uploaded_by' => null,
                ],
            );
        } catch (Throwable $e) {
            // Écriture DB en échec après dépôt disque : on nettoie l'orphelin avant de propager.
            if (is_file($target)) {
                @unlink($target);
            }

            throw $e;
        }
    }

    /**
     * Extension sûre dérivée du nom déclaré : minuscule, whitelistée, sinon défaut. Ne fait JAMAIS
     * confiance au nom client pour le chemin — seule l'extension (si sûre) est reprise.
     *
     * @param  array<int, string>  $whitelist
     */
    private function deriveSafeExtension(?string $declaredFilename, array $whitelist, string $default): string
    {
        if ($declaredFilename === null || $declaredFilename === '') {
            return $default;
        }
        $ext = strtolower((string) pathinfo($declaredFilename, PATHINFO_EXTENSION));
        if ($ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) === 1 && in_array($ext, $whitelist, true)) {
            return $ext;
        }

        return $default;
    }

    private function markDownloaded(ControlHubContractItem $item): void
    {
        $item->pull_status = ControlHubArtifactPullStatus::Downloaded;
        $item->pull_error = null;
        $item->save();
    }

    private function markError(ControlHubContractItem $item, string $message): void
    {
        $item->pull_status = ControlHubArtifactPullStatus::Error;
        $item->pull_error = Str::limit($message, self::ERROR_MAX, '');
        $item->save();
    }
}
