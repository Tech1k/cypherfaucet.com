<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Free software under the GNU AGPL v3 or later. See LICENSE.
//
// Developer JSON API. Routed by .htaccess:
//   GET  /api/v1/          -> index (endpoint list)
//   GET  /api/v1/info      -> per-faucet payout / window / balance  [?network=<slug>]
//   POST /api/v1/claim     -> { "network": "<slug>", "address": "<addr>" }
//
// <slug> is the faucet's URL slug: xmr-stagenet, xmr-testnet, ltc-testnet,
// btc-testnet (the catalog 'href' without the leading slash).
//
// Keyless by design: the per-address/IP claim window and a faucet-wide daily cap
// are SHARED with the web faucets (same SQLite tables + reservation pattern), so
// the API can't be used to bypass limits or double-pay. Put Cloudflare WAF
// rate-limiting in front of /api/ as the outer layer. Off unless api_enabled.

$catalog = require __DIR__ . '/faucets.php';
$config  = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
require __DIR__ . '/faucet_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Emit a JSON response and stop. */
function api_out(int $code, array $payload): void
{
    global $config;
    http_response_code($code);
    // AGPL section 13: API callers never see the website footer that offers the
    // source to browser users, so every response carries the source link.
    if (!isset($payload['source'])) {
        $payload['source'] = $config['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

// Opt-in per deployment: when the API is off, serve the normal styled 404 page
// (not a JSON error) so a fork that hasn't enabled it doesn't advertise the
// endpoint at all. (api.php already sent a JSON content-type; override it.)
if (($config['api_enabled'] ?? false) !== true) {
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/404.php';
    exit;
}

// Map URL slug -> [catalog key, entry]. The slug is the public identifier. Only
// engines this library knows how to serve are exposed; an unrecognised engine is
// skipped rather than mishandled as Core.
$bySlug = [];
foreach ($catalog as $key => $entry) {
    $engine = $entry['engine'] ?? '';
    if ($engine !== 'core' && $engine !== 'xmr') {
        continue;
    }
    $slug = ltrim($entry['href'] ?? '', '/');
    if ($slug !== '') {
        $bySlug[$slug] = [$key, $entry];
    }
}

$action = $_GET['_api'] ?? '';
$dbFile = getenv('FAUCET_DB') ?: __DIR__ . '/db/faucet.db';

// ---- GET /api/v1/ : index -----------------------------------------------
if ($action === 'index') {
    api_out(200, [
        'ok'        => true,
        'name'      => 'CypherFaucet API',
        'version'   => 'v1',
        'endpoints' => [
            'GET /api/v1/info'  => 'Faucet list with payout amount, claim window, and balance.',
            'POST /api/v1/claim' => 'Body: {"network":"<slug>","address":"<addr>"}. Sends one payout.',
        ],
        'networks'  => array_keys($bySlug),
        'docs'      => (($config['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet') . '#developer-api'),
    ]);
}

// ---- GET /api/v1/info ----------------------------------------------------
if ($action === 'info') {
    $only = trim($_GET['network'] ?? '');
    $cacheDir = dirname($dbFile);
    $out = [];
    foreach ($bySlug as $slug => [$key, $entry]) {
        if ($only !== '' && $slug !== $only) {
            continue;
        }
        $out[] = faucet_info_one($key, $entry, $config, $cacheDir);
    }
    if ($only !== '' && !$out) {
        api_out(404, ['ok' => false, 'error' => 'unknown_network', 'message' => 'Unknown network slug.']);
    }
    api_out(200, ['ok' => true, 'faucets' => $out]);
}

// ---- POST /api/v1/claim --------------------------------------------------
if ($action === 'claim') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        api_out(405, ['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use POST.']);
    }

    // Accept a JSON body (preferred) or form-encoded params.
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    // Coerce to string: a JSON body with a non-string field (e.g. {"network":[]})
    // would otherwise throw a TypeError on trim() and 500 instead of a clean 400.
    $nf = $body['network'] ?? $body['coin'] ?? '';
    $af = $body['address'] ?? '';
    $slug    = is_string($nf) ? trim($nf) : '';
    $address = is_string($af) ? trim($af) : '';

    if ($slug === '' || $address === '') {
        api_out(400, ['ok' => false, 'error' => 'invalid_request', 'message' => '"network" and "address" are required.']);
    }
    if (!isset($bySlug[$slug])) {
        api_out(400, ['ok' => false, 'error' => 'unknown_network', 'message' => 'Unknown network slug.']);
    }
    [$key, $entry] = $bySlug[$slug];

    if (($config['enabled'][$key] ?? true) === false) {
        api_out(503, ['ok' => false, 'error' => 'faucet_offline', 'message' => 'This faucet is offline.']);
    }

    try {
        $db = new PDO("sqlite:$dbFile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000');
    } catch (PDOException $e) {
        error_log("[faucet-api] DB connection failed for {$dbFile}: " . $e->getMessage());
        api_out(503, ['ok' => false, 'error' => 'unavailable', 'message' => 'Faucet temporarily unavailable.']);
    }

    $ip        = faucet_client_ip();
    $ipTrusted = !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
    $cap       = (int) ($config['api_daily_cap'] ?? 0);
    $ipCap     = (int) ($config['api_ip_daily_cap'] ?? 0);

    $r = faucet_claim($db, $key, $entry, $config, $address, $ip, $ipTrusted, $cap, $ipCap);

    switch ($r['status']) {
        case 'ok':
            $resp = [
                'ok'       => true,
                'network'  => $slug,
                'currency' => $r['currency'],
                'amount'   => number_format((float) $r['amount'], 8, '.', ''),
                'txid'     => $r['txid'],
            ];
            if (!empty($r['tx_key'])) {
                $resp['tx_key'] = $r['tx_key'];
            }
            api_out(200, $resp);

        case 'invalid_address':
            api_out(400, ['ok' => false, 'error' => 'invalid_address', 'message' => 'Not a valid address for this network.']);

        case 'rate_limited':
            if (!empty($r['retry_after'])) {
                header('Retry-After: ' . (int) $r['retry_after']);
            }
            api_out(429, [
                'ok'          => false,
                'error'       => 'rate_limited',
                'message'     => 'You have already claimed within the current window.',
                'retry_after' => $r['retry_after'] ?? null,
                'next_claim'  => $r['next_claim'] ?? null,
            ]);

        case 'ip_rate_limited':
            api_out(429, ['ok' => false, 'error' => 'ip_rate_limited', 'message' => 'This IP has reached its daily claim budget. Try again later or from another host.']);

        case 'daily_cap':
            api_out(429, ['ok' => false, 'error' => 'daily_cap', 'message' => 'The faucet has reached its daily limit. Try again later.']);

        case 'faucet_empty':
            api_out(409, ['ok' => false, 'error' => 'faucet_empty', 'message' => 'The faucet is out of spendable coins right now.']);

        case 'node_busy':
            header('Retry-After: 30');
            api_out(503, ['ok' => false, 'error' => 'node_busy', 'message' => 'The node is busy; no coins were sent. Try again shortly.']);

        case 'send_failed':
            header('Retry-After: 30');
            api_out(503, ['ok' => false, 'error' => 'send_failed', 'message' => 'Could not complete the send. Try again shortly.']);

        case 'faucet_unavailable':
            api_out(503, ['ok' => false, 'error' => 'unavailable', 'message' => 'Faucet temporarily unavailable.']);

        default:
            api_out(500, ['ok' => false, 'error' => 'internal_error', 'message' => 'Something went wrong. If it persists, contact me.']);
    }
}

// Unknown action / bare api.php hit.
api_out(404, ['ok' => false, 'error' => 'not_found']);
