<?php

declare(strict_types=1);

/**
 * Racines canoniques des zones de fichiers SambaEdu gérées par SE5 (ACLs POSIX).
 *
 * NE PAS confondre avec `config/filesystems.php` (pluriel — disques Laravel
 * Storage). Ce fichier porte les racines métier de la couche d'exécution.
 *
 * ---------------------------------------------------------------------------
 * **DEUX racines sont déclarées ici, et elles sont DISJOINTES.**
 *
 *  1. `shares_root` — les répertoires réseau nommés (story 34.1). Exposée en SMB
 *     par le partage `[partages]` : ce qui vit dessous est VISIBLE des postes.
 *  2. `class_trees_root` — les arbres de classe matérialisés par la chaîne
 *     générique recette → plan → backend (story 60.5). Racine NEUVE, volontairement
 *     HORS de `shares_root` : l'y loger exposerait chaque arbre de classe dans la
 *     liste des partages vue par les utilisateurs. Aucune exposition SMB n'est
 *     livrée par la story 60.5 — l'arbre neuf se vérifie en lecture d'ACL côté
 *     serveur, le temps de la comparaison avec l'arbre historique. Une racine
 *     dédiée est aussi ce qui permettra d'y monter un disque séparé sans toucher
 *     une ligne de code.
 *
 * ---------------------------------------------------------------------------
 * **UNE racine n'est PAS déclarée ici, et c'est délibéré : `classes_root`.**
 *
 * C'est la racine de l'arbre de classe HISTORIQUE, celui que SE5 continue de
 * servir aux établissements et auquel la chaîne générique n'écrit JAMAIS.
 * {@see App\Services\Filesystem\AclService::classesRoot()} et
 * {@see App\Services\Filesystem\ShareService::classesRoot()} la résolvent via
 * `config('filesystem.classes_root', static::$classesRoot)` : tant que la clé est
 * ABSENTE, ils retombent sur leur propriété statique, surchargeable en tests.
 * Déclarer `classes_root` ici masquerait cet override et changerait le
 * comportement du chemin historique — un test épingle son absence.
 *
 * Convention iso `AclService::$classesRoot` : valeur statique par défaut,
 * surchargée ici (et donc par `.env`) pour le multi-tenant ou les tests
 * d'intégration.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Racine des RÉPERTOIRES RÉSEAU nommés (Story 34.1)
    |--------------------------------------------------------------------------
    | Racine dédiée des « lecteurs réseau gérés » : chaque `network_shares`
    | matérialise un sous-dossier `<directory_name>` ici. L'export SMB
    | `[partages]` → ce path est une tâche d'infra serveur (hors git, §[PROD]).
    */
    'shares_root' => env('SAMBAEDU_SHARES_ROOT', '/var/sambaedu/Partages'),

    /*
    |--------------------------------------------------------------------------
    | Racine des ARBRES DE CLASSE de la chaîne générique (Story 60.5)
    |--------------------------------------------------------------------------
    | Racine NEUVE, voisine de l'arbre historique `/var/sambaedu/Classes` et
    | strictement DISTINCTE de lui : les deux arbres coexistent, l'historique
    | reste le seul servi, le neuf existe pour être comparé. Jamais sous
    | `shares_root` (aucune exposition SMB) ; jamais l'arbre historique (SE5 n'y
    | écrit pas un octet).
    |
    | Prérequis d'infra (hors git, §[PROD]) : la liste blanche `sudo` du serveur
    | doit couvrir ce NOUVEAU chemin pour `mkdir`, la pose de droits étendus et
    | le changement de propriétaire — faute de quoi toute la matérialisation
    | décline avec un refus de permission uniforme (voir le runbook QA).
    */
    'class_trees_root' => env('SAMBAEDU_CLASS_TREES_ROOT', '/var/sambaedu/ClassesSE5'),

];
