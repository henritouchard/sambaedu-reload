#!/bin/bash
# =============================================================================
# sambaedu-opencloud-helper.sh
#
# LA frontière de privilège du déploiement OpenCloud.
#
# www-admin (l'utilisateur PHP-FPM) n'exécute JAMAIS `docker` directement : il
# appelle ce script par `sudo -n`, via l'unique ligne
#
#     www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/sbin/sambaedu-opencloud-helper.sh
#
# posée par `ensure_opencloud_engine` (install.sh) dans
# /etc/sudoers.d/sambaedu-opencloud, après validation par `visudo -cf`.
#
# ─────────────────────────────────────────────────────────────────────────────
# POURQUOI UN HELPER DÉDIÉ, ET PAS CELUI DES EXTENSIONS
#
# Le helper du système d'extensions a des verbes FERMÉS par conception. Les
# élargir pour un autre chantier reconstituerait le couplage que la séparation
# du 2026-08-08 a défait : administrer une instance et installer une instance ne
# sont pas le même livrable. Un helper dédié, aux verbes également fermés, est
# plus petit et plus honnête. Ce fichier ne connaît RIEN des extensions, et le
# fichier des extensions ne connaît rien d'ici.
#
# ─────────────────────────────────────────────────────────────────────────────
# CE QUE CE SCRIPT GARANTIT
#
#   * QUATRE verbes, pas un de plus : deploy / status / stop / logs. Il n'existe
#     AUCUN verbe de suppression — ni de conteneur, ni de volume, ni de donnée.
#     Aucune commande de démontage de composition n'est formée nulle part, et
#     aucune option de suppression de volume n'apparaît : c'est ce qui rend la
#     promesse « les données survivent à l'outil » structurelle plutôt que
#     déclarative. Une garde d'architecture le vérifie par un scan TEXTUEL, y
#     compris dans ces commentaires — parce qu'un commentaire qui cite une
#     commande interdite finit par être copié en appel ;
#   * les CHEMINS sont dérivés de constantes, jamais reçus en argument — on ne
#     peut donc pas faire écrire ce script ailleurs ;
#   * l'IMAGE est épinglée ICI. L'accepter en argument depuis www-admin serait
#     un équivalent-root : faire tourner un conteneur arbitraire en root, avec
#     les volumes de son choix, EST la machine. Même doctrine que le fragment
#     Apache du helper d'extensions, généré ici depuis un gabarit interne ;
#   * la COMPOSITION est GÉNÉRÉE ICI, pour la même raison. Un fichier de
#     composition lisible en écriture par www-admin serait exactement la même
#     porte ;
#   * le port est re-validé (plage, chiffres) et son OCCUPATION par un tiers est
#     un refus NOMMÉ, jamais un écrasement ;
#   * le SECRET d'administration arrive par STDIN, jamais en argument : argv est
#     lisible dans /proc/<pid>/cmdline, dans un `ps` de n'importe quel
#     utilisateur, et sudo journalise la commande complète. Il n'est ni affiché,
#     ni journalisé, et il n'est consommé qu'à la PREMIÈRE initialisation. Il ne
#     ressort pas non plus par la ligne de commande du conteneur d'initialisation :
#     il est EXPORTÉ dans l'environnement du processus et le moteur en hérite
#     (`-e NOM`, sans valeur), parce que /proc/<pid>/environ n'est lisible que par
#     le propriétaire du processus, contrairement à /proc/<pid>/cmdline. Une garde
#     d'architecture rejoue ce scan sur ce fichier ;
#   * les VOLUMES appartiennent à un compte SYSTÈME DÉDIÉ, créé ici s'il manque,
#     et dont l'uid/gid est RÉSOLU à l'exécution — jamais écrit en littéral. Le
#     fichier de configuration de l'instance porte ses secrets internes
#     (jeton de signature, clés d'API de service, mot de passe de l'annuaire) :
#     les laisser à l'uid 1000, c'est-à-dire au premier compte humain d'une
#     Debian, donnerait à un utilisateur sans privilège de quoi forger un jeton
#     pour n'importe quel compte de l'instance et lire les fichiers de tous les
#     élèves. Un déploiement REPREND donc la propriété d'une instance déjà
#     installée, et le dit dans son rapport ;
#   * `deploy` est IDEMPOTENT et CONVERGENT : une instance déjà conforme est un
#     succès qui n'écrit rien, et une configuration déjà initialisée n'est
#     jamais ré-initialisée (ré-initialiser réécrirait les secrets internes de
#     l'instance et la rendrait inutilisable sur ses propres données).
#
# ─────────────────────────────────────────────────────────────────────────────
# LE PIÈGE MESURÉ LE 2026-08-13, ET POURQUOI `deploy` VÉRIFIE LA SANTÉ
#
# Le service d'identité refuse de démarrer si l'URL publique n'est pas en
# `https://` — et le conteneur meurt APRÈS que l'annuaire interne a commencé à
# s'amorcer. La base de l'annuaire reste alors à moitié écrite, toute
# authentification échoue ensuite définitivement, et la seule sortie est de
# repartir d'un volume vierge. Un déploiement qui rendrait « OK » parce que
# `docker compose up` a rendu 0 laisserait donc une instance morte derrière lui.
# `deploy` SONDE donc l'instance et rend un échec NOMMÉ si elle ne répond pas.
#
# ─────────────────────────────────────────────────────────────────────────────
# SORTIE : des lignes `clé=valeur` sur la sortie standard, lues par SE5. Aucune
# phrase libre, aucun secret. Les codes de sortie sont :
#     0  état atteint (déployé ou déjà conforme — la clé `outcome` le dit)
#     2  refus nommé (validation, port occupé, sonde en échec, docker absent)
# =============================================================================

