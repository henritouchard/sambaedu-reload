<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Models\ExtensionAuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Story 56.5 (AC5, FR36 complet) — **LECTURE** du journal d'audit des
 * extensions.
 *
 * L'audit FR36 est intégralement ÉCRIT depuis 54.2 (`integrate`/`uninstall`),
 * 56.1 (`source_*`), 56.2 (`install`/`remove`/`install_failed`), 56.3
 * (`update`/`update_failed`) et 56.4 (`scope_revoke`) — il n'avait jamais été
 * LU. Ce service est ce chaînon, et rien d'autre : **AUCUNE écriture**, aucun
 * acte, aucun effet de bord.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  RENDU TOLÉRANT — l'inverse assumé du validateur de manifest
 *
 *  `action` est un string LIBRE par construction (docblock de la migration
 *  54.2 : « l'Epic 56 étend sans migration »). Cette page affichera donc un jour
 *  des actions qu'elle ne connaît pas — écrites par une story future, ou par une
 *  instance plus récente dont la base a été restaurée ici.
 *
 *  Une action absente du mapping est rendue TELLE QUELLE, avec un badge neutre.
 *  C'est l'exact opposé du rejet strict de `manifest_version` : là-bas on VALIDE
 *  un contrat d'entrée (fail-closed obligatoire), ici on AFFICHE de l'historique
 *  déjà écrit — refuser de l'afficher ne protégerait rien et effacerait de la
 *  trace de conformité à l'écran.
 * ══════════════════════════════════════════════════════════════════════════
 *
 * **CE QUI NE DOIT JAMAIS APPARAÎTRE** : URL de source (elle peut porter un
 * `?private_token=…` — règle `last_error` de 56.1), secret, `client_id`,
 * `installed_sha256`. C'est garanti à l'ÉCRITURE par toutes les stories amont
 * (`details` = catégorie courte) ; c'est garanti ici à la LECTURE en ne rendant
 * QUE les colonnes du journal — jamais `source->url`, jamais une relation au-delà
 * des dénormalisations (`extension_key`, `extension_name`, `source_key`,
 * `actor_login`). Ces dénormalisations sont aussi ce qui rend une ligne lisible
 * APRÈS suppression de sa cible (les FK sont `nullOnDelete`).
 *
 * **Rétention : AUCUNE purge automatique — décision assumée (n° 6 de la story).**
 * Le volume est structurellement borné : actes humains, échecs PAR TENTATIVE
 * d'actes humains, et transitions DÉDUPLIQUÉES pour le répétitif planifié
 * (`source_sync_failed` à la transition seulement ; la santé, elle, n'écrit rien
 * du tout). Quelques centaines de lignes par an, pas des millions. Purger un
 * journal de conformité append-only est un acte sensible : on ne le construit pas
 * sans besoin démontré. Si un volume réel dément ce raisonnement, une commande
 * `ext:audit:prune` bornée sera une évolution ADDITIVE.
 *
 * Tri `id` DESC : la table est append-only, donc l'`id` EST l'ordre
 * chronologique — et c'est la clé primaire, donc aucun index à ajouter. Le filtre
 * `action` est servi par l'index existant ; `extension_key` n'est PAS indexé et
 * ce n'en vaut pas la peine sur ce volume (index spéculatif = coût d'écriture
 * permanent pour un gain nul).
 *
 * NFR15 : aucune entité Eloquent ne remonte à la vue — le paginateur est
 * transformé par `through()` en tableaux plats.
 */
class ExtensionAuditJournalService
{
    public const DEFAULT_PER_PAGE = 25;

    /**
     * Page du journal, filtrée et mise en forme.
     *
     * `$action` / `$extensionKey` vides ou `null` ⇒ pas de filtre. Les valeurs
     * viennent d'un `#[Url]` : elles sont traitées comme des entrées quelconques
     * (paramètre lié, jamais concaténé) et ne peuvent produire qu'un résultat
     * vide si elles sont fantaisistes.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function page(
        ?string $action = null,
        ?string $extensionKey = null,
        int $perPage = self::DEFAULT_PER_PAGE,
    ): LengthAwarePaginator {
        $action = trim((string) $action);
        $extensionKey = trim((string) $extensionKey);

        return ExtensionAuditLog::query()
            ->when($action !== '', fn ($query) => $query->where('action', $action))
            ->when($extensionKey !== '', fn ($query) => $query->where('extension_key', $extensionKey))
            ->orderByDesc('id')
            ->paginate(max(1, $perPage))
            ->through(fn (ExtensionAuditLog $row): array => $this->toRow($row));
    }

    /**
     * Actions CONNUES → libellé français, pour le select de filtre et pour le
     * rendu des lignes.
     *
     * L'ordre est celui du cycle de vie, pas l'ordre alphabétique : c'est ce que
     * l'admin a en tête quand il cherche « les installations ».
     *
     * @return array<string, string>
     */
    public function knownActions(): array
    {
        return [
            ExtensionAuditLog::ACTION_INTEGRATE => 'Intégration',
            ExtensionAuditLog::ACTION_UNINSTALL => 'Désinstallation',
            ExtensionAuditLog::ACTION_INSTALL => 'Installation',
            ExtensionAuditLog::ACTION_INSTALL_FAILED => 'Installation en échec',
            ExtensionAuditLog::ACTION_UPDATE => 'Mise à jour',
            ExtensionAuditLog::ACTION_UPDATE_FAILED => 'Mise à jour en échec',
            ExtensionAuditLog::ACTION_REMOVE => 'Retrait',
            ExtensionAuditLog::ACTION_SCOPE_REVOKE => 'Autorisation révoquée',
            ExtensionAuditLog::ACTION_SOURCE_ADD => 'Source ajoutée',
            ExtensionAuditLog::ACTION_SOURCE_ENABLE => 'Source activée',
            ExtensionAuditLog::ACTION_SOURCE_DISABLE => 'Source désactivée',
            ExtensionAuditLog::ACTION_SOURCE_REMOVE => 'Source retirée',
            ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED => 'Catalogue de source refusé',
        ];
    }

