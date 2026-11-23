<?php
/**
 * Extracteur m3u8/mp4 vidmoly — v2
 * ===============================
 * Usage web  : vidmoly.php?id=iztl71ko757x   (ou ?url=https://vidmoly.me/v/iztl71ko757x)
 *              vidmoly.php?id=iztl71ko757x&proxy=1   -> URLs media via le proxy (voir ci-dessous)
 *              vidmoly.php?stream=<url encodee>      -> relaye un flux .m3u8 ou un segment
 * Usage CLI  : php vidmoly.php iztl71ko757x
 *
 * Protection par IP : les URLs de flux (https://prx-....vmpx.online/...) sont
 * liees a l'IP qui a charge la page d'integration (token ?t=...&s=...&e=43200
 * &asn=...). Un navigateur ne peut donc pas les recuperer directement avec
 * une autre IP (403) : le mode proxy est obligatoire pour lire. Cote serveur,
 * toutes les requetes partent de la meme IP (celle du serveur), donc le token
 * reste valide de la page jusqu'aux segments. Le lecteur ne parle qu'a ce
 * serveur, qui refait les requetes vers le CDN avec les bons headers (et
 * transmet le Range pour le seek).
 *
 * Mecanisme (documente par POC-FrAnime-Scrapping et l'extracteur VidMoly de
 * KekikStream, confirme sur la page de test) :
 *   1. La page /v/<id> (vidmoly.me) n'embarque pas le lecteur : elle contient
 *      une iframe vers la page d'integration, ex :
 *      https://vidmoly.biz/embed-iztl71ko757x.html
 *   2. La page d'integration charge un lecteur JW Player dont la config est
 *      dans le HTML : sources: [{file: "https://prx-....m3u8?t=..."}]
 *      (ou file: '...master.m3u8...').
 *   3. Certaines videos affichent un formulaire de verification
 *      ("Select number") : champs caches op / file_code / ts / nonce / ctok
 *      + reponse dans <div class="vhint"><b>...</b></div> — soumis en POST.
 *   4. Le flux est du HLS (.m3u8) ; la playlist master est parsee pour
 *      lister les variantes de qualite (comme uqload.php).
 *
 * Reponse JSON :
 *   master       : URL du flux (.m3u8 ou .mp4)
 *   type         : "m3u8" ou "mp4"
 *   variants     : [{bandwidth, resolution, url}] tries par qualite decroissante (m3u8)
 *   best / media : URL de la meilleure variante (ou du master si pas de variantes)
 *   proxied      : true si les URLs passent par le proxy (?proxy=1)
 *   playlistHttp : code HTTP de la playlist master (m3u8)
 *   masterBody   : contenu brut du master .m3u8
 *   subtitles    : [{file, label, kind}] si des sous-titres sont embarques
 *   embed        : URL de la page d'integration utilisee
 *   cached       : true si la reponse vient du cache
 *   key_name     : nom de la cle API utilisee (si cles configurees)
 *
 * Variables d'environnement (partagees avec uqload.php, meme service) :
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

/** Hotes autorises pour ?url= (protection SSRF : on ne fetch que vidmoly). */
const ALLOWED_HOSTS = [
    'vidmoly.me', 'www.vidmoly.me',
    'vidmoly.to', 'www.vidmoly.to',
    'vidmoly.net', 'www.vidmoly.net',
    'vidmoly.biz', 'www.vidmoly.biz',
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

function httpPost(string $url, array $fields, array $headers = [], int $timeout = 25): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_USERAGENT      => UA,
        CURLOPT_HTTPHEADER     => array_merge(
            ['Accept: text/html,application/xhtml+xml', 'Content-Type: application/x-www-form-urlencoded'],
            $headers
        ),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
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

/**
 * Hote autorise pour le relais de flux (?stream=) : un domaine vidmoly
 * (me/to/net/biz, avec sous-domaines) ou le CDN des flux, *.vmpx.online
 * (ex : prx-1331-ant-q.vmpx.online). Anti-SSRF : rien d'autre n'est relaye.
 */
function vidmolyMediaHostAllowed(string $host): bool
{
    $host = strtolower($host);
    if (preg_match('/^(?:[a-z0-9-]+\.)*vidmoly\.(me|to|net|biz)$/', $host)) {
        return true;
    }
    if (preg_match('/^(?:[a-z0-9-]+\.)*vmpx\.online$/', $host)) {
        return true;
    }
    return false;
}

/** URL absolue du script courant pour atteindre le mode proxy (?stream=...). */
function proxyUrl(string $target): string
{
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
    if (!is_string($proto) || $proto === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/vidmoly.php';
    $url    = "{$proto}://{$host}{$script}?stream=" . rawurlencode($target);
    // Signature HMAC (si activee) : le lecteur n'aura pas besoin de la cle API.
    $url .= streamSignatureQuery(basename(__FILE__, '.php'), $target);
    return $url;
}

/**
 * Re-ecrit une playlist .m3u8 : chaque URI (segment ou variante) et chaque
 * URI="..." (ex : cle EXT-X-KEY) passe par le proxy.
 */
function rewritePlaylist(string $body, string $baseUrl): string
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
                    static fn($m) => 'URI="' . proxyUrl(resolveUrl($baseUrl, $m[1])) . '"',
                    $trim
                ) ?? $trim;
            }
            $out[] = $trim;
            continue;
        }
        $out[] = proxyUrl(resolveUrl($baseUrl, $trim));
    }
    return implode("\n", $out) . "\n";
}

