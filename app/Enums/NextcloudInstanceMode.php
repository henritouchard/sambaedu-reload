<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Facades\Log;

/**
 * Story 61.2 — LE MODE D'ADMINISTRATION DE L'INSTANCE NEXTCLOUD CONNECTÉE.
 *
 * ---------------------------------------------------------------------------
 * **CE N'EST PAS UN MODE D'ACCÈS, ET CE N'EST PAS UN BACKEND.** Le cadrage lit la
 * cible sur deux axes : l'axe A (l'AUTORITÉ — la colonne `backend` d'un partage) et
 * l'axe B (le CHEMIN D'ACCÈS — SMB, web, client de synchro). Cet enum décrit ce
 * que SE5 a le droit de faire SUR L'INSTANCE, c'est-à-dire la position d'axe A que
 * l'instance pourra tenir le jour où un partage y basculera (61.3).
 *
 * L'axe A a TROIS positions — `posix`, `nextcloud`, `nextcloud_delegue` — mais cet
 * enum n'en porte que DEUX. `posix` n'est pas un mode d'administration d'instance :
 * c'est l'état de tout partage aujourd'hui, et l'absence d'instance (capacité
 * « Accès Nextcloud » éteinte) le laisse seul en lice. Y ajouter une case
 * `posix` ferait de ce réglage un mode exclusif — exactement ce que la décision du
 * 2026-07-17 a refusé pour les capacités.
 *
 * **La troisième position n'est pas un « non-mode ».** La correction du 2026-08-03
 * est explicite : le cas de l'instance non administrée est une TROISIÈME position
 * sur l'axe A, et le précédent SE4 le prouve (`cloud_api_user` configuré par cloud,
 * `nuage.apps` et `drive.monlycee.net` compris). Le délégué n'est pas
 * « `nextcloud` moins les quotas » : c'est une STRATÉGIE D'OCTROI différente —
 * l'octroi par utilisateur au lieu de l'octroi par groupe — et c'est là son vrai
 * contenu.
 * ---------------------------------------------------------------------------
 *
 * **Où il vit** : dans le payload `files.policy`
 * ({@see \App\Services\FilePolicyService}), sous la clé `nextcloud_mode`. **Pas**
 * dans le vocabulaire de backend de l'Epic 60 : une case de cet enum-là est une
 * valeur de colonne SÉLECTIONNABLE sur un partage, et la poser sans adaptateur
 * enregistré serait déclarer une position que le système ne sait pas honorer — le
 * défaut même que la sélection fail-closed de cette story combat. Les cases
 * voyagent avec l'adaptateur, en 61.3.
 *
 * **Les textes de promesse et de dégradation vivent ICI**, pas dans le blade : ils
 * sont la définition de ce que chaque position veut dire, ils sont testés, et un
 * texte éparpillé dans une vue finit par mentir quand le code change.
 */
enum NextcloudInstanceMode: string
{
    /**
     * Compte ADMINISTRATEUR de l'instance : SE5 gouverne les montages globaux, les
     * comptes, les groupes et les quotas. C'est ce que 61.1 a configuré, et c'est
     * le défaut — les instances existantes ne changent pas de comportement.
     */
    case Admin = 'admin';

    /**
     * Compte PORTEUR ordinaire : SE5 crée une arborescence dans l'espace de ce
     * compte et émet des octrois PAR UTILISATEUR, en tant que propriétaire. Ni
     * identités, ni groupes, ni quotas, ni montages globaux — un compte ordinaire
     * n'en a pas le privilège.
     */
    case Delegue = 'delegue';

    /** Défaut : ce que 61.1 a configuré. Une instance déjà connectée ne bouge pas. */
    public const DEFAULT = self::Admin;

    /**
     * Libellé au SUJET neutre (convention des capacités) : il nomme le mode, il ne
     * porte pas l'état — l'état, c'est la valeur sélectionnée.
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Instance administrée (compte admin)',
            self::Delegue => 'Compte porteur (délégué)',
        };
    }

    /** Une phrase : ce que le mode est, avant ce qu'il promet ou dégrade. */
    public function summary(): string
    {
        return match ($this) {
            self::Admin => 'Le compte configuré est administrateur de l\'instance : SE5 peut y gouverner '
                . 'les montages, les comptes et les groupes.',
            self::Delegue => 'Le compte configuré est un compte ordinaire : SE5 ne peut agir que dans '
                . 'l\'espace de ce compte, et seulement par des partages qu\'il émet lui-même.',
        };
    }

