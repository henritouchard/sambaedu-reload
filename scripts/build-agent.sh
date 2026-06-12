#!/bin/bash
# ============================================================================
# Build serveur de l'agent Go signé (binaire Windows, story 24.5)
# ============================================================================
# Enrobe agent/build/build.sh pour un build CÔTÉ SERVEUR SE5 : le PFX
# code-signing (émis par scripts/emit-codesign-pfx.sh) ne quitte jamais le
# serveur, et l'artefact signé est produit là où l'Epic 25 le distribuera
# (storage/agent/releases/). Amorce ses propres prérequis :
#   - toolchain Go épinglée (version + SHA-256), installée dans /usr/local/go
#     si absente ;
#   - osslsigncode via apt si absent.
#
# IDEMPOTENT : no-op si le binaire dist/ existe, qu'aucune source de agent/
# n'est plus récente que lui et que le cert code-signing n'a pas changé.
# `--force` pour rebuilder. Appelé par scripts/update.sh (ensure_agent_build)
# — donc aussi par install.sh. Utilisable seul :
#   sudo scripts/build-agent.sh [--force]
#
# Hors-ligne : TIMESTAMP_URL= est passé vide (pas d'horodatage — la signature
# reste valide tant que le cert l'est ; iso build lab).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"

PKI_DIR="$APP_DIR/storage/keys/pki"
CS_PFX="$PKI_DIR/sambaedu-codesign.pfx"
CS_CRT="$PKI_DIR/codesign.crt"
CA_CRT="$PKI_DIR/ca-root.crt"
DIST_DIR="$APP_DIR/agent/build/dist"

# Toolchain Go épinglée — bump volontaire uniquement (mettre à jour le couple
# version + SHA-256 ensemble, source : https://go.dev/dl/).
GO_VERSION="1.26.4"
GO_SHA256="1153d3d50e0ac764b447adfe05c2bcf08e889d42a02e0fe0259bd47f6733ad7f"
GO_PREFIX="/usr/local/go"

FORCE=false
[[ "${1:-}" == "--force" ]] && FORCE=true

log() { echo "[build-agent] $*"; }

# --- Pré-requis ---------------------------------------------------------------
if [[ ! -f "$APP_DIR/agent/go.mod" ]]; then
    log "agent/go.mod absent — rien à builder."
    exit 0
fi
if [[ ! -f "$CS_PFX" ]]; then
    log "ERREUR : PFX code-signing absent ($CS_PFX)." >&2
    log "L'émettre d'abord : scripts/emit-codesign-pfx.sh (ou scripts/update.sh)." >&2
    exit 1
fi

# --- Toolchain Go (bootstrap épinglé) ------------------------------------------
GO_BIN=""
if command -v go >/dev/null 2>&1; then
    GO_BIN="$(command -v go)"
elif [[ -x "$GO_PREFIX/bin/go" ]]; then
    GO_BIN="$GO_PREFIX/bin/go"
else
    log "Toolchain Go absente — installation go${GO_VERSION} dans $GO_PREFIX..."
    tarball="$(mktemp /tmp/go.XXXXXX.tar.gz)"
    trap 'rm -f "$tarball"' EXIT
    curl -fsSL "https://go.dev/dl/go${GO_VERSION}.linux-amd64.tar.gz" -o "$tarball"
    echo "$GO_SHA256  $tarball" | sha256sum -c - >/dev/null
    rm -rf "$GO_PREFIX"
    tar -C /usr/local -xzf "$tarball"
    GO_BIN="$GO_PREFIX/bin/go"
    log "Toolchain installée : $("$GO_BIN" version)"
fi

# --- osslsigncode ---------------------------------------------------------------
if ! command -v osslsigncode >/dev/null 2>&1; then
    log "osslsigncode absent — installation apt..."
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq osslsigncode >/dev/null
fi

# --- Idempotence : sources / cert plus récents que le binaire ? -----------------
version="$(sed -n 's/.*Version = "\(.*\)".*/\1/p' "$APP_DIR/agent/shared/version.go" | head -1)"
binary="$DIST_DIR/sambaedu-agent-${version}.exe"

if [[ "$FORCE" != true && -f "$binary" ]]; then
    stale="$(find "$APP_DIR/agent" -type f \( -name '*.go' -o -name 'go.mod' -o -name 'go.sum' -o -name 'build.sh' \) -newer "$binary" -print -quit)"
    if [[ -z "$stale" && ! "$CS_CRT" -nt "$binary" ]]; then
        log "Binaire à jour ($binary) — rien à faire."
        exit 0
    fi
    log "Rebuild : ${stale:-cert code-signing} plus récent que le binaire."
fi

# --- Build + signature (la CA du serveur, le PFX ne sort pas d'ici) -------------
log "Build agent Go ${version} signé (CA interne)..."
GO="$GO_BIN" \
CODESIGN_PFX="$CS_PFX" \
CODESIGN_CA="$CA_CRT" \
TIMESTAMP_URL="${TIMESTAMP_URL:-}" \
    bash "$APP_DIR/agent/build/build.sh"

log "Artefact signé : $binary"
