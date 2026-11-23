<?php
/**
 * signing.php — Signature HMAC des URLs du relais de flux (?stream=).
 * =================================================================
 * Module partage par les quatre extracteurs (uqload, vidmoly, streamtape,
 * lulustream). Meme principe que le proxy signe de Movix (proxiesembed) :
 *
 * Sans signature, le relais ?stream=<url> est un « open bar » : quiconque
 * connait une URL de flux d'un hebergeur autorise peut la faire relayer par
 * ce serveur (sa bande passante, son IP), pour une duree illimitee.
 *
 * Avec signature, les URLs de flux emises par une extraction authentifiee
 * (cle API) portent deux parametres supplementaires :
 *   exp : date d'expiration (timestamp Unix)
 *   sig : HMAC-SHA256 calcule avec un secret que seuls les extracteurs
 *         connaissent, sur le texte :  route \n cible \n extra \n exp
 *         - route : le nom de l'extracteur (uqload, vidmoly, ...) — une URL
 *           signee pour un extracteur ne peut pas etre rejouee sur un autre ;
 *         - cible : l'URL du flux relayee — la modifier invalide la signature ;
 *         - extra : parametre lie (l'origine &o= de streamtape/lulustream),
 *           vide pour uqload/vidmoly ;
 *         - exp   : prolonger la duree de vie invalide aussi la signature.
 * Le lecteur (navigateur) n'a donc plus besoin de la cle API pour lire un
 * flux : la signature vaut autorisation, pour cette URL exacte et jusqu'a
 * expiration. La cle API ne sert plus qu'a demander des extractions.
 *
 * Variables d'environnement :
 *   STREAM_SIGNING_SECRET : le secret partage. Sans lui, la signature est
 *                           desactivee et le relais garde l'ancien
 *                           comportement (cle API exigee si configuree).
 *                           Generer : openssl rand -hex 32
 *   STREAM_SIGNATURE_TTL  : duree de vie d'une URL signee, en secondes
 *                           (defaut 43200 = 12 h, comme Movix).
 *
 * PHP 8.0+ requis.
 */
declare(strict_types=1);

/** Secret de signature (variable STREAM_SIGNING_SECRET), '' si absent. */
function streamSigningSecret(): string
{
    $secret = getenv('STREAM_SIGNING_SECRET');
    return is_string($secret) ? trim($secret) : '';
}

/** La signature est-elle active ? (secret configure) */
function streamSigningEnabled(): bool
{
    return streamSigningSecret() !== '';
}

/** Duree de vie d'une URL signee, en secondes (defaut 12 h). */
function streamSignatureTtl(): int
{
    $raw = getenv('STREAM_SIGNATURE_TTL');
    $ttl = ($raw === false || $raw === '') ? 43200 : (int) $raw;
    return $ttl > 0 ? $ttl : 43200;
}

/** Signature HMAC-SHA256 (base64url, sans '=') du triplet route/cible/extra + expiration. */
function streamSignature(string $route, string $target, string $extra, int $exp): string
{
    $payload = $route . "\n" . $target . "\n" . $extra . "\n" . $exp;
    $raw = hash_hmac('sha256', $payload, streamSigningSecret(), true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Fragment a ajouter a une URL de relais : '&exp=...&sig=...'.
 * Retourne '' si la signature est desactivee (secret absent).
 */
function streamSignatureQuery(string $route, string $target, string $extra = ''): string
{
    if (!streamSigningEnabled()) {
        return '';
    }
    $exp = time() + streamSignatureTtl();
    return '&exp=' . $exp . '&sig=' . rawurlencode(streamSignature($route, $target, $extra, $exp));
}

/**
 * Verifie une signature recue pour ?stream= : secret present, expiration
 * numerique non depassee, et HMAC identique (comparaison a temps constant).
 * $expRaw / $sigRaw viennent de $_GET : types non garantis.
 */
function verifyStreamSignature(string $route, string $target, string $extra, mixed $expRaw, mixed $sigRaw): bool
{
    if (!streamSigningEnabled()) {
        return false;
    }
    if (!is_string($expRaw) || !ctype_digit($expRaw) || !is_string($sigRaw) || $sigRaw === '') {
        return false;
    }
    $exp = (int) $expRaw;
    if ($exp < time()) {
        return false;
    }
    return hash_equals(streamSignature($route, $target, $extra, $exp), $sigRaw);
}
