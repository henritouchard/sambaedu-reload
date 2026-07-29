<?php

declare(strict_types=1);

namespace App\Services\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\ExtensionLifecycleException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Story 54.2 (FR6/FR9/FR10-link, NFR8, FR36 socle) — Service du CYCLE DE VIE
 * d'une extension : les deux transitions `available ⇄ integrated` du type
 * `link`, et leur trace d'audit.
 *
 * **Seul écrivain de `extensions.status` du projet.** `status` reste
 * VOLONTAIREMENT hors du `$fillable` d'{@see Extension} (décision 54.1
 * reconduite) : ajouter `status` au `$fillable` rouvrirait la trappe de
 * mass-assignment pour TOUS les chemins existants et futurs — au premier chef
 * le `fill()` de l'upsert de {@see ExtensionCatalogService::syncBundled()},
 * qu'un manifest embarqué portant une clé `status` parasite redeviendrait
 * dangereux. La mutation se fait donc par ASSIGNATION DE PROPRIÉTÉ explicite,
 * confinée à ce service nommé et testé :
 *
 * ```php
 * $extension->status = ExtensionStatus::Integrated;
 * $extension->save();
 * ```
 *
 * **Pourquoi un service SÉPARÉ de {@see ExtensionCatalogService}** : le
 * catalogue a été reviewé avec l'invariant « `syncBundled()` n'écrit jamais
 * `status` » (invariant #2) et un prune qui respecte les intégrées
 * (invariant #4) — y loger les transitions brouillerait la propriété « ce
 * fichier ne mute jamais l'état d'intégration ». Séparer catalogue (lecture +
 * sync source) et cycle de vie (transitions + audit) garde les invariants
 * #1-#5 de 54.1 intacts et donne à l'Epic 56 son point d'extension naturel (le
 * moteur `app` étendra le lifecycle, jamais le catalogue).
 *
 * **Invariant NFR8 : no-op ⇒ ZÉRO ligne d'audit.** Intégrer une extension déjà
 * `integrated` (resp. désinstaller une déjà `available`) est un no-op propre :
 * aucune écriture (pas même `updated_at`), aucune ligne d'audit — le journal
 * trace des TRANSITIONS RÉELLES, pas des clics ; sinon un double-clic
 * fabriquerait de fausses entrées d'historique.
 *
 * **Atomicité acte ↔ trace.** Chaque transition s'exécute dans un
 * `DB::transaction()` qui englobe la mutation de `status` ET
 * `ExtensionAuditLog::log()` : un acte sans sa trace ne peut pas exister (si
 * l'écriture de la trace échoue, la mutation est annulée par le rollback).
 * L'extension est relue **dans** la transaction avec `lockForUpdate()` avant
 * de décider : deux admins simultanés ⇒ le second voit l'état final et
 * no-op au lieu de produire une double ligne d'audit (neutre sur SQLite de
 * test, effectif sur PostgreSQL en prod). ⚠️ Pas de `Cache::lock()` (APCu
 * n'a pas de support de lock dans ce projet) — le verrou DB suffit et reste
 * dans la transaction.
 *
 * **Fail-closed** : identifiant inconnu ou type ≠ `link` lèvent
 * {@see ExtensionLifecycleException}, attrapée par le SFC appelant →
 * `toastError`, jamais une 500. Un type `app` est refusé explicitement — le
 * service ne connaît aucun moteur d'installation avant l'Epic 56 (AR1).
 *
 * NFR15 : les deux méthodes publiques renvoient des **tableaux plats** — rien
 * d'Eloquent ne remonte à un SFC Livewire.
 *
 * L'acteur (`User $actor`) est passé en paramètre par l'appelant — le service
 * ne lit jamais `auth()` lui-même, il reste testable en isolation.
 */
class ExtensionLifecycleService
{
    /**
     * Intègre une extension `link` : `available → integrated`, immédiat,
     * aucune installation de composants.
     *
     * @return array{changed: bool, status: string}
     */
    public function integrate(int $extensionId, User $actor): array
    {
        return $this->transition($extensionId, $actor, ExtensionStatus::Integrated, ExtensionAuditLog::ACTION_INTEGRATE);
    }

    /**
     * Désinstalle une extension `link` : `integrated → available`. Une `link`
     * n'a jamais installé de composant — la désinstallation ne « nettoie »
     * rien, c'est un pur retour à l'état disponible.
     *
     * @return array{changed: bool, status: string}
     */
    public function uninstall(int $extensionId, User $actor): array
    {
        return $this->transition($extensionId, $actor, ExtensionStatus::Available, ExtensionAuditLog::ACTION_UNINSTALL);
    }

    /**
     * @return array{changed: bool, status: string}
     */
    private function transition(int $extensionId, User $actor, ExtensionStatus $target, string $action): array
    {
        return DB::transaction(function () use ($extensionId, $actor, $target, $action): array {
            /** @var Extension|null $extension */
            $extension = Extension::query()->lockForUpdate()->find($extensionId);

            if ($extension === null) {
                throw ExtensionLifecycleException::unknownExtension($extensionId);
            }

            if ($extension->type !== ExtensionType::Link) {
                throw ExtensionLifecycleException::unsupportedType($extension->type?->value ?? 'inconnu');
            }

            $current = $extension->status ?? ExtensionStatus::Available;

            // NFR8 — état déjà atteint = no-op propre : ni écriture, ni audit.
            if ($current === $target) {
                return ['changed' => false, 'status' => $target->value];
            }

            // ── FAIL-CLOSED DE L'INTÉGRATION (56.1, review #1) ────────────
            // Masquer une extension dans la bibliothèque ne suffit pas : la
            // méthode Livewire `integrate(<id>)` est publique et prend un
            // identifiant arbitraire. Sans cette garde, une extension d'une
            // source GELÉE ou dont la signature ne se vérifie plus (`error`)
            // restait intégrable — et la modale d'avertissement « source
            // tierce » (AC2) restait contournable. La règle est celle du
            // modèle, la MÊME que celle qui décide de l'affichage.
            //
            // Uniquement sur `available → integrated` : la désinstallation
            // reste ouverte quoi qu'il arrive à la source (rompre le lien fige
            // l'état, il ne piège pas l'admin).
            if ($target === ExtensionStatus::Integrated) {
                $source = $extension->source;

                if ($source === null || ! $source->offersAvailableExtensions()) {
                    throw ExtensionLifecycleException::sourceNoLongerOffers((string) $extension->name);
                }
            }

            $extension->status = $target;
            $extension->save();

            ExtensionAuditLog::log(
                extensionId: $extension->id,
                extensionKey: $extension->key,
                extensionName: $extension->name,
                action: $action,
                actorUserId: $actor->id,
                actorLogin: $actor->login,
            );

            return ['changed' => true, 'status' => $target->value];
        });
    }
}
