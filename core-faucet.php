<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Free software under the GNU AGPL v3 or later. See LICENSE.
/**
 * Shared faucet engine for Bitcoin Core-compatible coins (Litecoin testnet,
 * Bitcoin testnet, ...). Mirrors xmr-faucet.php but talks to a Bitcoin Core
 * JSON-RPC (getbalance / validateaddress / sendtoaddress / getblockchaininfo).
 *
 * The active coin is chosen by ?coin=, set by .htaccess when rewriting the
 * pretty URLs (/ltc-testnet -> ?coin=ltc, /btc-testnet -> ?coin=btc). This
 * engine serves the faucets.php entries tagged 'engine' => 'core'; adding a coin
 * is one catalog entry plus its RPC creds in config.php, no new code.
 */

// ---- Faucet catalog -----------------------------------------------------
// Every faucet's full definition lives in faucets.php (shared with the Monero
// engine and the homepage). This engine serves only the 'core' entries
// (Bitcoin Core RPC); anything else 404s.
$catalog = require __DIR__ . '/faucets.php';

// Which coin? Set by the .htaccess rewrite; reject anything this engine
// doesn't serve.
$coin = $_GET['coin'] ?? '';
if (!isset($catalog[$coin]) || ($catalog[$coin]['engine'] ?? '') !== 'core') {
    http_response_code(404);
    exit;
}
$c = $catalog[$coin];

// ---- Config -------------------------------------------------------------
$net_label     = $c['net_label'];
$currency      = $c['currency'];
$table         = $c['table'];
$payout_amount = $c['payout_amount'];
$claim_hours   = $c['claim_hours'];
$address_hint  = $c['address_hint'];
$canonical     = $c['href'];
$explorer_tx   = $c['explorer_tx'];
$coin_icon     = $c['icon'];
$rpc_port      = $c['rpc_port'];
$claim_text    = $claim_hours === 1 ? 'once per hour' : "once every {$claim_hours} hours";

// ---- Local config (secrets, not committed) ------------------------------
// Copy config.example.php to config.php and fill in your values. config.php is
// gitignored so secrets never reach the repo.
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];

// Cloudflare Turnstile keys (sitekey is public; secret must stay private).
define('TURNSTILE_SECRET',  $config['turnstile_secret']  ?? '');
define('TURNSTILE_SITEKEY', $config['turnstile_sitekey'] ?? '');

