<?php
/**
 * Extracteur MP4 streamtape — v1.1
 * ==============================
 * Usage web  : streamtape.php?id=MkGWp47a8bUma4o
 *              streamtape.php?id=MkGWp47a8bUma4o&proxy=1   -> URL media via le proxy (voir ci-dessous)
 *              streamtape.php?stream=<url encodee>          -> relaye le fichier MP4
 * Usage CLI  : php streamtape.php MkGWp47a8bUma4o
 *
 * Mecanisme (documente par les extracteurs open source AdultHideout,
 * ResolveURL et CyberDropDownloader, lus le 17 novembre 2026, et par la
 * lecture de la page live le 18 novembre 2026) :
 *   1. La page d'integration https://streamtape.com/e/<id> (ou la page
 *      /v/<id>) contient un <script> qui assemble l'URL directe en
 *      JavaScript : des fragments de chaine concatenes avec des
 *      .substring(N), .substring(N, M), .substr() ou .slice(), parfois
 *      en chaine, ecrits dans des elements appats dont l'id finit par
 *      "link" et tourne avec le temps (ideoolink, botlink, robotlink,
 *      videoolink, norobotlink...).
 *   2. ATTENTION (bug corrige en v1.1) : la page ecrit PLUSIEURS URLs
 *      assemblees, et certaines sont des leurres — les assignations vers
 *      #ideoolink produisent un hote bidon (ex : streambtape.com) alors
 *      que le vrai lien est sur le meme hote que la page. Le lecteur
 *      video de la page lit #botlink :
 *        var srclink = $('#botlink').text() + '&stream=1';
 *      donc #botlink est la source de reference, puis #robotlink, puis
 *      les autres. L'evaluateur doit aussi gerer les .substring() en
 *      chaine et a deux arguments (sinon l'id est corrompu, ex :
 *      MkGxcdbWp47a8bUma4o au lieu de MkGWp47a8bUma4o -> 403).
 *   3. L'URL assemblee ressemble a //host/get_video?... (+ &token=...
 *      et &stream=1). C'est un MP4 direct (pas de HLS) ; elle fait une
 *      redirection 302 vers le fichier final sur le CDN.
 *   4. Les en-tetes requis : User-Agent de navigateur + Referer = URL de
 *      la page d'integration.
 *
 * Cloudflare : streamtape.com est protege par Cloudflare — un fetch serveur
 * en cURL simple peut recevoir un 503 (ResolveURL le signale comme
 * "Site using Cloudflare DDOS protection"). Si la page repond 503, c'est
 * tres probablement ca, pas un bug de l'extracteur. AdultHideout passe
 * par cloudscraper avant de retomber sur une requete simple ; ce script
 * ne fait que du cURL simple.
 *
 * Limitation IP : NON VERIFIEE. Aucun des extracteurs lus ne demontre un
 * controle d'IP sur le lien get_video (ses parametres exacts ne sont
 * jamais parses par ces codes — la chaine est consommee telle quelle).
 * Le lien est emis a chaque chargement de page et limite dans le temps,
 * donc le plus sur est de le generer ET de le consommer depuis la meme
 * IP : le mode proxy (?proxy=1) est recommande, comme pour vidmoly.
 * La lecture directe reste possible en theorie, mais non garantie.
 *
 * Reponse JSON :
 *   master       : URL directe du MP4 (get_video, avec token et stream=1)
 *   type         : "mp4"
 *   best / media : meme URL que master (pas de variantes sur streamtape)
 *   title        : titre de la video (og:title), si trouve
 *   proxied      : true si les URLs passent par le proxy (?proxy=1)
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

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36';

/** Hotes autorises pour ?url= (protection SSRF : on ne fetch que streamtape). */
const ALLOWED_HOSTS = [
    'streamtape.com', 'www.streamtape.com',
    'strtape.cloud', 'streamtape.net', 'streamta.pe', 'streamtape.site',
    'strcloud.link', 'streamtape.cc', 'scloud.online', 'stape.fun',
    'streamtape.to', 'streamtape.xyz', 'tapeblocker.com', 'streamnoads.com',
    'gettapeads.com', 'advtpe.com',
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
 * Hote autorise pour le relais de flux (?stream=) : un domaine streamtape
 * (voir ALLOWED_HOSTS) ou le CDN des fichiers, *.tapecontent.net
 * (ex : tapecontent.net, vu dans la doc de l'API streamtape).
 * Anti-SSRF : rien d'autre n'est relaye.
 */
function streamtapeMediaHostAllowed(string $host): bool
{
    $host = strtolower($host);
    if (in_array($host, ALLOWED_HOSTS, true)) {
        return true;
    }
    if (preg_match('/^(?:[a-z0-9-]+\.)*tapecontent\.net$/', $host)) {
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
    $script = $_SERVER['SCRIPT_NAME'] ?? '/streamtape.php';
    $url    = "{$proto}://{$host}{$script}?stream=" . rawurlencode($target);
    if ($origin !== null && $origin !== '') {
        $url .= '&o=' . rawurlencode($origin);
    }
    return $url;
}

/**
 * Re-ecrit les URLs media d'une reponse JSON en URLs de proxy (?stream=...).
 * Les URLs directes restent dans le cache ; la re-ecriture se fait a l'envoi.
 */
function applyProxyMode(array $res): array
{
    $origin = $res['embed'] ?? null;
    $origin = is_string($origin) ? $origin : null;
    foreach (['master', 'best', 'media'] as $key) {
        if (isset($res[$key]) && is_string($res[$key]) && $res[$key] !== '') {
            $res[$key] = proxyUrl($res[$key], $origin);
        }
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
 * Mode proxy de flux : recupere le MP4 cote serveur avec Referer = page
 * d'integration, puis le sert au client. Recommande (voir en-tete) : le
 * lien get_video est emis par page et limite dans le temps, et la
 * limitation par IP n'est pas exclue. Le Range du client est transmis
 * (seek), statut 200/206 et headers utiles relayes. La redirection 302
 * vers le CDN final est suivie cote serveur.
 * Securite : seuls les domaines streamtape et le CDN *.tapecontent.net
 * sont autorises (anti-SSRF) et la cle API (si configuree) est exigee
 * en amont comme pour l'extraction.
 */
function serveStream(string $target): void
{
    $parts = parse_url($target);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        || !streamtapeMediaHostAllowed($parts['host'])) {
        sendJson(['error' => 'URL de flux invalide (domaines streamtape uniquement)'], 400);
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
        $origin = 'https://streamtape.com';
    }
    $range = $_SERVER['HTTP_RANGE'] ?? null;
    $range = is_string($range) ? $range : null;
    $res = httpFetch($target, ["Referer: {$origin}/", "Origin: {$origin}"], $range);
    if ($res['code'] < 200 || $res['code'] >= 400 || $res['body'] === '') {
        sendJson(['error' => "flux HTTP {$res['code']} {$res['error']}"], 502);
        return;
    }
    http_response_code($res['code']);
    $ctype = strtolower($res['headers']['content-type'] ?? '');
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

/**
 * Applique une methode de chaine JS (substring / substr / slice) a $s,
 * avec la semantique JavaScript : indices colles a [0, longueur],
 * substring echange debut/fin si debut > fin, slice accepte les negatifs.
 * $args : indices entiers (0, 1 ou 2).
 */
function jsStringMethod(string $s, string $method, array $args): string
{
    $len = strlen($s);
    if ($method === 'substring') {
        $start = max(0, min($args[0] ?? 0, $len));
        $end = isset($args[1]) ? max(0, min($args[1], $len)) : $len;
        if ($start > $end) {
            $tmp = $start;
            $start = $end;
            $end = $tmp;
        }
        return substr($s, $start, $end - $start);
    }
    if ($method === 'substr') {
        $start = $args[0] ?? 0;
        if ($start < 0) {
            $start = max(0, $len + $start);
        }
        if ($start >= $len) {
            return '';
        }
        if (!isset($args[1])) {
            return substr($s, $start);
        }
        if ($args[1] <= 0) {
            return '';
        }
        return substr($s, $start, $args[1]);
    }
    // slice
    $start = $args[0] ?? 0;
    if ($start < 0) {
        $start = max(0, $len + $start);
    } else {
        $start = min($start, $len);
    }
    $end = $args[1] ?? $len;
    if ($end < 0) {
        $end = max(0, $len + $end);
    } else {
        $end = min($end, $len);
    }
    if ($end <= $start) {
        return '';
    }
    return substr($s, $start, $end - $start);
}

/**
 * Evalue une expression JS de concatenation de chaines, du type
 *   'frag1' + 'frag2'.substring(2, 5).slice(1) + "frag3"
 * Chaque litteral peut porter une chaine de methodes (.substring/.substr/
 * .slice, 1 ou 2 arguments) appliquees dans l'ordre, comme en JS.
 * Les operandes non litteraux (variables, appels de fonctions) sont ignores.
 * Retourne null si aucun litteral n'est trouve.
 */
function evaluateConcatExpr(string $expr): ?string
{
    $out   = '';
    $found = false;
    if (preg_match_all(
        '/(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\')((?:\s*\)?\s*\.(?:substring|substr|slice)\(\s*-?\d+\s*(?:,\s*-?\d+\s*)?\))*)?/s',
        $expr,
        $m,
        PREG_SET_ORDER
    )) {
        foreach ($m as $part) {
            $lit = ($part[1] !== '') ? $part[1] : ($part[2] ?? '');
            $lit = stripcslashes(str_replace('\\/', '/', $lit));
            $chain = $part[3] ?? '';
            if ($chain !== '' && preg_match_all(
                '/\.(substring|substr|slice)\(\s*(-?\d+)\s*(?:,\s*(-?\d+)\s*)?\)/',
                $chain,
                $calls,
                PREG_SET_ORDER
            )) {
                foreach ($calls as $call) {
                    $args = [(int) $call[2]];
                    if (isset($call[3]) && $call[3] !== '') {
                        $args[] = (int) $call[3];
                    }
                    $lit = jsStringMethod($lit, $call[1], $args);
                }
            }
            $out .= $lit;
            $found = true;
        }
    }
    return $found ? $out : null;
}

/**
 * Normalise une URL get_video assemblee : // -> https:, /get_vide...? ->
 * /get_video?, ajout de &stream=1 si absent. Retourne null si l'URL
 * ne ressemble pas a un lien get_video.
 */
function normalizeDirectUrl(string $url, string $embedUrl): ?string
{
    $url = trim(stripcslashes(str_replace('\\/', '/', $url)));
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    } elseif (preg_match('~^/[^/]+\.[^/]+/~', $url)) {
        $url = 'https:/' . $url;
    } elseif (str_starts_with($url, '/')) {
        $url = resolveUrl($embedUrl, $url);
    }
    $url = preg_replace('~/get_vide[a-z0-9_]*\?~i', '/get_video?', $url) ?? $url;
    if (!preg_match('~^https?://[^/]+/get_video\?~i', $url)) {
        return null;
    }
    if (stripos($url, 'stream=1') === false) {
        $url .= '&stream=1';
    }
    return $url;
}

/**
 * Vrai si $host est l'hote de la page d'integration (ou un sous-domaine),
 * apres suppression d'un eventuel "www." de chaque cote. Sert a ecarter
 * les leurres : streamtape ecrit plusieurs URLs assemblees, dont certaines
 * (souvent les premieres, vers #ideoolink) pointent vers un hote bidon
 * (ex : streambtape.com) alors que le vrai lien est sur le meme hote que
 * la page (voir #botlink, lu par le lecteur video).
 */
function sameEmbedHost(string $host, string $embedHost): bool
{
    $norm = static function (string $h): string {
        $h = strtolower(trim($h));
        if (str_starts_with($h, 'www.')) {
            $h = substr($h, 4);
        }
        return $h;
    };
    $a = $norm($host);
    $b = $norm($embedHost);
    return $a === $b || str_ends_with($a, '.' . $b) || str_ends_with($b, '.' . $a);
}

/**
 * Cherche l'URL directe dans le HTML, par specificite decroissante :
 *   1. expressions JS assignees aux elements appats (...link).innerHTML
 *      (priorite : #botlink, lu par le lecteur video, puis #robotlink,
 *      puis les autres) — plusieurs assignations coexistent et certaines
 *      sont des leurres vers un hote bidon.
 *   2. variante minifiee ById('...') = "...//...get_video..."
 *   3. contenu statique des elements appats (#botlink, #robotlink, ...)
 *   4. URL //.../get_video?... brute dans le HTML
 * Dans chaque cas on prefere une URL dont l'hote est celui de la page
 * d'integration ; a defaut on garde la premiere URL normalisee trouvee
 * (comportement historique).
 */
function findDirectUrl(string $html, string $embedUrl): ?string
{
    $embedHost = parse_url($embedUrl, PHP_URL_HOST);
    $embedHost = is_string($embedHost) ? $embedHost : '';
    $hostOk = [];
    $any = [];
    $consider = static function (?string $raw) use ($embedUrl, $embedHost, &$hostOk, &$any): void {
        if ($raw === null || $raw === '') {
            return;
        }
        $norm = normalizeDirectUrl($raw, $embedUrl);
        if ($norm === null) {
            return;
        }
        $any[] = $norm;
        $host = parse_url($norm, PHP_URL_HOST);
        if (is_string($host) && $embedHost !== '' && sameEmbedHost($host, $embedHost)) {
            $hostOk[] = $norm;
        }
    };
    $rank = static function (string $id): int {
        $id = strtolower($id);
        if ($id === 'botlink') {
            return 0;
        }
        if ($id === 'robotlink') {
            return 1;
        }
        return 2;
    };

    // Tier 1 : toutes les assignations innerHTML vers les elements appats.
    if (preg_match_all(
        "/document\\.getElementById\\(\\s*['\"]([A-Za-z0-9]*link)['\"]\\s*\\)\\.innerHTML\\s*=\\s*(.+?);/is",
        $html,
        $assigns,
        PREG_SET_ORDER
    )) {
        usort($assigns, static function (array $a, array $b) use ($rank): int {
            return $rank($a[1]) <=> $rank($b[1]);
        });
        foreach ($assigns as $as) {
            $consider(evaluateConcatExpr($as[2]));
        }
    }
    // Tier 2 : ById('...') = "//...get_video..."
    if (preg_match("/ById\\('.+?=\\s*([\"']\\/\\/[^;<]+)/s", $html, $m)) {
        $consider(evaluateConcatExpr($m[1]));
    }
    // Tier 3 : contenu statique des elements appats (botlink d'abord).
    if (preg_match_all(
        '/<(?:div|span)\b[^>]*\bid\s*=\s*["\']([A-Za-z0-9]*link)["\'][^>]*>([^<]+)/i',
        $html,
        $statics,
        PREG_SET_ORDER
    )) {
        usort($statics, static function (array $a, array $b) use ($rank): int {
            return $rank($a[1]) <=> $rank($b[1]);
        });
        foreach ($statics as $st) {
            $consider(trim($st[2]));
        }
    }
    // Tier 4 : URL get_video brute.
    if (preg_match_all('~["\']?(//[^"\'\s<>]*get_vide[a-z0-9_]*\?[^"\'\s<>]*)~i', $html, $brutes)) {
        foreach ($brutes[1] as $raw) {
            $consider($raw);
        }
    }
    if ($hostOk !== []) {
        return $hostOk[0];
    }
    return $any[0] ?? null;
}

/** Titre de la video (og:title, sinon <title>). */
function findTitle(string $html): ?string
{
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/i', $html, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $t = trim(strip_tags($m[1]));
        return $t !== '' ? $t : null;
    }
    return null;
}

/** Recupere le lien direct : page d'integration -> element appat / JS. */
function extractStream(string $pageUrl): array
{
    $origin = originOf($pageUrl) ?? 'https://streamtape.com';
    $page = httpGet($pageUrl, ['Accept: text/html,application/xhtml+xml', "Referer: {$origin}/"]);
    if ($page['code'] === 503) {
        return ['error' => 'page HTTP 503 (Cloudflare : streamtape bloque ce serveur pour le moment)'];
    }
    if ($page['code'] !== 200 || $page['body'] === '') {
        return ['error' => "page HTTP {$page['code']} {$page['error']}"];
    }
    $html = $page['body'];
    if (stripos($html, 'nofile') !== false) {
        return ['error' => 'video introuvable ou supprimee (nofile)'];
    }
    $direct = findDirectUrl($html, $pageUrl);
    if ($direct === null) {
        return ['error' => 'lien direct introuvable'];
    }
    // Token : si l'URL assemblee ne l'a pas, il est dans le meme script.
    if (stripos($direct, 'token=') === false
        && preg_match('/[?&]token=([A-Za-z0-9_\-]+)/', $html, $tm)) {
        $direct .= '&token=' . $tm[1];
    }
    $res = ['master' => $direct, 'embed' => $pageUrl];
    $title = findTitle($html);
    if ($title !== null) {
        $res['title'] = $title;
    }
    return $res;
}

/** Construit l'URL de la page streamtape a partir de ?id= ou ?url= (avec validation). */
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
        if (!preg_match('~/(?:e|v)/([A-Za-z0-9]+)~', $parts['path'] ?? '', $m)) {
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
        return "https://streamtape.com/e/{$input}";
    }
    return null;
}

/** Cache fichier (duree via UQLOAD_CACHE_TTL, 0 = desactive). */
function cacheGet(string $key, int $ttl): ?array
{
    if ($ttl <= 0) {
        return null;
    }
    $file = sys_get_temp_dir() . '/streamtape-cache-' . $key . '.json';
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
    @file_put_contents(sys_get_temp_dir() . '/streamtape-cache-' . $key . '.json', json_encode($data), LOCK_EX);
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
    $file = sys_get_temp_dir() . '/streamtape-rl-' . md5($keyCfg['key']) . '.json';
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

// ---- Authentification par cle API (si des cles sont configurees, sauf en CLI)
$apiKeys = parseApiKeys();
$auth    = (php_sapi_name() === 'cli') ? ['open' => true] : findApiKey($apiKeys);
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
$stream = $_GET['stream'] ?? null;
if (is_string($stream) && $stream !== '' && php_sapi_name() !== 'cli') {
    serveStream($stream);
    exit;
}

// ---- Mode proxy JSON : ?proxy=1 -> les URLs media de la reponse passent par ?stream=
$proxyMode = isset($_GET['proxy']) && $_GET['proxy'] !== '' && $_GET['proxy'] !== '0' && php_sapi_name() !== 'cli';

$pageUrl = buildPageUrl(is_string($input) ? $input : null, is_string($url) ? $url : null);
if ($pageUrl === null) {
    sendJson(['error' => 'param ?id= (code video streamtape) ou ?url= (lien streamtape /e/ ou /v/) requis/valide'], 400);
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
    sendJson(['error' => $res['error'] ?? 'lien direct introuvable'], 502);
    exit;
}

$res['type']  = 'mp4';
$res['best']  = $res['master'];
$res['media'] = $res['master'];

cacheSet($cacheKey, $res, $cacheTtl);
$res['cached'] = false;
if ($auth !== null && !empty($auth['name'])) {
    $res['key_name'] = $auth['name'];
}
if ($proxyMode) {
    $res = applyProxyMode($res);
}
sendJson($res);
