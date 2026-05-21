<?php

declare(strict_types=1);

namespace App\Ipxe\Exceptions;

use RuntimeException;

/**
 * Story 3.4 — T2.2 / AC2.1.
 *
 * Exception levée par {@see \App\Ipxe\Services\LinuxPreseedService::generate()}
 * en cas d'échec de l'assemblage du preseed :
 *
 *  - Fragment .cfg manquant ou illisible.
 *  - Variant non supportée par la distribution choisie.
 *  - Erreur d'IO lors de la lecture des fragments.
 *
 * **Wrap obligatoire côté caller** : un firmware iPXE doit recevoir une
 * réponse `text/plain` propre (jamais une 500 HTML) — le controller
 * `IpxeLinuxPreseedController::handle()` catch cette exception et retourne
 * une 500 minimaliste (ou 404 si fragment introuvable) avec log structuré.
 */
class PreseedGenerationException extends RuntimeException
{
}
