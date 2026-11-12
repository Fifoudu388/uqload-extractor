// Constantes partagées par les extracteurs uqload + vidmoly (version Node.js).
// Équivalent des `const` en tête des fichiers PHP.

export const UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';

/** Hôtes autorisés pour ?url= côté uqload (protection SSRF). */
export const UQLOAD_HOSTS = [
  'uqload.vc', 'www.uqload.vc',
  'uqload.io', 'www.uqload.io',
  'uqload.net', 'www.uqload.net',
  'uqload.com', 'www.uqload.com',
  'uqload.to', 'www.uqload.to',
];

/** Hôtes autorisés pour ?url= côté vidmoly (protection SSRF). */
export const VIDMOLY_HOSTS = [
  'vidmoly.me', 'www.vidmoly.me',
  'vidmoly.to', 'www.vidmoly.to',
  'vidmoly.net', 'www.vidmoly.net',
  'vidmoly.biz', 'www.vidmoly.biz',
];

/** Alphabet du packer Dean Edwards (base 2 à 62). */
export const PACKER_ALPHABET =
  '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** Durée du cache en secondes : UQLOAD_CACHE_TTL (défaut 3600, 0 = désactivé). */
export function cacheTtlSeconds() {
  const raw = process.env.UQLOAD_CACHE_TTL;
  if (raw === undefined || raw === '') return 3600;
  const n = parseInt(raw, 10);
  return Number.isNaN(n) ? 3600 : n;
}
