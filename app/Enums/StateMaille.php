<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Maille de ciblage d'un candidat d'état — enum **interne** au
 * serveur : elle n'apparaît jamais dans le JSON `se5.desired-state/v1` (donc
 * non soumise au gel des identifiants publiés). Les providers étiquettent leurs
 * candidats avec elle ; le compilateur l'utilise pour arbitrer la précédence.
 *
 * ⚠️ AUCUNE méthode de rang ici : l'ordre de spécificité
 * INVERSE `logical_group`/`physical_group` GLOBALEMENT (le parc logique bat la
 * salle physique). Le tier AMONT se place au-dessus de tout le local (le contrat
 * imposé par l'autorité amont prime sur le réglage local) ; le tier AMONT
 * **PERMISSIF** se place au-dessous de tout le local (un item `permissive` est un
 * **plancher** que toute maille locale surcharge) :
 * `upstream > user > user_group > workstation > logical_group > physical_group >
 * broadcast > upstream_permissive`. Cet ordre vit dans le **StateCompiler seul** ;
 * l'y dupliquer ferait fuiter la règle de précédence vers les providers.
 *
 * ⚠️ Convention de nommage : le tier amont est `Upstream` (valeur
 * `'upstream'`), le tier amont permissif `UpstreamPermissive` (valeur
 * `'upstream_permissive'`), JAMAIS « central ». Vocabulaire « amont » /
 * `Upstream`.
 */
enum StateMaille: string
{
    /**
     * Maille AMONT — un item imposé par le contrat amont
     * (controlHub) ; plus spécifique que TOUTE maille locale → il prime au
     * compilateur. La précédence est arbitrée par
     * {@see \App\Services\Agent\StateCompiler::specificity()} SEUL : un candidat
     * étiqueté `Upstream` reste un candidat BRUT, la règle de précédence ne
     * sortant pas du compilateur.
     */
    case Upstream = 'upstream';
    case User = 'user';
    case UserGroup = 'user_group';
    case Workstation = 'workstation';
    case PhysicalGroup = 'physical_group';
    case LogicalGroup = 'logical_group';
    case Broadcast = 'broadcast';

    /**
     * Maille AMONT PERMISSIVE — un item imposé par le contrat amont
     * en état `permissive` (controlHub). Contrairement à `Upstream` (qui reste
     * INBATTABLE pour `locked`), ce tier est le MOINS spécifique de TOUTE la
     * chaîne (sous `Broadcast`) : un item `permissive` est un **plancher** que
     * **toute** maille locale surcharge — défaut diffusé, groupe, poste, user.
     * Il ne s'applique qu'en l'ABSENCE TOTALE de candidat local. La
     * précédence est arbitrée par
     * {@see \App\Services\Agent\StateCompiler::specificity()} SEUL : un candidat
     * étiqueté `UpstreamPermissive` reste un candidat BRUT, la règle de
     * précédence ne sortant pas du compilateur.
     */
    case UpstreamPermissive = 'upstream_permissive';
}
