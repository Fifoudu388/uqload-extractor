// Parsing et réécriture de playlists .m3u8 (port de parseMasterPlaylist()
// et rewritePlaylist() de uqload.php).
import { resolveUrl } from './urls.js';

/** Parse un master .m3u8 : variantes triées par bande passante décroissante. */
export function parseMasterPlaylist(body, masterUrl) {
  const variants = [];
  const lines = body.split(/\r\n|\n|\r/);
  let pending = null;
  for (const raw of lines) {
    const line = raw.trim();
    if (line === '') continue;
    if (line.startsWith('#EXT-X-STREAM-INF:')) {
      let bw = null;
      let res = null;
      const bm = /BANDWIDTH=(\d+)/.exec(line);
      if (bm) bw = parseInt(bm[1], 10);
      const rm = /RESOLUTION=(\d+)x(\d+)/.exec(line);
      if (rm) res = rm[1] + 'x' + rm[2];
      pending = { bandwidth: bw, resolution: res };
      continue;
    }
    if (line.startsWith('#')) continue;
    if (pending !== null) {
      pending.url = resolveUrl(masterUrl, line);
      variants.push(pending);
      pending = null;
    }
  }
  variants.sort((a, b) => (b.bandwidth ?? 0) - (a.bandwidth ?? 0));
  return variants;
}

/**
 * Ré-écrit une playlist .m3u8 : chaque URI (segment ou variante) et chaque
 * URI="..." (ex : clé EXT-X-KEY) passe par le proxy (proxyUrlFn).
 */
export function rewritePlaylist(body, baseUrl, proxyUrlFn) {
  const out = [];
  for (const raw of body.split(/\r\n|\n|\r/)) {
    const line = raw.trim();
    if (line === '') {
      out.push('');
      continue;
    }
    if (line.startsWith('#')) {
      if (line.includes('URI="')) {
        out.push(line.replace(/URI="([^"]+)"/g, (_m, uri) => `URI="${proxyUrlFn(resolveUrl(baseUrl, uri))}"`));
      } else {
        out.push(line);
      }
      continue;
    }
    out.push(proxyUrlFn(resolveUrl(baseUrl, line)));
  }
  return out.join('\n') + '\n';
}
