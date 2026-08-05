<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\AdSync\UserGroupAdSyncJob;
use App\Models\UserGroup;
use App\Services\Filesystem\ClassTreeShareService;

/**
 * Observateur du groupe d'utilisateurs.
 *
 * Story 60.5 — s'y ajoute la MATÉRIALISATION DE L'ARBRE : créer un groupe dont le
 * type porte une recette d'arbre crée (idempotent) la ligne de partage
 * correspondante et enfile sa réconciliation. Voir
 * {@see ClassTreeShareService} pour les trois propriétés qui rendent ce
 * déclencheur sûr — il est SCOPÉ aux recettes d'arbre, il est FAIL-SOFT, et il a
 * son propre interrupteur.
 *
 * **Il n'est PAS gardé par `$syncEnabled`**, et c'est délibéré : ce drapeau
 * gouverne la projection vers l'annuaire, et il est coupé par des chemins — imports,
 * tests — qui n'ont aucune raison de suspendre les fichiers. Patron documenté
 * depuis la story 42.2 : chaque canal son flag.
 *
 * **La SUPPRESSION d'un groupe ne déprovisionne rien** (D9) : la ligne du partage
 * survit, orpheline et visible, et l'administrateur décide depuis l'écran des
 * partages. Aucune donnée ne disparaît sur un geste qui ne parlait que d'un groupe.
 */
class UserGroupObserver
{
    public static bool $syncEnabled = true;

    public static function disableSync(): void
    {
        self::$syncEnabled = false;
    }

    public static function enableSync(): void
    {
        self::$syncEnabled = true;
    }

    public function created(UserGroup $group): void
    {
        // Story 60.5 — ancré AVANT le garde de projection d'annuaire, sous son
        // propre interrupteur : les deux canaux ne se suspendent pas ensemble.
        app(ClassTreeShareService::class)->materializeQuietly($group);

        if (!self::$syncEnabled) {
            return;
        }

        dispatch(UserGroupAdSyncJob::create($group->id));
    }

    public function updated(UserGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        if ($group->wasChanged(['name', 'display_name', 'type'])) {
            $oldName = (string) $group->getOriginal('name');
            dispatch(UserGroupAdSyncJob::update($group->id, $oldName));
        }
    }

    public function deleting(UserGroup $group): void
    {
        if (!self::$syncEnabled) {
            return;
        }

        dispatch(UserGroupAdSyncJob::delete($group->name));
    }
}
