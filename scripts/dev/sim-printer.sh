#!/usr/bin/env bash
# Simule une imprimante IPP via un container Docker (cups-pdf), accessible
# depuis la VM via le bridge virbr0. La VM enregistre l'imprimante CUPS
# distante pointant vers ce container.
set -euo pipefail

CONTAINER_NAME="sim-printer"
HOST_IP="192.168.122.1"
HOST_PORT="6310"            # port CUPS exposé sur l'hôte
IMAGE="olbat/cupsd:latest"  # CUPS minimal avec cups-pdf
PRINTER_NAME="TestPrinter"
SHARE_NAME="PDF"            # nom de la queue cups-pdf dans le container
SSH_KEY="$HOME/.ssh/id_se4fs_vm"
VM="root@192.168.122.50"
PDF_OUT_DIR="/tmp/sim-printer-out"

usage() {
    echo "Usage: $0 {start|stop|status|logs|out}"
    exit 1
}

cmd="${1:-}"
[[ -z "$cmd" ]] && usage

case "$cmd" in
    start)
        if ! command -v docker &>/dev/null; then
            echo "Docker introuvable" >&2
            exit 1
        fi

        mkdir -p "$PDF_OUT_DIR"

        # Tuer le container existant si présent
        if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
            docker rm -f "$CONTAINER_NAME" >/dev/null
            echo "Ancien container supprimé."
        fi

        echo "Démarrage du container cups-pdf sur ${HOST_IP}:${HOST_PORT}…"
        docker run -d \
            --name "$CONTAINER_NAME" \
            --restart unless-stopped \
            -p "${HOST_IP}:${HOST_PORT}:631" \
            -v "${PDF_OUT_DIR}:/var/spool/cups-pdf/ANONYMOUS" \
            "$IMAGE" >/dev/null

        # Attendre que CUPS soit prêt
        echo -n "Attente de CUPS"
        for i in {1..20}; do
            if curl -sf "http://${HOST_IP}:${HOST_PORT}/" -o /dev/null 2>&1; then
                echo " ✓"
                break
            fi
            echo -n "."
            sleep 0.5
        done

        # Vérifier que la queue cups-pdf existe dans le container
        if ! docker exec "$CONTAINER_NAME" lpstat -p "$SHARE_NAME" &>/dev/null; then
            echo "Création de la queue cups-pdf dans le container…"
            docker exec "$CONTAINER_NAME" bash -c "
                lpadmin -p $SHARE_NAME -E -v cups-pdf:/ -P /usr/share/ppd/cups-pdf/CUPS-PDF_opt.ppd 2>/dev/null \
                    || lpadmin -p $SHARE_NAME -E -v cups-pdf:/ -m drv:///sample.drv/generic.ppd
                cupsenable $SHARE_NAME
                cupsaccept $SHARE_NAME
            "
        fi

        # Enregistrer l'imprimante côté VM
        echo "Enregistrement de $PRINTER_NAME sur la VM…"
        ssh -i "$SSH_KEY" "$VM" bash <<REMOTE
set -e
if lpstat -p "$PRINTER_NAME" &>/dev/null; then
    lpadmin -x "$PRINTER_NAME"
fi
lpadmin -p "$PRINTER_NAME" -E \
    -v "ipp://${HOST_IP}:${HOST_PORT}/printers/${SHARE_NAME}" \
    -m everywhere \
    -L "Imprimante simulée (Docker hôte)"
lpstat -p "$PRINTER_NAME"
REMOTE

        echo ""
        echo "✓ Imprimante simulée prête : ipp://${HOST_IP}:${HOST_PORT}/printers/${SHARE_NAME}"
        echo "  PDF générés dans : ${PDF_OUT_DIR}"
        echo "  Logs container   : $0 logs"
        echo "  Arrêt            : $0 stop"
        ;;

    stop)
        if docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
            docker rm -f "$CONTAINER_NAME" >/dev/null
            echo "Container arrêté."
        else
            echo "Aucun container à arrêter."
        fi

        ssh -i "$SSH_KEY" "$VM" \
            "lpstat -p $PRINTER_NAME &>/dev/null && lpadmin -x $PRINTER_NAME && echo 'Imprimante VM supprimée.' || echo 'Imprimante VM déjà absente.'"
        ;;

    status)
        echo "=== Container hôte ==="
        if docker ps --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' | grep "^${CONTAINER_NAME}\b"; then
            :
        else
            echo "Arrêté"
        fi

        echo ""
        echo "=== Imprimante côté VM ==="
        ssh -i "$SSH_KEY" "$VM" \
            "lpstat -p $PRINTER_NAME 2>/dev/null || echo '$PRINTER_NAME : non enregistrée'"

        echo ""
        echo "=== PDF générés ==="
        ls -lt "$PDF_OUT_DIR" 2>/dev/null | head -10 || echo "(aucun)"
        ;;

    logs)
        docker logs -f "$CONTAINER_NAME"
        ;;

    out)
        ls -lt "$PDF_OUT_DIR" 2>/dev/null
        ;;

    *)
        usage
        ;;
esac
