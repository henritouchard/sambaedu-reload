<?php

declare(strict_types=1);

namespace App\Services\Extensions\Contracts;

/**
 * Story 56.2 — LE seam privilégié du moteur d'installation.
 *
 * Contrat volontairement ÉTROIT : « invoquer LE helper root avec ces
 * arguments, éventuellement en lui poussant ce contenu sur stdin ». Ce n'est
 * PAS un exécuteur de shell générique — l'appelant ne choisit ni le binaire, ni
 * le shell, ni l'environnement. Toute la surface privilégiée du système
 * d'extensions tient donc dans cette unique interface, et les tests l'observent
 * comme une SÉQUENCE d'appels `(args, stdin)` assertable exactement.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  POURQUOI PAS {@see \App\Services\Print\Contracts\CommandRunner} (6.1) ?
 *
 *  Deux raisons, toutes deux de sécurité :
 *
 *  1. **`CommandRunner::run(string $command)` n'a pas de stdin.** Le secret
 *     OIDC du client d'une extension DOIT être poussé par stdin au helper : en
 *     argument, il apparaîtrait dans `/proc/<pid>/cmdline`, dans un `ps` de
 *     n'importe quel utilisateur de la machine, et dans le journal de `sudo`
 *     (qui trace la commande complète). Un secret journalisé est un secret
 *     perdu (NFR3).
 *  2. **`CommandRunner` prend une CHAÎNE déjà composée**, ce qui fait reposer
 *     l'échappement sur chaque site d'appel. Ici la liste d'arguments est
 *     typée : l'implémentation réelle échappe systématiquement, et le helper
 *     RE-VALIDE tout côté root. La défense ne repose jamais sur l'appelant.
 *
 *  `CommandRunner` reste parfaitement adapté à son domaine (CUPS, DHCP :
 *  commandes sans secret) — on ne le remplace pas, on ne l'étend pas non plus
 *  pour un besoin qui n'est pas le sien.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Implémentation réelle : {@see \App\Services\Extensions\SudoExtensionHelperRunner}
 * (bindée dans `AppServiceProvider`). Doublure de test :
 * `tests/Support/FakeExtensionHelperRunner`.
 */
interface ExtensionHelperRunner
{
    /**
     * Invoque le helper root avec `$args` (sous-commande en tête).
     *
     * Ne lève JAMAIS : un échec se lit dans `exitCode` (le moteur d'installation
     * en fait une compensation, pas une exception qui traverserait la CLI).
     *
     * @param  list<string>  $args   Sous-commande et ses arguments, valeurs brutes
     *                               (l'implémentation échappe, le helper re-valide).
     * @param  string|null   $stdin  Contenu poussé sur l'entrée standard (secrets),
     *                               jamais un argument.
     * @return array{stdout: list<string>, stderr: list<string>, exitCode: int}
     */
    public function run(array $args, ?string $stdin = null): array;
}
