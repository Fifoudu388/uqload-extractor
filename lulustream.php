<?php
/**
 * Extracteur m3u8 lulustream — v1
 * ==============================
 * Usage web  : lulustream.php?id=q7qp31locaqo
 *              lulustream.php?id=q7qp31locaqo&proxy=1   -> URLs media via le proxy (voir ci-dessous)
 *              lulustream.php?stream=<url encodee>       -> relaye un flux .m3u8 ou un segment
 * Usage CLI  : php lulustream.php q7qp31locaqo
 *
 * Mecanisme (documente par les extracteurs open source AdultHideout,
 * ResolveURL et CyberDropDownloader, lus le 17 novembre 2026) :
 *   1. La page d'integration https://lulustream.com/e/<id> (ou
 *      https://lplaygo.com/e/<id> — lplaygo.com est un domaine alternatif
 *      du meme service, meme lecteur, meme template /e/<id>) contient
 *      la config du lecteur dans du JavaScript obfusque par le packer
 *      « Dean Edwards » : eval(function(p,a,c,k,e,d)...).
 *   2. Une fois le JS depacke, la config est de type JWPlayer :
 *      {sources:[{file:"https://...tnmr.org/hls2/.../master.m3u8"}],
 *      avec aussi le poster (image:"...") et le titre (page /d/<id>).
 *   3. Le flux est du HLS (.m3u8) sur le CDN *.tnmr.org ; la playlist
 *      master est parsee pour lister les variantes (comme vidmoly.php).
 *
 * IMPORTANT — fetch en cURL simple, pas de contournement Cloudflare :
 * le commentaire du resolver AdultHideout dit que Lulu/TNMR detecte les
 * empreintes TLS facon cloudscraper et genere des tokens en 403. Le
 * fetch doit donc rester un cURL ordinaire, comme ce script le fait.
 *
 * Referer : le CDN refuse (403) les requetes sans Referer d'apres un
 * retour de terrain ; ce script envoie Referer = origine de la page
 * d'integration (+ Origin) sur la playlist, les segments et le relais.
 *
 * Limitation IP : NON VERIFIEE. Aucun des extracteurs lus ne demontre
 * un controle d'IP sur le .m3u8 (ses parametres de signature ne sont
 * jamais parses par ces codes — l'URL est consommee telle quelle). Le
 * lien est emis a chaque chargement de page et limite dans le temps,
 * donc le plus sur est de le generer ET de le consommer depuis la meme
 * IP : le mode proxy (?proxy=1) est recommande, comme pour vidmoly.
 * La lecture directe reste possible en theorie, mais non garantie.
 *
 * Reponse JSON :
 *   master       : URL du flux (.m3u8)
 *   type         : "m3u8" (ou "mp4" si le lecteur renvoie un MP4)
 *   variants     : [{bandwidth, resolution, url}] tries par qualite decroissante (m3u8)
 *   best / media : URL de la meilleure variante (ou du master si pas de variantes)
 *   title        : titre de la video (h1 de la page /d/ ou /e/), si trouve
 *   poster       : URL de la miniature (image:...), si presente (non proxiee)
 *   proxied      : true si les URLs passent par le proxy (?proxy=1)
 *   playlistHttp : code HTTP de la playlist master (m3u8)
 *   masterBody   : contenu brut du master .m3u8
 *   subtitles    : [{file, label, kind}] si des sous-titres sont embarques
 *   embed        : URL de la page d'integration utilisee
 *   cached       : true si la reponse vient du cache
 *   key_name     : nom de la cle API utilisee (si cles configurees)
 *
 * Variables d'environnement (partagees avec uqload.php / vidmoly.php) :
 *   UQLOAD_CACHE_TTL : duree du cache en secondes (defaut 3600, 0 = desactive)
 *   UQLOAD_API_KEYS  : cles API, une par ligne, format nom:cle[:limite_par_minute]
 *                      Si vide : acces libre. Si defini : cle requise
 *                      (header X-API-Key ou ?key=), 401 sinon, 429 si limite atteinte.
 *
 * PHP 8.0+ requis (str_starts_with, fonctions flechees).
 */
declare(strict_types=1);

