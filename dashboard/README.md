# Tableau de bord des extracteurs — niveau 2 (état + contrôle)

Un service web protégé par mot de passe pour :

- **voir l'état** : entrée publique, load balancer (actif ou non, et avec
  quels serveurs), chaque serveur extracteur (en ligne ou non, temps de
  réponse), avec rafraîchissement automatique toutes les 30 s ;
- **tester les extracteurs** : un bouton lance une vraie extraction de
  test (vidmoly, streamtape, lulustream) via l'entrée publique ;
- **contrôler le load balancer** : cocher les serveurs à mettre derrière,
  activer / mettre à jour, ou tout désactiver — sans aller toucher aux
  variables dans Railway. Le dashboard modifie la variable `BACKENDS` du
  service LB via l'API Railway, puis redéploie le service.

Il existe aussi un **niveau 1** dans `statique/` : une page HTML unique,
sans serveur ni mot de passe, lecture seule — pratique en attendant de
déployer ce service, ou en secours.

## Déploiement sur Railway

1. **Créer le service** : New Service → GitHub Repo → `uqload-extractor`
   → **Root Directory : `dashboard/`**. Génère un domaine
   `*.up.railway.app` pour ce service (Settings → Networking) : c'est
   l'adresse de ton tableau de bord.
2. **Créer un jeton Railway** (il donne au dashboard le droit de modifier
   les variables du service LB) :
   - le plus sûr : un **jeton de projet** — dans ton projet Railway :
     Settings → Tokens → crée un jeton pour l'environnement
     **production**. Il ne donne accès qu'à ce projet.
   - ou un jeton de compte : <https://railway.com/account/tokens>
     (accès à tout ton compte — moins bien).
   ⚠️ Ne colle jamais ce jeton dans un chat ou un fichier du dépôt :
   uniquement dans les variables du service dashboard (étape 3).
3. **Variables du service dashboard** (Settings → Variables) :
   - `DASHBOARD_PASSWORD` : le mot de passe de la page (choisis-le toi,
     long et unique — c'est lui qui protège le jeton ci-dessous).
   - `RAILWAY_API_TOKEN` : le jeton de l'étape 2.
   - `RAILWAY_TOKEN_TYPE` : `project` (défaut) pour un jeton de projet,
     `account` pour un jeton de compte.
   - `RAILWAY_PROJECT_ID`, `RAILWAY_ENVIRONMENT_ID`, `LB_SERVICE_ID` :
     les identifiants de ton projet et du service LB. Pour les trouver :
     ouvre le service LB dans Railway, l'adresse de la page contient le
     projet et le service — `railway.com/project/<PROJECT_ID>/service/<SERVICE_ID>` —
     et l'identifiant de l'environnement **production** se lit dans l'URL
     (`environmentId=...`) ou dans les réglages du projet. C'est
     l'environnement du jeton de projet qu'il faut mettre.
   - `EXTRACTOR_URLS` : les URL publiques de tes services extracteurs,
     séparées par des espaces — c'est la liste des serveurs que le
     dashboard surveille et que tu peux cocher pour le load balancer.
   - `EXTRACTOR_SERVICE_IDS` : les identifiants Railway de ces mêmes
     services extracteurs, séparés par des espaces, **dans le même ordre**
     que `EXTRACTOR_URLS` (ils se lisent dans l'URL quand tu ouvres
     chaque service : `.../service/<ID>`). Ils servent à activer /
     désactiver un extracteur sur tous les serveurs.
   - `PUBLIC_URL` : `https://stream.visionary.qzz.io` (ton entrée
     publique).
   - `EXTRACTOR_API_KEY` (optionnel) : une des clés de `UQLOAD_API_KEYS`,
     seulement si tes extracteurs exigent une clé — elle sert aux tests
     d'extraction depuis le dashboard.
4. **Ouvrir le domaine du dashboard** : le navigateur demande un mot de
   passe (identifiant libre, mot de passe = `DASHBOARD_PASSWORD`).

## Ce que font les boutons

- **Activer / mettre à jour** : écrit `BACKENDS` sur le service LB avec
  les URL cochées (séparées par des espaces), supprime l'ancien format
  `BACKEND_1/2/3` s'il existe, puis redéploie le LB (~1 minute).
- **Désactiver le load balancer** : supprime `BACKENDS` (et l'ancien
  format), puis redéploie le LB, qui répond alors sa page « désactivé ».
- **Lancer les tests** : appelle `vidmoly.php`, `streamtape.php` et
  `lulustream.php` sur l'entrée publique avec les identifiants de test
  connus, et vérifie qu'un flux est trouvé.
- **Désactiver / Réactiver un extracteur** (uqload, vidmoly, streamtape,
  lulustream) : pose la variable `DISABLED_EXTRACTORS` sur chaque service
  extracteur, puis redéploie chacun (~1 minute). Un extracteur désactivé
  répond à tout appel par **HTTP 503** et un message explicite :
  `{"error": "extracteur_desactive", "message": "L'extracteur lulustream est desactive pour le moment."}`

## Sécurité

- La page est protégée par le mot de passe (`DASHBOARD_PASSWORD`) en
  authentification HTTP Basic, servie en HTTPS par Railway. Choisis un
  mot de passe long : quiconque l'a peut piloter ton load balancer.
- Le jeton Railway ne quitte jamais le service : il n'apparaît ni dans
  la page, ni dans le code, seulement dans les variables du service.
- Les actions sont protégées par un jeton CSRF de session.

## Fichiers

- `index.php` : la page, l'authentification et les actions.
- `lib/railway.php` : client de l'API GraphQL Railway (variables du
  service LB + redéploiement ; signatures vérifiées dans le schéma
  officiel le 19 novembre 2026).
- `lib/status.php` : vérifications HTTP (état des services, tests
  d'extraction).
- `statique/` : le dashboard niveau 1 (page HTML autonome, lecture seule).
- `Dockerfile` : PHP 8.3, serveur intégré, syntaxe vérifiée au build.
