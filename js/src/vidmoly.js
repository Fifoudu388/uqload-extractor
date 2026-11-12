// Extracteur m3u8/mp4 vidmoly — version Node.js
// ===========================================
// Port fidèle de vidmoly.php (v1).
//
// Usage web  : /vidmoly?id=iztl71ko757x   (ou ?url=https://vidmoly.me/v/iztl71ko757x)
// Usage CLI  : node cli.js vidmoly iztl71ko757x
//
// Mécanisme (documenté par POC-FrAnime-Scrapping et l'extracteur VidMoly de
// KekikStream, confirmé sur la page de test) :
//   1. La page /v/<id> (vidmoly.me) n'embarque pas le lecteur : elle contient
//      une iframe vers la page d'intégration, ex :
//      https://vidmoly.biz/embed-iztl71ko757x.html
//   2. La page d'intégration charge un lecteur JW Player dont la config est
//      dans le HTML : sources: [{file: "https://prx-....m3u8?t=..."}]
//      (ou file: '...master.m3u8...').
//   3. Certaines vidéos affichent un formulaire de vérification
//      ("Select number") : champs cachés op / file_code / ts / nonce / ctok
//      + réponse dans <div class="vhint"><b>...</b></div> — soumis en POST.
//   4. Le flux est du HLS (.m3u8) ; la playlist master est parsée pour
//      lister les variantes de qualité (comme uqload.js).
import { VIDMOLY_HOSTS } from './constants.js';
import { stripcslashes } from './depacker.js';
import { httpGet, httpPost } from './http.js';
import { resolveUrl } from './urls.js';
import { parseMasterPlaylist } from './m3u8.js';
import { cacheGet, cacheSet, cacheKeyFor } from './cache.js';
import { sendJson } from './respond.js';