// Signature des URLs du relais ?stream= (module partage signing.php, meme
// dossier) : voir signing.php pour le principe. Absent = repli silencieux
// sur l'ancien comportement (relais protege par la seule cle API).
if (file_exists(__DIR__ . '/signing.php')) {
    require_once __DIR__ . '/signing.php';
}
if (!function_exists('streamSignatureQuery')) {
    function streamSignatureQuery(string $route, string $target, string $extra = ''): string { return ''; }
    function verifyStreamSignature(string $route, string $target, string $extra, mixed $expRaw, mixed $sigRaw): bool { return false; }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';

/** Hotes autorises pour ?url= (protection SSRF : on ne fetch que lulustream). */
const ALLOWED_HOSTS = [
    'lulustream.com', 'www.lulustream.com',
    'lplaygo.com', 'www.lplaygo.com',
    'luluvdo.com', 'luluvido.com', 'lulu.st', 'luluvid.com',
    'luluvdoo.com', 'cdn1.site', 'streamhihi.com', 'd00ds.site',
    'lulust.com', '732eg54de642sa.sbs',
];

function httpGet(string $url, array $headers = [], int $timeout = 25): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => array_merge(['Accept: */*'], $headers),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body === false ? '' : (string) $body, 'error' => $err];
}

/** Resout une URL relative par rapport a une URL de base. */
function resolveUrl(string $base, string $rel): string
{
    if (preg_match('~^https?://~i', $rel)) {
        return $rel;
    }
    if (str_starts_with($rel, '//')) {
        return 'https:' . $rel;
    }
    $parts  = parse_url($base);
    $scheme = $parts['scheme'] ?? 'https';
    $host   = $parts['host'] ?? '';
    $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
    if (str_starts_with($rel, '/')) {
        return "{$scheme}://{$host}{$port}{$rel}";
    }
    $path = $parts['path'] ?? '/';
    $dir  = substr($path, 0, (int) strrpos($path, '/') + 1);
    return "{$scheme}://{$host}{$port}{$dir}{$rel}";
}

/** Origine (scheme://host) d'une URL, ou null si invalide. */
function originOf(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return null;
    }
    return strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
}

/**
 * Hote autorise pour le relais de flux (?stream=) : un domaine lulustream
 * (voir ALLOWED_HOSTS) ou le CDN des flux, *.tnmr.org (ex :
 * prx-....tnmr.org) et les miniatures *.lulucdn.com.
 * Anti-SSRF : rien d'autre n'est relaye.
 */
function lulustreamMediaHostAllowed(string $host): bool
{
    $host = strtolower($host);
    if (in_array($host, ALLOWED_HOSTS, true)) {
        return true;
    }
    if (preg_match('/^(?:[a-z0-9-]+\.)*tnmr\.org$/', $host)) {
        return true;
    }
    if (preg_match('/^(?:[a-z0-9-]+\.)*lulucdn\.com$/', $host)) {
        return true;
    }
    return false;
}

/**
 * URL absolue du script courant pour atteindre le mode proxy (?stream=...).
 * $origin : URL de la page d'integration a renvoyer en Referer/Origin
 * quand le relais ira chercher le flux (le lecteur ne la connait pas).
 */
function proxyUrl(string $target, ?string $origin = null): string
{
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    if (!is_string($proto) || $proto === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/lulustream.php';
    $url    = "{$proto}://{$host}{$script}?stream=" . rawurlencode($target);
    if ($origin !== null && $origin !== '') {
        $url .= '&o=' . rawurlencode($origin);
    }
    // Signature HMAC (si activee) : elle couvre aussi l'origine &o=.
    $url .= streamSignatureQuery(basename(__FILE__, '.php'), $target, $origin ?? '');
    return $url;
}

/**
 * Re-ecrit une playlist .m3u8 : chaque URI (segment ou variante) et chaque
 * URI="..." (ex : cle EXT-X-KEY) passe par le proxy.
 */
function rewritePlaylist(string $body, string $baseUrl, ?string $origin = null): string
{
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
        $trim = trim((string) $line);
        if ($trim === '') {
            $out[] = '';
            continue;
        }
        if (str_starts_with($trim, '#')) {
            if (str_contains($trim, 'URI="')) {
                $trim = preg_replace_callback(
                    '/URI="([^"]+)"/',
                    static fn($m) => 'URI="' . proxyUrl(resolveUrl($baseUrl, $m[1]), $origin) . '"',
                    $trim
                ) ?? $trim;
            }
            $out[] = $trim;
            continue;
        }
        $out[] = proxyUrl(resolveUrl($baseUrl, $trim), $origin);
    }
    return implode("\n", $out) . "\n";
}

