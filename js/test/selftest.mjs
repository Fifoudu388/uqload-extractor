// Tests (sans dépendance) des extracteurs Node.js.
//   node test/selftest.mjs           -> unitaires + intégration (serveur simulé)
//   LIVE=1 node test/selftest.mjs    -> + test live contre uqload.vc et vidmoly.me
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { dePacker, baseEncode } from '../src/depacker.js';
import { parseMasterPlaylist, rewritePlaylist } from '../src/m3u8.js';
import { applyProxyMode, extractUqload, findMasterUrl } from '../src/uqload.js';
import { findStreamUrl, findSubtitles } from '../src/vidmoly.js';

const JS_DIR = join(dirname(fileURLToPath(import.meta.url)), '..');
const LIVE = process.env.LIVE === '1';

let passed = 0;
function ok(name, fn) {
  return Promise.resolve()
    .then(fn)
    .then(() => { passed++; console.log(`  ✓ ${name}`); })
    .catch((err) => { console.error(`  ✗ ${name}\n    ${err.message}`); throw err; });
}

const fakeProxy = (u) => 'https://srv/uqload?stream=' + encodeURIComponent(u);

// ---------- Unitaires ----------
console.log('Unitaires :');
await ok('baseEncode (base 62)', () => {
  assert.equal(baseEncode(0, 62), '0');
  assert.equal(baseEncode(9, 62), '9');
  assert.equal(baseEncode(10, 62), 'a');
  assert.equal(baseEncode(35, 62), 'z');
  assert.equal(baseEncode(36, 62), 'A');
  assert.equal(baseEncode(61, 62), 'Z');
  assert.equal(baseEncode(62, 62), '10');
  assert.equal(baseEncode(63, 62), '11');
  assert.equal(baseEncode(69, 62), '17');
  assert.equal(baseEncode(125, 62), '21');
});

await ok('dePacker base 62 (70 clés, tokens multi-caractères)', () => {
  const keys = Array.from({ length: 70 }, (_v, i) => `k${i}`);
  const idx = [0, 1, 9, 10, 35, 36, 61, 62, 63, 69];
  const payload = idx.map((i) => baseEncode(i, 62)).join(' ');
  const packed = `x}('${payload}',62,70,'${keys.join('|')}'.split('|'),0,{})`;
  assert.equal(dePacker(packed), idx.map((i) => `k${i}`).join(' '));
});

await ok('dePacker guillemets doubles', () => {
  const packed = `x}("0 1",62,2,"foo|bar".split("|"),0,{})`;
  assert.equal(dePacker(packed), 'foo bar');
});

await ok("dePacker caractères échappés (\\' et \\/)", () => {
  // La clé contient une apostrophe, écrite l\'homme dans le source packé.
  const packed = String.raw`x}('0',62,1,'l\'homme'.split('|'),0,{})`;
  assert.equal(dePacker(packed), "l'homme");
});

await ok('dePacker sans packer (texte brut inchangé)', () => {
  assert.equal(dePacker('file:"https://x/master.m3u8"'), 'file:"https://x/master.m3u8"');
});

await ok('findMasterUrl (dé-obfusqué + brut)', () => {
  const packed = `x}('file:"https://strm1.uqload.vc/abc/master.m3u8?token=t" 0',62,1,'zz'.split('|'),0,{})`;
  assert.equal(findMasterUrl(packed), 'https://strm1.uqload.vc/abc/master.m3u8?token=t');
  assert.equal(findMasterUrl(`file: 'https://strm2.uqload.vc/m/master.m3u8'`), 'https://strm2.uqload.vc/m/master.m3u8');
  assert.equal(findMasterUrl('rien ici'), null);
});

await ok('parseMasterPlaylist (tri par bande passante)', () => {
  const body = [
    '#EXTM3U',
    '#EXT-X-STREAM-INF:BANDWIDTH=1200000,RESOLUTION=854x480',
    '480.m3u8',
    '#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720',
    '720.m3u8',
    '#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360',
    'https://cdn.example.com/360.m3u8',
    '',
  ].join('\n');
  const v = parseMasterPlaylist(body, 'https://strm1.uqload.vc/x/master.m3u8');
  assert.equal(v.length, 3);
  assert.equal(v[0].bandwidth, 2500000);
  assert.equal(v[0].resolution, '1280x720');
  assert.equal(v[0].url, 'https://strm1.uqload.vc/x/720.m3u8');
  assert.equal(v[2].url, 'https://cdn.example.com/360.m3u8');
});

