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
 * Les tuiles renvoyées sont des `<a>` STATIQUES (aucun `Http::`, aucun ping) :
 * zéro requête HTTP sortante par construction.
 *
 * ## Story 56.5 (FR35) — l'état de santé est LU, jamais MESURÉ
 *
 * La promesse « aucun état de santé » de 54.3 devient « état de santé LU dans la
 * MÊME requête, jamais mesuré ». La nuance est toute la conception :
 *
 *  - la MESURE appartient à `ext:health:check` (planifiée toutes les 5 min) et à
 *    {@see ExtensionHealthService}, son écrivain unique ;
 *  - ce service ne fait que LIRE `health_status`/`health_checked_at`, déjà
 *    chargées par le `->get()` existant. **Toujours 1 requête, toujours 0
 *    HTTP** — les tests NFR9 de 54.3 restent verts sans être affaiblis (ils
 *    gagnent une assertion, ils n'en perdent aucune).
 *
 * ⚠️ Le `->get()` sélectionne `*` : AUCUNE colonne n'est nommée dans la requête.
 * Ce n'est pas un détail de style, c'est la protection contre la fenêtre
 * `update.sh` (le code neuf est servi plusieurs minutes AVANT
 * `migrate --force`) : sans colonne nommée, la requête passe même quand
 * `health_status` n'existe pas encore, et l'accès PHP rend `null` ⇒ pas de
 * badge, pas de 500. Ne JAMAIS introduire de `->select([...])` ici. Le try/catch
 * du `mount()` reste le filet — pas le plan.
 *
 * ⚠️ Le badge « Indisponible » ne BLOQUE rien : la tuile reste un `<a>`
 * cliquable (FR14 — un affichage n'est pas une autorisation, et l'état peut
 * dater de 5 minutes).
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
 * ## Fail-closed : ce qui devient une tuile
 *
 * Une extension `available` n'apparaît JAMAIS. Parmi les `integrated` :
 *
 *  - une **`link`** apparaît (cycle 54.2) ;
 *  - une **`app`** apparaît si — et seulement si — elle porte un
 *    `installed_port`.
 *
 * Cette seconde règle est la levée MAÎTRISÉE du filtre `type = link` posé en
 * 54.3, dont le docblock annonçait « jusqu'à l'Epic 56 » : le moteur existe
 * désormais ({@see ExtensionInstallService}, Story 56.2), et une `app`
 * réellement installée DOIT avoir sa tuile — c'est l'objet même de FR8.
 *
 * Le port n'est pas un détail de confort : il n'est écrit que par
 * {@see ExtensionLifecycleService::markAppInstalled()}, en toute dernière étape
 * d'une installation dont l'avant-dernière a posé le `ProxyPass /ext/<key>`.
 * Le tester, c'est exiger que l'exposition ait été RÉELLEMENT provisionnée
 * avant d'afficher un lien vers elle — une `app` marquée `integrated` sans
 * port (ligne fabriquée à la main, fixture de test) n'a aucun backend derrière
 * `/ext/<key>` : sa tuile mènerait à un 404. On préfère l'absence de tuile à
 * une tuile morte.
 *
 * `entry_url` d'une `app` vaut EXACTEMENT `/ext/<key>` (règle AR3 du
 * validateur) : la tuile pointe donc, par construction, le chemin que
 * l'installation a provisionné.
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
     * Les tuiles du lanceur pour `$user` : extensions intégrées (`link`, ou
     * `app` réellement installée) dont `visibility.roles` intersecte les rôles
     * métier de l'utilisateur ({@see \App\Models\User::businessRoles()}).
     *
     * Toujours UNE SEULE requête (NFR9) : la condition de type reste dans le
     * `WHERE`, elle n'a pas migré vers un filtre PHP.
     *
     * Story 56.5 — chaque tuile porte `unavailable` : l'état de santé PERSISTÉ,
     * LU (jamais mesuré) via la règle unique
     * {@see \App\Models\Extension::isFlaggedUnreachable()}. Un état périmé ou
     * jamais sondé ⇒ `false` : on ne signale que ce qu'on SAIT.
     *
     * @return list<array{key: string, name: string, icon: string, entry_url: string, unavailable: bool}>
     */
    public function tilesFor(User $user): array
    {
        $businessRoles = $user->businessRoles();

        return Extension::query()
            ->where('status', ExtensionStatus::Integrated)
            ->where(function ($query): void {
                $query
                    ->where('type', ExtensionType::Link)
                    // Story 56.2 — une `app` n'est une tuile que si son
                    // exposition `/ext/<key>` a réellement été provisionnée.
                    ->orWhere(fn ($sub) => $sub
                        ->where('type', ExtensionType::App)
                        ->whereNotNull('installed_port'));
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Extension $extension): bool => array_intersect($extension->visibilityRoles(), $businessRoles) !== [])
            ->map(fn (Extension $extension): array => [
                'key' => (string) $extension->key,
                'name' => (string) $extension->name,
                'icon' => (string) $extension->icon,
                'entry_url' => $extension->entryUrl(),
                'unavailable' => $extension->isFlaggedUnreachable(),
            ])
            ->values()
            ->all();
    }
}
