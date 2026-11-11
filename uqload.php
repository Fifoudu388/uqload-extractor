<?php
/**
 * Extracteur m3u8 uqload — v2
 * ==========================
 * Usage web  : uqload.php?id=v80v3zz0vay2   (ou ?url=https://uqload.vc/xxxx.html)
 * Usage CLI  : php uqload.php v80v3zz0vay2
 *
 * Le token est regenere a chaque chargement de page (valide ~4h) et n'est PAS lie a l'IP.
 * Il est present dans le HTML, dans un script obfusque par le packer "Dean Edwards".
 *
 * Reponse JSON :
 *   master       : URL du master .m3u8
 *   variants     : [{bandwidth, resolution, url}] tries par qualite decroissante
 *   best / media : URL de la meilleure variante (ou du master si pas de variantes)
 *   playlistHttp : code HTTP de la playlist master
 *   masterBody   : contenu brut du master .m3u8
 *   cached       : true si la reponse vient du cache
 *   key_name     : nom de la cle API utilisee (si cles configurees)
 *
 * Variables d'environnement :
 *   UQLOAD_CACHE_TTL : duree du cache en secondes (defaut 3600, 0 = desactive)
 *   UQLOAD_API_KEYS  : cles API, une par ligne, format nom:cle[:limite_par_minute]
 *                      ex : visionary:sk_live_abc123:60
 *                           test:sk_test_xyz:10
 *                      Si vide : acces libre. Si defini : cle requise
 *                      (header X-API-Key ou ?key=), 401 sinon, 429 si limite atteinte.
 *                      Generer une cle : openssl rand -hex 24
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

/** Hotes autorises pour ?url= (protection SSRF : on ne fetch que uqload). */
const ALLOWED_HOSTS = [
    'uqload.vc', 'www.uqload.vc',
    'uqload.io', 'www.uqload.io',
    'uqload.net', 'www.uqload.net',
    'uqload.com', 'www.uqload.com',
    'uqload.to', 'www.uqload.to',
];

/** Alphabet du packer Dean Edwards (base 2 a 62). */
const PACKER_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** Encodage d'un entier dans une base 2..62 (base_convert() plafonne a 36). */
function baseEncode(int $num, int $base): string
{
    if ($num === 0) {
        return '0';
    }
    $out = '';
    while ($num > 0) {
        $out = PACKER_ALPHABET[$num % $base] . $out;
        $num = intdiv($num, $base);
    }
    return $out;
}

/**
 * De-obfuscation du packer Dean Edwards :
 * eval(function(p,a,c,k,e,d){...}('PAYLOAD',BASE,COUNT,'K1|K2|...'.split('|')))
 *
 * Corrections par rapport a la v1 :
 * - base 62 supportee (base_convert() plafonnait a 36, donc les tokens etaient faux)
 * - remplacement en une seule passe : les mots deja remplaces ne sont pas re-traites,
 *   ce qui evitait des corruptions quand un mot de remplacement ressemblait a un token
 * - payload et cles gerent les caracteres echappes (\', \\, \/, ...)
 */
function dePacker(string $js, int $rounds = 3): string
{
    $patterns = [
        // Guillemets simples (forme classique)
        "/\\}\\s*\\(\\s*'((?:\\\\.|[^'\\\\])*)'\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*'((?:\\\\.|[^'\\\\])*)'\\.split\\('\\|'\\)/s",
        // Guillemets doubles
        '/\\}\\s*\\(\\s*"((?:\\\\.|[^"\\\\])*)"\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*"((?:\\\\.|[^"\\\\])*)"\\.split\\("\\|"\\)/s',
    ];

    for ($r = 0; $r < $rounds; $r++) {
        $m = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $js, $m)) {
                break;
            }
            $m = null;
        }
        if ($m === null) {
            break; // pas (plus) de packer
        }

        $payload = stripcslashes($m[1]);
        $base    = (int) $m[2];
        $count   = (int) $m[3];
        $keys    = array_map('stripcslashes', explode('|', $m[4]));

        if ($base < 2 || $base > 62 || $count <= 0) {
            break;
        }

        $map = [];
        for ($i = 0; $i < $count; $i++) {
            if (!isset($keys[$i]) || $keys[$i] === '') {
                continue;
            }
            $map[baseEncode($i, $base)] = $keys[$i];
        }
        if ($map === []) {
            break;
        }

        // Plus longs d'abord pour eviter les collisions de prefixe (ex : "1a" avant "1").
        uksort($map, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        $pattern = '/\b(' . implode('|', array_map(
            static fn($t) => preg_quote((string) $t, '/'),
            array_keys($map)
        )) . ')\b/';
        $js = (string) preg_replace_callback(
            $pattern,
            static fn($mm) => $map[$mm[1]] ?? $mm[1],
            $payload
        );
    }
    return $js;
}

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

