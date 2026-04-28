<?php

declare(strict_types=1);

namespace App\Services\Print\Exceptions;

/**
 * Levée quand le daemon CUPS est inaccessible (`lpstat -s` ou `lpstat -r` échoue
 * avec un code non-nul).
 *
 * Distincte de `CupsCommandException` (erreur métier d'une commande individuelle)
 * pour permettre aux appelants de différencier « CUPS down » de « commande invalide ».
 * Le `PrintersSyncCommand` l'attrape pour interrompre la synchronisation sans
 * marquer tous les rows SER comme orphelins (Story 6.1 AC9, fix #12).
 */
class CupsDaemonDownException extends \RuntimeException {}