/**
 * Re-ecrit les URLs media d'une reponse JSON en URLs de proxy (?stream=...).
 * Les URLs directes restent dans le cache ; la re-ecriture se fait a l'envoi.
 * Les sous-titres passent aussi par le proxy : leurs fichiers sont sur le
 * meme CDN, donc soumis a la meme protection par IP.
 */
function applyProxyMode(array $res): array
{
    $origMaster = $res['master'] ?? null;
    if (is_string($origMaster) && $origMaster !== '') {
        $res['master'] = proxyUrl($origMaster);
    }
    if (isset($res['variants']) && is_array($res['variants'])) {
        foreach ($res['variants'] as &$v) {
            if (isset($v['url']) && is_string($v['url'])) {
                $v['url'] = proxyUrl($v['url']);
            }
        }
        unset($v);
    }
    if (isset($res['best']) && is_string($res['best'])) {
        $res['best'] = proxyUrl($res['best']);
    }
    if (isset($res['media']) && is_string($res['media'])) {
        $res['media'] = proxyUrl($res['media']);
    }
    if (isset($res['subtitles']) && is_array($res['subtitles'])) {
        foreach ($res['subtitles'] as &$s) {
            if (isset($s['file']) && is_string($s['file'])) {
                $s['file'] = proxyUrl($s['file']);
            }
        }
        unset($s);
    }
    if (isset($res['masterBody']) && is_string($origMaster) && $origMaster !== '') {
        $res['masterBody'] = rewritePlaylist($res['masterBody'], $origMaster);
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
 * Referer/Origin vidmoly, puis le sert au client. Indispensable a cause de
 * la protection par IP : les URLs du CDN sont liees a l'IP du serveur, un
 * navigateur qui les recupere directement recoit 403.
 * - playlist .m3u8 : les URLs sont re-ecrites vers ?stream= (le lecteur ne
 *   touche jamais le CDN directement)
 * - segment : le Range du client est transmis (seek), statut 200/206 et
 *   headers utiles relayes
 * Securite : seuls les domaines vidmoly et le CDN *.vmpx.online sont
 * autorises (anti-SSRF) et la cle API (si configuree) est exigee en amont
 * comme pour l'extraction.
 */
function serveStream(string $target): void
{
    $parts = parse_url($target);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || !vidmolyMediaHostAllowed($parts['host'])) {
        sendJson(['error' => 'URL de flux invalide (domaines vidmoly uniquement)'], 400);
        return;
    }
    $path   = parse_url($target, PHP_URL_PATH) ?? '';
    $range  = $_SERVER['HTTP_RANGE'] ?? null;
    $range  = is_string($range) ? $range : null;
    if (str_ends_with(strtolower($path), '.m3u8')) {
        $range = null; // une playlist se recupere entiere, pas par morceaux
    }
    $res = httpFetch($target, ['Referer: https://vidmoly.me/', 'Origin: https://vidmoly.me'], $range);
    if ($res['code'] < 200 || $res['code'] >= 400 || $res['body'] === '') {
        sendJson(['error' => "flux HTTP {$res['code']} {$res['error']}"], 502);
        return;
    }
    $ctype = strtolower($res['headers']['content-type'] ?? '');
    $isPlaylist = str_ends_with(strtolower($path), '.m3u8') || str_contains($ctype, 'mpegurl');
    if ($isPlaylist) {
        header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
        header('Cache-Control: no-cache');
        echo rewritePlaylist($res['body'], $target);
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

/** Extrait les champs name/value de tous les <input> d'un formulaire HTML. */
function parseFormInputs(string $html): array
{
    $fields = [];
    if (preg_match_all('/<input\b[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\bname\s*=\s*["\']([^"\']+)["\']/i', $tag, $nm)) {
                continue;
            }
            $val = '';
            if (preg_match('/\bvalue\s*=\s*["\']([^"\']*)["\']/i', $tag, $vm)) {
                $val = html_entity_decode($vm[1], ENT_QUOTES, 'UTF-8');
            }
            $fields[$nm[1]] = $val;
        }
    }
    return $fields;
}

/**
 * Si la page affiche le formulaire de verification ("Select number"),
 * le soumet en POST (reponse dans <div class="vhint"><b>...</b></div>)
 * et retourne le HTML resultant. Sinon retourne le HTML tel quel.
 */
function maybeSubmitVerification(string $url, string $html): string
{
    if (stripos($html, 'Select number') === false) {
        return $html;
    }
    $fields = parseFormInputs($html);
    if (preg_match('/<div[^>]*class=["\'][^"\']*vhint[^"\']*["\'][^>]*>.*?<b[^>]*>([^<]+)<\/b>/is', $html, $m)) {
        $fields['answer'] = trim(strip_tags($m[1]));
    }
    if (!isset($fields['op'], $fields['file_code'])) {
        return $html;
    }
    $res = httpPost($url, $fields, ['Referer: ' . $url, 'Sec-Fetch-Dest: iframe']);
    if ($res['code'] === 200 && $res['body'] !== '') {
        return $res['body'];
    }
    return $html;
}

/** Trouve l'URL de la page d'integration vidmoly (/embed-XXXX.html) dans une iframe. */
function findEmbedUrl(string $html, string $baseUrl): ?string
{
    if (preg_match_all('/<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
        foreach ($m[1] as $src) {
            $abs = resolveUrl($baseUrl, html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
            if (preg_match('~^https?://(www\.)?vidmoly\.(me|to|net|biz)/embed-[A-Za-z0-9_-]+\.html(\?[^#]*)?$~i', $abs)) {
                return $abs;
            }
        }
    }
    return null;
}

/**
 * Cherche l'URL du flux video dans le HTML, par specificite decroissante :
 *   1. tableau sources: [{file: "..."}] du lecteur JW Player
 *   2. file: "...m3u8..." (guillemets doubles ou simples)
 *   3. n'importe quelle URL .m3u8 dans le HTML
 *   4. file: "..." generique (hors images)
 * Les .m3u8 sont preferes aux autres formats.
 */
function findStreamUrl(string $html): ?string
{
    $candidates = [];
    // Tier 1 : tableau sources du JW Player
    if (preg_match('/sources\s*:\s*\[(.*?)\]/is', $html, $sm)) {
        if (preg_match_all('/["\']?file["\']?\s*:\s*["\']([^"\']+)["\']/i', $sm[1], $fm)) {
            foreach ($fm[1] as $f) {
                $candidates[] = $f;
            }
        }
    }
    // Tier 2 : file: "...m3u8..."
    if (preg_match('/file\s*:\s*"([^"]*\.m3u8[^"]*)"/i', $html, $m)) {
        $candidates[] = $m[1];
    }
    if (preg_match("/file\s*:\s*'([^']*\.m3u8[^']*)'/i", $html, $m)) {
        $candidates[] = $m[1];
    }
    // Tier 3 : URL .m3u8 brute dans le HTML
    if (preg_match_all('~(https?:)?//[^"\'\\\\\\s<>]*\.m3u8[^"\'\\\\\\s<>]*~i', $html, $m)) {
        foreach ($m[0] as $u) {
            $candidates[] = $u;
        }
    }
    // Tier 4 : file: "..." generique
    if (preg_match('/file\s*:\s*"([^"]+)"/i', $html, $m)) {
        $candidates[] = $m[1];
    }
    if (preg_match("/file\s*:\s*'([^']+)'/i", $html, $m)) {
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

/** Sous-titres embarques : tableau tracks: [{file, label, kind}] du lecteur. */
function findSubtitles(string $html): array
{
    $subs = [];
    if (!preg_match('/tracks\s*:\s*\[(.*?)\]/is', $html, $tm)) {
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

/** Recupere le flux : page /v/ -> iframe d'integration -> config du lecteur. */
function extractStream(string $pageUrl): array
{
    $page = httpGet($pageUrl, ['Accept: text/html,application/xhtml+xml', 'Referer: https://vidmoly.me/']);
    if ($page['code'] !== 200 || $page['body'] === '') {
        return ['error' => "page HTTP {$page['code']} {$page['error']}"];
    }
    $html     = maybeSubmitVerification($pageUrl, $page['body']);
    $embedUrl = findEmbedUrl($html, $pageUrl);
    if ($embedUrl !== null && $embedUrl !== $pageUrl) {
        $embed = httpGet($embedUrl, [
            'Accept: text/html,application/xhtml+xml',
            'Referer: ' . $pageUrl,
            'Sec-Fetch-Dest: iframe',
        ]);
        if ($embed['code'] === 200 && $embed['body'] !== '') {
            $html = maybeSubmitVerification($embedUrl, $embed['body']);
        }
    }
    $stream = findStreamUrl($html);
    if ($stream === null) {
        return ['error' => 'flux video introuvable'];
    }
    $res = ['master' => $stream, 'embed' => $embedUrl ?? $pageUrl];
    $subs = findSubtitles($html);
    if ($subs !== []) {
        $res['subtitles'] = $subs;
    }
    return $res;
}

/** Construit l'URL de la page vidmoly a partir de ?id= ou ?url= (avec validation). */
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
        return $url;
    }
    if ($input !== null && $input !== '') {
        if (preg_match('~^https?://~i', $input)) {
            return buildPageUrl(null, $input);
        }
        if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $input)) {
            return null;
        }
        return "https://vidmoly.me/v/{$input}";
    }
    return null;
}

/** Cache fichier (duree via UQLOAD_CACHE_TTL, 0 = desactive). */
function cacheGet(string $key, int $ttl): ?array
{
    if ($ttl <= 0) {
        return null;
    }
    $file = sys_get_temp_dir() . '/vidmoly-cache-' . $key . '.json';
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
    @file_put_contents(sys_get_temp_dir() . '/vidmoly-cache-' . $key . '.json', json_encode($data), LOCK_EX);
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
    $file = sys_get_temp_dir() . '/vidmoly-rl-' . md5($keyCfg['key']) . '.json';
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
    if (in_array('vidmoly', $disabledList, true)) {
        sendJson([
            'error'   => 'extracteur_desactive',
            'message' => "L'extracteur vidmoly est desactive pour le moment.",
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
// refait la requete avec sa propre IP (protection par IP, voir en-tete).
$stream = $_GET['stream'] ?? null;
if (is_string($stream) && $stream !== '' && php_sapi_name() !== 'cli') {
    serveStream($stream);
    exit;
}

// ---- Mode proxy JSON : ?proxy=1 -> les URLs media de la reponse passent par ?stream=
$proxyMode = isset($_GET['proxy']) && $_GET['proxy'] !== '' && $_GET['proxy'] !== '0' && php_sapi_name() !== 'cli';

$pageUrl = buildPageUrl(is_string($input) ? $input : null, is_string($url) ? $url : null);
if ($pageUrl === null) {
    sendJson(['error' => 'param ?id= (code video vidmoly) ou ?url= (lien vidmoly.me/to/net/biz) requis/valide'], 400);
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
    $pl = httpGet($master, ['Accept: application/vnd.apple.mpegurl', "Referer: {$pageUrl}"]);
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