/**
 * Re-ecrit les URLs media d'une reponse JSON en URLs de proxy (?stream=...).
 * Les URLs directes restent dans le cache ; la re-ecriture se fait a l'envoi.
 * Les sous-titres passent aussi par le proxy : leurs fichiers sont sur le
 * meme CDN, donc soumis a la meme protection. Le poster n'est pas proxie.
 */
function applyProxyMode(array $res): array
{
    $origin = $res['embed'] ?? null;
    $origin = is_string($origin) ? $origin : null;
    $origMaster = $res['master'] ?? null;
    if (is_string($origMaster) && $origMaster !== '') {
        $res['master'] = proxyUrl($origMaster, $origin);
    }
    if (isset($res['variants']) && is_array($res['variants'])) {
        foreach ($res['variants'] as &$v) {
            if (isset($v['url']) && is_string($v['url'])) {
                $v['url'] = proxyUrl($v['url'], $origin);
            }
        }
        unset($v);
    }
    if (isset($res['best']) && is_string($res['best'])) {
        $res['best'] = proxyUrl($res['best'], $origin);
    }
    if (isset($res['media']) && is_string($res['media'])) {
        $res['media'] = proxyUrl($res['media'], $origin);
    }
    if (isset($res['subtitles']) && is_array($res['subtitles'])) {
        foreach ($res['subtitles'] as &$s) {
            if (isset($s['file']) && is_string($s['file'])) {
                $s['file'] = proxyUrl($s['file'], $origin);
            }
        }
        unset($s);
    }
    if (isset($res['masterBody']) && is_string($origMaster) && $origMaster !== '') {
        $res['masterBody'] = rewritePlaylist($res['masterBody'], $origMaster, $origin);
    }
    $res['proxied'] = true;
    return $res;
}

/**
 * GET avec en-tetes de reponse captures (pour relayer statut et headers).
 * $range : valeur brute de l'en-tete Range du client (ex : "bytes=0-1023").
 */
function httpFetch(string $url, array $headers = [], ?string $range = null, int $timeout = 25): array
{
    $respHeaders = [];
    $allHeaders  = array_merge(['Accept: */*'], $headers);
    if ($range !== null && $range !== '') {
        $allHeaders[] = 'Range: ' . $range;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => $allHeaders,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$respHeaders) {
            $len = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body === false ? '' : (string) $body, 'error' => $err, 'headers' => $respHeaders];
}

/**
 * Mode proxy de flux : recupere un .m3u8 ou un segment cote serveur avec
 * Referer/Origin = origine de la page d'integration, puis le sert au
 * client. Recommande (voir en-tete) : le lien est emis par page et limite
 * dans le temps, et la limitation par IP n'est pas exclue.
 * - playlist .m3u8 : les URLs sont re-ecrites vers ?stream= (le lecteur ne
 *   touche jamais le CDN directement)
 * - segment : le Range du client est transmis (seek), statut 200/206 et
 *   headers utiles relayes
 * Securite : seuls les domaines lulustream et le CDN *.tnmr.org sont
 * autorises (anti-SSRF) et la cle API (si configuree) est exigee en amont
 * comme pour l'extraction.
 */
function serveStream(string $target): void
{
    $parts = parse_url($target);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || !lulustreamMediaHostAllowed($parts['host'])) {
        sendJson(['error' => 'URL de flux invalide (domaines lulustream uniquement)'], 400);
        return;
    }
    $origin = null;
    $rawOrigin = $_GET['o'] ?? null;
    if (is_string($rawOrigin) && $rawOrigin !== '') {
        $o = originOf($rawOrigin);
        if ($o !== null) {
            $oHost = parse_url($o, PHP_URL_HOST);
            if (is_string($oHost) && in_array(strtolower($oHost), ALLOWED_HOSTS, true)) {
                $origin = $o;
            }
        }
    }
    if ($origin === null) {
        $origin = 'https://lulustream.com';
    }
    $path   = parse_url($target, PHP_URL_PATH) ?? '';
    $range  = $_SERVER['HTTP_RANGE'] ?? null;
    $range  = is_string($range) ? $range : null;
    if (str_ends_with(strtolower($path), '.m3u8')) {
        $range = null; // une playlist se recupere entiere, pas par morceaux
    }
    $res = httpFetch($target, ["Referer: {$origin}/", "Origin: {$origin}"], $range);
    if ($res['code'] < 200 || $res['code'] >= 400 || $res['body'] === '') {
        sendJson(['error' => "flux HTTP {$res['code']} {$res['error']}"], 502);
        return;
    }
    $ctype = strtolower($res['headers']['content-type'] ?? '');
    $isPlaylist = str_ends_with(strtolower($path), '.m3u8') || str_contains($ctype, 'mpegurl');
    if ($isPlaylist) {
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache');
        echo rewritePlaylist($res['body'], $target, $origin);
        return;
    }
    http_response_code($res['code']);
    if ($ctype !== '') {
        header('Content-Type: ' . $res['headers']['content-type']);
    }
    foreach (['content-range', 'accept-ranges', 'content-length'] as $h) {
        if (isset($res['headers'][$h])) {
            header(ucwords($h, '-') . ': ' . $res['headers'][$h]);
        }
    }
    header('Cache-Control: public, max-age=3600');
    echo $res['body'];
}

