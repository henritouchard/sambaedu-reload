<?php

declare(strict_types=1);

namespace App\Services\AppCustomization;

use App\Enums\AppKind;
use App\Services\AppCustomization\Contracts\AppPolicyAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Registre centralisé des adapters `AppPolicyAdapter`.
 *
 * Story 4.8 — AC 1. Auto-découvre via `AppKind::cases()` + `adapterClass()`.
 * Résout via le container IoC (permet l'injection de dépendances dans chaque
 * adapter). Cache in-memory par instance (1 instance par request).
 *
 * Extension : `register(AppKind, class-string)` pour injecter dynamiquement
 * un adapter supplémentaire (utile pour les tests ou des plugins dynamiques).
 */
class AppPolicyRegistry
{
    /**
     * Map AppKind → classe adapter (fallback: `AppKind::adapterClass()`).
     *
     * @var array<string, class-string<AppPolicyAdapter>>
     */
    private array $overrides = [];

    /**
     * Instances résolues (cache per-request).
     *
     * @var array<string, AppPolicyAdapter>
     */
    private array $instances = [];

    public function __construct(private readonly Container $container) {}

    /**
     * Override de l'adapter pour un AppKind donné.
     *
     * @param  class-string<AppPolicyAdapter>  $adapterClass
     */
    public function register(AppKind $kind, string $adapterClass): void
    {
        $this->overrides[$kind->value] = $adapterClass;
        unset($this->instances[$kind->value]);
    }

    /**
     * Résout l'adapter correspondant à l'AppKind.
     *
     * @throws \InvalidArgumentException si `$kind` est une string qui ne matche aucun case.
     */
    public function resolve(AppKind|string $kind): AppPolicyAdapter
    {
        $resolvedKind = $kind instanceof AppKind
            ? $kind
            : AppKind::tryFrom($kind)
                ?? throw new \InvalidArgumentException("AppKind inconnu : {$kind}");

        $key = $resolvedKind->value;

        if (! isset($this->instances[$key])) {
            $class = $this->overrides[$key] ?? $resolvedKind->adapterClass();
            /** @var AppPolicyAdapter $adapter */
            $adapter = $this->container->make($class);
            $this->instances[$key] = $adapter;
        }

        return $this->instances[$key];
    }

    /**
     * Liste tous les AppKind enregistrés.
     *
     * @return AppKind[]
     */
    public function kinds(): array
    {
        return AppKind::cases();
    }
}
