FROM node:24-alpine

WORKDIR /app
COPY . /app

# Vérifie la syntaxe JS au build : une erreur ici fait échouer le
# déploiement Railway tout de suite, avec le message d'erreur en clair.
RUN node --check server.js && node --check cli.js && for f in src/*.js test/*.mjs; do node --check "$f"; done

# Railway injecte $PORT : le serveur Node écoute dessus.
CMD ["node", "server.js"]
