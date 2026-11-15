// Résolution d'URL et domaines uqload (port de resolveUrl() /
// uqloadBaseDomain() / uqloadSiteOrigin() de uqload.php).

/** Résout une URL relative par rapport à une URL de base. */
export function resolveUrl(base, rel) {
  if (/^https?:\/\//i.test(rel)) return rel;
  if (rel.startsWith('//')) return 'https:' + rel;
  const u = new URL(base);
  if (rel.startsWith('/')) return `${u.protocol}//${u.host}${rel}`;
  const path = u.pathname;
  const dir = path.slice(0, path.lastIndexOf('/') + 1);
  return `${u.protocol}//${u.host}${dir}${rel}`;
}

/**
 * Domaine uqload de base d'un hôte (ex : 'uqload.vc' pour 'strm1.uqload.vc',
 * 'www.uqload.vc' ou 'uqload.vc'), null si ce n'est pas un domaine uqload.
 */
export function uqloadBaseDomain(host) {
  const m = /^(?:[a-z0-9-]+\.)*uqload\.(vc|io|net|com|to)$/.exec(host.toLowerCase());
  return m ? 'uqload.' + m[1] : null;
}

/** Origine uqload (domaine de base) à utiliser en Referer/Origin pour une URL. */
export function uqloadSiteOrigin(url) {
  let host = '';
  try {
    host = new URL(url).hostname;
  } catch {
    return 'uqload.vc';
  }
  return uqloadBaseDomain(host) ?? 'uqload.vc';
}

/**
 * Hôte autorisé pour le relais de flux vidmoly (?stream=) :
 * - un domaine vidmoly (me/to/net/biz, avec sous-domaines) ;
 * - le CDN qui héberge les flux : *.vmpx.online (ex : prx-1331-ant-q.vmpx.online).
 * Les URLs de flux vidmoly sont liées à l'IP qui a chargé la page
 * (token ?t=...&asn=...) : seul le serveur (IP fixe) peut les récupérer,
 * d'où le passage obligé par le proxy.
 */
export function vidmolyMediaHostAllowed(host) {
  const h = host.toLowerCase();
  if (/^(?:[a-z0-9-]+\.)*vidmoly\.(me|to|net|biz)$/.test(h)) return true;
  if (/^(?:[a-z0-9-]+\.)*vmpx\.online$/.test(h)) return true;
  return false;
}
