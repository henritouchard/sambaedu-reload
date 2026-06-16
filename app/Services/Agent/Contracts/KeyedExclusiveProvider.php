<?php

declare(strict_types=1);

namespace App\Services\Agent\Contracts;

/**
 * Marqueur OPTIONNEL d'un {@see StateProvider} `Exclusive` dont l'exclusivité se
 * joue PAR IDENTITÉ DE CLÉ et non « un seul item pour tout le type »
 * (Story 27.3).
 *
 * Cas d'usage : `registry`. Une clé de registre = UNE valeur, mais un poste peut
 * recevoir PLUSIEURS clés distinctes. La maille la plus spécifique gagne **pour
 * une clé donnée** `{hive, path, name}` ; les clés différentes s'accumulent
 * toutes. C'est différent de `wallpaper` (un seul fond pour tout le poste) :
 * sans ce marqueur, `StateCompiler::selectExclusive()` n'élit qu'UN candidat
 * pour tout le type — ce qui écraserait des clés distinctes.
 *
 * Discipline D2 PRÉSERVÉE : le provider rend toujours des candidats BRUTS par
 * maille (aucune précédence/tri/dédup). Il déclare seulement COMMENT identifier
 * une « ressource exclusive » dans son payload — la sélection (précédence par
 * maille, récence intra-maille) reste au {@see StateCompiler} SEUL.
 *
 * Un provider Exclusive qui n'implémente PAS ce marqueur conserve le
 * comportement « un seul item gagnant pour le type » (wallpaper).
 */
interface KeyedExclusiveProvider
{
    /**
     * Clé d'identité de la ressource exclusive portée par CE payload. Deux
     * candidats au MÊME `exclusiveKey()` sont en concurrence (la maille la plus
     * spécifique gagne) ; deux candidats à clé DIFFÉRENTE s'accumulent.
     *
     * Doit être STABLE et DÉTERMINISTE (sert le déterminisme de l'état/ETag).
     *
     * @param  array<string,mixed>  $payload
     */
    public function exclusiveKey(array $payload): string;
}
