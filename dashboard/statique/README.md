# Dashboard extracteurs — niveau 1 (lecture seule)

Page unique, sans serveur : `index.html`. Elle ne modifie rien et n'a besoin
d'aucun jeton.

## Ce qu'elle fait

- **Serveurs extracteurs** : `GET /` sur chaque backend listé (par défaut
  `https://stream.visionary.qzz.io`, vérifié en ligne le 19 nov. 2026 vers
  18h49 : HTTP 200, `{"status":"online",...}`, ~1 s). En ligne / hors ligne
  + temps de réponse, actualisation auto toutes les 30 s. On ajoute ou retire
  des serveurs dans la page (gardés dans le navigateur).
- **Load balancer** : coller l'URL publique du service LB quand il est déployé.
  La page distingue « Désactivé » (le LB répond « Load balancer désactivé »,
  BACKENDS vide — le réglage choisi) d'« Actif » (il relaie et répond comme un
  extracteur).
- **Tester un extracteur** : choisir serveur + extracteur (uqload, vidmoly,
  streamtape, lulustream), coller un ID vidéo, lancer. La clé API est
  facultative : nécessaire seulement si `UQLOAD_API_KEYS` est défini sur le
  service (sinon 401). Elle est tapée dans la page, gardée dans le
  `localStorage` de ce navigateur uniquement, envoyée directement au serveur
  extracteur — jamais ailleurs. Les 4 extracteurs envoient
  `Access-Control-Allow-Origin: *`, donc les appels passent depuis le
  navigateur.

## L'utiliser

- Ouvrir `index.html` directement dans le navigateur (double-clic), ou
  l'héberger comme page statique (GitHub Pages, Railway, InfinityFree comme
  Visionary). Aucun build.
- Exemples d'ID pour tester : vidmoly `iztl71ko757x`, streamtape
  `MkGWp47a8bUma4o`, lulustream `q7qp31locaqo`.

## Ce qu'elle ne fait pas (niveau 2, dans le dossier parent)

- Pas de mot de passe : il n'y a rien de secret dedans — les endpoints de
  santé sont publics, la clé API reste dans le navigateur de Rafael.
- Pas de contrôle du LB (activer / couper, choisir les backends) : c'est le
  rôle du dashboard de niveau 2 (le service PHP du dossier parent,
  `../README.md`), choisi par Rafael le 19 novembre 2026. Il utilise un
  jeton Railway gardé dans les variables du service dashboard, jamais
  dans le chat ni dans un fichier.

## État

- Dans le dépôt `Fifoudu388/uqload-extractor`, dossier
  `dashboard/statique/` (poussé le 19 novembre 2026, en même temps que le
  niveau 2). Vérifié : HTML équilibré, JS valide (`node --check`), santé
  du backend par défaut confirmée en direct.