$source_url    = $config['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
$expected_host = $config['expected_host'] ?? null; // optional captcha host pin

// Per-coin "return unused testnet coins" address (donate_tltc / donate_tbtc),
// falling back to the legacy donate_ltc / donate_btc keys if still in config.
$donate_addr = $config['donate_' . strtolower($currency)] ?? $config["donate_{$coin}"] ?? '';
$donate_safe = htmlspecialchars($donate_addr, ENT_QUOTES, 'UTF-8'); // display; keep raw for comparison

// Optional node-status dashboard URL for this coin (status_ltc / status_btc).
// When set, the "Network:" line links to it; blank hides the link.
$status_url = $config["status_{$coin}"] ?? '';

// Optional companion testnet wallet (testnetwallet.net). When set, a "get an
// address" hint shows under the claim input. The wallet supports BTC and LTC.
$wallet_url = $config['wallet_url'] ?? '';

// Optional mainnet "support the faucet" address for this coin (mainnet_ltc /
// mainnet_btc) plus an optional QR, and a shared OpenAlias handle that resolves
// per coin. Empty by default so the public code asks for nothing; set in
// config.php to show a low-key FAQ entry.
$mainnet_addr      = $config["mainnet_{$coin}"] ?? '';
$mainnet_qr        = $config["mainnet_{$coin}_qr"] ?? '';
$mainnet_openalias = $config['mainnet_openalias'] ?? '';

// Bitcoin Core JSON-RPC. Creds from config (e.g. ltc_rpc_user / ltc_rpc_pass);
// bind the daemon to 127.0.0.1. If the daemon has more than one wallet loaded
// (e.g. a mining-pool wallet alongside the faucet), set {$coin}_wallet so calls
// target the right one via the /wallet/<name> endpoint; Core rejects ambiguous
// wallet RPCs otherwise. Blank uses the single loaded wallet. Node-level calls
// (getblockchaininfo) work through the wallet endpoint too.
$wallet  = $config["{$coin}_wallet"] ?? '';
$rpcUrl  = "http://127.0.0.1:{$rpc_port}/" . ($wallet !== '' ? 'wallet/' . rawurlencode($wallet) : '');
$rpcUser = $config["{$coin}_rpc_user"] ?? '';
$rpcPass = $config["{$coin}_rpc_pass"] ?? '';

// Per-coin on/off switch from config. A coin absent from the 'enabled' map
// defaults to on, so existing deployments keep working; set it false in
// config.php to take that faucet offline (its homepage card hides too).
$faucet_enabled = (($config['enabled'][$coin] ?? true) !== false);
if (!$faucet_enabled) {
    // Switched off in config: skip the DB and daemon entirely, serve the styled
    // 503 page so the homepage card and this URL stay in sync.
    $reason = "The {$net_label} faucet is temporarily offline. Please check back soon.";
    require __DIR__ . '/503.php';
    exit;
}

$display_form = "";
$active_err   = "";

// ---- Helpers ------------------------------------------------------------

/**
 * Resolve the client IP for rate limiting: the real visitor IP from
 * CF-Connecting-IP, falling back to REMOTE_ADDR. X-Forwarded-For / Client-IP
 * are never trusted. For CF-Connecting-IP to be unspoofable, the origin must
 * only be reachable through Cloudflare (firewall it to Cloudflare's IP ranges
 * and/or enable Authenticated Origin Pulls).
 */
function resolve_client_ip(): string
{
    return !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
        ? $_SERVER['HTTP_CF_CONNECTING_IP']
        : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Verify a Cloudflare Turnstile token via siteverify. */
function verify_captcha(string $response, string $ip, ?string $expectedHost = null): bool
{
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret'   => TURNSTILE_SECRET,
        'response' => $response,
        'remoteip' => $ip,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $out = curl_exec($ch);
    $failed = curl_errno($ch);
    curl_close($ch);
    if ($failed) {
        return false;
    }
    $data = json_decode($out, true);
    if (empty($data['success'])) {
        return false;
    }
    if ($expectedHost !== null && !empty($data['hostname']) && $data['hostname'] !== $expectedHost) {
        return false;
    }
    return true;
}

/**
 * Call a Bitcoin Core JSON-RPC method. Returns the decoded array (with 'result'
 * and/or 'error'), or null on transport error so the caller can fall back to
 * the generic error message.
 */
function core_rpc(string $url, string $user, string $pass, string $method, array $params = []): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'jsonrpc' => '1.0', 'id' => 'cypherfaucet', 'method' => $method, 'params' => $params,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $out = curl_exec($ch);
    $failed = curl_errno($ch);
    curl_close($ch);
    if ($failed) {
        return null;
    }
    return json_decode($out, true);
}

/**
 * Spendable wallet balance, cached briefly so ordinary page views (and
 * crawlers) don't each hit the daemon. Null if the daemon can't be reached.
 */
function get_core_balance(string $url, string $user, string $pass, string $coin, string $cacheDir, int $ttl = 20): ?float
{
    $cacheFile = rtrim($cacheDir, '/') . "/.balance_cache_{$coin}.json";
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (isset($cached['ts'], $cached['balance']) && (time() - (int) $cached['ts']) < $ttl) {
            return (float) $cached['balance'];
        }
    }
    $r = core_rpc($url, $user, $pass, 'getbalance');
    if ($r === null || !isset($r['result'])) {
        return null;
    }
    $bal = (float) $r['result'];
    @file_put_contents($cacheFile, json_encode(['ts' => time(), 'balance' => $bal]), LOCK_EX);
    return $bal;
}

/** Sync status (height + synced flag) for the liveness line, cached briefly. */
function get_core_status(string $url, string $user, string $pass, string $coin, string $cacheDir, int $ttl = 20): ?array
{
    $cacheFile = rtrim($cacheDir, '/') . "/.height_cache_{$coin}.json";
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (isset($cached['ts']) && (time() - (int) $cached['ts']) < $ttl) {
            return $cached['status'];
        }
    }
    $r = core_rpc($url, $user, $pass, 'getblockchaininfo');
    if ($r === null || !isset($r['result']['blocks'])) {
        return null;
    }
    $res = $r['result'];
    $status = [
        'height' => (int) $res['blocks'],
        'synced' => empty($res['initialblockdownload'])
            && (int) $res['blocks'] >= (int) ($res['headers'] ?? $res['blocks']),
    ];
    @file_put_contents($cacheFile, json_encode(['ts' => time(), 'status' => $status]), LOCK_EX);
    return $status;
}