/** Cherche l'URL du master .m3u8 dans le HTML (de-obfusque puis brut). */
function findMasterUrl(string $html): ?string
{
    $candidates = [];
    foreach ([dePacker($html), $html] as $text) {
        // JWPlayer : file: "...master.m3u8..." (guillemets doubles ou simples)
        if (preg_match('/file\s*:\s*"([^"]*master\.m3u8[^"]*)"/i', $text, $m)) {
            $candidates[] = $m[1];
        }
        if (preg_match("/file\s*:\s*'([^']*master\.m3u8[^']*)'/i", $text, $m)) {
            $candidates[] = $m[1];
        }
        // sources: [{file: "..."}]
        if (preg_match('/["\']file["\']\s*:\s*["\']([^"\']*master\.m3u8[^"\']*)["\']/i', $text, $m)) {
            $candidates[] = $m[1];
        }
        // URL brute vers le CDN uqload
        if (preg_match('~(https?:)?//strm\d*\.uqload\.[a-z]+/[^"\'\\\\ ]*master\.m3u8[^"\'\\\\ ]*~i', $text, $m)) {
            $candidates[] = $m[0];
        }
    }
    foreach ($candidates as $c) {
        $c = stripcslashes(str_replace('\\/', '/', $c));
        if (str_starts_with($c, '//')) {
            $c = 'https:' . $c;
        }
        if (preg_match('~^https?://~i', $c)) {
            return $c;
        }
    }
    return null;
}

function extractMaster(string $pageUrl): array
{
    $page = httpGet($pageUrl, ['Accept: text/html,application/xhtml+xml', 'Referer: https://uqload.vc/']);
    if ($page['code'] !== 200 || $page['body'] === '') {
        return ['error' => "page HTTP {$page['code']} {$page['error']}"];
    }
    $master = findMasterUrl($page['body']);
    if ($master === null) {
        return ['error' => 'token/master introuvable'];
    }
    return ['master' => $master];
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

/** Construit l'URL de la page uqload a partir de ?id= ou ?url= (avec validation). */
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
        return "https://uqload.vc/{$input}.html";
    }
    return null;
}

/** Cache fichier (duree via UQLOAD_CACHE_TTL, 0 = desactive). */
function cacheGet(string $key, int $ttl): ?array
{
    if ($ttl <= 0) {
        return null;
    }
    $file = sys_get_temp_dir() . '/uqload-cache-' . $key . '.json';
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
    @file_put_contents(sys_get_temp_dir() . '/uqload-cache-' . $key . '.json', json_encode($data), LOCK_EX);
}

function sendJson(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}

/**
 * Cles API configurees via UQLOAD_API_KEYS, une par ligne :
 *   nom:cle[:limite_par_minute]   (limite 0 ou absente = illimite)
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
    $file = sys_get_temp_dir() . '/uqload-rl-' . md5($keyCfg['key']) . '.json';
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

$pageUrl = buildPageUrl(is_string($input) ? $input : null, is_string($url) ? $url : null);
if ($pageUrl === null) {
    sendJson(['error' => 'param ?id= (code video uqload) ou ?url= (lien uqload.vc) requis/valide'], 400);
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
    sendJson($cached);
    exit;
}

$res = extractMaster($pageUrl);
if (!isset($res['master'])) {
    sendJson(['error' => $res['error'] ?? 'token/master introuvable'], 502);
    exit;
}

$pl = httpGet($res['master'], ['Accept: application/vnd.apple.mpegurl', "Referer: {$pageUrl}"]);
$res['playlistHttp'] = $pl['code'];
if ($pl['code'] === 200 && $pl['body'] !== '') {
    $variants = parseMasterPlaylist($pl['body'], $res['master']);
    if ($variants !== []) {
        $res['variants'] = $variants;
        $res['best']     = $variants[0]['url'];
        $res['media']    = $variants[0]['url'];
    } else {
        // Playlist media directe (pas de variantes) : le master est deja le flux.
        $res['media'] = $res['master'];
    }
    $res['masterBody'] = trim($pl['body']);
}

cacheSet($cacheKey, $res, $cacheTtl);
$res['cached'] = false;
if ($auth !== null && !empty($auth['name'])) {
    $res['key_name'] = $auth['name'];
}
sendJson($res);
