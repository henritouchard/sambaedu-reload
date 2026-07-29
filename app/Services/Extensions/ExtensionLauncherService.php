<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\User;

/**
 * Story 54.3 (FR13/FR14, NFR9, NFR15) — Service du LANCEUR : les tuiles
 * qu'un utilisateur donné a le droit de voir.
 *
 * **Service séparé** d'{@see ExtensionCatalogService} (lecture du catalogue
 * admin) et d'{@see \App\Services\Extensions\ExtensionLifecycleService}
 * (transitions `status`) — même logique de séparation que 54.2 : le
 * catalogue reviewé reste à ZÉRO diff, ses invariants #1-#5 restent intacts,
 * et ce service n'écrit strictement rien.
 *
 * ## NFR9 — une seule requête SQL, zéro HTTP, aucun cache
 *
 * `tilesFor()` exécute **UNE SEULE** requête sur `extensions`
 * (`status = integrated AND type = link`, indexable, table minuscule). Aucun
 * `with('source')` : la tuile n'affiche pas la provenance (contrairement à
 * `ExtensionCatalogService::library()`), donc aucun besoin de la relation.
 *
 * Pas de cache applicatif : un cache poserait une invalidation (⚠️ ce projet
 * n'a PAS de `Cache::lock()` sur APCu — fiche mémoire) pour un gain non
 * mesurable sur une requête déjà négligeable, et un chargement différé à
 * l'ouverture du dropdown ajouterait un aller-retour Livewire + spinner pour
 * économiser une requête gratuite. « Pas de sur-conçu » : la solution simple
 * est la bonne, verrouillée par un test de comptage de requêtes.
 *
 * Les tuiles renvoyées sont des `<a>` STATIQUES (aucun `Http::`, aucun ping,
 * aucun état de santé — FR35 = Story 56.5) : zéro requête HTTP sortante par
 * construction.
 *
 * ## FR14 — un filtre d'AFFICHAGE, jamais une autorisation
 *
 * Ce service décide UNIQUEMENT si une tuile est **visible**. Il n'ajoute, ne
 * consulte et ne suppose AUCUNE route, middleware ou garde devant
 * `entry_url()` : une extension masquée reste atteignable par son URL directe
 * si celle-ci est publique — c'est le comportement voulu, l'autorisation
 * réelle appartient à la cible (les extensions `app` la feront par claims
 * SSO, Epics 55+).
 *
 * ## Fail-closed jusqu'à l'Epic 56
 *
 * Seules les extensions `status = integrated` ET `type = link` peuvent
 * devenir des tuiles — une `available` n'apparaît jamais, une `app` (même
 * artificiellement `integrated`, ex. en factory de test) n'apparaît jamais
 * non plus : aucun moteur `app` n'existe avant l'Epic 56 (AR1).
 *
 * ## L'état de la SOURCE ne retire jamais une tuile (décision Story 56.1)
 *
 * La Story 54.3 avait reporté à la 56.1 la question du filtre
 * `extension_sources.enabled`. Elle est tranchée, et la réponse est **aucun
 * diff fonctionnel ici** :
 *
 *  - `tilesFor()` ne montre QUE des `integrated` : « les extensions non
 *    installées n'apparaissent pas au lanceur » est donc vrai PAR
 *    CONSTRUCTION, quel que soit l'état de leur source. C'est
 *    `library()`/`find()` d'{@see ExtensionCatalogService} qui filtrent
 *    réellement, parce que ce sont eux qui PROPOSENT.
 *  - une extension **déjà intégrée** dont la source est désactivée, en erreur
 *    de signature ou en cours de retrait **GARDE sa tuile**. Deux raisons
 *    convergentes : la doctrine projet « rupture = figer l'état » (un lien
 *    coupé n'annule pas ce qui était en service), et l'invariant 54.1 #4 qui
 *    interdit de dé-intégrer silencieusement. Faire disparaître une tuile
 *    parce qu'un dépôt distant est tombé transformerait un incident de
 *    catalogue en panne visible pour les enseignants et les élèves — l'exact
 *    contraire de NFR7.
 *
 * L'admin, lui, VOIT l'état : la bibliothèque signale « source désactivée » /
 * « catalogue refusé » sur la carte, et c'est lui qui décide de désinstaller.
 * Un test de régression verrouille cette décision (une intégrée d'une source
 * désactivée conserve sa tuile) ; l'absence de tuile pour une `available`
 * reste couverte par les tests 54.3.
 */
class ExtensionLauncherService
{
    /**
     * Les tuiles du lanceur pour `$user` : extensions intégrées de type
     * `link` dont `visibility.roles` intersecte les rôles métier de
     * l'utilisateur ({@see \App\Models\User::businessRoles()}).
     *
     * @return list<array{key: string, name: string, icon: string, entry_url: string}>
     */
    public function tilesFor(User $user): array
    {
        $businessRoles = $user->businessRoles();

        return Extension::query()
            ->where('status', ExtensionStatus::Integrated)
            ->where('type', ExtensionType::Link)
            ->orderBy('name')
            ->get()
            ->filter(fn (Extension $extension): bool => array_intersect($extension->visibilityRoles(), $businessRoles) !== [])
            ->map(fn (Extension $extension): array => [
                'key' => (string) $extension->key,
                'name' => (string) $extension->name,
                'icon' => (string) $extension->icon,
                'entry_url' => $extension->entryUrl(),
            ])
            ->values()
            ->all();
    }
}
