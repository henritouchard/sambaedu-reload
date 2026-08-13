<?php

declare(strict_types=1);

namespace App\Exceptions\OpenCloud;

use RuntimeException;

/**
 * LE REFUS QUI NOMME CE QUI MANQUE, avant le premier appel.
 *
 * La configuration de connexion à l'instance OpenCloud est **fail-closed** : une
 * URL vide, un identifiant d'administration absent ou un secret jamais saisi
 * n'autorisent pas une réconciliation « partielle qui fera ce qu'elle peut ».
 * Ils l'interdisent, et ils disent lequel des trois manque.
 *
 * Pourquoi une exception et pas un `false` : une zone à moitié provisionnée est
 * pire qu'une zone refusée. Un espace créé sans ses octrois, ou une arborescence
 * posée sans son cloisonnement, laisse une instance dans un état que personne n'a
 * décrit — et sous drift STRICT, un état indescriptible ne se réconcilie pas.
 * On s'arrête AVANT la première écriture.
 *
 * **Le secret n'entre jamais dans ce message.** Ce qui manque se nomme par sa
 * clé (« le mot de passe du compte d'administration »), jamais par sa valeur —
 * ni la bonne, ni celle qu'on a lue. Un test l'épingle.
 */
final class OpenCloudConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $missing  libellés des réglages manquants, dans l'ordre de l'écran
     */
    private function __construct(
        string $message,
        public readonly array $missing,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(
            'Configuration OpenCloud incomplète — rien n\'a été tenté. Manque : '
            . implode(', ', $missing) . '.',
            array_values($missing),
        );
    }

    /**
     * L'URL est là mais elle n'est pas une adresse : sans schéma, aucun appel
     * n'est possible et le message d'erreur du client HTTP serait illisible.
     */
    public static function malformedUrl(string $url): self
    {
        return new self(
            sprintf(
                'URL OpenCloud invalide (« %s ») : le schéma est requis (http:// ou https://).',
                $url,
            ),
            ['opencloud_server_url'],
        );
    }

    /**
     * La capacité « Accès OpenCloud » est éteinte : ce n'est pas une erreur de
     * saisie, c'est un refus de périmètre — et il se distingue du précédent, sinon
     * l'exploitant cherche une clé manquante qui n'existe pas.
     */
    public static function capabilityDisabled(): self
    {
        return new self(
            'La capacité « Accès OpenCloud » est désactivée sur /admin/settings/files : '
            . 'aucun appel n\'est émis tant qu\'elle ne l\'est pas.',
            ['opencloud'],
        );
    }
}
