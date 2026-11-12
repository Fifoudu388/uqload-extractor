# uqload + vidmoly — extracteurs vidéo

Extrait l'URL du flux direct (`.m3u8` HLS, avec toutes ses variantes de qualité)
d'une page [_uqload_](https://uqload.vc) ou [_vidmoly_](https://vidmoly.me).

- **uqload** : le token est régénéré à chaque chargement de page (valide ~4h)
  et se trouve dans un script obfusqué par le packer « Dean Edwards ».
- **vidmoly** : la page `/v/<id>` contient une iframe vers la page
  d'intégration (`/embed-<id>.html`, domaine `vidmoly.biz`), dont le lecteur
  JW Player embarque le `file: ...master.m3u8...` dans le HTML. Le formulaire
  de vérification éventuel (« Select number ») est soumis automatiquement.

## Version JavaScript (Node.js)

La **même chose en JavaScript** existe dans le dossier [`js/`](js/) : Node.js 18+,
**sans aucune dépendance**, même comportement, mêmes routes (`/uqload`,
`/vidmoly` — avec les alias `/uqload.php` et `/vidmoly.php` pour rester
compatible avec cette version PHP), mêmes variables d'environnement
(`UQLOAD_CACHE_TTL`, `UQLOAD_API_KEYS`), même proxy de flux (`?proxy=1`,
`?stream=`). Voir [`js/README.md`](js/README.md) pour l'utilisation, les
tests (`node test/selftest.mjs`, `LIVE=1` pour le test live) et le déploiement
sur Railway (deuxième service, même dépôt, **Dockerfile Path : `js/Dockerfile`**).

## Utilisation

```
# uqload — par code vidéo
GET /uqload.php?id=v80v3zz0vay2

# uqload — par URL complète
GET /uqload.php?url=https://uqload.vc/v80v3zz0vay2.html

# uqload — via le proxy (flux relayé par le serveur, voir ci-dessous)
GET /uqload.php?id=v80v3zz0vay2&proxy=1
GET /uqload.php?stream=https%3A%2F%2Fstrm1.uqload.vc%2F...%2Fmaster.m3u8

# vidmoly — par code vidéo
GET /vidmoly.php?id=iztl71ko757x

# vidmoly — par URL complète (/v/ ou /embed-...html, domaines .me/.to/.net/.biz)
GET /vidmoly.php?url=https://vidmoly.me/v/iztl71ko757x

# En CLI
php uqload.php v80v3zz0vay2
php vidmoly.php iztl71ko757x
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

uqload refuse les requêtes dont le `Referer` ou l'`Origin` n'est pas uqload :
- Côté serveur, toutes les requêtes partent avec `Referer: https://uqload.xx/`
  et `Origin: https://uqload.xx` (domaine dérivé de la page : `.vc`, `.io`,
  `.com`, `.to`).
- Mais un navigateur ne peut pas falsifier ces en-têtes (en-têtes interdits en
  JavaScript) : si ton lecteur (hls.js, `<video>`, VLC…) récupère le `.m3u8`
  ou les segments directement, uqload peut répondre `403`.

Solution : faire passer le flux par le serveur avec `?proxy=1` :

```
GET /uqload.php?id=v80v3zz0vay2&proxy=1
```

La réponse JSON renvoie alors les URLs des médias via le proxy (`?stream=...`,
champ `"proxied": true`). Le lecteur ne parle qu'à ton serveur, qui refait
chaque requête vers uqload avec les bons en-têtes. Les playlists `.m3u8` sont
réécrites pour que les segments passent aussi par le proxy, et l'en-tête
`Range` est transmis (le seek fonctionne, réponse `206`).

Tu peux aussi appeler le proxy directement :

```
GET /uqload.php?stream=https%3A%2F%2Fstrm1.uqload.vc%2F...%2Fmaster.m3u8
```

- Seuls les domaines uqload sont autorisés (`?stream=` ne sert pas de proxy
  ouvert).
- La clé API (si configurée) est exigée aussi sur `?stream=` et `?proxy=1`.
- Le proxy ne met pas les segments en cache (ils sont relayés à la demande).

