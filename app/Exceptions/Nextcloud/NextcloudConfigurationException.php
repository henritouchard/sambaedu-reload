<?php

declare(strict_types=1);

namespace App\Exceptions\Nextcloud;

use App\Enums\NextcloudInstanceMode;
use RuntimeException;

/**
 * Story 61.1 — LE REFUS QUI NOMME CE QUI MANQUE.
 *
 * La configuration de connexion à l'instance Nextcloud est **fail-closed** : une
 * URL vide, un identifiant admin absent ou un secret jamais saisi n'autorisent
 * pas un provisionnement « partiel qui fera ce qu'il peut ». Ils l'interdisent,
 * et ils disent lequel des trois manque.
 *
 * Pourquoi une exception et pas un `false` : un provisionnement à moitié fait est
 * pire qu'un provisionnement refusé. Le montage « Partages » créé sans le montage
 * « Documents », ou les utilisateurs adoptés sans montage, laissent une instance
 * dans un état que personne n'a décrit et que le rejeu ne réparera pas forcément.
 * On s'arrête AVANT la première écriture.
 *
 * **Le secret n'entre jamais dans ce message.** Ce qui manque se nomme par sa
 * clé (« l'app password admin »), jamais par sa valeur — ni la bonne, ni celle
 * qu'on a lue. Un test l'épingle.
 */
final class NextcloudConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $missing  Libellés des réglages manquants, dans l'ordre de l'écran.
     * @param  ?NextcloudInstanceMode  $declaredMode  Mode déclaré, **renseigné uniquement quand
     *                                                c'est LUI qui refuse** ({@see wrongMode()}).
     *                                                Correction de revue 61.2 #5 : l'appelant qui
     *                                                veut tracer « sauté parce que délégué » le lit
     *                                                ici plutôt que de relire `files.policy` — un
     *                                                `SELECT` de plus par compte sauté, sans cache,
     *                                                pour produire une ligne de `debug`.
     */
    private function __construct(
        string $message,
        public readonly array $missing,
        public readonly ?NextcloudInstanceMode $declaredMode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $missing
     */
    public static function incomplete(array $missing): self
    {
        return new self(
            'Configuration Nextcloud incomplète — rien n\'a été tenté. Manque : '
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
                'URL Nextcloud invalide (« %s ») : le schéma est requis (http:// ou https://).',
                $url,
            ),
            ['nextcloud_server_url'],
        );
    }

    /**
     * La capacité « Accès Nextcloud » est éteinte : ce n'est pas une erreur de
     * saisie, c'est un refus de périmètre — et il se distingue du précédent, sinon
     * l'exploitant cherche une clé manquante qui n'existe pas.
     */
    public static function capabilityDisabled(): self
    {
        return new self(
            'La capacité « Accès Nextcloud » est désactivée sur /admin/settings/files : '
            . 'aucun appel n\'est émis tant qu\'elle ne l\'est pas.',
            ['nextcloud'],
        );
    }

    /**
     * Story 61.2 — LE MODE DÉCLARÉ NE PORTE PAS L'OPÉRATION DEMANDÉE.
     *
     * C'est le refus qui coupe la machinerie d'administration de 61.1 quand
     * l'instance est déclarée en compte porteur — **avant tout appel HTTP**, ce qui
     * est la seule façon d'être certain qu'aucun montage global ni aucune gestion
     * de compte n'est tentée avec un compte qui ne les a pas.
     *
     * Il NOMME le mode plutôt que de dire « refusé » : l'exploitant qui lit ce
     * message doit comprendre qu'il n'a rien cassé — il a déclaré une position, et
     * cette position exclut cette opération.
     */
    public static function wrongMode(NextcloudInstanceMode $current, NextcloudInstanceMode $required): self
    {
        if ($required === NextcloudInstanceMode::Admin) {
            return new self(
                'Le mode d\'administration déclaré est « ' . $current->label() . ' » : les montages de '
                . 'stockage externe et la gestion des comptes sont des opérations d\'administration, et le '
                . 'mode délégué ne les porte pas. Aucun appel n\'a été émis. Les montages déjà provisionnés '
                . 'restent en place — SE5 cesse simplement de les gouverner.',
                ['nextcloud_mode'],
                $current,
            );
        }

        return new self(
            'Le mode d\'administration déclaré est « ' . $current->label() . ' » : cette opération '
            . 'appartient au mode « ' . $required->label() . ' ». Aucun appel n\'a été émis.',
            ['nextcloud_mode'],
            $current,
        );
    }
}
