// Cache en mémoire (Map + expiration).
// Équivalent du cache fichier de la version PHP, simplifié : Node est
// mono-thread (pas besoin de verrou) et le processus vit longtemps, donc
// un cache en mémoire suffit. Il est perdu au redémarrage du serveur —
// comme le cache fichier PHP, il ne garde un résultat qu'1h par défaut
// (UQLOAD_CACHE_TTL), bien en dessous de la durée de vie du token (~4h).
import { createHash } from 'node:crypto';

const store = new Map(); // clé -> { expiresAt, data }

/** Clé de cache pour une URL de page. */
export function cacheKeyFor(url) {
  return createHash('md5').update(url).digest('hex');
}

export function cacheGet(key, ttlSeconds) {
  if (ttlSeconds <= 0) return null;
  const entry = store.get(key);
  if (!entry) return null;
  if (Date.now() > entry.expiresAt) {
    store.delete(key);
    return null;
  }
  return structuredClone(entry.data);
}

export function cacheSet(key, data, ttlSeconds) {
  if (ttlSeconds <= 0) return;
  store.set(key, { expiresAt: Date.now() + ttlSeconds * 1000, data: structuredClone(data) });
}
