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
