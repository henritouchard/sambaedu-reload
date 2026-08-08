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
 * `getent`, en LECTURE SEULE et SANS élévation. Cette dernière est l'addition de la
 * story 60.4 ; elle est exigée par la vérification d'existence d'un groupe avant
 * écriture, elle est déjà en service ailleurs dans le dépôt pour le même besoin, et
 * un test énumère les binaires effectivement émis pour que l'addition reste
 * visible.
 *
 * ---------------------------------------------------------------------------
 * **Story 62.4 — UNE SEULE ADDITION, `find`, ET ELLE EST DÉCLARÉE.**
 *
 * Les quatre verbes rendent exprimables deux gestes que la pose récursive
 * uniforme ne sait pas faire :
 *
 *  1. **Poser un niveau sur les DOSSIERS et un autre sur les FICHIERS.** Le
 *     mécanisme de listes d'accès n'a aucun sélecteur de type : `-R` applique la
 *     même chose à tout. Le seul moyen de distinguer est de SÉLECTIONNER les objets
 *     — c'est le rôle de `find`, et il n'y en a pas d'autre dans le jeu. La
 *     solution de repli aurait été de poser un niveau unique approximé, c'est-à-dire
 *     d'accorder en silence un verbe que la recette n'écrit pas ;
 *  2. **Poser la restriction de suppression sur les DOSSIERS seulement.** Le
 *     binaire qui change le mode est `chmod`, déjà dans le jeu ; c'est la
 *     SÉLECTION des dossiers qui demande `find`. L'appliquer récursivement sans
 *     sélection marquerait aussi les fichiers ordinaires — sans effet utile, mais
 *     visible dans tout listage, et donc source de doute à la première inspection.
 *
 * **Conséquence d'exploitation, écrite pour qu'elle ne surprenne personne.** Ces
 * deux gestes n'existent que pour des combinaisons de verbes qu'AUCUNE recette ne
 * porte aujourd'hui (la migration ne produit que « lire » seul et les quatre
 * verbes). Ils sont donc INATTEIGNABLES en l'état, et le resteront jusqu'à l'écran
 * de composition (story 62.6). D'ici là, `find` doit entrer dans la liste blanche
 * d'élévation des instances — sans quoi le premier octroi composé échouera, mais
 * BRUYAMMENT : le nœud rapportera l'échec avec sa cause, il ne posera pas un droit
 * approximatif. Le point est porté au runbook.
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
     * Lecture de l'état effectif. `-E` effectif, `-p` physique.
     *
     * La redirection d'erreur de l'ancien audit de dérive est ABANDONNÉE : elle
     * jetait la seule information qui aurait dit POURQUOI la relecture échoue, et
     * le contrat exige désormais qu'un échec de relecture nomme sa cause.
     *
     * **Story 62.4 — `-c` a DISPARU, et c'est la seule façon de voir la restriction
     * de suppression.** Cette option supprimait l'EN-TÊTE, et l'en-tête est le seul
     * endroit où l'outil dit les drapeaux du dossier. Sans elle, la restriction
     * était invisible à la relecture : elle se serait reposée à chaque passage
     * (idempotence rompue, « déjà conforme » impossible à atteindre) et une dérive
     * de restriction n'aurait jamais été vue. Aucune autre commande n'est ajoutée —
     * les lignes d'en-tête commencent par « # » et les analyseurs de format les
     * ignoraient déjà.
     */
    public function readAcl(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo getfacl -E -p %s', escapeshellarg($path)));
    }

    /**
     * Pose d'une entrée sur les DOSSIERS SEULEMENT (pose différenciée, story 62.4).
     *
     * Non récursive côté outil : c'est la sélection qui parcourt l'arbre. Les
     * miroirs d'héritage n'ont de sens que sur un dossier, et c'est bien ici
     * qu'ils sont posés.
     */
    public function applyAclToDirectories(string $path, string $acl): PosixCommandOutcome
    {
        return $this->run(sprintf(
            'sudo find %s -type d -exec setfacl -P -m %s {} +',
            escapeshellarg($path),
            escapeshellarg($acl),
        ));
    }

    /**
     * Pose d'une entrée sur les FICHIERS SEULEMENT (pose différenciée, story 62.4).
     *
     * Ne reçoit JAMAIS de miroir d'héritage : un fichier ne peut pas en porter, et
     * l'outil refuserait la commande entière.
     */
    public function applyAclToFiles(string $path, string $acl): PosixCommandOutcome
    {
        return $this->run(sprintf(
            'sudo find %s -type f -exec setfacl -P -m %s {} +',
            escapeshellarg($path),
            escapeshellarg($acl),
        ));
    }

    /**
     * Pose la restriction de suppression au propriétaire sur tous les DOSSIERS de
     * l'arbre (story 62.4) — le geste qui approche « déposer sans effacer ».
     */
    public function restrictDeletionToOwner(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo find %s -type d -exec chmod +t {} +', escapeshellarg($path)));
    }

    /** Retire cette même restriction — le plan ne la demande plus. */
    public function releaseDeletionRestriction(string $path): PosixCommandOutcome
    {
        return $this->run(sprintf('sudo find %s -type d -exec chmod -t {} +', escapeshellarg($path)));
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
