<?php

declare(strict_types=1);

/**
 * LA CLÉ IMMUABLE D'IDENTITÉ D'ANNUAIRE.
 *
 * **Le problème qu'elle résout.** Un plan de fichiers cloud (Nextcloud, OpenCloud)
 * stocke ses octrois et la propriété des espaces sous l'IDENTIFIANT du compte tel
 * que le produit le calcule depuis l'annuaire. Si cet identifiant est le login, un
 * renommage — qui arrive — orpheline SILENCIEUSEMENT tous les octrois nominatifs et
 * l'espace personnel. Il n'y a ni erreur, ni trace : les droits pointent dans le vide.
 *
 * **Pourquoi pas `objectGUID` directement.** Mesuré le 2026-08-14 sur OpenCloud 7.2.3
 * (`_bmad-output/implementation-artifacts/opencloud-ad-ldap-mesures.md`) : le produit
 * rend l'octetstring en GROS-BOUTISTE BRUT à la lecture, puis le ré-encode en
 * BOUTISME MIXTE Microsoft pour la recherche inverse. Les deux conversions ne sont pas
 * réciproques ⇒ le mot de passe est validé puis le compte devient introuvable par son
 * propre identifiant ⇒ 401, aucune authentification possible.
 *
 * **La parade.** SE5 écrit lui-même le GUID **rendu en texte** dans un attribut
 * d'annuaire ordinaire. C'est une chaîne : plus d'octetstring, donc plus de défaut de
 * boutisme. Et c'est le GUID : donc immuable, un renommage n'a plus de prise.
 *
 * La forme retenue est la forme **Microsoft canonique** — celle que produit
 * `uuid(bytes_le=…)`, et donc **exactement la chaîne que Nextcloud utilise déjà**
 * comme uid par défaut. Une seule clé pour les deux produits.
 *
 * L'annuaire reste la SOURCE de la valeur (c'est son `objectGUID`) ; SE5 n'invente
 * rien, il le recopie sous une forme exploitable.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | L'attribut d'annuaire qui PORTE la clé
    |--------------------------------------------------------------------------
    |
    | `employeeType` : mono-valué, 256 caractères, et libre — vérifié sur l'AD réel
    | comme dans le code SE4 et SE5 (aucune occurrence).
    |
    | ⚠️ NE PAS reprendre les attributs suivants, tous déjà porteurs :
    |   - `employeeNumber` → identifiants Siècle / GPEI / ASM / Pronote
    |     (`UserService::buildEmployeeNumber`) ;
    |   - `title` → id ENT + externalId (`UserService::buildTitle`) ;
    |   - `physicalDeliveryOfficeName` → date de naissance encodée ;
    |   - `employeeID` → l'AD le PLAFONNE À 16 CARACTÈRES, un GUID en fait 36 :
    |     l'écriture est refusée par `Invalid syntax (21)`.
    |
    | Changer cet attribut sur une instance en service INVALIDE toutes les identités
    | déjà calculées côté cloud : c'est une re-pose complète, pas un réglage.
    */
    'attribute' => strtolower((string) env('AD_IMMUTABLE_KEY_ATTRIBUTE', 'employeetype')),

    /*
    |--------------------------------------------------------------------------
    | Pose automatique à la création d'un compte
    |--------------------------------------------------------------------------
    |
    | La pose est FAIL-SOFT : un échec est journalisé et n'interrompt jamais la
    | création de l'utilisateur. La commande `ad:immutable-key` rattrape ensuite.
    */
    'set_on_create' => (bool) env('AD_IMMUTABLE_KEY_ON_CREATE', true),
];
