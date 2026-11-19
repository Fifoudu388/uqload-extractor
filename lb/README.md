# Load balancer des extracteurs

Un service Caddy qui repartit le trafic entre plusieurs copies du service
extracteur sur Railway, et qui sort de la rotation tout backend en panne.

**Il est désactivé par défaut** : tant qu'aucun backend n'est configuré,
le service répond juste « Load balancer désactivé » et ne relaie rien.
C'est toi qui l'actives, et toi qui choisis combien de serveurs vont
derrière (1, 2, 3… autant que tu veux).

## Pourquoi un load balancer ici

- **Disponibilité** : si un service est en cours de redéploiement ou tombe,
  les requêtes vont vers les autres — pas d'interruption.
- **Répartition** : les nouveaux visiteurs sont répartis entre les backends.
- **Sessions collantes (obligatoire)** : le lien `get_video` est lié à l'IP
  du serveur qui l'a généré. La requête d'extraction (`?proxy=1`) et la
  requête de lecture (`?stream=`) doivent arriver sur le **même** backend,
  sinon le CDN répond `403 "unauthorized request"`. Caddy pose un cookie
  `lb` à la première réponse et le navigateur reste ensuite sur le même
  backend. Ne pas remplacer par du round-robin pur sans avoir vérifié que
  les backends partagent la même IP de sortie.

## Activer / désactiver

Tout se joue dans les variables du service LB sur Railway (Settings →
Variables), puis un redémarrage / redéploiement du service :

- **Activer** : définir `BACKENDS` avec les URL publiques des services
  extracteurs, séparées par des espaces (ou des virgules) :

  ```
  BACKENDS=https://extracteur-1.up.railway.app https://extracteur-2.up.railway.app
  ```

  Une seule URL = un seul serveur derrière le LB (relais simple, utile
  pour préparer la bascule). Trois URL = trois serveurs. Ajouter ou
  retirer un serveur = modifier la liste et redémarrer.

- **Désactiver** : vider ou supprimer `BACKENDS` (et remettre le domaine
  public sur un service extracteur si tu veux que le site continue de
  répondre sans passer par le LB).

- L'ancien format `BACKEND_1`, `BACKEND_2`, `BACKEND_3` est toujours
  accepté quand `BACKENDS` n'est pas défini.

## Déploiement sur Railway

1. **Dupliquer le service extracteur** (dashboard Railway, service existant
   → Duplicate) autant de fois que tu veux de serveurs derrière le LB.
   Vérifie que chaque copie a bien les mêmes variables d'environnement
   (`UQLOAD_API_KEYS`, etc.). Chaque copie a son propre domaine
   `*.up.railway.app` — note-les.
2. **Créer le service LB** : New Service → GitHub Repo → `uqload-extractor`
   → **Root Directory : `lb/`** (sinon Railway build le Dockerfile de
   l'extracteur au lieu de celui-ci). À ce stade il est déployé mais
   **désactivé** : il répond « Load balancer désactivé ».
3. **Variables du service LB** (Settings → Variables) :
   - `BACKENDS` = les URL des extracteurs, séparées par des espaces
     (voir « Activer / désactiver » ci-dessus).
   - `PORT` est injecté par Railway, ne pas le définir.
4. **Déplacer le domaine** `stream.visionary.qzz.io` du service extracteur
   vers le service LB (Settings → Networking → Custom Domain : le retirer
   de l'ancien, l'ajouter au nouveau). C'est le LB qui devient l'adresse
   publique ; les extracteurs restent joignables par leurs domaines
   `*.up.railway.app` (utiles pour tester un backend directement).
5. **Tester** : `curl -i https://stream.visionary.qzz.io/` doit répondre
   200 (la liste des endpoints) ; refais la requête avec le cookie `lb`
   renvoyé pour vérifier que tu restes sur le même backend. Coupe un
   backend (pause ou redéploiement) : les requêtes doivent continuer de
   marcher sur les autres.

## Coût

Chaque service facture son temps de calcul actif : N extracteurs + 1 LB.
Le LB lui-même consomme très peu (il ne fait que relayer). Si le trafic
est faible, 2 backends suffisent — et tant que le LB est désactivé, tu
peux même supprimer son service et ne garder qu'un seul extracteur.

## Variante sans LB : les réplicas Railway

Railway sait aussi le faire tout seul : service extracteur → Settings →
Replicas → 3. Railway répartit alors le trafic et retire les réplicas en
panne. Deux limites : **pas de sessions collantes** (chaque requête peut
atterrir sur n'importe quel réplica) et chaque réplica est facturé. Si les
réplicas partagent la même IP de sortie, l'absence de sessions collantes
ne pose pas de problème — à tester : mets 3 réplicas, rejoue plusieurs
fois le flux `?proxy=1` puis lecture ; si des 403 apparaissent par
intermittence, reviens au LB Caddy ci-dessus.

## Changer la stratégie de répartition

Dans le `Caddyfile` (le modèle de configuration), `lb_policy` ne prend
qu'une seule politique :

- `cookie lb` (actuel) : sessions collantes, répartition des nouveaux
  visiteurs au hasard — le bon choix tant que les backends n'ont pas la
  même IP de sortie.
- `least_conn` : le backend avec le moins de requêtes **en cours** — le
  vrai « moins de requêtes » — mais sans affinité : à utiliser seulement
  si les backends partagent la même IP de sortie (vérifiable en comparant
  la réponse de `https://api.ipify.org` sur chaque service).
- `round_robin` : tour à tour, 1, 2, 3, 1, 2, 3… — simple, mais sans
  affinité non plus.

## Fichiers

- `entrypoint.sh` : lit `BACKENDS` au démarrage et génère la
  configuration ; sans backend configuré, génère la page « désactivé ».
- `Caddyfile` : le modèle de configuration (la liste des backends y est
  injectée au démarrage ; health checks actifs sur `GET /` toutes les
  10 s + passifs sur connexion refusée, retry 5 s).
- `Dockerfile` : image `caddy:2-alpine` + modèle + entrypoint.