/** Parse un master .m3u8 : variantes triees par bande passante decroissante. */
function parseMasterPlaylist(string $body, string $masterUrl): array
{
    $variants = [];
    $lines    = preg_split("/\r\n|\n|\r/", $body) ?: [];
    $pending  = null;
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        if (str_starts_with($line, '#EXT-X-STREAM-INF:')) {
            $bw  = null;
            $res = null;
            if (preg_match('/BANDWIDTH=(\d+)/', $line, $m)) {
                $bw = (int) $m[1];
            }
            if (preg_match('/RESOLUTION=(\d+)x(\d+)/', $line, $m)) {
                $res = $m[1] . 'x' . $m[2];
            }
            $pending = ['bandwidth' => $bw, 'resolution' => $res];
            continue;
        }
        if (str_starts_with($line, '#')) {
            continue;
        }
        if ($pending !== null) {
            $pending['url'] = resolveUrl($masterUrl, $line);
            $variants[]     = $pending;
            $pending        = null;
        }
    }
    usort($variants, static fn($a, $b) => ($b['bandwidth'] ?? 0) <=> ($a['bandwidth'] ?? 0));
    return $variants;
}

/** Encode un entier en base N (chiffres 0-9a-zA-Z), pour le depacker. */
function baseEncode(int $num, int $radix): string
{
    $digits = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    if ($radix < 2 || $radix > 62) {
        return (string) $num;
    }
    if ($num === 0) {
        return '0';
    }
    $out = '';
    while ($num > 0) {
        $out  = $digits[$num % $radix] . $out;
        $num  = intdiv($num, $radix);
    }
    return $out;
}

/**
 * Depacke du JavaScript obfusque par le packer « Dean Edwards »
 * (eval(function(p,a,c,k,e,d)...)). $p = payload, $a = base, $c = nombre
 * de mots, $k = mots separes par '|'. Remplacement en une seule passe
 * descendante, comme jsunpack.
 */
function jsUnpack(string $p, int $a, int $c, string $k): string
{
    $words = explode('|', $k);
    for ($i = $c - 1; $i >= 0; $i--) {
        if (!isset($words[$i]) || $words[$i] === '') {
            continue;
        }
        $token = baseEncode($i, $a);
        $word  = $words[$i];
        $p = preg_replace_callback(
            '/\b' . preg_quote($token, '/') . '\b/',
            static fn() => $word,
            $p
        ) ?? $p;
    }
    return $p;
}

/**
 * Extrait le JS depacke d'une page : cherche le bloc
 * eval(function(p,a,c,k,e,d)...('payload',base,count,'mots'.split('|')).
 * Retourne null si aucun bloc packe n'est trouve.
 */
function unpackPackedJs(string $html): ?string
{
    if (!preg_match(
        "/eval\\(function\\(p,a,c,k,e,d\\).*?\\}\\(\\s*'([\\s\\S]*?)'\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*'([\\s\\S]*?)'\\.split\\('\\|'\\)/s",
        $html,
        $m
    )) {
        return null;
    }
    return jsUnpack($m[1], (int) $m[2], (int) $m[3], $m[4]);
}

/**
 * Cherche l'URL du flux video dans du HTML (ou du JS depacke), par
 * specificite decroissante :
 *   1. tableau sources: [{file: "..."}] du lecteur
 *   2. file: "...m3u8..." (guillemets doubles ou simples)
 *   3. n'importe quelle URL .m3u8 dans le texte
 *   4. file: "..." generique (hors images)
 * Les .m3u8 sont preferes aux autres formats.
 */
