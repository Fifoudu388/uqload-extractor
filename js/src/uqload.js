// Extracteur m3u8 uqload — version Node.js
// ========================================
// Port fidèle de uqload.php (v2 + mode proxy Referer/Origin).
//
// Usage web  : /uqload?id=v80v3zz0vay2   (ou ?url=https://uqload.vc/xxxx.html)
//              /uqload?id=v80v3zz0vay2&proxy=1   -> URLs média via le proxy
//              /uqload?stream=<url encodée>      -> relaye un flux .m3u8 ou un segment
// Usage CLI  : node cli.js uqload v80v3zz0vay2
//
// Referer / Origin : uqload refuse les requêtes dont le Referer/Origin n'est
// pas uqload. Côté serveur, toutes les requêtes partent donc avec Referer:
// et Origin: uqload. Mais un navigateur ne peut pas falsifier ces headers
// (headers interdits en JS) : le mode proxy évite ça (voir src/proxy.js).
//
// Le token est régénéré à chaque chargement de page (valide ~4h) et n'est
// PAS lié à l'IP. Il est présent dans le HTML, dans un script obfusqué par
// le packer "Dean Edwards" (voir src/depacker.js).
import { UQLOAD_HOSTS } from './constants.js';
import { dePacker, stripcslashes } from './depacker.js';
import { httpGet } from './http.js';
import { uqloadSiteOrigin } from './urls.js';
import { parseMasterPlaylist, rewritePlaylist } from './m3u8.js';
import { cacheGet, cacheSet, cacheKeyFor } from './cache.js';
import { serveStream } from './proxy.js';
import { sendJson } from './respond.js';

