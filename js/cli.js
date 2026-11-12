// CLI des extracteurs (équivalent de `php uqload.php <id>` / `php vidmoly.php <id>`).
// Pas d'authentification en CLI (comme en PHP).
//
//   node cli.js uqload v80v3zz0vay2
//   node cli.js uqload https://uqload.vc/v80v3zz0vay2.html
//   node cli.js vidmoly iztl71ko757x
import { buildPageUrl as buildUqloadUrl, extractUqload } from './src/uqload.js';
import { buildPageUrl as buildVidmolyUrl, extractVidmoly } from './src/vidmoly.js';

const [,, kind, arg] = process.argv;

if ((kind !== 'uqload' && kind !== 'vidmoly') || !arg) {
  console.error('Usage : node cli.js <uqload|vidmoly> <id ou url>');
  process.exit(2);
}

const pageUrl = kind === 'uqload' ? buildUqloadUrl(arg, null) : buildVidmolyUrl(arg, null);
if (!pageUrl) {
  console.error('id ou url invalide');
  process.exit(1);
}

const result = kind === 'uqload'
  ? await extractUqload(pageUrl)
  : await extractVidmoly(pageUrl);

if (!result.master) {
  console.error(result.error ?? 'extraction impossible');
  process.exit(1);
}
result.cached = false;
console.log(JSON.stringify(result, null, 2));
