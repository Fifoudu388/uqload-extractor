# uqload + vidmoly — extracteurs vidéo (version Node.js)

Même chose que la version PHP à la racine du dépôt, réécrite en JavaScript
(Node.js 18+, **sans aucune dépendance**). Même comportement, mêmes routes,
mêmes variables d'environnement — les deux versions peuvent tourner côte à
côte, chacune sur son propre service Railway.

- **uqload** : le token est régénéré à chaque chargement de page (valide ~4h)
  et se trouve dans un script obfusqué par le packer « Dean Edwards »
  (dé-obfuscation en base 62, voir `src/depacker.js`).
- **vidmoly** : la page `/v/<id>` contient une iframe vers la page
  d'intégration (`/embed-<id>.html`, domaine `vidmoly.biz`), dont le lecteur
  JW Player embarque le `file: ...master.m3u8...` dans le HTML. Le formulaire
  de vérification éventuel (« Select number ») est soumis automatiquement.

## Utilisation

```
# uqload — par code vidéo
GET /uqload?id=v80v3zz0vay2
GET /uqload.php?id=v80v3zz0vay2        (alias, compatible avec la version PHP)

# uqload — par URL complète
GET /uqload?url=https://uqload.vc/v80v3zz0vay2.html

# uqload — via le proxy (flux relayé par le serveur, voir ci-dessous)
GET /uqload?id=v80v3zz0vay2&proxy=1
GET /uqload?stream=https%3A%2F%2Fstrm1.uqload.vc%2F...%2Fmaster.m3u8

# vidmoly — par code vidéo
GET /vidmoly?id=iztl71ko757x
GET /vidmoly.php?id=iztl71ko757x       (alias)

# vidmoly — par URL complète (/v/ ou /embed-...html, domaines .me/.to/.net/.biz)
GET /vidmoly?url=https://vidmoly.me/v/iztl71ko757x

# En CLI
node cli.js uqload v80v3zz0vay2
node cli.js vidmoly iztl71ko757x
```

## Réponse JSON

```json
{
  "master": "https://strm1.uqload.vc/.../master.m3u8?...",
  "variants": [
    {"bandwidth": 2500000, "resolution": "1280x720", "url": "https://..."},
    {"bandwidth": 1200000, "resolution": "854x480", "url": "https://..."}
  ],
  "best": "https://...",
  "media": "https://...",
  "playlistHttp": 200,
  "masterBody": "#EXTM3U\n...",
  "cached": false
}
```

- `variants` : toutes les qualités du master, triées par bande passante décroissante.
- `best` / `media` : URL de la meilleure variante (ou du master s'il n'y a pas de variantes).
- `cached` : `true` si la réponse vient du cache (durée par défaut : 1h).
- `type` (vidmoly) : `"m3u8"` ou `"mp4"` selon le flux trouvé.
- `subtitles` (vidmoly) : sous-titres embarqués `[{file, label, kind}]`, si présents.
- `embed` (vidmoly) : URL de la page d'intégration utilisée.

Codes HTTP : `200` succès, `400` paramètre `id`/`url` manquant ou invalide,
`401` clé API manquante/invalide (si des clés sont configurées), `429` rate
limit atteint, `502` la page ne répond pas ou le flux est introuvable.

## Referer / Origin et proxy de flux

Identique à la version PHP — uqload refuse les requêtes dont le `Referer` ou
l'`Origin` n'est pas uqload :

```
GET /uqload?id=v80v3zz0vay2&proxy=1
```

La réponse JSON renvoie alors les URLs des médias via le proxy (`?stream=...`,
champ `"proxied": true`). Le lecteur ne parle qu'à ton serveur, qui refait
chaque requête vers uqload avec les bons en-têtes. Les playlists `.m3u8` sont
réécrites pour que les segments passent aussi par le proxy, et l'en-tête
`Range` est transmis (le seek fonctionne, réponse `206`).

- Seuls les domaines uqload sont autorisés (`?stream=` ne sert pas de proxy
  ouvert).
- La clé API (si configurée) est exigée aussi sur `?stream=` et `?proxy=1`.
- Le proxy ne met pas les segments en cache (ils sont relayés à la demande).

## Clés API

Par défaut, l'API est ouverte. Pour la protéger, définis la variable
`UQLOAD_API_KEYS` (une clé par ligne) — **la même variable protège les deux
extracteurs** (`uqload` et `vidmoly`), et elle est partagée avec la version
PHP si les deux services tournent avec les mêmes variables :

```
visionary:sk_live_abc123:60
test:sk_test_xyz:10
perso:sk_perso_zzz
```

Format : `nom:cle[:limite_par_minute]` — la limite est optionnelle (`0` ou
absente = illimitée). Générer une clé :

```bash
openssl rand -hex 24
```