set -euo pipefail

# L'image est ÉPINGLÉE ici : elle n'est jamais reçue en argument.
readonly IMAGE="opencloudeu/opencloud:7.2.3"
readonly PROJECT="sambaedu-opencloud"
readonly SERVICE="opencloud"
readonly CONTAINER="${PROJECT}-${SERVICE}"

# Chemins DÉRIVÉS de constantes, jamais reçus.
readonly CONF_DIR="/etc/sambaedu/opencloud"
readonly COMPOSE_FILE="${CONF_DIR}/compose.yaml"
readonly ENV_FILE="${CONF_DIR}/opencloud.env"
readonly STATE_DIR="/var/lib/sambaedu/opencloud"
readonly CONFIG_VOLUME="${STATE_DIR}/config"
readonly DATA_VOLUME="${STATE_DIR}/data"

# LE COMPTE SYSTÈME DÉDIÉ sous lequel l'image tourne et à qui les volumes
# appartiennent. Son uid/gid n'est JAMAIS un littéral : il est résolu à
# l'exécution par `ensure_run_identity`, qui crée le compte s'il manque et
# ÉCHOUE NOMMÉMENT plutôt que de retomber sur une valeur devinée.
readonly RUN_USER="sambaedu-opencloud"
RUN_UID=""
RUN_GID=""

# Délai d'attente de la sonde de santé au premier démarrage (l'amorçage de
# l'annuaire interne et des espaces de stockage prend une bonne vingtaine de
# secondes sur une machine ordinaire).
readonly PROBE_TRIES=40
readonly PROBE_DELAY=3

die() {
    echo "sambaedu-opencloud-helper: $*" >&2
    exit 2
}

