// Mode proxy de flux (port de serveStream() de uqload.php).
// Récupère un .m3u8 ou un segment côté serveur avec les bons headers,
// puis le sert au client.
// - playlist .m3u8 : les URLs sont ré-écrites vers ?stream= (le lecteur ne
//   touche jamais le CDN directement)
// - segment : le Range du client est transmis (seek), statut 200/206 et
//   headers utiles relayés
// Sécurité : seuls les domaines autorisés sont relayés (anti-SSRF) et la clé
// API (si configurée) est exigée en amont comme pour l'extraction.
// `opts` (optionnel) adapte le relais à la source :
//   allowedHost : (host) => bool   (défaut : domaines uqload)
//   referer     : string           (défaut : https://<origine uqload>/)
//   origin      : string           (défaut : https://<origine uqload>)
//   label       : string           (défaut : 'uqload', pour les messages)
import { httpFetch } from './http.js';
import { uqloadBaseDomain, uqloadSiteOrigin } from './urls.js';
import { rewritePlaylist } from './m3u8.js';
import { sendJson } from './respond.js';

export async function serveStream(req, res, target, proxyUrlFn, opts = {}) {
  const allowedHost = opts.allowedHost ?? ((h) => uqloadBaseDomain(h) !== null);
  const label = opts.label ?? 'uqload';
  let parsed;
  try {
    parsed = new URL(target);
  } catch {
    return sendJson(res, { error: `URL de flux invalide (domaines ${label} uniquement)` }, 400);
  }
  if (!['http:', 'https:'].includes(parsed.protocol)
    || !allowedHost(parsed.hostname)) {
    return sendJson(res, { error: `URL de flux invalide (domaines ${label} uniquement)` }, 400);
  }

  const origin = opts.origin ?? uqloadSiteOrigin(target);
  const referer = opts.referer ?? `https://${origin}/`;
  const path = parsed.pathname ?? '';
  let range = req.headers.range ?? null;
  if (path.toLowerCase().endsWith('.m3u8')) {
    range = null; // une playlist se récupère entière, pas par morceaux
  }

  const fetched = await httpFetch(target, {
    Referer: referer,
    Origin: `https://${origin}`,
  }, range);

  if (fetched.code < 200 || fetched.code >= 400 || fetched.body.length === 0) {
    return sendJson(res, { error: `flux HTTP ${fetched.code} ${fetched.error}` }, 502);
  }

  const ctype = (fetched.headers['content-type'] ?? '').toLowerCase();
  const isPlaylist = path.toLowerCase().endsWith('.m3u8') || ctype.includes('mpegurl');
  if (isPlaylist) {
    res.writeHead(200, {
      'Content-Type': 'application/vnd.apple.mpegurl; charset=utf-8',
      'Cache-Control': 'no-cache',
      'Access-Control-Allow-Origin': '*',
    });
    res.end(rewritePlaylist(fetched.body.toString('utf8'), target, proxyUrlFn));
    return;
  }

  res.statusCode = fetched.code;
  if (fetched.headers['content-type']) {
    res.setHeader('Content-Type', fetched.headers['content-type']);
  }
  for (const h of ['content-range', 'accept-ranges', 'content-length']) {
    if (fetched.headers[h]) res.setHeader(h, fetched.headers[h]);
  }
  res.setHeader('Cache-Control', 'public, max-age=3600');
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.end(fetched.body);
}
