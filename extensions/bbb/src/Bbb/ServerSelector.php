<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

use SambaEdu\ExtBbb\Store;

/**
 * Story 57.4 — **LE CHOIX DU SERVEUR : LE MOINS CHARGÉ, OU SCALELITE.**
 *
 * Remplace `Store::firstEnabledServer()`, dont le docblock de 57.2 annonçait
 * lui-même sa propre fin (« le choix du serveur le moins chargé, et la bascule
 * sur panne, sont le sujet entier de la story 57.4 »).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  L'ALGORITHME EST CELUI DU LEGACY, ET RIEN D'AUTRE (D5, carte §6)
 *
 *  Pour chaque serveur ACTIF, dans l'ordre des identifiants :
 *
 *   - **Scalelite** (`scalelite_threshold > 0`) : la charge est **la valeur
 *     configurée**, et **aucun appel n'est émis**. Ce n'est pas une paresse
 *     portée du legacy, c'est son CONTRAT : `load_server_bbb()` retournait la
 *     valeur saisie en guise de nombre d'utilisateurs, ce qui fait du seuil un
 *     **point de délégation** — « au-delà de N participants ailleurs, envoie
 *     chez Scalelite », qui répartit ensuite pour son compte.
 *   - **Serveur normal** : `measureLoad()`, c'est-à-dire `getMeetings` et la
 *     somme des participants. Tout retour non-succès l'écarte **de ce
 *     démarrage-ci**.
 *
 *  Le minimum gagne. À égalité, le plus petit identifiant — pour que deux
 *  démarrages successifs dans un parc au repos se comportent pareil, et que le
 *  test qui l'affirme ne soit pas une loterie.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **Ce qui n'est PAS porté du legacy, et pourquoi** :
 *
 *  1. le « serveur joignable ? » par simple `GET` sur l'URL de base : il ne
 *     validait rien (leçon 57.1) — ici la sonde EST signée, et ce qui la
 *     remplace vraiment est la bascule au moment de la création ;
 *  2. le miroir des conférences en mémoire partagée, et son ramasse-miettes :
 *     aucun cache dans cette extension, décision de 57.2 ;
 *  3. **le compteur d'échecs par serveur** : SE4 mémorisait les échecs
 *     successifs pour écarter un serveur quelque temps. Non porté — l'état
 *     par processus n'était partagé par personne, notre coût d'un serveur mort
 *     est UNE sonde bornée par démarrage de salon (événement humain, rare,
 *     explicite), et un compteur exige une politique (combien d'échecs ?
 *     pendant combien de temps ?) qu'aucun critère d'acceptation ne fonde.
 *
 * **Sans réseau en test** : le client est injecté, comme partout dans cette
 * extension. La matrice complète du choix s'exerce sur l'hôte de développement,
 * sans le moindre serveur BigBlueButton.
 *
 * ⚠️ **Le verrou d'état est relâché AVANT d'entrer ici** (règle des revues 57.2
 * et 57.3). La boucle peut sonder N serveurs à 3 s pièce : le tenir pendant ce
 * temps bloquerait tous les autres onglets de la même personne. C'est
 * l'appelant qui relâche, parce que c'est lui qui détient l'état — ce collabo-
 * rateur-ci ne connaît que la table et le client.
 */
final class ServerSelector
{
    /** Message de 57.2, conservé mot pour mot : c'est la même situation. */
    public const NO_SERVER_CONFIGURED = 'Aucun serveur de visioconférence configuré — prévenez l\'administrateur.';

    /**
     * Des serveurs existent, aucun n'a répondu. **Distinct du précédent** :
     * deux causes, deux remèdes — l'un se règle sur la page d'administration,
     * l'autre en allumant une machine.
     */
    public const NONE_REACHABLE = 'Aucun serveur de visioconférence n\'est joignable actuellement. '
        . 'Réessayez dans un instant, puis prévenez l\'administrateur.';

