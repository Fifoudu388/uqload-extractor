<?php
/**
 * Page d'accueil / healthcheck : GET / -> 200 JSON.
 * Les extracteurs sont sur /uqload.php?id=VIDEO_ID, /vidmoly.php?id=VIDEO_ID,
 * /streamtape.php?id=VIDEO_ID et /lulustream.php?id=VIDEO_ID
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo json_encode([
    'status'  => 'online',
    'service' => 'uqload + vidmoly + streamtape + lulustream extractors',
    'usage'   => '/uqload.php?id=VIDEO_ID (&proxy=1 pour passer le flux par le proxy), /vidmoly.php?id=VIDEO_ID (ou ?url=...), /streamtape.php?id=VIDEO_ID (ou ?url=...) ou /lulustream.php?id=VIDEO_ID (ou ?url=...)',
    'example' => '/vidmoly.php?id=iztl71ko757x',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
