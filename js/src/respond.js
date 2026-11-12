// Réponses JSON (équivalent de sendJson() en PHP : JSON indenté + \n final).
export function sendJson(res, data, code = 200) {
  const body = JSON.stringify(data, null, 2) + '\n';
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(body);
}
