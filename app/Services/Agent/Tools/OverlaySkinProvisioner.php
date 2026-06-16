<?php

declare(strict_types=1);

namespace App\Services\Agent\Tools;

use Illuminate\Support\Facades\Log;

/**
 * Story 25.6 — Provisioning + résolution de la skin d'overlay Rainmeter servie
 * (volet A, D1, D7).
 *
 * La skin CANONIQUE (autorité) est versionnée sous
 * `resources/overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini` (UTF-8).
 * Elle est PROVISIONNÉE (copie idempotente) vers `config('agent.overlay_skin_path')`
 * (convention storage NON versionné) d'où elle est SERVIE par la route agent
 * authentifiée. L'embed `go:embed` de 27.1bis est RETIRÉ : le serving est la
 * source unique (D1) ; l'agent télécharge la skin (vérif SHA-256) puis la
 * convertit UTF-16 LE + BOM à la pose (logique 27.1bis inchangée côté agent).
 *
 * Idempotent : la copie n'a lieu que si la cible diverge de la canonique
 * (comparaison de hash). Le serving lit ENSUITE le fichier provisionné — c'est
 * lui qui fait foi du SHA-256 exposé au manifest (un drift entre canonique et
 * servie est ainsi corrigé au prochain serving). En production, le fichier doit
 * être lisible www-admin (uid 599) — sinon `hash_file()` → false → 404
 * silencieux (mémoire project_php_fpm_user_www_admin) ; la copie tente le
 * meilleur effort, l'alignement de droits reste une action ops.
 *
 * NFR7 : aucune dépendance AD/LDAP/APCu — lecture/copie de fichier, rien d'autre.
 */
class OverlaySkinProvisioner
{
    /**
     * Chemin de la skin servie (provisionnée), garantie alignée sur la
     * canonique. Retourne null si NI la cible NI la canonique ne sont
     * lisibles (le serving répond alors 404 indistinct).
     */
    public function resolveServedPath(): ?string
    {
        $served = (string) config('agent.overlay_skin_path');
        $canonical = $this->canonicalPath();

        $canonicalReadable = $canonical !== '' && is_file($canonical) && is_readable($canonical);

        // Aligne la cible servie sur la canonique si elle diverge/absente
        // (best-effort ; un échec de copie laisse la cible existante telle
        // quelle — le serving servira ce qui est lisible).
        if ($canonicalReadable) {
            $this->syncIfDiverged($canonical, $served);
        }

        if (is_file($served) && is_readable($served)) {
            return $served;
        }

        // Repli : si la cible servie n'est pas exploitable mais la canonique
        // l'est, on sert la canonique directement (zéro prod ; évite un 404
        // bloquant en dev/test où storage n'est pas encore provisionné).
        if ($canonicalReadable) {
            return $canonical;
        }

        return null;
    }

    /**
     * SHA-256 hex de la skin effectivement servie (calculé serveur), ou null
     * si introuvable/illisible. Exposé dans le manifest tool/skin (D6/D8).
     */
    public function servedChecksum(): ?string
    {
        $path = $this->resolveServedPath();
        if ($path === null) {
            return null;
        }
        $hash = hash_file('sha256', $path);

        return $hash === false ? null : $hash;
    }

    private function syncIfDiverged(string $canonical, string $served): void
    {
        $canonicalHash = hash_file('sha256', $canonical);
        if ($canonicalHash === false) {
            return;
        }
        if (is_file($served)) {
            $servedHash = hash_file('sha256', $served);
            if ($servedHash !== false && hash_equals($canonicalHash, $servedHash)) {
                return; // déjà aligné, no-op idempotent
            }
        }

        $dir = dirname($served);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            Log::channel('agent')->warning('[OverlaySkinProvisioner] agent.skin.provision_dir_failed', [
                'action_type' => 'agent.skin.provision_dir_failed',
                'dir' => $dir,
            ]);

            return;
        }
        if (! @copy($canonical, $served)) {
            Log::channel('agent')->warning('[OverlaySkinProvisioner] agent.skin.provision_copy_failed', [
                'action_type' => 'agent.skin.provision_copy_failed',
                'served' => $served,
            ]);
        }
    }

    private function canonicalPath(): string
    {
        return resource_path('overlay/rainmeter/SambaEduOverlay/SambaEduOverlay.ini');
    }
}