function findStreamUrl(string $text): ?string
{
    $candidates = [];
    // Tier 1 : tableau sources du lecteur
    if (preg_match('/sources\s*:\s*\[(.*?)\]/is', $text, $sm)) {
        if (preg_match_all('/["\']?file["\']?\s*:\s*["\']([^"\']+)["\']/i', $sm[1], $fm)) {
            foreach ($fm[1] as $f) {
                $candidates[] = $f;
            }
        }
    }
    // Tier 2 : file: "...m3u8..."
    if (preg_match('/file\s*:\s*"([^"]*\.m3u8[^"]*)"/i', $text, $m)) {
        $candidates[] = $m[1];
    }
    if (preg_match("/file\s*:\s*'([^']*\.m3u8[^']*)'/i", $text, $m)) {
        $candidates[] = $m[1];
    }
    // Tier 3 : URL .m3u8 brute dans le texte
    if (preg_match_all('~(https?:)?//[^"\'\\\\\\s<>]*\.m3u8[^"\'\\\\\\s<>]*~i', $text, $m)) {
        foreach ($m[0] as $u) {
            $candidates[] = $u;
        }
    }
    // Tier 4 : file: "..." generique
    if (preg_match('/file\s*:\s*"([^"]+)"/i', $text, $m)) {
        $candidates[] = $m[1];
    }
    if (preg_match("/file\s*:\s*'([^']+)'/i", $text, $m)) {
        $candidates[] = $m[1];
    }
    // D'abord les .m3u8, puis le reste.
    $ordered = [];
    foreach ($candidates as $c) {
        if (stripos($c, '.m3u8') !== false) {
            $ordered[] = $c;
        }
    }
    foreach ($candidates as $c) {
        if (stripos($c, '.m3u8') === false) {
            $ordered[] = $c;
        }
    }
    foreach ($ordered as $c) {
        $c = stripcslashes(str_replace('\\/', '/', trim($c)));
        if (str_starts_with($c, '//')) {
            $c = 'https:' . $c;
        }
        if (!preg_match('~^https?://~i', $c)) {
            continue;
        }
        if (preg_match('~\.(jpe?g|png|gif|webp|svg|css|js)(\?|$)~i', $c)) {
            continue;
        }
        return $c;
    }
    return null;
}

/** Miniature : image:"..." dans la config du lecteur. */
function findPoster(string $text): ?string
{
    if (preg_match('/image\s*:\s*["\']([^"\']+)["\']/i', $text, $m)) {
        $u = stripcslashes(str_replace('\\/', '/', trim($m[1])));
        if (str_starts_with($u, '//')) {
            $u = 'https:' . $u;
        }
        if (preg_match('~^https?://~i', $u)) {
            return $u;
        }
    }
    return null;
}

/** Sous-titres embarques : tableau tracks: [{file, label, kind}] du lecteur. */
function findSubtitles(string $text): array
{
    $subs = [];
    if (!preg_match('/tracks\s*:\s*\[(.*?)\]/is', $text, $tm)) {
        return $subs;
    }
    if (preg_match_all('/\{([^{}]*)\}/', $tm[1], $objs)) {
        foreach ($objs[1] as $obj) {
            if (!preg_match('/file\s*:\s*["\']([^"\']+)["\']/i', $obj, $f)) {
                continue;
            }
            $label = null;
            if (preg_match('/label\s*:\s*["\']([^"\']+)["\']/i', $obj, $l)) {
                $label = $l[1];
            }
            $kind = null;
            if (preg_match('/kind\s*:\s*["\']([^"\']+)["\']/i', $obj, $k)) {
                $kind = $k[1];
            }
            $subs[] = [
                'file'  => stripcslashes(str_replace('\\/', '/', $f[1])),
                'label' => $label,
                'kind'  => $kind,
            ];
        }
    }
    return $subs;
}

/** Titre de la video (<h1>, sinon <title>). */
function findTitle(string $html): ?string
{
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m)) {
        $t = trim(strip_tags($m[1]));
        if ($t !== '') {
            return html_entity_decode($t, ENT_QUOTES, 'UTF-8');
        }
    }
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $t = trim(strip_tags($m[1]));
        return $t !== '' ? html_entity_decode($t, ENT_QUOTES, 'UTF-8') : null;
    }
    return null;
}

