<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Listing expiré — SambaEdu</title>
</head>
<body style="font-family: sans-serif; max-width: 600px; margin: 4rem auto; padding: 2rem; border: 1px solid #ddd;">
    <h1 style="color: #c0392b;">Listing expiré</h1>
    <p>
        Le listing de mots de passe temporaire est introuvable ou expiré
        (durée de vie : 20 minutes).
    </p>
    <p>
        Pour générer à nouveau un listing, effectuez une nouvelle réinitialisation
        des mots de passe depuis la page <strong>Utilisateurs</strong>.
    </p>
    <p>
        <a href="{{ url('/app/users') }}">Retour à la liste des utilisateurs</a>
    </p>
</body>
</html>
