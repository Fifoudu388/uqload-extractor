#!/bin/sh
# Point d'entree du load balancer des extracteurs.
#
# Le load balancer est DESACTIVE par defaut : tant qu'aucun backend n'est
# configure, Caddy demarre avec une page "Load balancer desactive" et ne
# relaie aucun trafic.
#
# Pour l'activer, definir UNE des deux formes (puis redemarrer le service) :
#   BACKENDS="https://extracteur-1.up.railway.app https://extracteur-2.up.railway.app"
#       -> autant d'URL qu'on veut (1, 2, 3...), separees par des espaces
#          ou des virgules. C'est le nombre de serveurs derriere le LB.
#   BACKEND_1=... BACKEND_2=... (ancien format, toujours accepte si
#       BACKENDS n'est pas defini)
#
# Pour le desactiver : vider ou supprimer BACKENDS, puis redemarrer.
#
# PORT est injecte par Railway (8080 par defaut en local).

set -eu

export PORT="${PORT:-8080}"
TEMPLATE="${LB_TEMPLATE:-/etc/caddy/Caddyfile.template}"
OUT="${LB_OUT:-/tmp/caddy/Caddyfile}"

# --- Construit la liste brute des backends --------------------------------
RAW="${BACKENDS:-}"
if [ -z "$RAW" ]; then
	i=1
	while :; do
		eval "v=\${BACKEND_$i:-}"
		[ -z "$v" ] && break
		RAW="$RAW $v"
		i=$((i + 1))
	done
fi

# --- Nettoie et valide chaque URL ----------------------------------------
UPSTREAMS=""
for url in $(printf '%s' "$RAW" | tr ',\n' '  '); do
	url="${url%/}"
	case "$url" in
		http://* | https://*) UPSTREAMS="$UPSTREAMS $url" ;;
		*) echo "lb: backend ignore (URL invalide) : $url" >&2 ;;
	esac
done
UPSTREAMS="${UPSTREAMS# }"

mkdir -p "$(dirname "$OUT")"

if [ -z "$UPSTREAMS" ]; then
	echo "lb: aucun backend configure — load balancer DESACTIVE."
	cat > "$OUT" <<'EOF'
:{$PORT} {
	respond "Load balancer desactive. Pour l'activer, definir la variable BACKENDS sur ce service (voir lb/README.md)." 200
	log
}
EOF
else
	echo "lb: load balancer actif, backends : $UPSTREAMS"
	sed "s|%%UPSTREAMS%%|$UPSTREAMS|" "$TEMPLATE" > "$OUT"
fi

exec caddy run --config "$OUT" --adapter caddyfile