/** Recupere le flux : page /e/ -> JS depacke -> config du lecteur. */
function extractStream(string $pageUrl): array
{
    $origin = originOf($pageUrl) ?? 'https://lulustream.com';
    $page = httpGet($pageUrl, [
        'Accept: text/html,application/xhtml+xml',
        "Referer: {$origin}/",
        "Origin: {$origin}",
    ]);
    if ($page['code'] !== 200 || $page['body'] === '') {
        return ['error' => "page HTTP {$page['code']} {$page['error']}"];
    }
    $html = $page['body'];
    // 1) Config du lecteur dans le HTML brut (repli rapide).
    $stream = findStreamUrl($html);
    $poster = findPoster($html);
    $searchText = $html;
    // 2) Sinon, depacke le JS et cherche dans le resultat.
    if ($stream === null) {
        $unpacked = unpackPackedJs($html);
        if ($unpacked !== null) {
            $searchText = $unpacked;
            $stream = findStreamUrl($unpacked);
            if ($poster === null) {
                $poster = findPoster($unpacked);
            }
        }
    }
    // 3) Dernier repli : n'importe quelle URL mp4|m3u8 du JS depacke.
    if ($stream === null && $searchText !== $html) {
        if (preg_match('~(https?:)?//[^"\'\\\\\\s<>]*\.(?:mp4|m3u8)[^"\'\\\\\\s<>]*~i', $searchText, $m)) {
            $stream = str_starts_with($m[0], '//') ? 'https:' . $m[0] : $m[0];
        }
    }
    if ($stream === null) {
        return ['error' => 'flux video introuvable'];
    }
    $res = ['master' => $stream, 'embed' => $pageUrl];
    if ($poster !== null) {
        $res['poster'] = $poster;
    }
    $subs = findSubtitles($searchText);
    if ($subs !== []) {
        $res['subtitles'] = $subs;
    }
    $title = findTitle($html);
    if ($title === null) {
        // Titre sur la page /d/<id> (meme hote).
        $detailUrl = preg_replace('~/e/~', '/d/', $pageUrl, 1);
        if (is_string($detailUrl) && $detailUrl !== $pageUrl) {
            $detail = httpGet($detailUrl, [
                'Accept: text/html,application/xhtml+xml',
                "Referer: {$origin}/",
            ]);
            if ($detail['code'] === 200 && $detail['body'] !== '') {
                $title = findTitle($detail['body']);
            }
        }
    }
    if ($title !== null) {
        $res['title'] = $title;
    }
    return $res;
}

/** Construit l'URL de la page lulustream a partir de ?id= ou ?url= (avec validation). */
function buildPageUrl(?string $input, ?string $url): ?string
{
    if ($url !== null && $url !== '') {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        if (!in_array(strtolower($parts['host']), ALLOWED_HOSTS, true)) {
            return null;
        }
        if (!preg_match('~/(?:e|d)?/?([A-Za-z0-9]+)~', $parts['path'] ?? '', $m)) {
            return null;
        }
        return "https://{$parts['host']}/e/{$m[1]}";
    }
    if ($input !== null && $input !== '') {
        if (preg_match('~^https?://~i', $input)) {
            return buildPageUrl(null, $input);
        }
        if (!preg_match('/^[A-Za-z0-9]{3,64}$/', $input)) {
            return null;
        }
        return "https://lulustream.com/e/{$input}";
    }
    return null;
}

