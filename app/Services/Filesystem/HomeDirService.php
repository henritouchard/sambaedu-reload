<?php

namespace App\Services\Filesystem;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service de gestion des home directories XFS
 *
 * Encapsule toutes les opérations filesystem sur les répertoires personnels :
 * - Création du home (depuis le skel user.windows)
 * - Archivage dans /home/trash/ lors de la désactivation
 * - Restauration depuis /home/trash/ lors de la réactivation
 * - Suppression définitive de l'archive
 *
 * Toutes les méthodes appliquent une validation de login (/^[a-zA-Z0-9._-]+$/)
 * avant tout appel exec/sudo — garde anti-injection de commande.
 *
 * Extrait de UserService (Story 5.1a) — iso-comportement garanti.
 */
class HomeDirService
{
    /**
     * Crée ou vérifie le dossier home de l'utilisateur
     *
     * Reproduit le comportement legacy de mkhome.sh :
     * - Si /home/$login n'existe pas : mkdir + copie skel + chown + chmod 770
     * - Si /home/$login existe : vérifie et corrige le propriétaire si nécessaire
     */
    public function createHomeDirectory(string $login): void
    {
        // Validation : empêcher l'injection de commande via le login
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("createHomeDirectory: login invalide (caractères non autorisés)", ['login' => $login]);
            return;
        }

        $homePath = "/home/" . $login;

