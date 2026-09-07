<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\User;

/**
 * Service du LANCEUR : les tuiles
 * qu'un utilisateur donné a le droit de voir.
 *
 * **Service séparé** d'{@see ExtensionCatalogService} (lecture du catalogue
 * admin) et d'{@see \App\Services\Extensions\ExtensionLifecycleService}
 * (transitions `status`) : le catalogue garde ses invariants intacts, et ce
 * service n'écrit strictement rien.
 *
 * ## Une seule requête SQL, zéro HTTP, aucun cache
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
 * ## L'état de santé est LU, jamais MESURÉ
 *
 * La promesse « aucun état de santé » devient « état de santé LU dans la
 * MÊME requête, jamais mesuré ». La nuance est toute la conception :
 *
 *  - la MESURE appartient à `ext:health:check` (planifiée toutes les 5 min) et à
 *    {@see ExtensionHealthService}, son écrivain unique ;
 *  - ce service ne fait que LIRE `health_status`/`health_checked_at`, déjà
 *    chargées par le `->get()` existant. **Toujours 1 requête, toujours 0 HTTP.**
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
 * cliquable : un affichage n'est pas une autorisation, et l'état peut dater de
 * 5 minutes.
 *
 * ## Un filtre d'AFFICHAGE, jamais une autorisation
 *
 * Ce service décide UNIQUEMENT si une tuile est **visible**. Il n'ajoute, ne
 * consulte et ne suppose AUCUNE route, middleware ou garde devant
 * `entry_url()` : une extension masquée reste atteignable par son URL directe
 * si celle-ci est publique — c'est le comportement voulu, l'autorisation
 * réelle appartient à la cible (les extensions `app` la font par claims SSO).
 *
 * ## Fail-closed : ce qui devient une tuile
 *
 * Une extension `available` n'apparaît JAMAIS. Parmi les `integrated` :
 *
 *  - une **`link`** apparaît ;
 *  - une **`app`** apparaît si — et seulement si — elle porte un
 *    `installed_port`.
 *
 * Cette seconde règle est la levée MAÎTRISÉE du filtre `type = link` posé
 * autrefois, faute de moteur d'installation : il existe désormais
 * ({@see ExtensionInstallService}), et une `app` réellement installée DOIT
 * avoir sa tuile.
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
 * `entry_url` d'une `app` vaut EXACTEMENT `/ext/<key>`, règle imposée par le
 * validateur de manifest : la tuile pointe donc, par construction, le chemin
 * que l'installation a provisionné.
 *
 * ## L'état de la SOURCE ne retire jamais une tuile
 *
 * Le filtre `extension_sources.enabled` n'est PAS appliqué ici :
 *
 *  - `tilesFor()` ne montre QUE des `integrated` : « les extensions non
 *    installées n'apparaissent pas au lanceur » est donc vrai PAR
 *    CONSTRUCTION, quel que soit l'état de leur source. C'est
 *    `library()`/`find()` d'{@see ExtensionCatalogService} qui filtrent
 *    réellement, parce que ce sont eux qui PROPOSENT.
 *  - une extension **déjà intégrée** dont la source est désactivée, en erreur
 *    de signature ou en cours de retrait **GARDE sa tuile**. Deux raisons
 *    convergentes : la doctrine projet « rupture = figer l'état » (un lien
 *    coupé n'annule pas ce qui était en service), et la règle qui interdit de
 *    dé-intégrer silencieusement. Faire disparaître une tuile parce qu'un dépôt
 *    distant est tombé transformerait un incident de catalogue en panne visible
 *    pour les enseignants et les élèves.
 *
 * L'admin, lui, VOIT l'état : la bibliothèque signale « source désactivée » /
 * « catalogue refusé » sur la carte, et c'est lui qui décide de désinstaller.
 * Un test de régression verrouille cette décision : une intégrée d'une source
 * désactivée conserve sa tuile, et une `available` n'en a jamais.
 */
class ExtensionLauncherService
{
    /**
     * Les tuiles du lanceur pour `$user` : extensions intégrées (`link`, ou
     * `app` réellement installée) dont `visibility.roles` intersecte les rôles
     * métier de l'utilisateur ({@see \App\Models\User::businessRoles()}).
     *
     * Toujours UNE SEULE requête : la condition de type reste dans le
     * `WHERE`, elle n'a pas migré vers un filtre PHP.
     *
     * Chaque tuile porte `unavailable` : l'état de santé PERSISTÉ,
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
                    // Une `app` n'est une tuile que si son
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
                'entry_url' => $this->absoluteEntryUrl($extension->entryUrl()),
                'unavailable' => $extension->isFlaggedUnreachable(),
            ])
            ->values()
            ->all();
    }

    /**
     * Résout un `entry_url` de manifest en URL réellement cliquable.
     *
     * ⚠️ Un chemin absolu de manifest (`/sso-demo`) est absolu pour L'INSTANCE,
     * pas pour l'origine HTTP. Injecté brut dans un `href`, le navigateur le
     * résout contre la racine du host et PERD le préfixe de chemin des
     * instances servies sous un sous-chemin par reverse proxy (`APP_URL` du
     * type `https://lab1.sambaedu.org/0991229y`) : le clic partait sur
     * `https://lab1.sambaedu.org/sso-demo` → 404. `url()` applique le
     * `URL::forceRootUrl(config('app.url'))` posé par `AppServiceProvider`,
     * qui est précisément ce qui porte ce préfixe.
     *
     * Les URL absolues `http(s)://` (extension hébergée ailleurs) traversent
     * INCHANGÉES : les préfixer serait les casser. Le schéma a déjà été borné
     * à `/…` ou `http(s)://` par {@see ExtensionManifestValidator} (review
     * anti `javascript:`/`data:`), donc ces deux cas sont exhaustifs.
     */
    private function absoluteEntryUrl(string $entryUrl): string
    {
        if ($entryUrl === '' || preg_match('#^https?://#i', $entryUrl) === 1) {
            return $entryUrl;
        }

        return url($entryUrl);
    }
}