/** Cache fichier (duree via UQLOAD_CACHE_TTL, 0 = desactive). */
function cacheGet(string $key, int $ttl): ?array
{
    if ($ttl <= 0) {
        return null;
    }
    $file = sys_get_temp_dir() . '/lulustream-cache-' . $key . '.json';
    if (!is_file($file) || (time() - (int) filemtime($file) > $ttl)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function cacheSet(string $key, array $data, int $ttl): void
{
    if ($ttl <= 0) {
        return;
    }
    @file_put_contents(sys_get_temp_dir() . '/lulustream-cache-' . $key . '.json', json_encode($data), LOCK_EX);
}

function sendJson(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}

/**
 * Cles API configurees via UQLOAD_API_KEYS (variable partagee avec uqload.php),
 * une par ligne : nom:cle[:limite_par_minute] (limite 0 ou absente = illimite).
 * Retourne [] si aucune cle n'est configuree (acces libre).
 */
function parseApiKeys(): array
{
    $raw = getenv('UQLOAD_API_KEYS');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $keys = [];
    foreach (preg_split("/[\r\n,]+/", $raw) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode(':', $line, 3);
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            continue;
        }
        $keys[] = [
            'name'  => $parts[0],
            'key'   => $parts[1],
            'limit' => isset($parts[2]) && $parts[2] !== '' ? max(0, (int) $parts[2]) : 0,
        ];
    }
    return $keys;
}

/**
 * Authentifie la requete : header X-API-Key ou parametre ?key=.
 * Retourne la config de la cle trouvee, ['open' => true] si aucune cle
 * n'est configuree (acces libre), ou null si la cle est absente/invalide.
 */
function findApiKey(array $configuredKeys): ?array
{
    if ($configuredKeys === []) {
        return ['open' => true];
    }
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if (!is_string($provided) || $provided === '') {
        $provided = $_GET['key'] ?? null;
    }
    if (!is_string($provided) || $provided === '') {
        return null;
    }
    foreach ($configuredKeys as $cfg) {
        if (hash_equals($cfg['key'], $provided)) {
            return $cfg;
        }
    }
    return null;
}

/**
 * Rate limit par cle : fenetre fixe de 60 s, compteur en fichier (avec verrou).
 * Retourne ['allowed' => bool, 'limit' => int, 'remaining' => int, 'retry_after' => int].
 */
function checkRateLimit(array $keyCfg): array
{
    $limit = (int) ($keyCfg['limit'] ?? 0);
    if ($limit <= 0) {
        return ['allowed' => true, 'limit' => 0, 'remaining' => -1, 'retry_after' => 0];
    }
    $file = sys_get_temp_dir() . '/lulustream-rl-' . md5($keyCfg['key']) . '.json';
    $fp   = fopen($file, 'c+');
    if ($fp === false) {
        return ['allowed' => true, 'limit' => $limit, 'remaining' => $limit, 'retry_after' => 0];
    }
    flock($fp, LOCK_EX);
    $now  = time();
    $data = ['window' => $now, 'count' => 0];
    $raw  = stream_get_contents($fp);
    if ($raw !== false && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['window'], $decoded['count'])) {
            $data = $decoded;
        }
    }
    if ($now - (int) $data['window'] >= 60) {
        $data = ['window' => $now, 'count' => 0];
    }
    $allowed = (int) $data['count'] < $limit;
    if ($allowed) {
        $data['count']++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
    }
    $remaining  = max(0, $limit - (int) $data['count']);
    $retryAfter = $allowed ? 0 : max(1, 60 - ($now - (int) $data['window']));
    flock($fp, LOCK_UN);
    fclose($fp);
    return ['allowed' => $allowed, 'limit' => $limit, 'remaining' => $remaining, 'retry_after' => $retryAfter];
}

// ---- Entree
$input = $argv[1] ?? ($_GET['id'] ?? null);
$url   = $argv[2] ?? ($_GET['url'] ?? null);

// ---- Extracteur desactive depuis le tableau de bord ?
// Variable DISABLED_EXTRACTORS (noms separes par des virgules, posee par le
// dashboard sur le service) : tout appel web recoit une erreur 503 explicite.
if (php_sapi_name() !== 'cli') {
    $disabledList = array_filter(array_map('trim', explode(',', (string) getenv('DISABLED_EXTRACTORS'))));
    if (in_array('lulustream', $disabledList, true)) {
        sendJson([
            'error'   => 'extracteur_desactive',
            'message' => "L'extracteur lulustream est desactive pour le moment.",
        ], 503);
        exit;
    }
}