await ok('rewritePlaylist (segments + clé EXT-X-KEY)', () => {
  const body = [
    '#EXTM3U',
    '#EXT-X-KEY:METHOD=AES-128,URI="key.php?id=1"',
    'seg1.ts',
    'https://cdn.example.com/seg2.ts',
    '',
  ].join('\n');
  const out = rewritePlaylist(body, 'https://strm1.uqload.vc/x/list.m3u8', fakeProxy);
  assert.match(out, /URI="https:\/\/srv\/uqload\?stream=https%3A%2F%2Fstrm1\.uqload\.vc%2Fx%2Fkey\.php%3Fid%3D1"/);
  assert.match(out, /stream=https%3A%2F%2Fstrm1\.uqload\.vc%2Fx%2Fseg1\.ts/);
  assert.match(out, /stream=https%3A%2F%2Fcdn\.example\.com%2Fseg2\.ts/);
});

await ok('applyProxyMode (JSON + masterBody)', () => {
  const res = {
    master: 'https://strm1.uqload.vc/x/master.m3u8',
    variants: [{ bandwidth: 100, resolution: '640x360', url: 'https://strm1.uqload.vc/x/360.m3u8' }],
    best: 'https://strm1.uqload.vc/x/360.m3u8',
    media: 'https://strm1.uqload.vc/x/360.m3u8',
    masterBody: '#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=100\n360.m3u8\n',
  };
  applyProxyMode(res, fakeProxy);
  assert.equal(res.proxied, true);
  assert.ok(res.master.startsWith('https://srv/uqload?stream='));
  assert.ok(res.best.startsWith('https://srv/uqload?stream='));
  assert.match(res.masterBody, /stream=https%3A%2F%2Fstrm1\.uqload\.vc%2Fx%2F360\.m3u8/);
});

await ok('findStreamUrl / findSubtitles (vidmoly)', () => {
  const html = `jwplayer("p").setup({ sources: [{file: "https://prx-vid.example.com/v/master.m3u8?t=abc"}],
    tracks: [{file: "https://prx-vid.example.com/subs/fr.vtt", label: "Français", kind: "captions"}] });`;
  assert.equal(findStreamUrl(html), 'https://prx-vid.example.com/v/master.m3u8?t=abc');
  const subs = findSubtitles(html);
  assert.equal(subs.length, 1);
  assert.equal(subs[0].label, 'Français');
});

// ---------- Intégration (serveur simulé) ----------
console.log('Intégration (serveur simulé) :');
await ok('extractUqload contre un serveur simulé (page packée + master)', async () => {
  const routes = new Map();
  const server = http.createServer((req, res) => {
    const body = routes.get(req.url.split('?')[0]);
    if (body === undefined) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'Content-Type': 'text/plain' });
    res.end(body);
  });
  await new Promise((resolve) => server.listen(0, 'localhost', resolve));
  const port = server.address().port;
  const host = `http://localhost:${port}`;
  try {
    const pageUrl = `${host}/v80v3zz0vay2.html`;
    // On utilise localhost (pas 127.0.0.1) : le dictionnaire du packer
    // contient tous les caractères simples (0-9, a-z, A-Z) comme tokens,
    // donc un chiffre littéral dans une IP serait remplacé comme un token.
    // En vrai packer, chaque mot du payload est un token, donc ça n'arrive
    // pas — c'est juste le mock qu'il faut construire sans chiffres nus.
    const keys62 = Array.from({ length: 62 }, (_v, i) => `k${i}`);
    routes.set('/v80v3zz0vay2.html',
      `<html><body><script>eval(function(p,a,c,k,e,d){return p}('file:"${host}/master.m3u8?token=abc" z',62,62,'${keys62.join('|')}'.split('|'),0,{}))</script></body></html>`);
    routes.set('/master.m3u8', [
      '#EXTM3U',
      '#EXT-X-STREAM-INF:BANDWIDTH=1200000,RESOLUTION=854x480',
      '480.m3u8',
      '#EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720',
      '720.m3u8',
      '',
    ].join('\n'));
    routes.set('/720.m3u8', '#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nseg720-0.ts\n');
    routes.set('/480.m3u8', '#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nseg480-0.ts\n');

    const res = await extractUqload(pageUrl);
    assert.equal(res.master, `${host}/master.m3u8?token=abc`);
    assert.equal(res.playlistHttp, 200);
    assert.equal(res.variants.length, 2);
    assert.equal(res.best, `${host}/720.m3u8`);
    assert.equal(res.media, `${host}/720.m3u8`);
    assert.match(res.masterBody, /#EXT-X-STREAM-INF/);
  } finally {
    server.close();
  }
});

