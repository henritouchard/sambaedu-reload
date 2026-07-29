{{--
    Story 55.3 — Page d'erreur de l'app-témoin SSO.

    ⚠️ Blade AUTONOME (patron `oidc/authorize-error.blade.php`) : aucun layout,
    aucun composant, aucun `@vite`. Le chemin d'erreur ne doit dépendre d'aucun
    rendu applicatif.

    ⚠️ Ce que cette page ne dit JAMAIS : la cause fine côté fournisseur, le
    `client_id`, l'issuer, ni le contenu du jeton refusé. Elle rappelle le seul
    code normalisé du témoin — le diagnostic est dans `storage/logs/oidc/`.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Démo SSO indisponible — SambaEdu</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f5f6f8;
            color: #1f2430;
        }
        .card {
            max-width: 34rem;
            margin: 1.5rem;
            padding: 2rem;
            background: #ffffff;
            border: 1px solid #e2e5ea;
            border-radius: .75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
        }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { line-height: 1.55; margin: 0 0 .75rem; }
        .muted { color: #5b6474; font-size: .875rem; }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: #f0f1f4; padding: .1rem .35rem; border-radius: .25rem; font-size: .875rem;
        }
        pre {
            background: #f0f1f4; padding: .75rem; border-radius: .35rem;
            font-size: .8125rem; overflow-x: auto;
        }
        a.back { display: inline-block; margin-top: 1rem; color: #1c4fd8; }
        @media (prefers-color-scheme: dark) {
            body { background: #14171d; color: #e6e8ec; }
            .card { background: #1c2027; border-color: #2c313a; }
            .muted { color: #9aa2b1; }
            code, pre { background: #262b33; }
            a.back { color: #7aa2ff; }
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Démo SSO indisponible</h1>

        @if ($notProvisioned)
            <p>
                Cette application de démonstration n'a pas encore été activée sur ce serveur.
                Aucun jeton d'identité n'a été demandé ni délivré.
            </p>
            <p class="muted">L'administrateur du serveur l'active par :</p>
            <pre>php artisan oidc:witness:enable</pre>
        @else
            <p>
                La connexion n'a pas pu être menée à son terme. Par sécurité, cette page
                <strong>n'affiche aucune information d'identité</strong> : un jeton qui n'a pas
                été intégralement vérifié n'est jamais exploité.
            </p>
        @endif

        <p class="muted">
            Signalez cette erreur à l'administrateur du serveur en lui indiquant le code
            <code>{{ $errorCode }}</code>. Le détail figure dans le journal
            <code>storage/logs/oidc/</code>.
        </p>

        <a class="back" href="/">← Retour au lanceur</a>
    </main>
</body>
</html>
