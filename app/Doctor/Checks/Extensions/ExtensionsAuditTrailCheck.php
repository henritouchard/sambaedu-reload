<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Extensions;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use App\Models\ExtensionAuditLog;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Le journal d'audit des extensions est-il COMPLET ?
 *
 * `ExtensionInstallService::fail()` avale un échec d'écriture d'audit —
 * comportement volontairement CONSERVÉ : un refus déjà compensé ne doit jamais
 * redevenir une exception nue. Ce qui manquait, c'était le SIGNAL, côté santé
 * plutôt qu'en grep de logs. Le voici.
 *
 * Ce check ne lit pas la table : il lit le MARQUEUR
 * ({@see ExtensionAuditLog::writeFailureMarker()}, cache FICHIER). C'est
 * délibéré — si une écriture d'audit échoue, la cause plausible est la base de
 * données, et un signal stocké en base coulerait avec elle.
 *
 *  - `ok` : aucun marqueur. Le journal est réputé complet.
 *  - `error` : au moins une ligne d'audit a été perdue depuis une date connue.
 *    C'est un `error` et pas un `warn` : le journal d'audit est une exigence de
 *    conformité, et « le journal est peut-être incomplet » n'est pas une
 *    dégradation acceptable — c'est une information que l'exploitant doit traiter.
 *
 * L'acquittement se fait depuis `/admin/extensions/journal` (bouton +
 * confirmation), pas ici : un check ne mute rien (règle d'or
 * {@see EnvironmentCheck}).
 */
final class ExtensionsAuditTrailCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'extensions';
    }

    public function name(): string
    {
        return 'Extensions (journal d\'audit)';
    }

    public function run(): CheckResult
    {
        try {
            $marker = ExtensionAuditLog::writeFailureMarker();
        } catch (Throwable $e) {
            // La lecture est déjà défensive côté modèle ; cette ceinture
            // supplémentaire garantit qu'aucun chemin ne fait lever un check
            // (patron `it_all_new_checks_never_throw`).
            return CheckResult::warn(
                sprintf('signal d\'audit illisible : %s', substr($e->getMessage(), 0, 120)),
                'Vérifier les droits du cache fichier (storage/framework/cache).',
            );
        }

        if ($marker === null) {
            return CheckResult::ok('aucune écriture d\'audit perdue.');
        }

        return CheckResult::error(
            sprintf(
                'le journal d\'audit peut être INCOMPLET : %d écriture(s) perdue(s) depuis le %s (dernière : %s).',
                $marker['count'],
                $this->humanize($marker['first_at']),
                $this->humanize($marker['last_at']),
            ),
            'Chercher « Trace d\'échec NON ÉCRITE » dans les logs Laravel (storage/logs) pour savoir CE qui a été perdu, '
                .'vérifier la santé de la base, puis acquitter le signal depuis /admin/extensions/journal.',
        );
    }

    /** Date ISO du marqueur → format lisible ; valeur inattendue rendue telle quelle. */
    private function humanize(string $iso): string
    {
        if ($iso === '') {
            return 'date inconnue';
        }

        try {
            return Carbon::parse($iso)->format('d/m/Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }
}