function card(string $headerClass, string $bodyClass, string $title, string $bodyHtml): string
{
    $hc = $headerClass ? " $headerClass" : "";
    return <<<HTML
            <div class="card{$bodyClass}">
                <div class="card-header{$hc}"><b>{$title}</b></div>
                <div class="card-body">{$bodyHtml}</div>
            </div>
HTML;
}

/** Human "x ago" for a stored UTC timestamp (Y-m-d H:i:s). UTC-parsed. */
function time_ago(string $utcTimestamp): string
{
    try {
        $then = (new DateTime($utcTimestamp, new DateTimeZone('UTC')))->getTimestamp();
    } catch (Exception $e) {
        return '';
    }
    $secs = max(0, time() - $then);
    if ($secs < 60)   { return $secs <= 1 ? 'just now' : "{$secs} seconds ago"; }
    $mins = intdiv($secs, 60);
    if ($mins < 60)   { return $mins === 1 ? '1 minute ago' : "{$mins} minutes ago"; }
    $hours = intdiv($mins, 60);
    if ($hours < 24)  { return $hours === 1 ? '1 hour ago' : "{$hours} hours ago"; }
    $days = intdiv($hours, 24);
    return $days === 1 ? '1 day ago' : "{$days} days ago";
}

// ---- Database -----------------------------------------------------------
$dbFile = getenv('FAUCET_DB') ?: __DIR__ . '/db/faucet.db';
try {
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout = 5000');
} catch (PDOException $e) {
    error_log("[core-faucet] DB connection failed for {$dbFile}: " . $e->getMessage());
    require __DIR__ . '/503.php';
    exit;
}

$error_msg = card('alert', ' alertborder', 'Faucet Error',
    "<p>There seems to be an error with the faucet. Please <a href='/contact'>contact me</a> if this error persists, sorry for the inconvenience.</p>");

$ip = resolve_client_ip();
// A real per-visitor IP only exists when the request came through Cloudflare.
// Tor (and any direct-to-origin) requests arrive from the local daemon with a
// shared address (127.0.0.1), so there's no usable IP to rate-limit on; for
// those, fall back to limiting by payout address alone.
$ip_trusted = !empty($_SERVER['HTTP_CF_CONNECTING_IP']);

// ---- Balance ------------------------------------------------------------
$balance = get_core_balance($rpcUrl, $rpcUser, $rpcPass, $coin, dirname($dbFile));

