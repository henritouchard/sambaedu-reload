<?php

declare(strict_types=1);

namespace App\Dto\Overlay;

/**
 * DTO immuable du payload overlay renvoyé par
 * `GET /api/v1/workstation-config/overlay`.
 *
 * Frontière neutre entre le serveur et l'outil overlay (Rainmeter / Conky) :
 * structure plate, clés stables, tableau d'objets → parsable par Rainmeter
 * JsonParser (`[alerts][0][title]`) ET Conky/jq (`.alerts[0].title`).
 *
 * Cf. spike `spike-wallpaper-overlay-tools-2026-06-09.md` §6ter.
 */
final readonly class OverlayPayload
{
    /**
     * @param  string  $schema       version du contrat (`config('overlay.schema')`).
     * @param  string  $generatedAt  ISO-8601 de génération.
     * @param  int  $ttlSeconds      cadence de poll conseillée.
     * @param  array{fullname:string,login:string,is_admin:bool,main_type:?string}  $identity
     * @param  array{name:string,room:string,os:string}  $machine
     * @param  list<OverlayAlert>  $alerts
     */
    public function __construct(
        public string $schema,
        public string $generatedAt,
        public int $ttlSeconds,
        public array $identity,
        public array $machine,
        public array $alerts,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'generated_at' => $this->generatedAt,
            'ttl_seconds' => $this->ttlSeconds,
            'identity' => $this->identity,
            'machine' => $this->machine,
            'alerts' => array_map(static fn (OverlayAlert $a): array => $a->toArray(), $this->alerts),
        ];
    }
}
