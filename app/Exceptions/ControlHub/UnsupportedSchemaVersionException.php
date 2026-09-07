<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use RuntimeException;

/**
 * Levée lorsqu'un payload de contrat amont (controlHub) déclare une
 * `schema_version` (schéma d'ÉCHANGE amont→SE5) **non supportée** par cette instance SE5.
 *
 * Cause UNIQUE : la version **déclarée** (chaîne non vide) n'appartient pas à
 * {@see \App\Services\ControlHub\ControlHubContractSchema::SUPPORTED_VERSIONS} (égalité
 * stricte). Une version absente (`null` ou chaîne vide) n'est PAS une cause de rejet (défaut = version
 * courante, rétro-compat) ; une version supportée est acceptée. Seule une version déclarée
 * et incompatible déclenche ce rejet.
 *
 * Type **dédié et distinct** de {@see InvalidUpstreamContractException} :
 * - {@see InvalidUpstreamContractException} = rejet de **CONTENU** (enum hors domaine, incohérence
 *   de cible, intégrité référentielle `label_name`) ;
 * - {@see UnsupportedSchemaVersionException} (ici) = rejet de **FORMAT de VERSION** du schéma
 *   d'échange. Les deux partagent le **patron** : levée **avant toute écriture** (validation pure
 *   en amont de la transaction d'ingestion) ⇒ aucune écriture partielle (rollback
 *   total trivial). Aucune des deux n'étend l'autre.
 *
 * ⚠️ NE PAS CONFONDRE avec `ContractV1` (contrat AGENT, desired-state émis VERS l'agent, figé) :
 * le versionnement d'échange amont↔SE5 est **serveur-only**, invisible de l'agent.
 */
final class UnsupportedSchemaVersionException extends RuntimeException
{
    /**
     * @param  string  $declared  La version déclarée par le payload (non supportée).
     * @param  list<string>  $supported  Les versions supportées par cette instance SE5.
     */
    private function __construct(
        public readonly string $declared,
        public readonly array $supported,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Construit l'exception en nommant la version reçue ET les versions supportées.
     *
     * @param  list<string>  $supported
     */
    public static function for(string $declared, array $supported): self
    {
        $supportedList = $supported === [] ? '(aucune)' : implode(', ', $supported);

        return new self(
            $declared,
            $supported,
            "Version de schéma d'échange amont incompatible — reçue « {$declared} » ; supportées : {$supportedList}",
        );
    }

    /**
     * La version déclarée par le payload (rejetée).
     */
    public function declared(): string
    {
        return $this->declared;
    }

    /**
     * Les versions supportées par cette instance SE5.
     *
     * @return list<string>
     */
    public function supported(): array
    {
        return $this->supported;
    }
}