/** Décode les entités HTML (&amp; &quot; &#039; &#x27; &#123; ...). */
export function decodeEntities(s) {
  return s
    .replace(/&#x([0-9a-fA-F]+);/g, (_m, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_m, dec) => String.fromCodePoint(parseInt(dec, 10)))
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&#039;/g, "'")
    .replace(/&#39;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&amp;/g, '&');
}

/** Extrait les champs name/value de tous les <input> d'un formulaire HTML. */
export function parseFormInputs(html) {
  const fields = {};
  const tags = html.match(/<input\b[^>]*>/gi) ?? [];
  for (const tag of tags) {
    const nm = /\bname\s*=\s*["']([^"']+)["']/i.exec(tag);
    if (!nm) continue;
    let val = '';
    const vm = /\bvalue\s*=\s*["']([^"']*)["']/i.exec(tag);
    if (vm) val = decodeEntities(vm[1]);
    fields[nm[1]] = val;
  }
  return fields;
}

function stripTags(s) {
  return s.replace(/<[^>]*>/g, '');
}

/**
 * Si la page affiche le formulaire de vérification ("Select number"),
 * le soumet en POST (réponse dans <div class="vhint"><b>...</b></div>)
 * et retourne le HTML résultant. Sinon retourne le HTML tel quel.
 */
export async function maybeSubmitVerification(url, html) {
  if (!html.toLowerCase().includes('select number')) return html;
  const fields = parseFormInputs(html);
  const m = /<div[^>]*class=["'][^"']*vhint[^"']*["'][^>]*>.*?<b[^>]*>([^<]+)<\/b>/is.exec(html);
  if (m) fields.answer = stripTags(m[1]).trim();
  if (!('op' in fields) || !('file_code' in fields)) return html;
  const res = await httpPost(url, fields, { Referer: url, 'Sec-Fetch-Dest': 'iframe' });
  if (res.code === 200 && res.body !== '') return res.body;
  return html;
}

/** Trouve l'URL de la page d'intégration vidmoly (/embed-XXXX.html) dans une iframe. */
export function findEmbedUrl(html, baseUrl) {
  const re = /<iframe\b[^>]*\bsrc\s*=\s*["']([^"']+)["']/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    const abs = resolveUrl(baseUrl, decodeEntities(m[1]));
    if (/^https?:\/\/(www\.)?vidmoly\.(me|to|net|biz)\/embed-[A-Za-z0-9_-]+\.html(\?[^#]*)?$/i.test(abs)) {
      return abs;
    }
  }
  return null;
}

/**
 * Cherche l'URL du flux vidéo dans le HTML, par spécificité décroissante :
 *   1. tableau sources: [{file: "..."}] du lecteur JW Player
 *   2. file: "...m3u8..." (guillemets doubles ou simples)
 *   3. n'importe quelle URL .m3u8 dans le HTML
 *   4. file: "..." générique (hors images)
 * Les .m3u8 sont préférés aux autres formats.
 */
export function findStreamUrl(html) {
  const candidates = [];
  // Tier 1 : tableau sources du JW Player
  const sm = /sources\s*:\s*\[(.*?)\]/is.exec(html);
  if (sm) {
    for (const fm of sm[1].matchAll(/["']?file["']?\s*:\s*["']([^"']+)["']/gi)) {
      candidates.push(fm[1]);
    }
  }
  // Tier 2 : file: "...m3u8..."
  let m;
  if ((m = /file\s*:\s*"([^"]*\.m3u8[^"]*)"/i.exec(html))) candidates.push(m[1]);
  if ((m = /file\s*:\s*'([^']*\.m3u8[^']*)'/i.exec(html))) candidates.push(m[1]);
  // Tier 3 : URL .m3u8 brute dans le HTML
  for (const u of html.matchAll(/(https?:)?\/\/[^"'\\\s<>]*\.m3u8[^"'\\\s<>]*/gi)) {
    candidates.push(u[0]);
  }
  // Tier 4 : file: "..." générique
  if ((m = /file\s*:\s*"([^"]+)"/i.exec(html))) candidates.push(m[1]);
  if ((m = /file\s*:\s*'([^']+)'/i.exec(html))) candidates.push(m[1]);
  // D'abord les .m3u8, puis le reste.
  const ordered = [
    ...candidates.filter((c) => c.toLowerCase().includes('.m3u8')),
    ...candidates.filter((c) => !c.toLowerCase().includes('.m3u8')),
  ];
  for (const raw of ordered) {
    let c = stripcslashes(raw.replace(/\\\//g, '/').trim());
    if (c.startsWith('//')) c = 'https:' + c;
    if (!/^https?:\/\//i.test(c)) continue;
    if (/\.(jpe?g|png|gif|webp|svg|css|js)(\?|$)/i.test(c)) continue;
    return c;
  }
  return null;
}

/** Sous-titres embarqués : tableau tracks: [{file, label, kind}] du lecteur. */
export function findSubtitles(html) {
  const subs = [];
  const tm = /tracks\s*:\s*\[(.*?)\]/is.exec(html);
  if (!tm) return subs;
  for (const obj of tm[1].matchAll(/\{([^{}]*)\}/g)) {
    const f = /file\s*:\s*["']([^"']+)["']/i.exec(obj[1]);
    if (!f) continue;
    const l = /label\s*:\s*["']([^"']+)["']/i.exec(obj[1]);
    const k = /kind\s*:\s*["']([^"']+)["']/i.exec(obj[1]);
    subs.push({
      file: stripcslashes(f[1].replace(/\\\//g, '/')),
      label: l ? l[1] : null,
      kind: k ? k[1] : null,
    });
  }
  return subs;
}

/** Récupère le flux : page /v/ -> iframe d'intégration -> config du lecteur. */
export async function extractStream(pageUrl) {
  const page = await httpGet(pageUrl, {
    Accept: 'text/html,application/xhtml+xml',
    Referer: 'https://vidmoly.me/',
  });
  if (page.code !== 200 || !page.body) {
    return { error: `page HTTP ${page.code} ${page.error}` };
  }
  let html = await maybeSubmitVerification(pageUrl, page.body);
  const embedUrl = findEmbedUrl(html, pageUrl);
  if (embedUrl !== null && embedUrl !== pageUrl) {
    const embed = await httpGet(embedUrl, {
      Accept: 'text/html,application/xhtml+xml',
      Referer: pageUrl,
      'Sec-Fetch-Dest': 'iframe',
    });
    if (embed.code === 200 && embed.body !== '') {
      html = await maybeSubmitVerification(embedUrl, embed.body);
    }
  }
  const stream = findStreamUrl(html);
  if (stream === null) {
    return { error: 'flux vidéo introuvable' };
  }
  const res = { master: stream, embed: embedUrl ?? pageUrl };
  const subs = findSubtitles(html);
  if (subs.length > 0) res.subtitles = subs;
  return res;
}

/**
 * Extraction complète (page -> flux -> variantes) sans cache ni auth :
 * utilisée par le serveur web et par la CLI.
 */
export async function extractVidmoly(pageUrl) {
  const res = await extractStream(pageUrl);
  if (!res.master) return res;

  const master = res.master;
  if (master.toLowerCase().includes('.m3u8')) {
    res.type = 'm3u8';
    const pl = await httpGet(master, {
      Accept: 'application/vnd.apple.mpegurl',
      Referer: pageUrl,
    });
    res.playlistHttp = pl.code;
    if (pl.code === 200 && pl.body !== '') {
      const variants = parseMasterPlaylist(pl.body, master);
      if (variants.length > 0) {
        res.variants = variants;
        res.best = variants[0].url;
        res.media = variants[0].url;
      } else {
        // Playlist média directe (pas de variantes) : le master est déjà le flux.
        res.media = master;
      }
      res.masterBody = pl.body.trim();
    } else {
      // Playlist injoignable : le master reste utilisable tel quel.
      res.media = master;
    }
  } else {
    res.type = 'mp4';
    res.media = master;
  }
  return res;
}

/** Construit l'URL de la page vidmoly à partir de ?id= ou ?url= (avec validation). */
export function buildPageUrl(input, url) {
  if (url) {
    let parts;
    try {
      parts = new URL(url);
    } catch {
      return null;
    }
    if (!['http:', 'https:'].includes(parts.protocol)) return null;
    if (!VIDMOLY_HOSTS.includes(parts.hostname.toLowerCase())) return null;
    return url;
  }
  if (input) {
    if (/^https?:\/\//i.test(input)) return buildPageUrl(null, input);
    if (!/^[A-Za-z0-9_-]{3,64}$/.test(input)) return null;
    return `https://vidmoly.me/v/${input}`;
  }
  return null;
}

/** Gestionnaire web : /vidmoly et /vidmoly.php. */
export async function handleVidmoly(req, res, ctx) {
  const { searchParams, auth, cacheTtl } = ctx;

  const pageUrl = buildPageUrl(searchParams.get('id'), searchParams.get('url'));
  if (pageUrl === null) {
    return sendJson(res, { error: 'param ?id= (code vidéo vidmoly) ou ?url= (lien vidmoly.me/to/net/biz) requis/valide' }, 400);
  }

  const key = cacheKeyFor(pageUrl);
  const cached = cacheGet(key, cacheTtl);
  if (cached !== null) {
    cached.cached = true;
    if (auth && auth.name) cached.key_name = auth.name;
    return sendJson(res, cached);
  }

  const result = await extractVidmoly(pageUrl);
  if (!result.master) {
    return sendJson(res, { error: result.error ?? 'flux vidéo introuvable' }, 502);
  }

  cacheSet(key, result, cacheTtl);
  result.cached = false;
  if (auth && auth.name) result.key_name = auth.name;
  return sendJson(res, result);
}
