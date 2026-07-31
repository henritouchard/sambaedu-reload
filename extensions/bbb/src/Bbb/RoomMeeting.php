<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.2 — Ce qu'il faut savoir pour ouvrir un meeting : le strict
 * nécessaire, et rien qui vienne du navigateur.
 *
 * Le `meetingId` est le **jeton public du salon**, opaque. Deux propriétés en
 * découlent :
 *
 * 1. `createMeeting` est **idempotent** côté BigBlueButton — même identifiant et
 *    mêmes mots de passe sur un meeting déjà vivant ⇒ `SUCCESS`. C'est ce qui
 *    permet au bouton du créateur d'être « démarrer OU entrer » sans qu'aucun
 *    état de fonctionnement ne soit tenu localement (le miroir APCu du legacy et
 *    son ramasse-miettes disparaissent avec) ;
 * 2. il n'encode **aucune** sémantique : ni établissement, ni login, ni classe.
 *    Le legacy y empilait des `md5` et devait ensuite les décoder en relisant
 *    l'annuaire, avec un filtre d'enregistrements cassé à la clé.
 */
final class RoomMeeting
{
    public function __construct(
        public readonly string $meetingId,
        public readonly string $name,
        public readonly string $attendeePassword,
        public readonly string $moderatorPassword,
        /**
         * URL ABSOLUE de retour après la conférence. Vide = paramètre omis :
         * la seule origine absolue que l'extension connaisse est son issuer,
         * et une extension non provisionnée n'en a pas.
         */
        public readonly string $logoutUrl = '',
    ) {
    }
}
