<?php

declare(strict_types=1);

namespace App\Services\Agent\Enrollment;

use App\Ipxe\Support\MacAddressNormalizer;
use App\Models\Workstation;

/**
 * Story 25.3 — Rapprochement du faisceau de preuves d'une demande d'enrôlement
 * porte 2 vers un poste connu en DB (gap architecture n° 3, FR16).
 *
 * Règle de preuve FIGÉE (aucune preuve suffisante seule — l'uuid SMBIOS s'est
 * montré peu fiable, mémoire `project_ipxe_param_use_smbios_vars`) :
 *
 *  - **MAC = ancre fiable** : seule clé de rapprochement. Normalisée des deux
 *    côtés ({@see MacAddressNormalizer} — accepte tirets/colons/nu), comparée à
 *    `workstations.mac` (canonique lowercase `:` via le mutateur du modèle).
 *  - **hostname = corroborant** : la concordance exige un hostname cohérent
 *    (insensible à la casse) OU absent côté demande (un poste peut être renommé).
 *  - **uuid = corroborant faible** : ne sert JAMAIS à résoudre un candidat
 *    (peu fiable) — uniquement au log de cohérence côté service.
 *
 * Un rapprochement n'est retenu que s'il désigne un **candidat UNIQUE**
 * (plusieurs postes partageant une MAC → ambiguïté → pas de candidat). Le
 * service ne lit QUE `workstations` (lecture seule, zéro AD — critère Keycloak
 * NFR7) et n'écrit rien.
 */
class EnrollmentMatchService
{
    /**
     * Résout l'unique poste connu candidat pour le faisceau, ou null si aucun
     * candidat fiable (MAC absente/illisible, poste inconnu, ou multi-candidats).
     *
     * @param array{uuid?: string|null, mac?: string|null, hostname?: string|null} $identity
     */
    public function match(array $identity): ?Workstation
    {
        $mac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        if ($mac === null) {
            // Sans MAC lisible, aucun rapprochement fiable possible (l'uuid et
            // le hostname seuls ne suffisent jamais — gap 3).
            return null;
        }

        // `workstations.mac` est canonique lowercase `:` (mutateur modèle).
        $candidates = Workstation::query()->where('mac', $mac)->limit(2)->get();

        // Candidat UNIQUE exigé : 0 → inconnu, ≥ 2 → ambiguïté (pas de
        // rapprochement, retombe en manuel).
        if ($candidates->count() !== 1) {
            return null;
        }

        return $candidates->first();
    }

    /**
     * Concordance du faisceau avec un poste connu — condition NÉCESSAIRE (et,
     * combinée à l'unicité du candidat, suffisante) de l'auto-approbation.
     *
     * Concordant ⇔ MAC connue (le poste a une MAC qui matche le faisceau
     * normalisé) ET hostname cohérent (ou absent côté demande) ET poste **non
     * enrôlé** (un poste déjà enrôlé = conflit, jamais auto — piège n° 4). Cet
     * invariant de sécurité ne se débraye JAMAIS, même en campagne (piège n° 3).
     *
     * @param array{uuid?: string|null, mac?: string|null, hostname?: string|null} $identity
     */
    public function isConcordant(Workstation $workstation, array $identity): bool
    {
        // Poste déjà enrôlé → conflit, jamais concordant (anti-clone/ré-enrôlement).
        if ($workstation->isAgentEnrolled()) {
            return false;
        }

        // MAC = ancre : doit être connue ET identique au faisceau normalisé.
        $presentedMac = MacAddressNormalizer::normalize((string) ($identity['mac'] ?? ''));
        $knownMac = MacAddressNormalizer::normalize((string) ($workstation->mac ?? ''));
        if ($presentedMac === null || $knownMac === null || $presentedMac !== $knownMac) {
            return false;
        }

        // hostname = corroborant : cohérent (casse-insensible) OU absent côté
        // demande. Une divergence explicite casse la concordance.
        $hostname = trim((string) ($identity['hostname'] ?? ''));
        if ($hostname !== '' && $workstation->name !== null
            && strcasecmp($hostname, $workstation->name) !== 0) {
            return false;
        }

        return true;
    }
}
