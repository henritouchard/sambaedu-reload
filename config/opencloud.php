<?php

declare(strict_types=1);

/**
 * Déploiement de l'instance OpenCloud.
 *
 * **Ce fichier ne configure QUE le déploiement**, jamais l'autorité d'écriture.
 * La connexion (URL, compte, vérification TLS) est un RÉGLAGE d'exploitation, il
 * vit dans `files.policy` ; le secret vit chiffré dans `service_credentials`. Un
 * fichier de configuration versionné ne porte ni l'un ni l'autre.
 *
 * L'IMAGE du conteneur n'est pas ici non plus : elle est épinglée dans le helper
 * privilégié, côté root. La rendre configurable depuis l'application ferait
 * choisir à www-admin quel conteneur root exécute — c'est-à-dire lui donnerait la
 * machine.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Chemin du helper privilégié
    |--------------------------------------------------------------------------
    |
    | Le seul binaire que `sudo -n` est autorisé à lancer pour ce chantier. Il
    | est posé par `ensure_opencloud_engine` (scripts/install.sh) et autorisé par
    | /etc/sudoers.d/sambaedu-opencloud, validé par `visudo -cf` avant pose.
    |
    | Surchargeable en test pour éprouver le transport sans privilège.
    */
    'helper_path' => env(
        'OPENCLOUD_HELPER_PATH',
        \App\Services\OpenCloud\Deployment\SudoOpenCloudHelperRunner::DEFAULT_HELPER_PATH,
    ),

    /*
    |--------------------------------------------------------------------------
    | Port d'écoute LOCAL de l'instance
    |--------------------------------------------------------------------------
    |
    | L'instance écoute en HTTP sur la boucle locale ; la terminaison TLS est
    | assurée par le frontal existant de SE5 (scénario « derrière un proxy
    | externe »). 9200 est le port amont du produit ; il est configurable parce
    | qu'une machine peut déjà l'utiliser — et le helper REFUSE, en le nommant,
    | un port tenu par un tiers.
    */
    'port' => (int) env('OPENCLOUD_PORT', 9200),
];
