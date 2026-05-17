<?php

declare(strict_types=1);

namespace App\Doctor;

/**
 * Résultat d'un {@see EnvironmentCheck}. DTO immutable.
 *
 * - `ok` : pré-requis satisfait.
 * - `warn` : pré-requis non satisfait mais non bloquant (dégradation possible).
 * - `error` : pré-requis non satisfait et bloquant (fonctionnalité KO).
 *
 * `fix` est facultatif mais fortement recommandé : c'est ce que l'opérateur
 * lit en priorité quand un check échoue.
 */
final class CheckResult
{
    public function __construct(
        public readonly Level $level,
        public readonly string $detail,
        public readonly ?string $fix = null,
    ) {}

    public static function ok(string $detail): self
    {
        return new self(Level::Ok, $detail);
    }

    public static function warn(string $detail, ?string $fix = null): self
    {
        return new self(Level::Warn, $detail, $fix);
    }

    public static function error(string $detail, ?string $fix = null): self
    {
        return new self(Level::Error, $detail, $fix);
    }
}