        try {
            if (!is_dir($homePath)) {
                // Créer le répertoire
                exec("sudo mkdir -p " . escapeshellarg($homePath) . " 2>&1", $output, $returnCode);
                if ($returnCode !== 0) {
                    Log::error("createHomeDirectory: échec mkdir", ['login' => $login, 'output' => implode("\n", $output)]);
                    return;
                }

                // Copier le skel (user.windows comme dans le legacy)
                // Utiliser /. au lieu de /* pour inclure les dotfiles (.bashrc, .profile, etc.)
                $skelPath = '/etc/skel/user.windows';
                if (is_dir($skelPath)) {
                    exec("sudo cp -a " . escapeshellarg($skelPath) . "/. " . escapeshellarg($homePath) . "/ 2>&1", $output2, $rc2);
                    if ($rc2 !== 0) {
                        Log::warning("createHomeDirectory: échec copie skel", ['login' => $login, 'output' => implode("\n", $output2)]);
                    }
                } else {
                    Log::warning("createHomeDirectory: skel absent, home créé vide", ['skel' => $skelPath, 'login' => $login]);
                }

                // Appliquer propriétaire et permissions comme mkhome.sh
                // Sur SE4FS, les UID AD ne sont pas résolubles (pas de winbind) → www-admin est le propriétaire effectif
                exec("sudo chown -R www-admin:www-admin " . escapeshellarg($homePath) . " 2>&1", $output3, $rc3);
                if ($rc3 !== 0) {
                    Log::warning("createHomeDirectory: échec chown", ['login' => $login, 'output' => implode("\n", $output3)]);
                }
                exec("sudo chmod -R 770 " . escapeshellarg($homePath) . " 2>&1", $output4, $rc4);
                if ($rc4 !== 0) {
                    Log::warning("createHomeDirectory: échec chmod", ['login' => $login, 'output' => implode("\n", $output4)]);
                }

                Log::info("Home directory créé pour $login", ['path' => $homePath]);
            } else {
                // Le home existe : vérifier et corriger le propriétaire si nécessaire
                $stat = stat($homePath);
                if ($stat !== false) {
                    $expectedUid = posix_getpwnam('www-admin');
                    if ($expectedUid !== false && $stat['uid'] !== $expectedUid['uid']) {
                        exec("sudo chown -R www-admin:www-admin " . escapeshellarg($homePath) . " 2>&1", $output5, $rc5);
                        Log::info("Home directory propriétaire corrigé pour $login", ['path' => $homePath]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Erreur lors de la création/vérification du dossier home pour $login", [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Archive le home directory : /home/{login} → /home/trash/{login}
     *
     * Verrou (Story 5.1d Q3, 2026-04-29) : `Cache::lock('trash:action:'.$login, 60)`
     * pour éviter la race avec `restoreHomeDirectory` et `trash:purge`. Si le
     * lock est indisponible, on retourne false + log warning.
     */
    public function archiveHomeDirectory(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("archiveHomeDirectory: login invalide", ['login' => $login]);
            return false;
        }

        $lock = Cache::lock('trash:action:' . $login, 60);
        if (!$lock->get()) {
            Log::warning('archiveHomeDirectory: dossier verrouillé par une opération en cours', ['login' => $login]);
            return false;
        }

        try {
            $homePath = "/home/" . $login;
            $trashPath = "/home/trash/" . $login;

            if (!is_dir($homePath)) {
                Log::warning("archiveHomeDirectory: home inexistant", ['path' => $homePath]);
                return false;
            }

            // Créer /home/trash/ si inexistant
            if (!is_dir('/home/trash')) {
                exec("sudo mkdir -p /home/trash 2>&1", $output, $rc);
                if ($rc !== 0) {
                    Log::error("archiveHomeDirectory: échec création /home/trash", ['output' => implode("\n", $output)]);
                    return false;
                }
            }

            exec("sudo mv " . escapeshellarg($homePath) . " " . escapeshellarg($trashPath) . " 2>&1", $output, $rc);
            if ($rc !== 0) {
                Log::error("archiveHomeDirectory: échec mv", ['login' => $login, 'output' => implode("\n", $output)]);
                return false;
            }

            Log::info("Home directory archivé", ['login' => $login, 'from' => $homePath, 'to' => $trashPath]);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Restaure le home directory : /home/trash/{login} → /home/{login}
     *
     * Verrou (Story 5.1d Q3, 2026-04-29) : `Cache::lock('trash:action:'.$login, 60)`
     * pour éviter la race avec `trash:purge` (cron 02h00) et `archiveHomeDirectory`.
     * Si le lock est indisponible (autre opération en cours), retourne false +
     * log warning.
     */
    public function restoreHomeDirectory(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("restoreHomeDirectory: login invalide", ['login' => $login]);
            return false;
        }

        $lock = Cache::lock('trash:action:' . $login, 60);
        if (!$lock->get()) {
            Log::warning('restoreHomeDirectory: dossier verrouillé par une opération en cours', ['login' => $login]);
            return false;
        }

        try {
            $trashPath = "/home/trash/" . $login;
            $homePath = "/home/" . $login;

            if (!is_dir($trashPath)) {
                Log::warning("restoreHomeDirectory: archive inexistante", ['path' => $trashPath]);
                return false;
            }

            exec("sudo mv " . escapeshellarg($trashPath) . " " . escapeshellarg($homePath) . " 2>&1", $output, $rc);
            if ($rc !== 0) {
                Log::error("restoreHomeDirectory: échec mv", ['login' => $login, 'output' => implode("\n", $output)]);
                return false;
            }

            Log::info("Home directory restauré", ['login' => $login, 'from' => $trashPath, 'to' => $homePath]);
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Supprime définitivement le home archivé : rm -rf /home/trash/{login}
     * UNIQUEMENT depuis /home/trash/ — ne jamais supprimer /home/{login} directement.
     */
    public function deleteHomeDirectoryPermanently(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            Log::error("deleteHomeDirectoryPermanently: login invalide", ['login' => $login]);
            return false;
        }

        $trashPath = "/home/trash/" . $login;

        if (!is_dir($trashPath)) {
            Log::info("deleteHomeDirectoryPermanently: rien à supprimer", ['path' => $trashPath]);
            return true;
        }

        exec("sudo rm -rf " . escapeshellarg($trashPath) . " 2>&1", $output, $rc);
        if ($rc !== 0) {
            Log::error("deleteHomeDirectoryPermanently: échec rm", ['login' => $login, 'output' => implode("\n", $output)]);
            return false;
        }

        Log::info("Home directory supprimé définitivement", ['login' => $login, 'path' => $trashPath]);
        return true;
    }

    /**
     * Vérifie si un home archivé existe dans /home/trash/
     */
    public function hasArchivedHome(string $login): bool
    {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $login)) {
            return false;
        }
        return is_dir("/home/trash/" . $login);
    }
}