// ---------- Serveur web (healthcheck + validation) ----------
console.log('Serveur web :');
async function withServer(port, fn) {
  const child = spawn('node', ['server.js'], {
    cwd: JS_DIR,
    env: { ...process.env, PORT: String(port) },
    stdio: 'ignore',
  });
  try {
    const base = `http://127.0.0.1:${port}`;
    const deadline = Date.now() + 10000;
    for (;;) {
      try {
        const r = await fetch(base + '/');
        if (r.status === 200) break;
      } catch { /* pas encore prêt */ }
      if (Date.now() > deadline) throw new Error('serveur non démarré');
      await new Promise((r) => setTimeout(r, 200));
    }
    await fn(base);
  } finally {
    child.kill();
  }
}

await ok('GET / -> online, /uqload sans param -> 400, /uqload.php?id=invalide -> 400', async () => {
  await withServer(18081, async (base) => {
    let r = await fetch(base + '/');
    assert.equal(r.status, 200);
    assert.equal((await r.json()).status, 'online');
    r = await fetch(base + '/uqload');
    assert.equal(r.status, 400);
    r = await fetch(base + '/uqload.php?id=!!invalide!!');
    assert.equal(r.status, 400);
    r = await fetch(base + '/nimporte-quoi');
    assert.equal(r.status, 404);
  });
});

// ---------- Live (optionnel) ----------
if (LIVE) {
  console.log('Live (uqload.vc / vidmoly.me) :');
  await ok('GET /uqload?id=v80v3zz0vay2 (réel)', async () => {
    await withServer(18082, async (base) => {
      const r = await fetch(base + '/uqload?id=v80v3zz0vay2');
      assert.equal(r.status, 200);
      const j = await r.json();
      assert.match(j.master, /master\.m3u8/);
      assert.ok(Array.isArray(j.variants) && j.variants.length > 0);
      assert.ok(j.best && j.media);
      console.log(`    master: ${j.master.slice(0, 90)}… (${j.variants.length} variantes)`);
    });
  });
  await ok('GET /uqload?id=v80v3zz0vay2&proxy=1 + ?stream= (réel)', async () => {
    await withServer(18083, async (base) => {
      let r = await fetch(base + '/uqload?id=v80v3zz0vay2&proxy=1');
      assert.equal(r.status, 200);
      const j = await r.json();
      assert.equal(j.proxied, true);
      assert.ok(j.best.startsWith(base + '/uqload?stream='));
      // La playlist proxifiée doit être servie et réécrite.
      r = await fetch(j.master);
      assert.equal(r.status, 200);
      const body = await r.text();
      assert.match(body, /#EXT-X-STREAM-INF/);
      assert.match(body, new RegExp(base.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '/uqload\\?stream='));
      // Le master renvoie une variante : on la suit, puis on prend un
      // segment de la variante et on le demande en Range (100 premiers octets).
      const variantUrl = body.split('\n').map((l) => l.trim()).find((l) => l && !l.startsWith('#'));
      assert.ok(variantUrl, 'variante trouvée dans le master proxifié');
      r = await fetch(variantUrl);
      assert.equal(r.status, 200);
      const variantBody = await r.text();
      const seg = variantBody.split('\n').map((l) => l.trim()).find((l) => l && !l.startsWith('#'));
      assert.ok(seg, 'segment trouvé dans la variante proxifiée');
      r = await fetch(seg, { headers: { Range: 'bytes=0-99' } });
      assert.equal(r.status, 206);
      const buf = Buffer.from(await r.arrayBuffer());
      assert.equal(buf.length, 100);
    });
  });
  await ok('GET /vidmoly?id=iztl71ko757x (réel)', async () => {
    await withServer(18084, async (base) => {
      const r = await fetch(base + '/vidmoly?id=iztl71ko757x');
      assert.equal(r.status, 200);
      const j = await r.json();
      assert.ok(j.master);
      assert.ok(['m3u8', 'mp4'].includes(j.type));
      console.log(`    master: ${String(j.master).slice(0, 90)}… (type ${j.type})`);
    });
  });
}

console.log(`\n${passed} test(s) réussi(s)${LIVE ? ' (dont live)' : ' — lance avec LIVE=1 pour le test live'}.`);