    /**
     * Cas particulier du précédent : personne n'a été retenu, et au moins un
     * refus était un **secret refusé**. Le dire est la seule façon dont
     * l'administrateur l'apprendra — un serveur mal configuré ne se signale
     * jamais tout seul.
     */
    public const SECRET_REFUSED = 'Aucun serveur de visioconférence n\'a pu être retenu : '
        . 'le secret enregistré a été refusé — prévenez l\'administrateur.';

    public function __construct(
        private readonly Store $store,
        private readonly BbbApiClient $api,
    ) {
    }

    /**
     * Les serveurs retenus pour CE démarrage, du moins chargé au plus chargé.
     *
     * ⚠️ Appelée depuis le SEUL `POST /rooms/start`. Ni au rendu d'une page, ni
     * à la jonction, ni pour les enregistrements : un salon déjà démarré garde
     * son serveur (`rooms.server_id`), et rééquilibrer une conférence vivante
     * n'aurait aucun sens — BigBlueButton ne déplace pas un meeting.
     */
    public function select(): Selection
    {
        $servers = array_values(array_filter(
            $this->store->servers(),
            static fn (array $server): bool => $server['enabled'] === true,
        ));

        if ($servers === []) {
            return Selection::none(self::NO_SERVER_CONFIGURED);
        }

        $candidates = [];
        $secretRefused = false;

        foreach ($servers as $server) {
            $baseUrl = (string) $server['base_url'];
            $secret = (string) $server['secret'];
            $threshold = (int) $server['scalelite_threshold'];

            if ($threshold > 0) {
                // SCALELITE : seuil fixe configuré, JAMAIS sondé.
                $candidates[] = new ServerCandidate(
                    id: (int) $server['id'],
                    baseUrl: $baseUrl,
                    secret: $secret,
                    load: $threshold,
                    delegated: true,
                );

                continue;
            }

            $load = $this->api->measureLoad($baseUrl, $secret);

            if ($load->outcome === CallOutcome::InvalidSecret) {
                // Écarté COMME les autres — mais retenu pour le message : un
                // secret refusé n'est pas une panne, c'est une configuration à
                // corriger. Le noyer dans « injoignable » le rendrait éternel.
                $secretRefused = true;

                continue;
            }

            if (! $load->isOk()) {
                // Injoignable ou réponse inattendue : écarté SANS bruit. Tant
                // qu'un autre serveur répond, l'utilisateur n'a rien à en
                // savoir — son cours commence.
                continue;
            }

            $candidates[] = new ServerCandidate(
                id: (int) $server['id'],
                baseUrl: $baseUrl,
                secret: $secret,
                load: $load->participants,
            );
        }

        if ($candidates === []) {
            // ═══════════════════════════════════════════════════════════════
            //  PRIORITÉ ASSUMÉE QUAND LES DEUX CAUSES COEXISTENT
            //  (review 57.4 #3 — c'était un comportement de fait, c'est
            //  désormais un choix)
            //
            //  Avec trois serveurs dont un au secret refusé et deux éteints,
            //  l'utilisateur reçoit le message « secret refusé ». C'est
            //  délibéré : des deux causes, c'est la seule sur laquelle
            //  quelqu'un peut agir tout de suite, et la seule qui ne se
            //  répare pas toute seule. Un parc éteint se rallume ; un secret
            //  faux reste faux tant que personne ne le sait.
            //
            //  Le prix est réel et il est accepté : on n'annonce pas que le
            //  reste du parc est injoignable. Les deux causes figurent au
            //  runbook, et le message pointe l'administrateur — qui verra les
            //  deux en ouvrant la page des serveurs.
            // ═══════════════════════════════════════════════════════════════
            return Selection::none(
                $secretRefused ? self::SECRET_REFUSED : self::NONE_REACHABLE,
                $secretRefused,
            );
        }

        // Charge croissante ; à égalité, le plus petit identifiant. Comparaison
        // de tableaux : PHP compare élément par élément, dans l'ordre.
        usort(
            $candidates,
            static fn (ServerCandidate $a, ServerCandidate $b): int => [$a->load, $a->id] <=> [$b->load, $b->id],
        );

        return Selection::of($candidates, $secretRefused);
    }
}
