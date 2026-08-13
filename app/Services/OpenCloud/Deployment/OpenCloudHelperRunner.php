<?php

declare(strict_types=1);

namespace App\Services\OpenCloud\Deployment;

/**
 * LE seam privilégié du déploiement de l'instance OpenCloud.
 *
 * Contrat volontairement ÉTROIT, calqué sur celui du système d'extensions :
 * « invoquer LE helper root avec ces arguments, éventuellement en lui poussant
 * ce contenu sur l'entrée standard ». L'appelant ne choisit ni le binaire, ni le
 * shell, ni l'environnement, ni l'image de conteneur — le helper les tient tous.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * **POURQUOI UNE INTERFACE DÉDIÉE ET NON CELLE DU SYSTÈME D'EXTENSIONS.**
 *
 * Elles ont la même FORME et des destinataires différents. Réutiliser l'autre
 * ferait pointer ce chantier vers le helper des extensions — c'est-à-dire vers
 * un helper dont les verbes devraient alors s'élargir, ce qui reconstituerait
 * exactement le couplage défait le 2026-08-08. La forme se copie ; le
 * destinataire, non. Deux interfaces d'une douzaine de lignes coûtent moins
 * qu'un couplage qu'il faudra redéfaire.
 *
 * Le SECRET d'administration ne transite que par `$stdin` : en argument, il
 * apparaîtrait dans `/proc/<pid>/cmdline`, dans un `ps` de n'importe quel
 * utilisateur, et dans le journal de `sudo`.
 * ══════════════════════════════════════════════════════════════════════════
 */
interface OpenCloudHelperRunner
{
    /**
     * Invoque le helper root avec `$args` (verbe en tête).
     *
     * Ne lève JAMAIS : un échec se lit dans `exitCode`, et le pilote de
     * déploiement en fait un refus nommé plutôt qu'une exception qui
     * traverserait la commande d'administration.
     *
     * @param  list<string>  $args  verbe et ses arguments, valeurs brutes
     *                              (l'implémentation échappe, le helper re-valide)
     * @param  string|null  $stdin  contenu poussé sur l'entrée standard (secrets),
     *                              jamais un argument
     * @return array{stdout: list<string>, stderr: list<string>, exitCode: int}
     */
    public function run(array $args, ?string $stdin = null): array;
}
