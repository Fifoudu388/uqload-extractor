// Serveur HTTP des extracteurs uqload + vidmoly — version Node.js.
// Zéro dépendance (Node 18+, fetch global + http intégré).
//
// Routes :
//   GET /                  -> healthcheck JSON
//   GET /uqload[?id=|?url=]      (+ ?proxy=1, ?stream=...) — voir src/uqload.js
//   GET /uqload.php ...           (alias, même comportement — compatible PHP)
//   GET /vidmoly[?id=|?url=]     — voir src/vidmoly.js
//   GET /vidmoly.php ...          (alias, même comportement — compatible PHP)
//
// Auth API (UQLOAD_API_KEYS) et rate limit : voir src/auth.js — la même
// variable protège les deux extracteurs (et ?stream= / ?proxy=1).
// Le port vient de $PORT (Railway l'injecte), 8080 en local.
import http from 'node:http';
import { cacheTtlSeconds } from './src/constants.js';
import { parseApiKeys, findApiKey, checkRateLimit } from './src/auth.js';
import { handleUqload } from './src/uqload.js';
import { handleVidmoly } from './src/vidmoly.js';
import { sendJson } from './src/respond.js';

const PORT = parseInt(process.env.PORT ?? '8080', 10);
const apiKeys = parseApiKeys();

/** URL absolue du script courant pour atteindre le mode proxy (?stream=...). */
function proxyUrlFor(req, pathname) {
  const proto = req.headers['x-forwarded-proto']
    ?? (req.socket.encrypted ? 'https' : 'http');
  const host = req.headers.host ?? 'localhost';
  return (target) => `${proto}://${host}${pathname}?stream=${encodeURIComponent(target)}`;
}

const server = http.createServer(async (req, res) => {
  try {
    res.setHeader('Access-Control-Allow-Origin', '*');
    if (req.method === 'OPTIONS') {
      res.writeHead(204);
      res.end();
      return;
    }

    const url = new URL(req.url, 'http://localhost');
    const path = url.pathname;

    if (path === '/') {
      return sendJson(res, {
        status: 'online',
        service: 'uqload + vidmoly extractors (Node.js)',
        usage: '/uqload?id=VIDEO_ID (&proxy=1 pour passer le flux par le proxy) ou /vidmoly?id=VIDEO_ID (ou ?url=...)',
        example: '/vidmoly?id=iztl71ko757x',
      });
    }

    const route = (path === '/uqload' || path === '/uqload.php') ? 'uqload'
      : (path === '/vidmoly' || path === '/vidmoly.php') ? 'vidmoly'
      : null;
    if (!route) return sendJson(res, { error: 'route inconnue' }, 404);

    // ---- Authentification par clé API (si des clés sont configurées)
    const provided = req.headers['x-api-key'] ?? url.searchParams.get('key') ?? null;
    const auth = findApiKey(apiKeys, typeof provided === 'string' ? provided : null);
    if (apiKeys.length > 0 && auth === null) {
      return sendJson(res, { error: 'API key manquante ou invalide (header X-API-Key ou ?key=)' }, 401);
    }
    if (auth && !auth.open) {
      const rl = checkRateLimit(auth);
      if (rl.limit > 0) {
        res.setHeader('X-RateLimit-Limit', String(rl.limit));
        res.setHeader('X-RateLimit-Remaining', String(rl.remaining));
      }
      if (!rl.allowed) {
        res.setHeader('Retry-After', String(rl.retry_after));
        return sendJson(res, { error: 'rate limit atteint pour cette clé', retry_after: rl.retry_after }, 429);
      }
    }

    const ctx = {
      searchParams: url.searchParams,
      auth,
      proxyUrlFn: proxyUrlFor(req, path),
      cacheTtl: cacheTtlSeconds(),
    };
    if (route === 'uqload') return handleUqload(req, res, ctx);
    return handleVidmoly(req, res, ctx);
  } catch (err) {
    console.error(err);
    if (!res.headersSent) return sendJson(res, { error: 'erreur interne' }, 500);
    res.destroy();
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`uqload/vidmoly (Node.js) en écoute sur le port ${PORT}`);
});