    /**
     * Ce que le mode PROMET — au futur assumé pour ce qui n'est pas encore livré.
     *
     * @return list<string>
     */
    public function promises(): array
    {
        return match ($this) {
            self::Admin => [
                'Autorité pleine à venir (61.3) : octroi par GROUPE, dossiers d\'équipe, plafonds de zone.',
                'Montages de stockage externe et gestion des comptes : la machinerie de la story 61.1 reste active.',
                'Le rattachement d\'identité se vérifie directement auprès de l\'instance, compte par compte.',
            ],
            self::Delegue => [
                'L\'arborescence se crée dans l\'espace du compte porteur, sans aucun privilège d\'administration.',
                'Les octrois sont émis par le porteur, PAR UTILISATEUR : c\'est le précédent éprouvé de SE4 '
                . 'sur les instances tierces.',
            ],
        };
    }

    /**
     * Ce que le mode DÉGRADE — « sans complaisance », et au moment du CHOIX plutôt
     * qu'à la première réconciliation qui échoue (conclusion du spike 60.0).
     *
     * Les cinq dégradations du délégué ne sont pas une liste de précautions : ce
     * sont cinq faits, dont deux MESURÉS (le nœud privé inexprimable, le refus des
     * endpoints d'administration en compte ordinaire).
     *
     * @return list<string>
     */
    public function degradations(): array
    {
        return match ($this) {
            self::Admin => [],
            self::Delegue => [
                'L\'arborescence pend d\'un COMPTE PORTEUR : la propriété des dossiers, le décompte de son '
                . 'quota et la sémantique de suppression s\'attachent à ce compte — pas à l\'établissement. '
                . 'Le jour où ce compte disparaît, l\'arborescence part avec lui.',

                'L\'octroi est PAR UTILISATEUR : chaque changement d\'appartenance ou de rôle demande une '
                . 'resynchronisation des partages, un par un. C\'est la facture du modèle (le diff de SE4 '
                . 'tournait à la minute) — et cette resynchronisation n\'est PAS livrée par cette story.',

                'Aucun PLAFOND DE ZONE n\'est posable : les quotas sont une opération d\'administration.',

                'Un NŒUD PRIVÉ est inexprimable. Mesuré au spike : l\'ancêtre partagé propage au sous-arbre, '
                . 'et l\'instruction de retrait est acceptée « 200 OK » SANS EFFET — relue inchangée. Le '
                . 'partage de classe de SE4 (un dossier professeurs invisible aux élèves) ne peut pas être '
                . 'porté tel quel.',

                'Ni montages de stockage externe, ni gestion des comptes et des mots de passe : la machinerie '
                . 'de la story 61.1 est coupée tant que ce mode est déclaré (elle n\'est pas défaite — voir '
                . 'ci-dessous).',
            ],
        };
    }

    /**
     * L'honnêteté TEMPORELLE, commune aux deux modes : ce réglage DÉCLARE une
     * position, il n'exécute rien. Le taire ferait croire qu'un partage vient de
     * basculer sur Nextcloud — il n'en existe aucun.
     */
    public static function temporalHonesty(): string
    {
        return 'Déclarer un mode ne branche AUCUN partage sur Nextcloud aujourd\'hui : la colonne de '
            . 'backend des partages ne connaît que « posix ». C\'est la story 61.3 qui rendra ces '
            . 'positions sélectionnables partage par partage.';
    }

    /**
     * Ce que le passage au délégué NE FAIT PAS — dit à l'écran au moment du
     * changement de mode (D9 : jamais de suppression implicite).
     */
    public static function noImplicitRemoval(): string
    {
        return 'Les montages de stockage externe déjà provisionnés ne sont ni supprimés ni modifiés : '
            . 'SE5 cesse simplement de les gouverner. Repasser en instance administrée restitue le '
            . 'comportement précédent sans reconfiguration.';
    }

    /**
     * Lecture TOLÉRANTE d'une valeur persistée.
     *
     * Une valeur hors vocabulaire (réglage édité à la main, JSON importé d'une autre
     * version, migration future à moitié jouée) ne doit ni faire planter l'écran ni
     * — surtout pas — inventer un mode. Elle retombe sur le défaut, **en le
     * journalisant** : un repli silencieux ferait qu'une instance déclarée
     * « déléguée » se remettrait à émettre des opérations d'administration sans que
     * personne ne l'apprenne.
     */
    public static function fromStored(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $parsed = self::tryFrom($value);

            if ($parsed !== null) {
                return $parsed;
            }

            if (trim($value) !== '') {
                Log::warning('nextcloud.mode.unknown_value', [
                    'value' => $value,
                    'fallback' => self::DEFAULT->value,
                ]);
            }
        }

        return self::DEFAULT;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
