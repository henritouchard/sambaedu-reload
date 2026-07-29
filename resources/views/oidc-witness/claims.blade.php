{{--
    Story 55.3 — Page de l'app-témoin SSO : les claims VÉRIFIÉS, et rien d'autre.

    ⚠️ Blade DÉLIBÉRÉMENT AUTONOME : aucun layout SE5, aucun composant, aucun
    `@vite`. Une extension n'a pas la navbar de SE5 — elle a sa propre page.
    C'est aussi ce qui rend cette vue insensible à tout composant applicatif
    (leçon de la review 54.3).

    ⚠️ Tout ce qui s'affiche ici vient d'un id_token dont la signature, l'issuer,
    l'audience, l'expiration, le nonce et le jti ont été vérifiés
    (`WitnessIdTokenVerifier`). Aucune valeur n'est lue en base.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Démo SSO — SambaEdu</title>
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
        h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
        h2 { font-size: .8rem; text-transform: uppercase; letter-spacing: .06em; color: #5b6474; margin: 1.5rem 0 .5rem; }
        p { line-height: 1.55; margin: 0 0 .75rem; }
        dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: .4rem 1rem; }
        dt { color: #5b6474; font-size: .875rem; }
        dd { margin: 0; }
        .muted { color: #5b6474; font-size: .875rem; }
        .absent { color: #8a5300; font-style: italic; }
        ul.groups { margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: .35rem; }
        ul.groups li {
            background: #eef1f6; border: 1px solid #dde2ea; border-radius: .35rem;
            padding: .1rem .45rem; font-size: .875rem;
        }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: #f0f1f4; padding: .1rem .35rem; border-radius: .25rem; font-size: .875rem;
        }
        a.back { display: inline-block; margin-top: 1.5rem; color: #1c4fd8; }
        @media (prefers-color-scheme: dark) {
            body { background: #14171d; color: #e6e8ec; }
            .card { background: #1c2027; border-color: #2c313a; }
            .muted, dt, h2 { color: #9aa2b1; }
            .absent { color: #e0b062; }
            ul.groups li { background: #262b33; border-color: #333944; }
            code { background: #262b33; }
            a.back { color: #7aa2ff; }
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Bonjour {{ $name !== '' ? $name : $subject }}</h1>
        <p class="muted">Vous êtes authentifié auprès de cette application par le SSO SambaEdu, sans avoir ressaisi vos identifiants.</p>

        <h2>Vos informations transmises</h2>
        <dl>
            <dt>Identifiant</dt>
            <dd><code>{{ $subject }}</code></dd>

            <dt>Nom</dt>
            <dd>
                @if ($name !== '')
                    {{ $name }}
                @else
                    <span class="absent">(non transmis)</span>
                @endif
            </dd>

            <dt>Rôle</dt>
            <dd>
                @if ($role !== '')
                    {{ $role }}
                @else
                    <span class="absent">(non résolu)</span>
                @endif
            </dd>

            <dt>Groupes</dt>
            <dd>
                @if (! $hasGroupsClaim)
                    <span class="absent">(non transmis)</span>
                @elseif ($groups === [])
                    <span class="absent">(aucun)</span>
                @else
                    <ul class="groups">
                        @foreach ($groups as $group)
                            <li>{{ $group }}</li>
                        @endforeach
                    </ul>
                @endif
            </dd>
        </dl>

        <p class="muted" style="margin-top:1.5rem">
            Ces valeurs proviennent d'un jeton d'identité signé, vérifié par cette application
            (signature, émetteur, destinataire, expiration, usage unique). Cette page ne consulte
            ni la base de données ni l'annuaire de SambaEdu.
        </p>

        <a class="back" href="/">← Retour au lanceur</a>
    </main>
</body>
</html>
