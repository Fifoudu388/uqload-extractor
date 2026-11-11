<?php
/**
 * Page d'accueil / healthcheck : GET / -> 200 JSON.
 * L'extracteur lui-meme est sur /uqload.php?id=VIDEO_ID
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo json_encode([
    'status'  => 'online',
    'service' => 'uqload m3u8 extractor',
    'usage'   => '/uqload.php?id=VIDEO_ID ou /uqload.php?url=https://uqload.vc/VIDEO_ID.html',
    'example' => '/uqload.php?id=v80v3zz0vay2',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