/** Cherche l'URL du master .m3u8 dans le HTML (dé-obfusqué puis brut). */
export function findMasterUrl(html) {
  const candidates = [];
  for (const text of [dePacker(html), html]) {
    // JWPlayer : file: "...master.m3u8..." (guillemets doubles ou simples)
    let m;
    if ((m = /file\s*:\s*"([^"]*master\.m3u8[^"]*)"/i.exec(text))) candidates.push(m[1]);
    if ((m = /file\s*:\s*'([^']*master\.m3u8[^']*)'/i.exec(text))) candidates.push(m[1]);
    // sources: [{file: "..."}]
    if ((m = /["']file["']\s*:\s*["']([^"']*master\.m3u8[^"']*)["']/i.exec(text))) candidates.push(m[1]);
    // URL brute vers le CDN uqload
    if ((m = /(https?:)?\/\/strm\d*\.uqload\.[a-z]+\/[^"'\\ ]*master\.m3u8[^"'\\ ]*/i.exec(text))) candidates.push(m[0]);
  }
  for (const raw of candidates) {
    let c = stripcslashes(raw.replace(/\\\//g, '/'));
    if (c.startsWith('//')) c = 'https:' + c;
    if (/^https?:\/\//i.test(c)) return c;
  }
  return null;
}

export async function extractMaster(pageUrl) {
  // Referer + Origin uqload (dérivés du domaine de la page : .vc/.io/.com/.to).
  const origin = uqloadSiteOrigin(pageUrl);
  const page = await httpGet(pageUrl, {
    Accept: 'text/html,application/xhtml+xml',
    Referer: `https://${origin}/`,
    Origin: `https://${origin}`,
  });
  if (page.code !== 200 || !page.body) {
    return { error: `page HTTP ${page.code} ${page.error}` };
  }
  const master = findMasterUrl(page.body);
  if (master === null) {
    return { error: 'token/master introuvable' };
  }
  return { master };
}

/**
 * Extraction complète (page -> master -> variantes) sans cache ni auth :
 * utilisée par le serveur web et par la CLI.
 */
export async function extractUqload(pageUrl) {
  const res = await extractMaster(pageUrl);
  if (!res.master) return res;

  const pl = await httpGet(res.master, {
    Accept: 'application/vnd.apple.mpegurl',
    Referer: pageUrl,
    Origin: 'https://' + uqloadSiteOrigin(res.master),
  });
  res.playlistHttp = pl.code;
  if (pl.code === 200 && pl.body !== '') {
    const variants = parseMasterPlaylist(pl.body, res.master);
    if (variants.length > 0) {
      res.variants = variants;
      res.best = variants[0].url;
      res.media = variants[0].url;
    } else {
      // Playlist média directe (pas de variantes) : le master est déjà le flux.
      res.media = res.master;
    }
    res.masterBody = pl.body.trim();
  }
  return res;
}

/** Construit l'URL de la page uqload à partir de ?id= ou ?url= (avec validation). */
export function buildPageUrl(input, url) {
  if (url) {
    let parts;
    try {
      parts = new URL(url);
    } catch {
      return null;
    }
    if (!['http:', 'https:'].includes(parts.protocol)) return null;
    if (!UQLOAD_HOSTS.includes(parts.hostname.toLowerCase())) return null;
    return url;
  }
  if (input) {
    if (/^https?:\/\//i.test(input)) return buildPageUrl(null, input);
    if (!/^[A-Za-z0-9_-]{3,64}$/.test(input)) return null;
    return `https://uqload.vc/${input}.html`;
  }
  return null;
}

/**
 * Ré-écrit les URLs média d'une réponse JSON en URLs de proxy (?stream=...).
 * Les URLs directes restent dans le cache ; la ré-écriture se fait à l'envoi.
 */
export function applyProxyMode(res, proxyUrlFn) {
  const origMaster = typeof res.master === 'string' ? res.master : null;
  if (origMaster) res.master = proxyUrlFn(origMaster);
  if (Array.isArray(res.variants)) {
    for (const v of res.variants) {
      if (typeof v.url === 'string') v.url = proxyUrlFn(v.url);
    }
  }
  if (typeof res.best === 'string') res.best = proxyUrlFn(res.best);
  if (typeof res.media === 'string') res.media = proxyUrlFn(res.media);
  if (typeof res.masterBody === 'string' && origMaster) {
    res.masterBody = rewritePlaylist(res.masterBody, origMaster, proxyUrlFn);
  }
  res.proxied = true;
  return res;
}

/** Gestionnaire web : /uqload et /uqload.php. */
export async function handleUqload(req, res, ctx) {
  const { searchParams, auth, proxyUrlFn, cacheTtl } = ctx;

  // Mode proxy de flux : ?stream=<url encodée> (web uniquement).
  const stream = searchParams.get('stream');
  if (stream) {
    await serveStream(req, res, stream, proxyUrlFn);
    return;
  }

  // Mode proxy JSON : ?proxy=1 -> les URLs média de la réponse passent par ?stream=
  const proxyParam = searchParams.get('proxy');
  const proxyMode = proxyParam !== null && proxyParam !== '' && proxyParam !== '0';

  const pageUrl = buildPageUrl(searchParams.get('id'), searchParams.get('url'));
  if (pageUrl === null) {
    return sendJson(res, { error: 'param ?id= (code vidéo uqload) ou ?url= (lien uqload.vc) requis/valide' }, 400);
  }

  const key = cacheKeyFor(pageUrl);
  const cached = cacheGet(key, cacheTtl);
  if (cached !== null) {
    cached.cached = true;
    if (auth && auth.name) cached.key_name = auth.name;
    if (proxyMode) applyProxyMode(cached, proxyUrlFn);
    return sendJson(res, cached);
  }

  const result = await extractUqload(pageUrl);
  if (!result.master) {
    return sendJson(res, { error: result.error ?? 'token/master introuvable' }, 502);
  }

  cacheSet(key, result, cacheTtl);
  result.cached = false;
  if (auth && auth.name) result.key_name = auth.name;
  if (proxyMode) applyProxyMode(result, proxyUrlFn);
  return sendJson(res, result);
}
