<?php

namespace App\Components\Traits;

/**
 * Trait pour gérer un bouton retour vers l'onglet (ou la page) d'origine.
 *
 * Le lien d'entrée vers une page de détail transporte l'URL de provenance dans
 * le query param `from` (chemin relatif, ex. `/app/parc?tab=machines`). La page
 * de détail expose ce paramètre en `#[Url]` (pour survivre aux updates Livewire
 * qui perdent la query string) et résout son bouton retour via {@see resolveBack()},
 * avec repli sur une URL canonique.
 *
 * Usage :
 *   use App\Components\Traits\WithReturnBack;
 *
 *   new class extends Component {
 *       use WithReturnBack;
 *
 *       #[Url]
 *       public ?string $from = null;
 *
 *       public function backUrl(): string
 *       {
 *           return $this->resolveBack(route('app.parc.index', ['tab' => 'machines']));
 *       }
 *   };
 *
 * Côté lien d'entrée (partial qui liste), construire `from` avec une URL RELATIVE
 * via route(..., absolute: false) — stable même lors d'un re-render Livewire (où
 * request()->getRequestUri() vaudrait /livewire/update) :
 *   route('app.parc.machines.show', [
 *       'id' => $m->id,
 *       'from' => route('app.parc.index', ['tab' => 'machines'], false),
 *   ])
 */
trait WithReturnBack
{
    /**
     * Retourne l'URL de retour à utiliser : la provenance `$this->from` si elle
     * est un chemin relatif same-origin valide, sinon l'URL canonique fournie.
     */
    public function resolveBack(string $fallback): string
    {
        return $this->safeReturn($this->from) ?? $fallback;
    }

    /**
     * Valide qu'une valeur `from` est un chemin relatif same-origin sûr.
     *
     * Accepté : commence par un seul `/` (ex. `/app/parc?tab=machines`).
     * Rejeté  : `null`, chaîne vide, ou toute forme pouvant devenir réseau —
     *           `//host` (protocol-relative) et `/\host` (le `\` est normalisé
     *           en `/` par les navigateurs). Empêche toute redirection ouverte.
     */
    public function safeReturn(?string $from): ?string
    {
        if (! is_string($from) || $from === '') {
            return null;
        }

        // Doit commencer par un `/` unique — ni `//…`, ni `/\…`.
        if ($from[0] !== '/') {
            return null;
        }

        $second = $from[1] ?? '';
        if ($second === '/' || $second === '\\') {
            return null;
        }

        return $from;
    }
}
