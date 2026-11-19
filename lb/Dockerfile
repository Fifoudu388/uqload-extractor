# Load balancer Caddy pour les extracteurs — voir README.md.
# L'entrypoint genere la configuration au demarrage a partir de la
# variable BACKENDS ; sans BACKENDS, le load balancer est desactive.
FROM caddy:2-alpine
COPY Caddyfile /etc/caddy/Caddyfile.template
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
CMD ["/entrypoint.sh"]