if ($balance === null) {
    $balance = 0;
    $display_form = "display: none;";
    $active_err   = $error_msg;
} elseif ($balance < $payout_amount * 1.2) {
    // Not enough to comfortably cover a payout plus fee (proportional so it
    // works for both larger LTC and tiny BTC payouts).
    $display_form = "display: none;";
    $out_msg = "The faucet is running low on coins. Please check back later!";
    if ($donate_addr !== '') {
        $out_msg = "The faucet is running low on coins. Please consider returning some {$currency} to <strong>{$donate_safe}</strong> to keep the faucet running!";
    }
    $active_err = card('', '', 'Error - Out of Coins', "<p>{$out_msg}</p>");
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    // ---- Captcha --------------------------------------------------------
    $captcha = $_POST['cf-turnstile-response'] ?? '';
    if (empty(trim($captcha)) || !verify_captcha($captcha, $ip, $ip_trusted ? $expected_host : null)) {
        $active_err = card('alert', ' alertborder', 'Captcha Error',
            "<p>You must complete the captcha, this is so that we can reduce bot and spam attacks on our faucet.</p>");
    } else {
        $address = trim($_POST['address'] ?? '');

        if ($address === '') {
            $active_err = card('', '', 'Error - No Address',
                "<p>Please enter your {$net_label} address and try again.</p>");
        } elseif (strlen($address) > 120) {
            $active_err = card('alert', ' alertborder', 'Error - Invalid Address',
                "<p>Please make sure this is a valid {$net_label} address and that the capitalization and typing are correct, then try again!</p>");
        } elseif ($address === $donate_addr) {
            $active_err = card('alert', ' alertborder', 'Error - Invalid Address',
                "<p>That's the faucet's donation address. Please enter your own {$net_label} address.</p>");
        } else {
            // ---- Validate address (a testnet daemon reports mainnet as invalid) -
            $validation = core_rpc($rpcUrl, $rpcUser, $rpcPass, 'validateaddress', [$address]);

            if ($validation === null) {
                // Daemon unreachable, not the user's fault.
                $active_err = $error_msg;
            } elseif (empty($validation['result']['isvalid'])) {
                $active_err = card('alert', ' alertborder', 'Error - Invalid Address',
                    "<p>Please make sure this is a valid {$net_label} address and that the capitalization and typing are correct, then try again!</p>");
            } else {
                // Rate-limit and store the daemon's canonical address form, so
                // case/format variants of one bech32 address (equivalent, but
                // distinct strings) can't each defeat the per-address limit.
                $address = $validation['result']['address'] ?? $address;

                // ---- Atomically check rate limit + reserve a slot -------
                $window         = "-{$claim_hours} hour";
                $alreadyClaimed = false;
                $lastclaim      = null;
                $reservationId  = null;

                try {
                    $db->exec('BEGIN IMMEDIATE');

                    $ipClause = $ip_trusted ? ' OR ip_address = :ip' : '';
                    $check = $db->prepare(
                        "SELECT timestamp FROM {$table}
                         WHERE (payout_address = :addr{$ipClause})
                           AND timestamp >= DATETIME('now', :window)
                         ORDER BY timestamp DESC LIMIT 1"
                    );
                    $checkParams = [':addr' => $address, ':window' => $window];
                    if ($ip_trusted) {
                        $checkParams[':ip'] = $ip;
                    }
                    $check->execute($checkParams);
                    $recent = $check->fetch(PDO::FETCH_ASSOC);

                    if ($recent) {
                        $alreadyClaimed = true;
                        $lastclaim = $recent['timestamp'];
                    } else {
                        $reserve = $db->prepare(
                            "INSERT INTO {$table} (ip_address, payout_amount, payout_address, transaction_id, timestamp)
                             VALUES (:ip, :amount, :addr, NULL, :ts)"
                        );
                        $reserve->execute([
                            ':ip'     => $ip,
                            ':amount' => $payout_amount,
                            ':addr'   => $address,
                            ':ts'     => gmdate('Y-m-d H:i:s'),
                        ]);
                        $reservationId = (int) $db->lastInsertId();
                    }

                    $db->exec('COMMIT');
                } catch (PDOException $e) {
                    try { $db->exec('ROLLBACK'); } catch (PDOException $ignore) {}
                    error_log("[core-faucet] reservation failed: " . $e->getMessage());
                    $active_err = $error_msg;
                }

                if ($alreadyClaimed) {
                    $display_form = "display: none;";
                    $next = new DateTime($lastclaim, new DateTimeZone('UTC'));
                    $next->modify("+{$claim_hours} hours");
                    $formatted = $next->format('n/j/Y \a\t g:i:s A') . ' UTC';
                    $active_err = card('alert', ' alertborder', 'Error - Already Claimed',
                        "<p>It seems that you have already claimed from the faucet within the last {$claim_hours} hours. Please try again later or <a href=\"/contact\" class=\"site_link\"><b>contact us</b></a> if you think this is an error.<br/><br/>You may claim again on <span class=\"utc_next\">{$formatted}</span></p>");
                } elseif ($reservationId !== null) {
                    // ---- Send -------------------------------------------
                    // Pass the amount as a fixed-decimal string to avoid float /
                    // scientific-notation issues (Bitcoin Core accepts strings).
                    $amountStr = number_format($payout_amount, 8, '.', '');
                    $transfer = core_rpc($rpcUrl, $rpcUser, $rpcPass, 'sendtoaddress', [$address, $amountStr]);

                    if ($transfer !== null && !empty($transfer['result'])) {
                        $txid = $transfer['result'];
                        try {
                            $db->prepare("UPDATE {$table} SET transaction_id = :txid WHERE id = :id")
                               ->execute([':txid' => $txid, ':id' => $reservationId]);
                            $db->prepare(
                                "INSERT INTO faucet_totals (net, total_payouts, total_sent)
                                 VALUES (:net, 1, :amt)
                                 ON CONFLICT(net) DO UPDATE SET
                                     total_payouts = total_payouts + 1,
                                     total_sent    = total_sent + :amt2"
                            )->execute([':net' => $coin, ':amt' => $payout_amount, ':amt2' => $payout_amount]);
                        } catch (PDOException $e) {
                            error_log("[core-faucet] post-send bookkeeping failed for reservation {$reservationId} (tx {$txid}): " . $e->getMessage());
                        }

                        $display_form = "display: none;";
                        $addr_safe = htmlspecialchars($address, ENT_QUOTES, 'UTF-8');
                        $txid_safe = htmlspecialchars($txid, ENT_QUOTES, 'UTF-8');

                        $body = "<span>{$payout_amount} {$currency} has been sent to:</span>"
                              . "<br/><code class=\"mono\">{$addr_safe}</code>"
                              . "<br/><br/><span>Transaction ID</span> <button type=\"button\" class=\"copybtn\" data-copy=\"{$txid_safe}\">Copy</button><br/>";

                        if ($explorer_tx !== '') {
                            $explorer_safe = htmlspecialchars($explorer_tx, ENT_QUOTES, 'UTF-8');
                            $body .= "<a href=\"{$explorer_safe}{$txid_safe}\" target=\"_blank\" rel=\"noopener\" class=\"site_link mono\">{$txid_safe}</a>"
                                   . "<br/><br/><span>It may take a minute for the transaction to show up on the explorer.</span>";
                        } else {
                            $body .= "<code class=\"mono\">{$txid_safe}</code>";
                        }
                        $active_err = card('success', ' successborder', 'Transaction Status', $body);
                    } elseif ($transfer === null) {
                        // AMBIGUOUS: no RPC response. Keep the reservation so a
                        // retry can't double-pay.
                        error_log("[core-faucet] send ambiguous (no RPC response); reservation {$reservationId} kept");
                        $active_err = $error_msg;
                    } else {
                        // DEFINITE failure (e.g. insufficient funds). Release it.
                        try {
                            $db->prepare("DELETE FROM {$table} WHERE id = :id")->execute([':id' => $reservationId]);
                        } catch (PDOException $e) {
                            error_log("[core-faucet] reservation cleanup failed for {$reservationId}: " . $e->getMessage());
                        }
                        error_log("[core-faucet] send rejected: " . json_encode($transfer));
                        $active_err = $error_msg;
                    }
                }
            }
        }
    }
}

