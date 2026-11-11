# uqload — extracteur m3u8

Extrait l'URL du master `.m3u8` (et toutes ses variantes de qualité) d'une page
[_uqload_](https://uqload.vc) : le token est régénéré à chaque chargement de page
(valide ~4h) et se trouve dans un script obfusqué par le packer « Dean Edwards ».

## Utilisation

```
# Par code vidéo
GET /uqload.php?id=v80v3zz0vay2

# Par URL complète
GET /uqload.php?url=https://uqload.vc/v80v3zz0vay2.html

# En CLI
php uqload.php v80v3zz0vay2
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

Codes HTTP : `200` succès, `400` paramètre `id`/`url` manquant ou invalide,
`502` la page uqload ne répond pas ou le master est introuvable.

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
- **Robustesse** : `Referer` envoyé sur la playlist, timeout de connexion,
  codes HTTP cohérents, en-tête CORS (appelable depuis un lecteur web).
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

`PORT` est injecté automatiquement par Railway, rien à configurer.

### Variables d'environnement (optionnelles)

| Variable | Défaut | Description |
|---|---|---|
| `UQLOAD_CACHE_TTL` | `3600` | Durée du cache en secondes (`0` = désactivé) |

## Notes

- Le serveur intégré de PHP (`php -S`) est mono-thread : suffisant pour un usage
  personnel, mais pour du trafic sérieux, passe sur Apache/Nginx + PHP-FPM.
- Le token uqload expire après ~4h : l'API va toujours chercher la page, le
  cache ne garde un résultat qu'1h par défaut.
