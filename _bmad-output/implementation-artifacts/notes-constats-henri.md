# Notes & Constats — Henri

Story informelle : observations, questions et points à vérifier au fil de l'eau.

## TODO

- [ ] Les matières sont exclues des groupes dans SER — vérifier si c'est un oubli ou intentionnel? on peut noter que les matières sont globales pour les etabs d'où l'exclusion. Elles sont sync avec le GPEI. La question: est-ce que ces matières servent vraiment à quelque chose ?

## Appels central → SE legacy (all-post-call-legacy)

- [ ] **Auditer les 15 appels POST du central vers les endpoints legacy** (`parcs/action_cron.php`, `annu/sync_cron.php`, `wpkg/*`, `dhcp/*`, `gpo/del_roam.php`, etc.).
  - Cartographie complète : [`all-post-call-legacy.md`](all-post-call-legacy.md)
  - Event cal : "all-post-call-story w1bis" — 2026-04-16 10:00
  - 3 patterns à trancher endpoint par endpoint :
    - **A** — bascule en cron local Laravel (parcs, quotas, stats, partages, clean_connexions)
    - **B** — migration vers ControlHub Task (wpkg_ldap_update + WPKG + DHCP)
    - **C** — shim legacy temporaire (cycle ENT/GPO central)
  - Priorité 1 : `wpkg/wpkg_ldap_update.php` — déjà prévu pour remplacement par `SyncWorkstationGroupsFromAd`.

## OAuth2 / Proxy — Question architecturale ouverte

- [ ] **Décision à prendre : qui gère le proxy OAuth2 ?**
  - Dans le legacy (`callback.php`), `verify => false` était posé car le serveur central agissait comme proxy pour les requêtes vers l'ENT — c'est lui qui faisait l'OAuth2 au nom du client.
  - Dans la migration Laravel, ce `verify => false` a été repris tel quel dans `buildOAuth2Provider()` dès qu'un proxy est configuré, sans que la question soit tranchée.
  - **Constat :** il y aura toujours un proxy dans l'architecture. Ce qui n'est pas encore clair : est-ce que le serveur central *doit rester* ce proxy OAuth2, ou est-ce que chaque instance Laravel doit aller directement vers l'ENT (avec un vrai certificat TLS) ?
  - Si le serveur central reste proxy : il faut configurer le CA bundle de ce proxy côté Laravel, pas désactiver TLS.
  - Si Laravel va directement vers l'ENT : supprimer le proxy de la config OAuth2 et la vérification associée.

