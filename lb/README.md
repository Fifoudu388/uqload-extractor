# Load balancer des extracteurs

Un service Caddy qui repartit le trafic entre 2 ou 3 copies du service
extracteur sur Railway, et qui sort de la rotation tout backend en panne.

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

## Déploiement sur Railway

1. **Dupliquer le service extracteur** (dashboard Railway, service existant
   → Duplicate) deux fois : `extracteur-2`, `extracteur-3`. Vérifie que
   chaque copie a bien les mêmes variables d'environnement
   (`UQLOAD_API_KEYS`, etc.). Chaque copie a son propre domaine
   `*.up.railway.app` — note les trois.
2. **Créer le service LB** : New Service → GitHub Repo → `uqload-extractor`
   → **Root Directory : `lb/`** (sinon Railway build le Dockerfile de
   l'extracteur au lieu de celui-ci).
3. **Variables du service LB** (Settings → Variables) :
   - `BACKEND_1` = `https://<domaine-extracteur-1>.up.railway.app`
   - `BACKEND_2` = `https://<domaine-extracteur-2>.up.railway.app`
   - `BACKEND_3` = `https://<domaine-extracteur-3>.up.railway.app`
   - `PORT` est injecté par Railway, ne pas le définir.
   (Avec 2 backends seulement, mets deux fois la même URL ou retire le
   3e upstream du Caddyfile.)
4. **Déplacer le domaine** `stream.visionary.qzz.io` du service extracteur
   vers le service LB (Settings → Networking → Custom Domain : le retirer
   de l'ancien, l'ajouter au nouveau). C'est le LB qui devient l'adresse
   publique ; les 3 extracteurs restent joignables par leurs domaines
   `*.up.railway.app` (utiles pour tester un backend directement).
5. **Tester** : `curl -i https://stream.visionary.qzz.io/` doit répondre
   200 (la liste des endpoints) ; refais la requête avec le cookie `lb`
   renvoyé pour vérifier que tu restes sur le même backend. Coupe un
   backend (pause ou redéploiement) : les requêtes doivent continuer de
   marcher sur les autres.

## Coût

4 services qui tournent (3 extracteurs + 1 LB) : chacun facture son temps
de calcul actif. Le LB lui-même consomme très peu (il ne fait que relayer).
Si le trafic est faible, 2 backends suffisent.

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

Dans le `Caddyfile`, `lb_policy` ne prend qu'une seule politique :

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

- `Caddyfile` : la config (backends en variables d'environnement,
  health checks actifs sur `GET /` toutes les 10 s + passifs sur
  connexion refusée, retry 5 s).
- `Dockerfile` : image `caddy:2-alpine` + Caddyfile.
