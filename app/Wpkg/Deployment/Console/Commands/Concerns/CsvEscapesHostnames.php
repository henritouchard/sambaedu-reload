<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Console\Commands\Concerns;

/**
 * Story 15.5 / Fix #10 — Trait partagé pour l'échappement CSV des hostnames
 * (et autres valeurs) dans les outputs des commandes de provisioning/rotation
 * de secrets.
 *
 * Si la valeur contient une virgule, un guillemet double ou un saut de ligne,
 * elle est entourée de guillemets doubles, et les guillemets internes sont
 * doublés (RFC 4180).
 *
 * Utilisé par :
 *   - {@see \App\Wpkg\Deployment\Console\Commands\ProvisionWorkstationSecretsCommand}
 *   - {@see \App\Wpkg\Deployment\Console\Commands\RotateWorkstationSecretCommand}
 */
trait CsvEscapesHostnames
{
    protected function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
