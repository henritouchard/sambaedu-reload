<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.1 — Le contrat du client BBB, **injectable**.
 *
 * Interface pour une raison de test : la page d'administration doit pouvoir être
 * exercée de bout en bout sans le moindre appel réseau, et le MAPPING des
 * retours (succès / secret refusé / injoignable / réponse inattendue) doit être
 * prouvé sur des réponses réelles de la bibliothèque, pas sur une doublure du
 * mapping lui-même.
 *
 * Story 57.2 — Trois méthodes s'ajoutent, dont **deux sortantes et une locale**.
 * La distinction n'est pas cosmétique : sur un serveur HTTP intégré
 * mono-processus, tout ce qui sort doit être borné en temps et déclenché par un
 * acte explicite, jamais par le rendu d'une page.
 */
interface BbbApiClient
{
    /**
     * Éprouve une paire (URL, secret) par un appel **checksummé**.
     *
     * Un simple `GET` sur l'URL de base ne validerait que l'URL — c'est ce que
     * faisait le legacy avec `server_bbb_is_up()`, et cela laissait passer un
     * secret erroné jusqu'au premier salon créé.
     */
    public function testConnection(string $baseUrl, string $secret): ConnectionResult;

    /**
     * Ouvre — ou ré-ouvre — le meeting d'un salon. **Appel SORTANT, borné.**
     *
     * Idempotent côté BigBlueButton : le même identifiant avec les mêmes mots de
     * passe rend `SUCCESS` sur un meeting déjà vivant.
     */
    public function createMeeting(string $baseUrl, string $secret, RoomMeeting $meeting): CreateResult;

    /** Le meeting tourne-t-il ? **Appel SORTANT, borné.** */
    public function isMeetingRunning(string $baseUrl, string $secret, string $meetingId): RunningResult;

    /**
     * Fabrique l'URL de jonction signée. **CONSTRUCTION LOCALE — aucun réseau.**
     *
     * ⚠️ L'URL retournée **porte le mot de passe** qui décide du rôle dans la
     * conférence. Elle ne se journalise pas, ne s'affiche pas, ne se met pas
     * dans un attribut `href` d'une page listant les salons : son seul usage
     * légitime est un en-tête `Location:` de redirection, immédiatement suivi.
     */
    public function joinUrl(
        string $baseUrl,
        string $secret,
        string $meetingId,
        string $fullName,
        string $password,
    ): string;

    /**
     * Les enregistrements PUBLIÉS, **filtrés à la source**. Appel SORTANT, borné.
     *
     * `$meetingIds` part dans le paramètre `meetingID` de l'API, sous forme de
     * liste séparée par des virgules. SE4 appelait `getRecordings()` **sans
     * aucun filtre** sur chaque serveur, ramenait tout l'historique de
     * l'établissement, le mettait en cache une demi-heure, puis triait en PHP
     * en décodant le `meetingID` — un décodage faux dès qu'un salon portait des
     * classes.
     *
     * ⚠️ Le filtre envoyé n'est pas une garantie reçue : l'appelant re-filtre la
     * réponse. C'est de la défense en profondeur, pas de la méfiance décorative
     * — un serveur qui ignorerait `meetingID` rendrait les enregistrements de
     * tout l'établissement.
     *
     * `$recordId` interroge UN enregistrement précis (`recordID`) : c'est ce qui
     * permet de prouver la propriété AVANT de supprimer quoi que ce soit.
     *
     * @param  list<string>  $meetingIds
     */
    public function getRecordings(
        string $baseUrl,
        string $secret,
        array $meetingIds = [],
        string $recordId = '',
    ): RecordingsResult;

    /** Supprime UN enregistrement. **Appel SORTANT, borné, et irréversible.** */
    public function deleteRecording(string $baseUrl, string $secret, string $recordId): DeleteResult;

    /**
     * Story 57.4 — La CHARGE d'un serveur. **Appel SORTANT, borné plus court.**
     *
     * Son SEUL appelant légitime est {@see ServerSelector} : c'est une sonde de
     * répartition, jamais un élément de rendu. Aucune page ne l'appelle, aucune
     * jonction, aucune liste — le seul moment où la question se pose est le
     * démarrage d'un salon, c'est-à-dire un acte explicite du créateur.
     *
     * La borne de temps est **plus courte** que celle des autres appels (voir
     * `LiveBbbApiClient::PROBE_TIMEOUT_MS`) : un serveur qui n'arrive pas à dire
     * combien de monde il héberge n'est pas un bon hôte pour un meeting neuf, et
     * l'attendre coûterait à chaque démarrage la somme de toutes les sondes.
     */
    public function measureLoad(string $baseUrl, string $secret): LoadResult;
}
