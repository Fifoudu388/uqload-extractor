// Clés API + rate limit (port de parseApiKeys() / findApiKey() /
// checkRateLimit() de uqload.php et vidmoly.php — la même variable
// UQLOAD_API_KEYS protège les deux extracteurs).
// Compteurs en mémoire (fenêtre fixe de 60 s, par clé) au lieu de fichiers.
import { createHash, timingSafeEqual } from 'node:crypto';

/**
 * Clés API configurées via UQLOAD_API_KEYS, une par ligne :
 *   nom:cle[:limite_par_minute]   (limite 0 ou absente = illimitée)
 * Retourne [] si aucune clé n'est configurée (accès libre).
 */
export function parseApiKeys() {
  const raw = process.env.UQLOAD_API_KEYS;
  if (!raw || !raw.trim()) return [];
  const keys = [];
  for (const line of raw.split(/[\r\n,]+/)) {
    const l = line.trim();
    if (l === '' || l.startsWith('#')) continue;
    const i1 = l.indexOf(':');
    if (i1 <= 0) continue;
    const i2 = l.indexOf(':', i1 + 1);
    const name = l.slice(0, i1);
    const key = i2 === -1 ? l.slice(i1 + 1) : l.slice(i1 + 1, i2);
    const limitStr = i2 === -1 ? '' : l.slice(i2 + 1);
    if (!key) continue;
    keys.push({
      name,
      key,
      limit: limitStr !== '' ? Math.max(0, parseInt(limitStr, 10) || 0) : 0,
    });
  }
  return keys;
}

/**
 * Authentifie la requête : header X-API-Key ou paramètre ?key=.
 * Retourne la config de la clé trouvée, { open: true } si aucune clé
 * n'est configurée (accès libre), ou null si la clé est absente/invalide.
 */
export function findApiKey(configuredKeys, provided) {
  if (configuredKeys.length === 0) return { open: true };
  if (!provided) return null;
  for (const cfg of configuredKeys) {
    if (provided.length === cfg.key.length
      && timingSafeEqual(Buffer.from(provided), Buffer.from(cfg.key))) {
      return cfg;
    }
  }
  return null;
}

const rateLimits = new Map(); // md5(clé) -> { window, count }

/**
 * Rate limit par clé : fenêtre fixe de 60 s.
 * Retourne { allowed, limit, remaining, retry_after }.
 */
export function checkRateLimit(keyCfg) {
  const limit = keyCfg.limit ?? 0;
  if (limit <= 0) return { allowed: true, limit: 0, remaining: -1, retry_after: 0 };
  const now = Math.floor(Date.now() / 1000);
  const id = createHash('md5').update(keyCfg.key).digest('hex');
  let data = rateLimits.get(id) ?? { window: now, count: 0 };
  if (now - data.window >= 60) data = { window: now, count: 0 };
  const allowed = data.count < limit;
  if (allowed) {
    data.count++;
    rateLimits.set(id, data);
  }
  const remaining = Math.max(0, limit - data.count);
  const retryAfter = allowed ? 0 : Math.max(1, 60 - (now - data.window));
  return { allowed, limit, remaining, retry_after: retryAfter };
}
