# Bonne pratique pour la prod et docker (en utilisateur non root)

En revérifiant, Sonnet t'a induit en erreur en te donnant raison trop vite. Voici la réalité :

## Correction importante

Le warning de Composer vient de **l'intérieur du container**. Il détecte que le processus courant (dans le container) est root. **Ça n'a rien à voir avec le fait que tu sois root sur l'hôte.** Même si tu étais un utilisateur non-root sur l'hôte (ajouté au groupe `docker`), le container tournerait en root par défaut, et Composer afficherait le même message.

Docker fonctionne ainsi : le daemon tourne toujours en root, et les containers lancent leurs processus en root par défaut, quel que soit l'utilisateur qui tape `docker compose up`.

## Pour répondre à ta vraie question : est-ce un souci en prod ?

Oui, il y a **deux problèmes séparés** :

1. **Être root sur l'hôte** (ton SSH) — c'est une mauvaise pratique en prod. Idéalement tu crées un utilisateur dédié, tu lui donnes `sudo` et l'accès au groupe `docker`.

2. **Être root dans le container** — c'est le warning de Composer. Le risque concret : si un paquet malveillant exécute un script post-install, il tourne en root dans le container. En cas de faille d'évasion, c'est root sur l'hôte aussi.

## Ce que tu devrais faire en prod

- Sur l'hôte : créer un utilisateur dédié avec accès Docker
- Dans le Dockerfile : ajouter un `USER` non-root pour l'exécution de Composer et de l'application
- En attendant : `COMPOSER_ALLOW_SUPERUSER=1` fonctionne, mais c'est un compromis

Le premier point (Sonnet avait raison initialement là-dessus) est indépendant du message Composer, mais c'est effectivement une bonne pratique à mettre en place.
