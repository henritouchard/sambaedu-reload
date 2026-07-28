{{--
    Story 55.1 — Page d'erreur du flux d'autorisation OIDC (refus NON
    redirigeable : client inconnu/révoqué, `redirect_uri` non déclarée).

    ⚠️ Blade DÉLIBÉRÉMENT AUTONOME : aucun layout, aucun composant, aucun
    `@vite`. Un chemin d'erreur d'authentification ne doit dépendre d'AUCUN
    rendu applicatif — leçon de la review 54.3, où un composant de navbar
    interrogeant la base faisait tomber toutes les pages authentifiées. Ici, la
    seule chose qui doit fonctionner, c'est l'affichage du refus.

    ⚠️ Ce que cette page ne dit JAMAIS : la liste des `redirect_uris`
    déclarées, l'existence ou non du client, le nom de l'extension. Elle est
    servie à un visiteur arrivé par une URL fabriquée par un tiers ; tout
    détail du registre serait une fuite. Le diagnostic précis est dans
    `storage/logs/oidc/`, avec le code d'erreur normalisé rappelé ci-dessous.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connexion impossible — SambaEdu</title>
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
            background: #f0f1f4;
            padding: .1rem .35rem;
            border-radius: .25rem;
            font-size: .875rem;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #14171d; color: #e6e8ec; }
            .card { background: #1c2027; border-color: #2c313a; }
            .muted { color: #9aa2b1; }
            code { background: #262b33; }
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Connexion impossible</h1>

        <p>
            L'application qui vous a redirigé vers SambaEdu n'a pas pu être identifiée,
            ou son adresse de retour n'est pas celle qui a été déclarée.
        </p>

        <p>
            Par sécurité, SambaEdu ne vous renvoie pas vers l'adresse demandée et
            n'a délivré <strong>aucun jeton d'identité</strong>.
        </p>

        <p class="muted">
            Signalez cette erreur à l'administrateur du serveur en lui indiquant le code
            <code>{{ $errorCode }}</code>. Le détail figure dans le journal
            <code>storage/logs/oidc/</code>.
        </p>
    </main>
</body>
</html>