La clé se passe en header `X-API-Key` ou en paramètre `?key=` :

```bash
curl -H "X-API-Key: sk_live_abc123" "https://ton-app-js.up.railway.app/uqload?id=v80v3zz0vay2"
curl "https://ton-app-js.up.railway.app/uqload?id=v80v3zz0vay2&key=sk_live_abc123"
```

- Sans clé (ou clé fausse) quand des clés sont configurées : `401`.
- Limite atteinte : `429` avec `Retry-After` (en secondes) et les headers
  `X-RateLimit-Limit` / `X-RateLimit-Remaining` sur chaque réponse.
- La réponse inclut `key_name` (le nom de la clé utilisée).
- Chaque clé a son propre compteur (fenêtre fixe de 60 s, par clé).

## En local

```bash
node server.js            # écoute sur $PORT (8080 par défaut)
curl "http://localhost:8080/uqload?id=v80v3zz0vay2"
curl "http://localhost:8080/vidmoly?id=iztl71ko757x"
```

Node 18+ requis, aucune dépendance à installer (`npm install` inutile).

## Tests

```bash
node test/selftest.mjs           # tests unitaires + test d'intégration (serveur simulé)
LIVE=1 node test/selftest.mjs    # + test live contre uqload.vc et vidmoly.me
```

## Déployer sur Railway

La version PHP tourne déjà sur un service Railway. La version Node.js est un
**deuxième service**, sur le même dépôt GitHub :

1. Sur [railway.app](https://railway.app) : **New Service → Deploy from GitHub repo**
   → choisis le même dépôt `uqload-extractor`.
2. Dans **Settings → Build → Dockerfile Path** : mets `js/Dockerfile`
   (et si tu veux utiliser `js/railway.toml`, mets aussi **Config File Path** sur
   `js/railway.toml`). Railway détecte le reste tout seul.
3. Dans **Settings → Networking → Generate Domain** pour avoir l'URL publique.
4. (Optionnel) Recopie les variables d'environnement du service PHP
   (`UQLOAD_API_KEYS`, `UQLOAD_CACHE_TTL`) si tu veux les mêmes clés et le même cache.
5. Teste : `https://ton-app-js.up.railway.app/uqload?id=v80v3zz0vay2`
   et `https://ton-app-js.up.railway.app/vidmoly?id=iztl71ko757x`

`PORT` est injecté automatiquement par Railway, rien à configurer.

### Variables d'environnement (optionnelles)

| Variable | Défaut | Description |
|---|---|---|
| `UQLOAD_CACHE_TTL` | `3600` | Durée du cache en secondes (`0` = désactivé) |
| `UQLOAD_API_KEYS` | _(vide)_ | Clés API, une par ligne : `nom:cle[:limite_par_minute]`. Vide = accès libre |

## Différences avec la version PHP

- **Cache en mémoire** au lieu de fichiers : Node est mono-thread (pas besoin
  de verrou `flock`), le cache est perdu au redémarrage du serveur. Même durée
  (1h par défaut), même comportement visible côté client.
- **Rate limit en mémoire** (même fenêtre fixe de 60 s, mêmes headers).
- **Routes sans `.php`** (`/uqload`, `/vidmoly`) en plus des alias `.php` :
  les URLs PHP (`/uqload.php?id=...`) fonctionnent aussi, donc un client
  écrit pour la version PHP marche tel quel sur la version Node.js.
- **Serveur Node multi-connexions** : contrairement à `php -S` (mono-thread),
  le serveur accepte plusieurs requêtes en parallèle.

## Structure

```
js/
  server.js          point d'entrée : routage, CORS, auth, rate limit
  cli.js             CLI : node cli.js <uqload|vidmoly> <id ou url>
  package.json       type: module, zéro dépendance, scripts start/test
  Dockerfile         node:24-alpine, node --check au build
  railway.toml       config Railway (service séparé de la version PHP)
  src/
    constants.js     UA, domaines autorisés, TTL du cache
    depacker.js      dé-obfuscation du packer Dean Edwards (base 62)
    http.js          client HTTP (fetch global, timeout, headers)
    urls.js          resolveUrl, domaines uqload, Referer/Origin
    m3u8.js          parse de master .m3u8, réécriture de playlists
    cache.js         cache mémoire avec expiration
    auth.js          clés API (timingSafeEqual) + rate limit
    proxy.js         mode proxy de flux (?stream=, Range, 206)
    respond.js       réponses JSON
    uqload.js        extracteur uqload (+ handler web)
    vidmoly.js       extracteur vidmoly (+ handler web)
  test/
    selftest.mjs     tests unitaires + intégration (serveur simulé) + live (LIVE=1)
```

## Notes

- Le token uqload expire après ~4h : l'API va toujours chercher la page, le
  cache ne garde un résultat qu'1h par défaut.
