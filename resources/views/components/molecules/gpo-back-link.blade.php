{{--
    Composant Blade réutilisable — Fil d'Ariane "Retour à la GPO" (Story 16.3a, AC4.3).

    Affiché uniquement si l'URL courante contient ?from_gpo={guid}.
    Le displayName est résolu via GpoService::get($guid) (appel synchrone ~1 s — cas rare).
    Fallback générique si GpoService::get() retourne null ou lève une exception.

    Usage : <x-molecules.gpo-back-link />
    Le composant lit lui-même la query string — pas de props requises.
--}}
@php
    use Illuminate\Support\Facades\Request;

    // Récupération du paramètre `from_gpo` avec fallback Referer (#2 review 16.3a).
    //
    // En Livewire 3, après un wire:click sur la page hôte, la requête devient
    // `POST /livewire/update` SANS la query string d'origine — `Request::query()`
    // retourne alors null et le breadcrumb disparaîtrait. Solution : parser le
    // Referer (URL de la page hôte qui a déclenché l'update Livewire).
    //
    // Garde défensive (#3 review 16.3a) : `Request::query` peut retourner un
    // tableau si `?from_gpo[]=...`. On n'accepte que des strings non vides.
    $extractFromGpo = function (mixed $value): ?string {
        return is_string($value) && $value !== '' ? $value : null;
    };

    $guid = $extractFromGpo(Request::query('from_gpo'));

    if ($guid === null) {
        // Fallback : parser la query string du Referer (Livewire update).
        $referer = url()->previous();
        $query = is_string($referer) ? parse_url($referer, PHP_URL_QUERY) : null;
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $guid = $extractFromGpo($params['from_gpo'] ?? null);
        }
    }

    $displayName = null;

    if ($guid !== null) {
        try {
            /** @var \App\Gpo\Services\GpoService $gpoService */
            $gpoService = app(\App\Gpo\Services\GpoService::class);
            $gpoObj = $gpoService->get($guid);
            $displayName = $gpoObj?->displayName ?? null;
        } catch (\Throwable) {
            // Fallback silencieux (AC4.3) — latence ou GPO supprimée
            $displayName = null;
        }
    }
@endphp

@if ($guid !== null && $displayName)
    {{-- Lien complet avec displayName résolu.
         Strip accolades : Laravel/Symfony UrlGenerator ré-interprète les `{` `}` de la
         valeur comme placeholders. La regex de la route accepte les 2 formes. --}}
    <a href="{{ route('admin.gpo.show', ['guid' => trim((string) $guid, '{}')]) }}" class="btn btn-ghost btn-sm">
        <i class="fa-solid fa-arrow-left"></i>
        Retour à la GPO «{{ $displayName }}»
    </a>
@elseif ($guid !== null)
    {{-- Fallback générique : guid présent mais GPO introuvable (AC4.3) --}}
    <a href="{{ route('admin.gpo.index') }}" class="btn btn-ghost btn-sm">
        <i class="fa-solid fa-arrow-left"></i>
        Retour à la liste des GPOs
    </a>
@endif
