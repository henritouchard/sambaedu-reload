<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Story 56.1 — Refus explicite d'un acte d'administration de SOURCE
 * ({@see \App\Services\Extensions\ExtensionSourceService}).
 *
 * Toujours attrapée par le SFC appelant → `toastError`, jamais une 500. Le
 * message est destiné à l'ADMIN : il dit ce qui a été refusé et ce qu'il faut
 * faire — jamais un détail d'implémentation, jamais l'URL d'un dépôt (une URL
 * peut porter un jeton).
 */
final class ExtensionSourceException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /** Identifiant de source inconnu du registre. */
    public static function unknownSource(int $id): self
    {
        return new self("Source #{$id} introuvable dans le registre.");
    }

    /**
     * La source EMBARQUÉE n'est ni désactivable ni retirable : ses manifests
     * font partie du déploiement SE5 (fichiers du dépôt), il n'y a rien à
     * « couper ». La désactiver donnerait l'illusion d'un réglage, alors que
     * la prochaine synchro embarquée la remettrait en jeu.
     */
    public static function bundledIsImmutable(): self
    {
        return new self("La source embarquée fait partie du déploiement SE5 : elle ne peut être ni désactivée ni retirée.");
    }

    /** Seule une source DISTANTE se synchronise par le réseau. */
    public static function notRemote(string $key): self
    {
        return new self("La source « {$key} » n'est pas une source distante : il n'y a rien à synchroniser.");
    }

    /** Une source désactivée est GELÉE : on ne la synchronise pas. */
    public static function sourceDisabled(string $key): self
    {
        return new self("La source « {$key} » est désactivée : réactivez-la avant de l'actualiser.");
    }

    public static function invalidName(): self
    {
        return new self('Le nom de la source est obligatoire (120 caractères au plus).');
    }

    /**
     * URL mal formée, hors http(s), porteuse d'identifiants (`user:pass@`),
     * d'une query ou d'un fragment. On refuse ces trois derniers cas parce que
     * l'URL est une URL de BASE à laquelle SE5 concatène `/index.json` : une
     * query ou un fragment produirait une URL cassée, et des identifiants dans
     * l'URL finiraient dans des journaux.
     */
    public static function invalidUrl(): self
    {
        return new self(
            "L'URL du dépôt doit être une adresse http(s) simple (par exemple « https://depot.example.org/extensions »), "
            ."sans identifiants, paramètre ni ancre."
        );
    }

    /** Deux sources ne peuvent pas pointer le même dépôt. */
    public static function duplicateUrl(string $existingName): self
    {
        return new self("Ce dépôt est déjà enregistré sous le nom « {$existingName} ».");
    }

    /**
     * Story 56.1 (review #3) — L'INSERT a été refusé par la contrainte
     * d'unicité que le `SELECT` préalable croyait satisfaite : deux admins ont
     * ajouté une source au même instant. Fenêtre étroite, mais le seul
     * comportement acceptable est un message métier — la discipline « jamais
     * une 500 sur une action admin » ne souffre pas d'exception au motif que
     * le cas est rare.
     */
    public static function concurrentCreation(): self
    {
        return new self(
            'Une autre source vient d\'être enregistrée en même temps sous le même identifiant. '
            .'Réessayez : le nom sera suffixé automatiquement.'
        );
    }

    /**
     * URL en `http://` sans clé collée : le pin TOFU réseau (lecture de
     * `<url>/source.pub`) n'est acceptable que sous TLS. En clair, n'importe
     * quel intermédiaire réseau fournirait SA clé et signerait SON catalogue.
     */
    public static function publicKeyRequiredForHttp(): self
    {
        return new self(
            "Un dépôt en http:// exige que vous colliez sa clé publique vous-même : "
            ."SE5 refuse de récupérer une clé sur un canal non chiffré."
        );
    }

    /** Clé fournie (ou publiée) inexploitable : base64 de 32 octets attendu. */
    public static function invalidPublicKey(): self
    {
        return new self("La clé publique doit être une clé Ed25519 en base64 (32 octets).");
    }

    /** `<url>/source.pub` illisible : on n'invente pas de clé, on refuse l'ajout. */
    public static function publicKeyUnavailable(): self
    {
        return new self(
            "Impossible de récupérer la clé publique du dépôt (« source.pub »). "
            ."Vérifiez l'URL, ou collez la clé publique fournie par l'éditeur."
        );
    }

    /**
     * Retrait refusé tant qu'une extension de la source est INTÉGRÉE.
     *
     * On ne dé-intègre jamais silencieusement (invariant 54.1 #4) : supprimer
     * la source emporterait ses lignes `extensions` par cascade FK, donc des
     * tuiles en service. C'est à l'admin de désinstaller d'abord.
     *
     * @param  list<string>  $names
     */
    public static function integratedExtensionsBlockRemoval(array $names): self
    {
        $list = implode(', ', $names);

        return new self(
            "Retrait impossible : « {$list} » "
            .(count($names) > 1 ? 'sont encore intégrées' : 'est encore intégrée')
            ." à cette instance. Désinstallez-la"
            .(count($names) > 1 ? 's' : '')
            ." depuis la bibliothèque, puis retirez la source."
        );
    }
}
