<?php
/**
 * Verifications HTTP de l'etat des services : backends extracteurs,
 * entree publique, et tests d'extraction de bout en bout.
 */

/** Petit GET avec mesures ; ne leve jamais d'exception. */
function httpGet(string $url, array $headers = [], int $timeout = 8): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: extractor-dashboard/1.0'], $headers),
        CURLOPT_HEADER => true,
    ]);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['code' => 0, 'ms' => $ms, 'body' => '', 'headers' => [], 'error' => $err];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headersOut = [];
    foreach (explode("\r\n", substr((string) $raw, 0, $hsize)) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $headersOut[strtolower(trim($k))][] = trim($v);
        }
    }
    return [
        'code' => $code,
        'ms' => $ms,
        'body' => substr((string) $raw, $hsize),
        'headers' => $headersOut,
        'error' => '',
    ];
}

/** Un backend extracteur repond-il, et son index dit-il "online" ? */
function checkBackend(string $url): array
{
    $r = httpGet(rtrim($url, '/') . '/', [], 6);
    $online = false;
    if ($r['code'] === 200) {
        $j = json_decode($r['body'], true);
        $online = is_array($j) && ($j['status'] ?? '') === 'online';
    }
    return [
        'url' => $url,
        'code' => $r['code'],
        'ms' => $r['ms'],
        'online' => $online,
        'error' => $r['error'],
    ];
}

/**
 * Etat de l'entree publique (le domaine des extracteurs) :
 *  - lb_disabled : le load balancer repond sa page "desactive"
 *  - online      : un extracteur repond (via_lb = cookie lb vu dans la reponse)
 *  - down        : injoignable
 *  - unexpected  : autre reponse (code HTTP donne en plus)
 */
function checkPublic(string $url): array
{
    if ($url === '') {
        return ['state' => 'not_configured'];
    }
    $r = httpGet(rtrim($url, '/') . '/', [], 8);
    if ($r['code'] === 0) {
        return ['state' => 'down', 'error' => $r['error'], 'ms' => $r['ms']];
    }
    if (strpos($r['body'], 'Load balancer desactive') !== false) {
        return ['state' => 'lb_disabled', 'code' => $r['code'], 'ms' => $r['ms']];
    }
    $j = json_decode($r['body'], true);
    if (is_array($j) && ($j['status'] ?? '') === 'online') {
        $viaLb = false;
        foreach ($r['headers']['set-cookie'] ?? [] as $cookie) {
            if (stripos($cookie, 'lb=') === 0) {
                $viaLb = true;
            }
        }
        return ['state' => 'online', 'via_lb' => $viaLb, 'code' => $r['code'], 'ms' => $r['ms']];
    }
    return ['state' => 'unexpected', 'code' => $r['code'], 'ms' => $r['ms']];
}

/** Cas de test connus (deja valides sur les extracteurs). */
const TEST_CASES = [
    'vidmoly' => ['file' => 'vidmoly.php', 'id' => 'iztl71ko757x'],
    'streamtape' => ['file' => 'streamtape.php', 'id' => 'MkGWp47a8bUma4o'],
    'lulustream' => ['file' => 'lulustream.php', 'id' => 'q7qp31locaqo'],
];

/**
 * Teste un extracteur de bout en bout sur $base (sans proxy=1 : on verifie
 * que l'extraction trouve un flux ; le relais, lui, depend de l'IP).
 * Reussite = reponse JSON sans erreur contenant une URL de flux.
 */
function testExtractor(string $base, string $name, string $apiKey): array
{
    $case = TEST_CASES[$name];
    $url = rtrim($base, '/') . '/' . $case['file'] . '?id=' . $case['id'];
    $headers = $apiKey !== '' ? ['X-API-Key: ' . $apiKey] : [];
    $r = httpGet($url, $headers, 40);
    $out = ['name' => $name, 'code' => $r['code'], 'ms' => $r['ms'], 'ok' => false, 'detail' => ''];

    $j = json_decode($r['body'], true);
    if (!is_array($j)) {
        $out['detail'] = $r['code'] === 0
            ? 'injoignable (' . $r['error'] . ')'
            : 'reponse non JSON, HTTP ' . $r['code'];
        return $out;
    }
    if (isset($j['error'])) {
        $out['detail'] = 'erreur de l\'extracteur : ' . (is_string($j['error']) ? $j['error'] : json_encode($j['error']));
        return $out;
    }
    $stream = null;
    array_walk_recursive($j, function ($v) use (&$stream) {
        if ($stream === null && is_string($v)
            && preg_match('#^https?://#', $v)
            && preg_match('#(m3u8|mp4|get_video|stream=)#', $v)) {
            $stream = $v;
        }
    });
    if ($stream === null) {
        $out['detail'] = 'aucune URL de flux dans la reponse';
        return $out;
    }
    $out['ok'] = true;
    $host = parse_url($stream, PHP_URL_HOST) ?: '';
    $title = isset($j['title']) && is_string($j['title']) ? $j['title'] : '';
    $out['detail'] = 'flux trouve' . ($host !== '' ? ' (' . $host . ')' : '') . ($title !== '' ? ' — ' . $title : '');
    return $out;
}
