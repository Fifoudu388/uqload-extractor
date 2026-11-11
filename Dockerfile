FROM php:8.3-cli

# curl est normalement deja present dans l'image officielle ; on l'installe sinon.
RUN (php -m | grep -qi '^curl$') || docker-php-ext-install curl

WORKDIR /app
COPY . /app

# Verifie la syntaxe PHP au build : une erreur ici fait echouer le
# deploiement Railway tout de suite, avec le message d'erreur en clair.
RUN php -l uqload.php && php -l vidmoly.php && php -l index.php

# Railway injecte $PORT : le serveur integre de PHP ecoute dessus.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app"]