// ---- Stats (completed payouts only) -------------------------------------
// Lifetime totals come from faucet_totals, which the retention sweep never
// prunes. If the counter row isn't there yet (fresh install, no payouts), fall
// back to aggregating the live payout table.
$total_sent    = 0;
$total_payouts = 0;

$aggregate_live = function () use ($db, $table) {
    return $db->query("SELECT COUNT(id) AS total_payouts, SUM(payout_amount) AS total_sent
                       FROM {$table} WHERE transaction_id IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
};

try {
    $stmt = $db->prepare("SELECT total_payouts, total_sent FROM faucet_totals WHERE net = :net");
    $stmt->execute([':net' => $coin]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: $aggregate_live();
    $total_payouts = (int) ($row['total_payouts'] ?? 0);
    $total_sent    = (float) ($row['total_sent'] ?? 0);
} catch (PDOException $e) {
    try {
        $row = $aggregate_live();
        $total_payouts = (int) ($row['total_payouts'] ?? 0);
        $total_sent    = (float) ($row['total_sent'] ?? 0);
    } catch (PDOException $e2) {
        error_log("[core-faucet] stats query failed: " . $e2->getMessage());
    }
}

// Trim trailing zeros for display (e.g. 12.50000000 -> 12.5, 12 -> 12).
$trim = fn($v) => rtrim(rtrim(number_format((float) $v, 8, '.', ''), '0'), '.') ?: '0';
$balance_display = $trim($balance);
$total_sent      = $trim($total_sent);

// Most recent confirmed payout, shown as "x ago".
$last_payout = '';
try {
    $r = $db->query("SELECT MAX(timestamp) AS last FROM {$table} WHERE transaction_id IS NOT NULL")
            ->fetch(PDO::FETCH_ASSOC);
    if (!empty($r['last'])) {
        $last_payout = time_ago($r['last']);
    }
} catch (PDOException $e) {
    error_log("[core-faucet] last-payout query failed: " . $e->getMessage());
}

// Liveness / sync line, with a status dot.
$node = get_core_status($rpcUrl, $rpcUser, $rpcPass, $coin, dirname($dbFile));
if ($node === null) {
    $dot = 'dot-off';         // gray: daemon unreachable
    $height_display = 'Unavailable';
} elseif ($node['synced']) {
    $dot = 'dot-ok';          // green: synced
    $height_display = 'Synced, block ' . number_format($node['height']);
} else {
    $dot = 'dot-warn';        // amber: catching up
    $height_display = 'Syncing, block ' . number_format($node['height']);
}
$height_display = "<span class=\"dot {$dot}\">&#9679;</span> " . $height_display;
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | <?php echo $net_label; ?> Faucet (<?php echo $currency; ?>)</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="<?php echo $coin_icon; ?>" type="image/png" />
        <link rel="shortcut icon" href="<?php echo $coin_icon; ?>" type="image/png" />
        <meta name="description" content="Developer friendly <?php echo $net_label; ?> faucet. Free <?php echo strtolower($net_label); ?> coins for testing applications.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#14161b" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#f7f7f7" media="(prefers-color-scheme: light)">
        <link rel="canonical" href="https://cypherfaucet.com<?php echo $canonical; ?>" />
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="CypherFaucet">
        <meta property="og:title" content="CypherFaucet | <?php echo $net_label; ?> Faucet (<?php echo $currency; ?>)">
        <meta property="og:description" content="Free <?php echo strtolower($net_label); ?> coins for developers, <?php echo $claim_text; ?>.">
        <meta property="og:url" content="https://cypherfaucet.com<?php echo $canonical; ?>">
        <meta property="og:image" content="https://cypherfaucet.com/assets/images/og-banner.png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="https://cypherfaucet.com/assets/images/og-banner.png">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=20">
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    </head>
    <body>
<?php include __DIR__ . '/nav.php'; ?>
        <br/>
        <div id="main">
            <span class="title is-size-3 has-icon" style="margin-bottom: 0em !important;">
                <img class="lozad" src="<?php echo $coin_icon; ?>" alt="<?php echo $net_label; ?>" data-loaded="true" style="height: 28px; display: inline-block; padding-right: 5px;">
                <span style="font-size: 28px; color: var(--text);"><b><?php echo $net_label; ?> Faucet</b></span>
            </span>
            <br/>
            <span style="font-size: 18px;"><b>Receive <?php echo $payout_amount; ?> <?php echo $currency; ?> <?php echo $claim_text; ?>.</b></span>
            <br/>
            <span>&#9888; The <?php echo $net_label; ?> network is used for testing. Testnet funds have no value!</span>

            <div class="card">
                <div class="card-header"><b>Faucet Statistics</b></div>
                <div class="card-body">
                    <span>Balance: <strong><?php echo $balance_display; ?></strong> <?php echo $currency; ?></span><br/>
                    <span>Total Sent: <strong><?php echo $total_sent; ?></strong> <?php echo $currency; ?></span><br/>
                    <span>Total Payouts: <strong><?php echo $total_payouts; ?></strong></span><br/>
<?php if ($last_payout !== '') { ?>                    <span>Last payout: <strong><?php echo $last_payout; ?></strong></span><br/>
<?php } ?>                    <span>Network: <span class="badge"><?php echo $height_display; ?></span><?php if ($status_url !== '') { ?> &middot; <a href="<?php echo htmlspecialchars($status_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="site_link">node dashboard</a><?php } ?></span>
                </div>
            </div>

            <?php echo $active_err; ?>

            <div style="<?php echo $display_form; ?>">
                <div class="card">
                    <div class="card-header"><b>Claim <?php echo $currency; ?></b></div>
                    <div class="card-body">
                        <form method="post" action="">
                            <div class="forminput-wrapper">
                                <input class="forminput" id="address" name="address" aria-label="<?php echo $net_label; ?> address" type="text" value="" placeholder="Your <?php echo $net_label; ?> address (<?php echo $address_hint; ?>)" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false" required />
                                <span class="icon-left">
                                    <img src="<?php echo $coin_icon; ?>" alt="<?php echo $net_label; ?>" />
                                </span>
                            </div>
<?php if ($wallet_url !== '') { ?>
                            <span style="display: block; margin-top: 8px; font-size: 0.9em;">No <?php echo $net_label; ?> address yet? Generate one at <a href="<?php echo htmlspecialchars($wallet_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="site_link">testnetwallet.net</a>.</span>
<?php } ?>
                            <span style="display: inline-block; margin-top: 8px; font-size: 0.9em;">IP addresses are logged only to prevent faucet abuse.</span>
                            <div class="cf-turnstile" style="margin-top: 12px; margin-bottom: 12px;" data-sitekey="<?php echo TURNSTILE_SITEKEY; ?>" data-theme="auto"></div>
                            <input type="submit" id="send" name="claim" class="formbtn" value="Send <?php echo $currency; ?>" />
                        </form>
                    </div>
                </div>
            </div>

<?php if ($donate_addr !== '') { ?>
            <div class="card">
                <div class="card-header"><b>Please return your unused <?php echo $currency; ?> so others can use them!</b></div>
                <div class="card-body">
                    <p><code class="mono"><?php echo $donate_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $donate_safe; ?>">Copy</button></p>
                    <span><?php echo $currency; ?> sent to this address are forwarded to the faucet periodically.</span>
                </div>
            </div>
<?php } ?>

            <div class="card">
                <div class="card-header">FAQs</div>
                <div class="card-body">
                    <h3>How do I use the faucet?</h3>
                    <p>To use the faucet, enter your <?php echo $net_label; ?> wallet address, complete the captcha, and click "Send <?php echo $currency; ?>". By using the faucet you agree to our <a href="/legal#terms" class="site_link"><b>Terms and Conditions</b></a>.</p>
                    <h3>What is the <?php echo $net_label; ?> network?</h3>
                    <p>The <?php echo $net_label; ?> network lets developers test in place of the mainnet counterpart without using coins that have value.</p>
                    <h3>Why don't I see the coins in my wallet or on the explorer?</h3>
                    <p>Sometimes the testnet is slow with propagating transactions and the explorers could be running a bit behind. Give it a few minutes and you should be able to see the coins in your wallet (assuming you entered the correct wallet address) and on the explorer via the txid.</p>
                    <h3>The captcha isn't working. What should I do?</h3>
                    <p>This can happen for many reasons. Usually, the captcha could fail if you're using a VPN or ad blocker, unsupported or out-of-date browser.</p>
                    <h3>What's the catch?</h3>
                    <p>There is no catch! I created this and my other faucets to give back to the community.</p>
<?php if ($mainnet_addr !== '') {
    $mainnet_safe    = htmlspecialchars($mainnet_addr, ENT_QUOTES, 'UTF-8');
    $mainnet_qr_safe = htmlspecialchars($mainnet_qr, ENT_QUOTES, 'UTF-8');
    $openalias_safe  = htmlspecialchars($mainnet_openalias, ENT_QUOTES, 'UTF-8');
    $mainnet_ticker  = substr($currency, 1); // tLTC -> LTC, tBTC -> BTC
?>
                    <h3>Can I support the faucet?</h3>
                    <p>This faucet is free and I run it to give back. If it has saved you time and you would like to help with server costs, <b>mainnet</b> <?php echo $mainnet_ticker; ?> donations are welcome and entirely optional:</p>
<?php if ($mainnet_openalias !== '') { ?>
                    <p>OpenAlias: <code class="mono"><?php echo $openalias_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $openalias_safe; ?>">Copy</button></p>
<?php } ?>
                    <p><code class="mono"><?php echo $mainnet_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $mainnet_safe; ?>">Copy</button></p>
<?php if ($mainnet_qr !== '') { ?>
                    <p><span class="qr"><img src="<?php echo $mainnet_qr_safe; ?>" alt="<?php echo $mainnet_ticker; ?> donation QR code"></span></p>
<?php } ?>
                    <p style="margin-top: 8px;">See every way to support the faucet on the <a href="/donate" class="site_link">donations page</a>.</p>
<?php } ?>
                </div>
            </div>
            <br/>
<?php include __DIR__ . '/footer.php'; ?>
        </div>
        <script>
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-copy]');
                if (!btn) return;
                var text = btn.getAttribute('data-copy');
                var done = function () {
                    var label = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = label; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () {});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (err) {}
                    document.body.removeChild(ta);
                }
            });
        </script>
    </body>
</html>