usage() {
    cat >&2 <<'USAGE'
Usage: sambaedu-opencloud-helper.sh <sous-commande> [args]

  deploy <port> <url>   (mot de passe d'administration sur STDIN)
  status
  stop
  logs [lignes]

Aucun verbe de suppression n'existe : ni conteneur, ni volume, ni donnée.
USAGE
    exit 2
}

# ── Validations ──────────────────────────────────────────────────────────────

require_root() {
    [[ "$(id -u)" -eq 0 ]] || die "doit être exécuté en root (via sudo)"
}

require_docker() {
    command -v docker >/dev/null 2>&1 || die "docker indisponible sur cette machine"
    docker compose version >/dev/null 2>&1 \
        || die "le plugin docker compose est indisponible sur cette machine"
}

# ── L'identité d'exécution ───────────────────────────────────────────────────
#
# Résout l'uid/gid du compte système dédié, en le CRÉANT s'il manque.
#
# **Aucun repli sur un littéral.** `1000` est l'uid du premier compte humain
# d'une Debian ordinaire : lui donner les volumes de l'instance lui donnerait le
# fichier de configuration, donc les secrets internes de l'instance, donc la
# capacité de forger un jeton pour n'importe quel compte et de lire les fichiers
# de tout le monde. Si le compte ne peut être ni trouvé ni créé, le déploiement
# S'ARRÊTE en le disant — un refus nommé vaut mieux qu'une instance posée sur un
# propriétaire dangereux.
ensure_run_identity() {
    if ! id -u "$RUN_USER" >/dev/null 2>&1; then
        command -v useradd >/dev/null 2>&1 \
            || die "le compte système « $RUN_USER » est absent et useradd est indisponible : \
refus de poser les volumes de l'instance sous un propriétaire deviné"

        # Compte SYSTÈME : pas de connexion, pas de répertoire personnel créé.
        useradd --system --no-create-home --home-dir "$STATE_DIR" \
            --shell /usr/sbin/nologin --comment "SambaEdu OpenCloud" "$RUN_USER" >/dev/null 2>&1 \
            || die "le compte système « $RUN_USER » n'a pas pu être créé : \
refus de poser les volumes de l'instance sous un propriétaire deviné"
    fi

    RUN_UID="$(id -u "$RUN_USER" 2>/dev/null || true)"
    RUN_GID="$(id -g "$RUN_USER" 2>/dev/null || true)"

    [[ "$RUN_UID" =~ ^[0-9]+$ && "$RUN_GID" =~ ^[0-9]+$ ]] \
        || die "l'identité du compte système « $RUN_USER » n'a pas pu être résolue"
    [[ "$RUN_UID" -ne 0 ]] \
        || die "le compte système « $RUN_USER » est root : l'instance ne tourne pas en root"
}

validate_port() {
    local port="${1:-}"
    [[ "$port" =~ ^[0-9]{4,5}$ ]] || die "port invalide : « $port »"
    [[ "$port" -ge 1024 && "$port" -le 65535 ]] || die "port hors plage : « $port »"
}

# L'URL PUBLIQUE de l'instance. Elle doit être en https même quand le TLS est
# terminé par le frontal : c'est le service d'identité qui l'exige (mesuré).
validate_url() {
    local url="${1:-}"
    [[ -n "$url" ]] || die "URL publique manquante"
    [[ "$url" =~ ^https://[A-Za-z0-9._-]+(:[0-9]{1,5})?$ ]] \
        || die "URL publique invalide : « $url » (attendu https://hôte[:port], sans chemin)"
}

# Le port est-il tenu par quelqu'un d'AUTRE que notre propre conteneur ?
port_taken_by_a_third_party() {
    local port="$1"

    ss -lnt 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${port}\$" || return 1
    # Notre conteneur tourne et publie ce port : ce n'est pas un tiers.
    if docker ps --filter "name=^${CONTAINER}\$" --format '{{.Ports}}' 2>/dev/null \
        | grep -q ":${port}->"; then
        return 1
    fi
    return 0
}

running() {
    [[ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null || echo false)" == "true" ]]
}

# Sonde de santé : l'instance répond-elle vraiment ? (voir le piège mesuré)
#
# `https://` + `-k` : l'instance termine son propre TLS avec un certificat
# AUTOSIGNÉ. Le `-k` ne relâche donc rien — il constate ce que le déploiement
# pose lui-même, sur la boucle locale, contre un certificat dont nous sommes
# l'émetteur. Sonder en `http://` rendrait `000` sur une instance parfaitement
# saine, et le déploiement échouerait sur son propre succès.
probe() {
    local port="$1" i code
    for ((i = 0; i < PROBE_TRIES; i++)); do
        code="$(curl -sSk -o /dev/null -w '%{http_code}' --max-time 5 \
            "https://127.0.0.1:${port}/" 2>/dev/null || echo 000)"
        if [[ "$code" =~ ^(200|301|302|303|307|308)$ ]]; then
            echo "$code"
            return 0
        fi
        sleep "$PROBE_DELAY"
    done
    echo "${code:-000}"
    return 1
}

# ── Génération de la composition (gabarit INTERNE) ───────────────────────────

write_compose() {
    local port="$1"
    install -d -m 0750 -o root -g root "$CONF_DIR"

    local tmp
    tmp="$(mktemp "${CONF_DIR}/.compose.XXXXXX")"
    chmod 0644 "$tmp"
    chown root:root "$tmp"

    # L'instance est JOIGNABLE PAR ELLE-MÊME : elle termine son propre TLS et
    # publie son port. Le scénario « derrière un frontal qui termine le TLS » a
    # été écrit d'abord et n'a jamais tenu — SE5 n'expose aucun vhost pour cette
    # instance, et Apache n'a pas de `:443`. Une instance en écoute LOCALE seule
    # est inatteignable depuis un navigateur, et son `config.json` annonce
    # pourtant une URL publique : le frontend échoue alors sur
    # « Missing or invalid config », sans que rien ne soit en panne côté serveur.
    #
    # Le défaut est resté invisible parce que TOUTES les mesures ont été jouées
    # en boucle locale depuis la VM, où un `curl` n'a jamais besoin d'être un
    # navigateur.
    #
    # `OC_URL` n'est PAS touché : il annonçait déjà `https://…:9200`. On rend
    # cette annonce VRAIE au lieu de la contredire — le repasser en `http://`
    # tuerait définitivement l'annuaire (mesuré).
    #
    # Volumes persistants HORS du dépôt. Aucune surcouche bureautique : hors
    # périmètre.
    #
    # `user:` est le PENDANT INDISPENSABLE de la propriété des volumes : ils
    # appartiennent au compte système dédié, donc le processus doit tourner sous
    # lui. Les deux se posent ensemble ou aucun des deux ne tient.
    cat > "$tmp" <<COMPOSE
# Géré par SambaEdu (sambaedu-opencloud-helper.sh). Ne pas éditer.
name: ${PROJECT}
services:
  ${SERVICE}:
    image: ${IMAGE}
    container_name: ${CONTAINER}
    restart: unless-stopped
    user: "${RUN_UID}:${RUN_GID}"
    entrypoint: ["/bin/sh", "-c", "opencloud server"]
    env_file:
      - ${ENV_FILE}
    environment:
      PROXY_HTTP_ADDR: "0.0.0.0:9200"
    ports:
      - "${port}:9200"
    volumes:
      - ${CONFIG_VOLUME}:/etc/opencloud
      - ${DATA_VOLUME}:/var/lib/opencloud
COMPOSE

    mv -f "$tmp" "$COMPOSE_FILE"
}

# Le fichier d'environnement de la composition : 0600 root:root. Il ne porte
# AUCUN secret — le mot de passe d'administration n'y entre jamais, il est
# consommé une seule fois, à l'initialisation, par une variable d'environnement
# de processus qui ne survit pas à l'appel.
write_env() {
    local url="$1"
    install -d -m 0750 -o root -g root "$CONF_DIR"

    local tmp
    tmp="$(mktemp "${CONF_DIR}/.env.XXXXXX")"
    chmod 0600 "$tmp"
    chown root:root "$tmp"

    # NOTE : `OC_INSECURE=true` n'a PAS été mesuré. La mesure à jouer est
    # nommée : retirer la variable, redéployer sur un volume VIERGE, observer si
    # les services d'identité et de proxy démarrent. Tant qu'elle n'est pas
    # jouée, rien n'est changé ici — coder une hypothèse serait exactement le
    # défaut que ce chantier existe pour ne pas commettre.
    #
    # L'hypothèse la plus probable devient d'ailleurs testable maintenant que
    # l'instance termine son propre TLS : la variable servait vraisemblablement à
    # faire accepter aux services internes une `OC_URL` en `https://` que rien
    # ne servait réellement en TLS. Si c'est le cas, elle n'a plus d'objet.
    cat > "$tmp" <<ENVFILE
# Géré par SambaEdu (sambaedu-opencloud-helper.sh). Ne pas éditer.
OC_URL=${url}
OC_INSECURE=true
PROXY_TLS=true
PROXY_ENABLE_BASIC_AUTH=true
OC_LOG_LEVEL=warn
ENVFILE

    mv -f "$tmp" "$ENV_FILE"
    chmod 0600 "$ENV_FILE"
    chown root:root "$ENV_FILE"
}

# ── Sous-commandes ───────────────────────────────────────────────────────────

cmd_deploy() {
    local port="$1" url="$2"
    validate_port "$port"
    validate_url "$url"
    require_docker

    # Le secret arrive par STDIN et n'est JAMAIS journalisé. Il n'est utile
    # qu'à la toute première initialisation ; ensuite il est ignoré, parce que
    # ré-initialiser réécrirait les secrets internes de l'instance.
    local secret=""
    if [[ ! -t 0 ]]; then
        secret="$(cat || true)"
        secret="${secret%$'\n'}"
    fi

    if port_taken_by_a_third_party "$port"; then
        die "le port $port est déjà utilisé par un autre service de cette machine"
    fi

    ensure_run_identity

    # REPRISE DE PROPRIÉTÉ. Une instance installée avant ce durcissement a ses
    # volumes sur l'uid 1000 — un compte humain. Le déploiement les reprend au
    # compte système dédié plutôt que de laisser un serveur déjà installé dans
    # l'état vulnérable, et il le DIT dans son rapport.
    local owner_before="" reclaimed=false
    if [[ -d "$STATE_DIR" ]]; then
        owner_before="$(stat -c '%u' "$STATE_DIR" 2>/dev/null || echo "")"
        if [[ -n "$owner_before" && "$owner_before" != "$RUN_UID" ]]; then
            reclaimed=true
        fi
    fi

    install -d -m 0750 -o "$RUN_UID" -g "$RUN_GID" "$CONFIG_VOLUME"
    install -d -m 0750 -o "$RUN_UID" -g "$RUN_GID" "$DATA_VOLUME"
    chown -R "$RUN_UID:$RUN_GID" "$STATE_DIR"
    chmod 0750 "$STATE_DIR" "$CONFIG_VOLUME" "$DATA_VOLUME"

    local initialised=false
    if [[ -f "${CONFIG_VOLUME}/opencloud.yaml" ]]; then
        initialised=true
    fi

    write_env "$url"
    write_compose "$port"

    local created=false
    if [[ "$initialised" == false ]]; then
        [[ -n "$secret" ]] || die "mot de passe d'administration absent de l'entrée standard : \
l'initialisation d'une instance neuve l'exige"

        # `init` n'écrit que le fichier de configuration de l'instance. Il est
        # joué UNE fois, sur une instance neuve, et jamais rejoué.
        #
        # LE SECRET PASSE PAR L'ENVIRONNEMENT, PAS PAR argv. `-e NOM=valeur`
        # mettrait le mot de passe dans la ligne de commande du processus, que
        # /proc/<pid>/cmdline rend lisible à TOUT LE MONDE — l'en-tête de ce
        # fichier explique précisément pourquoi le secret arrive par stdin. On
        # l'exporte donc, et le moteur en HÉRITE par `-e NOM` sans valeur :
        # /proc/<pid>/environ, lui, n'est lisible que par le propriétaire.
        export IDM_ADMIN_PASSWORD="$secret"
        docker run --rm \
            --user "${RUN_UID}:${RUN_GID}" \
            -v "${CONFIG_VOLUME}:/etc/opencloud" \
            -v "${DATA_VOLUME}:/var/lib/opencloud" \
            -e OC_INSECURE=true \
            -e IDM_ADMIN_PASSWORD \
            "$IMAGE" init >/dev/null 2>&1 \
            || { unset IDM_ADMIN_PASSWORD; die "l'initialisation de l'instance a échoué"; }
        unset IDM_ADMIN_PASSWORD
        created=true
    fi
    unset secret

    local already_running=false
    if running; then
        already_running=true
    fi

    # `up -d` converge : il ne recrée le conteneur que si la composition a
    # changé. Aucun drapeau de suppression de volume n'existe ici.
    docker compose -f "$COMPOSE_FILE" up -d >/dev/null 2>&1 \
        || die "le démarrage de la composition a échoué"

    local code
    if ! code="$(probe "$port")"; then
        die "l'instance ne répond pas sur le port $port après démarrage (dernier code HTTP : $code) — \
consulter « logs » ; une instance dont le premier démarrage échoue laisse un annuaire interne \
inutilisable et doit repartir d'un volume vierge"
    fi

    local outcome="conforming"
    if [[ "$created" == true ]]; then
        outcome="deployed"
    elif [[ "$already_running" == false ]]; then
        outcome="deployed"
    fi

    echo "outcome=${outcome}"
    echo "image=${IMAGE}"
    echo "container=${CONTAINER}"
    echo "port=${port}"
    echo "url=${url}"
    echo "initialised=${initialised}"
    echo "health=${code}"
    echo "config_volume=${CONFIG_VOLUME}"
    echo "data_volume=${DATA_VOLUME}"
    echo "run_user=${RUN_USER}"
    echo "run_uid=${RUN_UID}"
    echo "ownership_reclaimed=${reclaimed}"
}

cmd_status() {
    require_docker

    local present=false state="absent" port="" code="000"
    if docker inspect "$CONTAINER" >/dev/null 2>&1; then
        present=true
        state="$(docker inspect -f '{{.State.Status}}' "$CONTAINER" 2>/dev/null || echo inconnu)"
        port="$(docker inspect -f '{{range $p, $c := .NetworkSettings.Ports}}{{range $c}}{{.HostPort}}{{end}}{{end}}' \
            "$CONTAINER" 2>/dev/null | head -1)"
    fi

    if [[ -n "$port" ]] && running; then
        code="$(curl -sSk -o /dev/null -w '%{http_code}' --max-time 5 "https://127.0.0.1:${port}/" 2>/dev/null || echo 000)"
    fi

    echo "expected=${CONTAINER}"
    echo "present=${present}"
    echo "state=${state}"
    echo "image=${IMAGE}"
    echo "port=${port}"
    echo "health=${code}"
    echo "initialised=$([[ -f "${CONFIG_VOLUME}/opencloud.yaml" ]] && echo true || echo false)"
    echo "compose=$([[ -f "$COMPOSE_FILE" ]] && echo true || echo false)"
    echo "config_volume=${CONFIG_VOLUME}"
    echo "data_volume=${DATA_VOLUME}"
    # `status` ne crée RIEN : il rapporte le propriétaire trouvé, et c'est au
    # déploiement de le reprendre. Une instance dont les volumes appartiennent à
    # autre chose que le compte système dédié est un fait à voir.
    echo "run_user=${RUN_USER}"
    echo "volume_owner=$(stat -c '%U' "$STATE_DIR" 2>/dev/null || echo inconnu)"
}

# ARRÊTER, jamais retirer. `stop` laisse le conteneur, ses volumes et ses
# données en place : c'est une pause d'exploitation, pas une désinstallation.
cmd_stop() {
    require_docker

    if ! docker inspect "$CONTAINER" >/dev/null 2>&1; then
        echo "outcome=conforming"
        echo "state=absent"
        return 0
    fi

    docker stop "$CONTAINER" >/dev/null 2>&1 || die "l'arrêt du conteneur a échoué"

    echo "outcome=stopped"
    echo "state=$(docker inspect -f '{{.State.Status}}' "$CONTAINER" 2>/dev/null || echo inconnu)"
}

cmd_logs() {
    require_docker

    local lines="${1:-100}"
    [[ "$lines" =~ ^[0-9]{1,4}$ ]] || die "nombre de lignes invalide : « $lines »"

    docker inspect "$CONTAINER" >/dev/null 2>&1 || die "aucun conteneur OpenCloud sur cette machine"
    docker logs --tail "$lines" "$CONTAINER" 2>&1 || true
}

# ── Dispatch ─────────────────────────────────────────────────────────────────

main() {
    require_root

    local subcommand="${1:-}"
    [[ -n "$subcommand" ]] || usage
    shift || true

    case "$subcommand" in
        deploy) [[ $# -eq 2 ]] || usage; cmd_deploy "$1" "$2" ;;
        status) [[ $# -eq 0 ]] || usage; cmd_status ;;
        stop)   [[ $# -eq 0 ]] || usage; cmd_stop ;;
        logs)   [[ $# -le 1 ]] || usage; cmd_logs "${1:-100}" ;;
        *)      die "sous-commande inconnue : « $subcommand »" ;;
    esac
}

main "$@"
