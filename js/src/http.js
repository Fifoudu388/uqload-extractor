// Client HTTP (basé sur le fetch global de Node 18+, sans dépendance).
// Équivalent de httpGet() / httpPost() / httpFetch() en PHP (cURL) :
// - suivi des redirections, timeout (25 s par défaut)
// - décompression gzip/deflate/br automatique (comme CURLOPT_ENCODING '')
// - httpFetch() capture les en-têtes de réponse (pour relayer statut/headers)
import { UA } from './constants.js';

/** GET avec en-têtes de réponse capturés (pour relayer statut et headers). */
export async function httpFetch(url, headers = {}, range = null, timeout = 25000, extra = {}) {
  const allHeaders = { Accept: '*/*', 'User-Agent': UA, ...headers };
  if (range !== null && range !== '') allHeaders.Range = range;
  let response;
  try {
    response = await fetch(url, {
      method: extra.method ?? 'GET',
      headers: allHeaders,
      redirect: 'follow',
      signal: AbortSignal.timeout(timeout),
      ...(extra.body !== undefined ? { body: extra.body } : {}),
    });
  } catch (err) {
    return { code: 0, body: Buffer.alloc(0), error: err?.message ?? String(err), headers: {} };
  }
  const buf = Buffer.from(await response.arrayBuffer());
  const respHeaders = {};
  response.headers.forEach((value, name) => {
    if (!(name in respHeaders)) respHeaders[name] = value;
  });
  return { code: response.status, body: buf, error: '', headers: respHeaders };
}

/** GET simple, corps en texte. */
export async function httpGet(url, headers = {}, timeout = 25000) {
  const res = await httpFetch(url, headers, null, timeout);
  return { code: res.code, body: res.body.toString('utf8'), error: res.error };
}

/** POST application/x-www-form-urlencoded, corps en texte. */
export async function httpPost(url, fields, headers = {}, timeout = 25000) {
  const body = new URLSearchParams(fields).toString();
  const res = await httpFetch(url, {
    ...headers,
    'Content-Type': 'application/x-www-form-urlencoded',
  }, null, timeout, { method: 'POST', body });
  return { code: res.code, body: res.body.toString('utf8'), error: res.error };
}
