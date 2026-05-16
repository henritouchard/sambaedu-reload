# Fixtures `auth-v1/` — Paire RS256 pour tests UNIQUEMENT

> ⚠️ **TEST ONLY — NEVER USE IN PRODUCTION**

Cette paire de clés RS256 (2048 bits) a été générée **une fois** pour
permettre les tests sign+verify déterministes du namespace `App\Auth\V1`
(Story 16.10).

- `private.pem` — clé privée RSA 2048 bits, format PKCS#8 PEM (clear, **pas
  chiffrée**). Suffisante pour signer un JWT RS256 via
  `Firebase\JWT\JWT::encode()`.
- `public.pem` — clé publique correspondante, format SubjectPublicKeyInfo
  PEM. Utilisée par `Firebase\JWT\JWT::decode()` pour valider la signature.

## Garde-fous runtime

Le `WorkstationJwtIssuer` refuse de signer avec ces fixtures si :

- `APP_ENV !== 'testing'` (ni `'local'`)
- ET `config('auth_v1.safety.forbid_test_keys_in_production')` = `true`
  (par défaut)

Voir `app/Auth/V1/Jwt/WorkstationJwtIssuer::loadPrivateKey()`.

## Régénération

Si jamais ces fixtures doivent être régénérées (rotation pour des raisons
de QA, par exemple) :

```bash
# Dans la racine du projet
php -r '
$res = openssl_pkey_new(["private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA]);
$priv = "";
openssl_pkey_export($res, $priv);
file_put_contents("tests/fixtures/auth-v1/private.pem", $priv);
$details = openssl_pkey_get_details($res);
file_put_contents("tests/fixtures/auth-v1/public.pem", $details["key"]);
'
```

Tous les tests `tests/Unit/Auth/V1/Jwt/*` qui pinnent des JWT pré-générés
devront être ré-encodés avec la nouvelle paire.

## Pourquoi commiter ?

- **Déterminisme** : pas de génération à la volée dans `setUp()` (50-100ms
  par classe test = +∞ flakiness sur CI lent).
- **Iso-pattern Laravel** : les apps publient parfois des `tests/fixtures/secret.txt`
  factices. Acceptable car la clé est rejetée au runtime hors testing.
- **Story 16.10 — Dev Notes** : décision SM explicite (« Recommandation SM :
  commiter les fixtures (plus simple, plus déterministe, moins de flakiness) »).
