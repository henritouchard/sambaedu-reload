{!! $shebang !!}
params
param mac ${net0/mac}
param uuid ${uuid}
param product ${product}
{{-- Story 4.10 - propagation creds dans le preambule handshake : si un endpoint
     protege (admin, enrollment...) recoit un mac/uuid vide et bascule ici, on
     re-chaine en conservant username/password (deja saisis via `login`). Vides
     au premier boot (pas encore de login) -> ignores par l'auth. Sans ca, toute
     bascule handshake d'un menu authentifie perdait l'auth (= retour boot). --}}
param username ${username}
param password ${password:base64}
chain --replace --autofree {{ $chainTarget ?? 'boot' }}##params
 || sleep 10
