#!/bin/bash
# ============================================================================
# Émission du certificat code-signing agent (PFX) depuis la CA interne
# ============================================================================
# Produit storage/keys/pki/sambaedu-codesign.pfx — le matériel de signature
# Authenticode consommé par agent/build/build.sh (CODESIGN_PFX=…). Émis depuis
# la CA Auth V1 (story 16.10) : la clé CA ne quitte jamais le serveur, seul le
# PFX descend sur la machine de build (cf. agent/README.md §Signature).
#
# IDEMPOTENT : no-op si le PFX existe, que son cert est valide > 30 j ET qu'il
# chaîne vers le ca-root.crt COURANT (une régénération de la CA invalide la
# chaîne sur les postes → ré-émission forcée). `--force` pour ré-émettre.
#
# Appelé automatiquement par scripts/update.sh (ensure_codesign_pfx) — donc
# aussi par install.sh, qui rejoue update.sh en fin d'install. Utilisable
# seul : sudo scripts/emit-codesign-pfx.sh [--force]
#
# Le PFX est exporté SANS mot de passe (il vit dans storage/keys/pki/, 600,
# même niveau de protection que ca-root.key) : build.sh fonctionne sans
# CODESIGN_PASS. *.pfx est gitignoré — jamais committé.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

PKI_DIR="$APP_DIR/storage/keys/pki"
CA_CRT="$PKI_DIR/ca-root.crt"
CA_KEY="$PKI_DIR/ca-root.key"
CS_KEY="$PKI_DIR/codesign.key"
CS_CRT="$PKI_DIR/codesign.crt"
CS_PFX="$PKI_DIR/sambaedu-codesign.pfx"

# Iso ensure_auth_v1_pki : un cert qui expire bientôt est ré-émis d'avance.
RENEWAL_THRESHOLD_DAYS=30
VALIDITY_DAYS=1095
PKI_OWNER="www-admin:www-admin"

FORCE=false
[[ "${1:-}" == "--force" ]] && FORCE=true

log() { echo "[codesign-pfx] $*"; }

# --- Pré-requis : la CA interne doit exister (auth:ca:init / update.sh) -----
if [[ ! -f "$CA_CRT" || ! -f "$CA_KEY" ]]; then
    log "ERREUR : CA interne absente ($CA_CRT / .key)." >&2
    log "Initialiser la PKI d'abord : php artisan auth:ca:init (ou scripts/update.sh)." >&2
    exit 1
fi

# --- Idempotence ------------------------------------------------------------
if [[ "$FORCE" != true && -f "$CS_PFX" && -f "$CS_CRT" ]]; then
    if openssl x509 -in "$CS_CRT" -checkend "$((RENEWAL_THRESHOLD_DAYS * 86400))" -noout >/dev/null 2>&1 \
        && openssl verify -CAfile "$CA_CRT" "$CS_CRT" >/dev/null 2>&1; then
        log "PFX code-signing en place et valide (> ${RENEWAL_THRESHOLD_DAYS} j, chaîne CA OK) — rien à faire."
        exit 0
    fi
    log "PFX présent mais cert expirant ou chaîne CA invalide (CA régénérée ?) — ré-émission."
fi

# --- Sauvegarde de l'existant (convention .bak-<ts> du répertoire PKI) ------
ts="$(date +%Y%m%d%H%M%S)"
for f in "$CS_KEY" "$CS_CRT" "$CS_PFX"; do
    [[ -f "$f" ]] && mv "$f" "$f.bak-$ts"
done

# --- Émission (iso procédure agent/README.md §Signature) ---------------------
log "Émission du certificat code-signing (EKU codeSigning, ${VALIDITY_DAYS} j)..."
csr="$(mktemp "$PKI_DIR/codesign.csr.XXXXXX")"
trap 'rm -f "$csr"' EXIT

openssl req -new -newkey rsa:3072 -nodes -keyout "$CS_KEY" -out "$csr" \
    -subj "/C=FR/O=SambaEdu/OU=SambaEdu Local PKI/CN=SambaEdu Code Signing" 2>/dev/null

openssl x509 -req -in "$csr" -CA "$CA_CRT" -CAkey "$CA_KEY" \
    -CAcreateserial -days "$VALIDITY_DAYS" -sha256 -out "$CS_CRT" \
    -extfile <(printf "extendedKeyUsage=codeSigning\nkeyUsage=digitalSignature\nbasicConstraints=CA:FALSE") 2>/dev/null

openssl pkcs12 -export -out "$CS_PFX" -inkey "$CS_KEY" -in "$CS_CRT" \
    -certfile "$CA_CRT" -passout pass:

# --- Permissions (iso répertoire : owner www-admin, clés 600) ----------------
chown "$PKI_OWNER" "$CS_KEY" "$CS_CRT" "$CS_PFX" 2>/dev/null || true
chmod 600 "$CS_KEY" "$CS_PFX"
chmod 644 "$CS_CRT"

openssl verify -CAfile "$CA_CRT" "$CS_CRT" >/dev/null
log "PFX émis : $CS_PFX (sans mot de passe, 600, jamais committé — *.pfx gitignoré)."
log "Build signé depuis la machine de build :"
log "  scp root@<serveur>:$CS_PFX ~/ && CODESIGN_PFX=~/sambaedu-codesign.pfx CODESIGN_CA=<ca-root.crt> agent/build/build.sh"
