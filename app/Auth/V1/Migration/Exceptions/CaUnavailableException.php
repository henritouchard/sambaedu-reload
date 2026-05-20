<?php

declare(strict_types=1);

namespace App\Auth\V1\Migration\Exceptions;

use RuntimeException;

/**
 * Story 16.13bis — Correction Opus-B (2026-05-20).
 *
 * Exception levée par `MigrationFragmentRenderer` quand le CA root local
 * est absent en environment **production**. Le caller (`MigrationController`)
 * la capture et retourne un 503 explicite plutôt qu'un fragment silencieux
 * avec CA vide qui causerait un `certutil` tronqué côté Windows.
 *
 * En dev/test, le renderer retombe sur un placeholder vide (DO-7) pour ne
 * pas casser les tests Feature qui tournent sans PKI initialisée.
 */
final class CaUnavailableException extends RuntimeException
{
}
