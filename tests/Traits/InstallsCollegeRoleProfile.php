<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Story 62.3 — INSTALLER le vocabulaire scolaire, au lieu de le recevoir.
 *
 * Les libellés « Élève », « Enseignant », « Professeur principal », « Porteur » et
 * « Référent » étaient posés par la migration `create_group_type_roles_table` : toute
 * suite tournant sous `RefreshDatabase` les recevait SANS LES DEMANDER, et les
 * épinglait comme s'ils étaient un défaut de la base.
 *
 * Ils n'en sont plus un. Déclarer des rôles sur un type le FERME
 * ({@see \App\Support\RoleCatalog::assignableKeys()} ne rend plus que les rôles
 * déclarés), et « Élève » n'a de sens que dans le vertical scolaire : le profil
 * s'installe désormais par un geste d'administration explicite,
 * `php artisan college:seed:role-x-type`.
 *
 * Les suites qui ÉPROUVENT ce vocabulaire doivent donc l'installer elles-mêmes.
 * Ce n'est pas une commodité de test : c'est la même bascule, dite du côté des
 * tests. Une suite qui, demain, tomberait faute de ces libellés se corrige en
 * appelant ce helper — jamais en desserrant son assertion, qui mesurerait alors le
 * régime de repli au lieu du profil qu'elle prétend vérifier.
 */
trait InstallsCollegeRoleProfile
{
    /**
     * Pose les sept déclarations du profil scolaire, comme un administrateur le
     * ferait en ligne de commande.
     */
    protected function installCollegeRoleProfile(): void
    {
        $this->artisan('college:seed:role-x-type')->assertSuccessful()->run();
    }
}