    /**
     * Les clés d'extension RÉELLEMENT présentes au journal (pour le select de
     * filtre) — pas celles du registre : une extension supprimée a laissé des
     * lignes lisibles, et l'admin doit pouvoir les retrouver.
     *
     * @return list<string>
     */
    public function extensionKeys(): array
    {
        return ExtensionAuditLog::query()
            ->where('extension_key', '<>', '')
            ->distinct()
            ->orderBy('extension_key')
            ->pluck('extension_key')
            ->map(static fn ($key): string => (string) $key)
            ->values()
            ->all();
    }

    /**
     * Une ligne, telle que la vue la rend — tableau PLAT, aucune relation
     * chargée.
     *
     * @return array<string, mixed>
     */
    private function toRow(ExtensionAuditLog $row): array
    {
        $action = (string) $row->action;
        $known = $this->knownActions();
        $isKnown = array_key_exists($action, $known);

        $extensionKey = (string) $row->extension_key;
        $sourceKey = (string) $row->source_key;

        // Cible : une extension OU une source. Les colonnes dénormalisées
        // suffisent — on ne touche JAMAIS aux relations (une `source->url`
        // rendue ici serait une fuite, et une extension supprimée rendrait la
        // ligne muette).
        if ($extensionKey !== '') {
            $targetKind = 'extension';
            $targetLabel = (string) $row->extension_name !== ''
                ? $row->extension_name.' ('.$extensionKey.')'
                : $extensionKey;
        } elseif ($sourceKey !== '') {
            $targetKind = 'source';
            $targetLabel = $sourceKey;
        } else {
            $targetKind = '';
            $targetLabel = '—';
        }

        return [
            'id' => (int) $row->id,
            'at' => $row->created_at?->format('d/m/Y H:i:s') ?? '—',
            'action' => $action,
            'action_label' => $isKnown ? $known[$action] : $action,
            'action_known' => $isKnown,
            'action_badge' => $this->badgeFor($action, $isKnown),
            'target_kind' => $targetKind,
            'target_label' => $targetLabel,
            'target_key' => $extensionKey !== '' ? $extensionKey : $sourceKey,
            'actor' => (string) ($row->actor_login ?? '') !== '' ? (string) $row->actor_login : '—',
            'is_system' => (string) $row->actor_login === ExtensionAuditLog::ACTOR_SYSTEM,
            'details' => (string) $row->details,
        ];
    }

    /**
     * Classe de badge DaisyUI d'une action.
     *
     * Une action inconnue prend un badge NEUTRE : elle est affichée, sans qu'on
     * prétende savoir si elle est bonne ou mauvaise.
     */
    private function badgeFor(string $action, bool $isKnown): string
    {
        if (! $isKnown) {
            return 'badge-neutral';
        }

        return match ($action) {
            ExtensionAuditLog::ACTION_INSTALL_FAILED,
            ExtensionAuditLog::ACTION_UPDATE_FAILED,
            ExtensionAuditLog::ACTION_SOURCE_SYNC_FAILED => 'badge-error',

            ExtensionAuditLog::ACTION_UNINSTALL,
            ExtensionAuditLog::ACTION_REMOVE,
            ExtensionAuditLog::ACTION_SCOPE_REVOKE,
            ExtensionAuditLog::ACTION_SOURCE_DISABLE,
            ExtensionAuditLog::ACTION_SOURCE_REMOVE => 'badge-warning',

            ExtensionAuditLog::ACTION_INTEGRATE,
            ExtensionAuditLog::ACTION_INSTALL,
            ExtensionAuditLog::ACTION_SOURCE_ADD,
            ExtensionAuditLog::ACTION_SOURCE_ENABLE => 'badge-success',

            default => 'badge-info',
        };
    }
}
