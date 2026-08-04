<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use Illuminate\Support\Facades\Process;

/**
 * Story 60.4 — les GESTES SYSTÈME, descendus tels quels.
 *
 * Ce sont les helpers privés du provisionnement 34.1, déplacés sans réécriture :
 * même commandes, mêmes options, même ordre. Les rassembler dans une classe
 * dédiée n'ajoute aucune capacité — c'est ce qui rend le jeu de commandes émis
 * ÉNUMÉRABLE par un test, et donc la promesse « aucune commande nouvelle »
 * vérifiable au lieu d'être affirmée.
 *
 * **Le jeu est fermé** : `mkdir`, `setfacl`, `getfacl`, `chown`, `chgrp`, `chmod`,
 * `mv` — tous déjà couverts par la liste blanche d'élévation de privilège — plus
 * `getent`, en LECTURE SEULE et SANS élévation. Cette dernière est la seule
 * addition de la story ; elle est exigée par la vérification d'existence d'un
 * groupe avant écriture, elle est déjà en service ailleurs dans le dépôt pour le
 * même besoin, et un test énumère les binaires effectivement émis pour que
 * l'addition reste visible.
 *
 * **Triple garde conservée** : chaque chemin passe par {@see PosixPathGuard} chez
 * l'appelant, chaque argument par l'échappement d'argument ici, chaque commande
 * par la liste blanche côté système. Aucun chemin n'est construit par
 * concaténation non validée.
 */
final class PosixExecutor
{
    /** Le nom d'exécution du serveur applicatif, propriétaire des répertoires gérés. */
    public const OWNER = 'www-admin';

    /** Le groupe d'administration, propriétaire de groupe des répertoires gérés. */
    public const OWNING_GROUP = 'domain admins';

    public function directoryExists(string $path): bool
    {
        return is_dir($path);
    }

    /** `mkdir -p` — idempotent, crée aussi la racine gérée si besoin. */
    public function makeDirectory(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo mkdir -p %s', escapeshellarg($path)));
    }

    /** Poubelle d'archivage : créée en 0700, non listable par les autres. */
    public function makeTrashRoot(string $path): PosixCommandOutcome
    {
        $made = $this->run(sprintf('sudo mkdir -p -m 0700 %s', escapeshellarg($path)));
        if (! $made->ok) {
            return $made;
        }

        return $this->changeOwner($path);
    }

    /**
     * Lecture de l'état effectif. `-c` sans en-tête, `-E` effectif, `-p` physique.
     *
     * La redirection d'erreur de l'ancien audit de dérive est ABANDONNÉE : elle
     * jetait la seule information qui aurait dit POURQUOI la relecture échoue, et
     * le contrat exige désormais qu'un échec de relecture nomme sa cause.
     */
    public function readAcl(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo getfacl -c -E -p %s', escapeshellarg($path)));
    }

    /** Purge des entrées étendues. `-P` (physique) contre la traversée de lien symbolique. */
    public function wipeAcls(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo setfacl -R -P -b %s', escapeshellarg($path)));
    }

    /** Pose d'une entrée, récursivement. */
    public function applyAcl(string $path, string $acl): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo setfacl -R -P -m %s %s', escapeshellarg($acl), escapeshellarg($path)));
    }

    /**
     * Les deux propriétaires sont des CONSTANTES du dépôt, pas des entrées : elles
     * sont écrites telles quelles, exactement comme la séquence historique (le nom
     * d'exécution sans guillemets, le groupe d'annuaire avec, puisqu'il contient
     * une espace). Les échapper aurait changé la chaîne émise sans rien sécuriser.
     */
    public function changeOwner(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo chown %s %s', self::OWNER, escapeshellarg($path)));
    }

    public function changeGroup(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf("sudo chgrp '%s' %s", self::OWNING_GROUP, escapeshellarg($path)));
    }

    /**
     * Retire l'accès résiduel laissé aux « autres » par le mode de base après la
     * purge des entrées étendues.
     */
    public function restrictMode(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo chmod -R 0770 %s', escapeshellarg($path)));
    }

    /** Déplacement — JAMAIS de suppression. La contrainte d'epic tient ici. */
    public function move(string $from, string $to): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo mv %s %s', escapeshellarg($from), escapeshellarg($to)));
    }

    private function run(string $command): PosixCommandOutcome
    {
        $result = Process::run($command);

        return new PosixCommandOutcome(
            $result->successful(),
            $result->output(),
            trim($result->errorOutput() ?: $result->output()),
        );
    }
}