// ---- Authentification par cle API (si des cles sont configurees, sauf en CLI)
// Exception : un appel au relais ?stream= porteur d'une signature valide
// (exp + sig, emise par une extraction authentifiee) n'a pas besoin de la
// cle : la signature vaut autorisation, pour cette URL exacte, cet
// extracteur et jusqu'a expiration — et ne consomme pas le quota de la cle.
// Une signature presente mais invalide ou expiree est rejetee (403),
// jamais ignoree en silence.
$streamTarget = $_GET['stream'] ?? null;
$signedStream = false;
if (php_sapi_name() !== 'cli' && is_string($streamTarget) && $streamTarget !== ''
    && (isset($_GET['exp']) || isset($_GET['sig']))) {
    $sigExtra = is_string($_GET['o'] ?? null) ? (string) $_GET['o'] : '';
    if (!verifyStreamSignature(basename(__FILE__, '.php'), $streamTarget, $sigExtra, $_GET['exp'] ?? null, $_GET['sig'] ?? null)) {
        sendJson(['error' => 'signature de flux invalide ou expiree'], 403);
        exit;
    }
    $signedStream = true;
}
$apiKeys = parseApiKeys();
$auth    = (php_sapi_name() === 'cli') ? ['open' => true] : ($signedStream ? ['open' => true, 'signed' => true] : findApiKey($apiKeys));
if ($apiKeys !== [] && $auth === null) {
    sendJson(['error' => 'API key manquante ou invalide (header X-API-Key ou ?key=)'], 401);
    exit;
}
if ($auth !== null && empty($auth['open'])) {
    $rl = checkRateLimit($auth);
    if ($rl['limit'] > 0) {
        header('X-RateLimit-Limit: ' . $rl['limit']);
        header('X-RateLimit-Remaining: ' . $rl['remaining']);
    }
    if (!$rl['allowed']) {
        header('Retry-After: ' . $rl['retry_after']);
        sendJson(['error' => 'rate limit atteint pour cette cle', 'retry_after' => $rl['retry_after']], 429);
        exit;
    }
}

// ---- Mode proxy de flux : ?stream=<url encodee> (web uniquement).
// Le lecteur ne contacte jamais le CDN directement : c'est ce serveur qui
// refait la requete avec sa propre IP (protection par IP non exclue, voir en-tete).
$stream = $_GET['stream'] ?? null;
if (is_string($stream) && $stream !== '' && php_sapi_name() !== 'cli') {
    serveStream($stream);
    exit;
}

// ---- Mode proxy JSON : ?proxy=1 -> les URLs media de la reponse passent par ?stream=
$proxyMode = isset($_GET['proxy']) && $_GET['proxy'] !== '' && $_GET['proxy'] !== '0' && php_sapi_name() !== 'cli';

$pageUrl = buildPageUrl(is_string($input) ? $input : null, is_string($url) ? $url : null);
if ($pageUrl === null) {
    sendJson(['error' => 'param ?id= (code video lulustream) ou ?url= (lien lulustream/lplaygo /e/, /d/ ou direct) requis/valide'], 400);
    exit;
}

$envTtl   = getenv('UQLOAD_CACHE_TTL');
$cacheTtl = ($envTtl === false || $envTtl === '') ? 3600 : (int) $envTtl;
$cacheKey = md5($pageUrl);

$cached = cacheGet($cacheKey, $cacheTtl);
if ($cached !== null) {
    $cached['cached'] = true;
    if ($auth !== null && !empty($auth['name'])) {
        $cached['key_name'] = $auth['name'];
    }
    if ($proxyMode) {
        $cached = applyProxyMode($cached);
    }
    sendJson($cached);
    exit;
}

$res = extractStream($pageUrl);
if (!isset($res['master'])) {
    sendJson(['error' => $res['error'] ?? 'flux video introuvable'], 502);
    exit;
}

$master = $res['master'];
if (stripos($master, '.m3u8') !== false) {
    $res['type'] = 'm3u8';
    $origin = originOf($pageUrl) ?? 'https://lulustream.com';
    $pl = httpGet($master, [
        'Accept: application/vnd.apple.mpegurl',
        "Referer: {$origin}/",
        "Origin: {$origin}",
    ]);
    $res['playlistHttp'] = $pl['code'];
    if ($pl['code'] === 200 && $pl['body'] !== '') {
        $variants = parseMasterPlaylist($pl['body'], $master);
        if ($variants !== []) {
            $res['variants'] = $variants;
            $res['best']     = $variants[0]['url'];
            $res['media']    = $variants[0]['url'];
        } else {
            // Playlist media directe (pas de variantes) : le master est deja le flux.
            $res['best']  = $master;
            $res['media'] = $master;
        }
        $res['masterBody'] = trim($pl['body']);
    } else {
        // Playlist injoignable : le master reste utilisable tel quel.
        $res['best']  = $master;
        $res['media'] = $master;
    }
} else {
    $res['type']  = 'mp4';
    $res['best']  = $master;
    $res['media'] = $master;
}

cacheSet($cacheKey, $res, $cacheTtl);
$res['cached'] = false;
if ($auth !== null && !empty($auth['name'])) {
    $res['key_name'] = $auth['name'];
}
if ($proxyMode) {
    $res = applyProxyMode($res);
}
sendJson($res);