## Clés API

Par défaut, l'API est ouverte. Pour la protéger, définis la variable
`UQLOAD_API_KEYS` (une clé par ligne) — **la même variable protège les deux
extracteurs** (`uqload.php` et `vidmoly.php`) :

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
curl -H "X-API-Key: sk_live_abc123" "https://ton-app.up.railway.app/uqload.php?id=v80v3zz0vay2"
curl "https://ton-app.up.railway.app/uqload.php?id=v80v3zz0vay2&key=sk_live_abc123"
```

- Sans clé (ou clé fausse) quand des clés sont configurées : `401`.
- Limite atteinte : `429` avec `Retry-After` (en secondes) et les headers
  `X-RateLimit-Limit` / `X-RateLimit-Remaining` sur chaque réponse.
- La réponse inclut `key_name` (le nom de la clé utilisée).
- Chaque clé a son propre compteur (fenêtre fixe de 60 s, par clé).
- Pour révoquer une clé, retire sa ligne de la variable et redéploie
  (Railway redémarre le service quand une variable change).

## Améliorations par rapport à la v1

- **dePacker corrigé** : la base 62 est supportée (`base_convert()` plafonnait à 36,
  donc les tokens étaient faux dès que le packer utilisait `a=62`), et le
  remplacement se fait en une seule passe (plus de corruption quand un mot
  remplacé ressemblait à un token). Les caractères échappés (`\'`, `\/`, …)
  sont gérés, guillemets simples et doubles.
- **Toutes les qualités** : le master est parsé (`#EXT-X-STREAM-INF`) au lieu
  de ne renvoyer que la première URL `.m3u8` trouvée.
- **Sécurité** : `?url=` n'accepte que les domaines uqload (anti-SSRF),
  `?id=` est validé (alphanumérique, 3–64 caractères).
- **Robustesse** : `Referer` et `Origin` uqload envoyés sur la page et sur la
  playlist (domaine dérivé de l'URL : `.vc`, `.io`, `.com`, `.to`), timeout de
  connexion, codes HTTP cohérents, en-tête CORS (appelable depuis un lecteur
  web). **Proxy de flux** (`?proxy=1` et `?stream=`) pour contourner le
  contrôle Referer/Origin d'uqload côté lecteur (voir la section ci-dessus).
- **Cache** : résultats mis en cache 1h (fichier, `UQLOAD_CACHE_TTL` pour régler,
  `0` pour désactiver).

## En local

```bash
php -S localhost:8080 -t .
curl "http://localhost:8080/uqload.php?id=v80v3zz0vay2"
```

PHP 8.0+ requis, extension `curl` activée.

## Déployer sur Railway

1. Crée un dépôt GitHub avec ces fichiers (ou `git push` depuis ce dossier).
2. Sur [railway.app](https://railway.app) : **New Project → Deploy from GitHub repo**
   → choisis le dépôt. Railway détecte le `Dockerfile` et `railway.toml` tout seul.
3. Dans **Settings → Networking → Generate Domain** pour avoir l'URL publique.
4. Teste : `https://ton-app.up.railway.app/uqload.php?id=v80v3zz0vay2`
   et `https://ton-app.up.railway.app/vidmoly.php?id=iztl71ko757x`

`PORT` est injecté automatiquement par Railway, rien à configurer.

### Variables d'environnement (optionnelles)

| Variable | Défaut | Description |
|---|---|---|
| `UQLOAD_CACHE_TTL` | `3600` | Durée du cache en secondes (`0` = désactivé) |
| `UQLOAD_API_KEYS` | _(vide)_ | Clés API, une par ligne : `nom:cle[:limite_par_minute]`. Vide = accès libre |

## Notes

- Le serveur intégré de PHP (`php -S`) est mono-thread : suffisant pour un usage
  personnel, mais pour du trafic sérieux, passe sur Apache/Nginx + PHP-FPM.
- Le token uqload expire après ~4h : l'API va toujours chercher la page, le
  cache ne garde un résultat qu'1h par défaut.
