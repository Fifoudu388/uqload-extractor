// Dé-obfuscation du packer « Dean Edwards » (+ utilitaires associés).
// Port fidèle de dePacker() dans uqload.php, avec les mêmes corrections
// par rapport à la v1 :
// - base 62 supportée (baseEncode maison, comme en PHP)
// - remplacement en une seule passe : les mots déjà remplacés ne sont pas
//   re-traités (évite les corruptions quand un mot ressemblait à un token)
// - payload et clés gèrent les caractères échappés (\', \\, \/, ...)
import { PACKER_ALPHABET } from './constants.js';

/** Équivalent de stripcslashes() en PHP : \n \r \t \\ \' \" \/ \0-\7 (octal) \xHH. */
export function stripcslashes(s) {
  return s.replace(/\\([0-7]{1,3}|x[0-9a-fA-F]{1,2}|.)/gs, (_m, esc) => {
    if (/^[0-7]{1,3}$/.test(esc)) return String.fromCharCode(parseInt(esc, 8));
    if (/^x[0-9a-fA-F]{1,2}$/.test(esc)) return String.fromCharCode(parseInt(esc.slice(1), 16));
    switch (esc) {
      case 'a': return '\x07';
      case 'b': return '\b';
      case 'f': return '\f';
      case 'n': return '\n';
      case 'r': return '\r';
      case 't': return '\t';
      case 'v': return '\v';
      case '\\': return '\\';
      default: return esc; // \' \" \/ et tout autre : le backslash est retiré (comme en PHP)
    }
  });
}

/** Encodage d'un entier dans une base 2..62. */
export function baseEncode(num, base) {
  if (num === 0) return '0';
  let out = '';
  while (num > 0) {
    out = PACKER_ALPHABET[num % base] + out;
    num = Math.floor(num / base);
  }
  return out;
}

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * De-obfuscation du packer Dean Edwards :
 * eval(function(p,a,c,k,e,d){...}('PAYLOAD',BASE,COUNT,'K1|K2|...'.split('|')))
 */
export function dePacker(js, rounds = 3) {
  const patterns = [
    // Guillemets simples (forme classique)
    /\}\s*\(\s*'((?:\\.|[^'\\])*)'\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*'((?:\\.|[^'\\])*)'\.split\('\|'\)/s,
    // Guillemets doubles
    /\}\s*\(\s*"((?:\\.|[^"\\])*)"\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*"((?:\\.|[^"\\])*)"\.split\("\|"\)/s,
  ];

  for (let r = 0; r < rounds; r++) {
    let m = null;
    for (const pattern of patterns) {
      m = pattern.exec(js);
      if (m) break;
    }
    if (!m) break; // pas (plus) de packer

    const payload = stripcslashes(m[1]);
    const base = parseInt(m[2], 10);
    const count = parseInt(m[3], 10);
    const keys = m[4].split('|').map((k) => stripcslashes(k));

    if (base < 2 || base > 62 || count <= 0) break;

    const map = new Map();
    for (let i = 0; i < count; i++) {
      if (keys[i] === undefined || keys[i] === '') continue;
      map.set(baseEncode(i, base), keys[i]);
    }
    if (map.size === 0) break;

    // Plus longs d'abord pour éviter les collisions de préfixe (ex : "1a" avant "1").
    const tokens = [...map.keys()].sort((a, b) => b.length - a.length);
    const re = new RegExp('\\b(' + tokens.map(escapeRegExp).join('|') + ')\\b', 'g');
    js = payload.replace(re, (match) => map.get(match) ?? match);
  }
  return js;
}
