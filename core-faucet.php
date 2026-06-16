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
 * pretty URLs (/ltc-testnet -> ?coin=ltc, /btc-testnet -> ?coin=btc). Adding a
 * coin is one entry below plus its RPC creds in config.php; no new code.
 */

// ---- Coin definitions (one engine, many coins) --------------------------
$COINS = [
    'ltc' => [
        'net_label'     => 'Litecoin Testnet',
        'currency'      => 'tLTC',
        'table'         => 'tltc_payouts',
        'rpc_port'      => 19332,
        'payout_amount' => 0.1,
        'claim_hours'   => 12,
        'address_hint'  => 'starts with m, n, Q, tltc1, or tmweb1',
        'canonical'     => '/ltc-testnet',
        'explorer_tx'   => 'https://litecoinspace.org/testnet/tx/', // '' shows a bare txid
        'icon'          => '/assets/images/litecoin.png',
    ],
    'btc' => [
        'net_label'     => 'Bitcoin Testnet',
        'currency'      => 'tBTC',
        'table'         => 'tbtc_payouts',
        'rpc_port'      => 18332,
        'payout_amount' => 0.0001,
        'claim_hours'   => 12,
        'address_hint'  => 'starts with m, n, 2, or tb1',
        'canonical'     => '/btc-testnet',
        'explorer_tx'   => 'https://mempool.space/testnet/tx/', // '' shows a bare txid
        'icon'          => '/assets/images/bitcoin.png',
    ],
];

// Which coin? Set by the .htaccess rewrite; reject anything not in the map.
$coin = $_GET['coin'] ?? '';
if (!isset($COINS[$coin])) {
    http_response_code(404);
    exit;
}
$c = $COINS[$coin];

// ---- Config -------------------------------------------------------------
$net_label     = $c['net_label'];
$currency      = $c['currency'];
$table         = $c['table'];
$payout_amount = $c['payout_amount'];
$claim_hours   = $c['claim_hours'];
$address_hint  = $c['address_hint'];
$canonical     = $c['canonical'];
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

// Per-coin "return unused coins" address, from config (donate_ltc / donate_btc).
$donate_addr = $config["donate_{$coin}"] ?? '';
$donate_safe = htmlspecialchars($donate_addr, ENT_QUOTES, 'UTF-8'); // display; keep raw for comparison

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
    if (empty(trim($captcha)) || !verify_captcha($captcha, $ip, $expected_host)) {
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
                // ---- Atomically check rate limit + reserve a slot -------
                $window         = "-{$claim_hours} hour";
                $alreadyClaimed = false;
                $lastclaim      = null;
                $reservationId  = null;

                try {
                    $db->exec('BEGIN IMMEDIATE');

                    $check = $db->prepare(
                        "SELECT timestamp FROM {$table}
                         WHERE (payout_address = :addr OR ip_address = :ip)
                           AND timestamp >= DATETIME('now', :window)
                         ORDER BY timestamp DESC LIMIT 1"
                    );
                    $check->execute([':addr' => $address, ':ip' => $ip, ':window' => $window]);
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
                        "<p>It seems that you have already claimed from the faucet within the last {$claim_hours} hours. Please try again later or <a href=\"/contact\" class=\"site_link\"><b>contact us</b></a> if you think this is an error.<br/><br/>You may claim again on <label class=\"utc_next\">{$formatted}</label></p>");
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
    $dot = '#888';            // gray: daemon unreachable
    $height_display = 'Unavailable';
} elseif ($node['synced']) {
    $dot = '#3fb950';         // green: synced
    $height_display = 'Synced, block ' . number_format($node['height']);
} else {
    $dot = '#d29922';         // amber: catching up
    $height_display = 'Syncing, block ' . number_format($node['height']);
}
$height_display = "<span style=\"color: {$dot};\">&#9679;</span> " . $height_display;
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
        <meta name="theme-color" content="#c5c5c5">
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
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=9">
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

        <style>
            strong {
                color: #d4d4d4;
            }
            .mono {
                font-family: monospace;
                word-break: break-word;
            }
            .copybtn {
                font-size: 12px;
                padding: 3px 10px;
                margin-left: 6px;
                cursor: pointer;
                vertical-align: middle;
                color: #d4d4d4;
                background-color: transparent;
                border: 1px solid #5b6168;
                border-radius: 4px;
                transition: background-color 0.15s ease, border-color 0.15s ease;
            }
            .copybtn:hover {
                background-color: #3a3f44;
                border-color: #7a8189;
            }
        </style>
    </head>
    <body>
        <nav class="navbar">
            <a href="/">
                <img src="/assets/images/cypherfaucet-banner.png" alt="Logo">
            </a>

            <input type="checkbox" class="menu-toggle" id="menu-toggle" />

            <label for="menu-toggle" class="hamburger">
                <div></div>
                <div></div>
                <div></div>
            </label>

            <div class="nav-links">
                <a href="/">Home</a>
                <a href="/contact">Contact</a>
                <a href="/legal">Legal</a>
            </div>
        </nav>
        <br/>
        <div id="main">
            <span class="title is-size-3 has-icon" style="margin-bottom: 0em !important;">
                <img class="lozad" src="<?php echo $coin_icon; ?>" alt="<?php echo $net_label; ?>" data-loaded="true" style="height: 28px; display: inline-block; padding-right: 5px;">
                <span style="font-size: 28px; color: #e1e1e1;"><b><?php echo $net_label; ?> Faucet</b></span>
            </span>
            <br/>
            <span style="font-size: 18px;"><b>Receive <?php echo $payout_amount; ?> <?php echo $currency; ?> <?php echo $claim_text; ?>.</b></span>
            <br/>
            <span>&#9888; The <?php echo $net_label; ?> is used for testing. Testnet funds have no value!</span>

            <div class="card">
                <div class="card-header"><b>Faucet Statistics</b></div>
                <div class="card-body">
                    <span>Balance: <strong><?php echo $balance_display; ?></strong> <?php echo $currency; ?></span><br/>
                    <span>Total Sent: <strong><?php echo $total_sent; ?></strong> <?php echo $currency; ?></span><br/>
                    <span>Total Payouts: <strong><?php echo $total_payouts; ?></strong></span><br/>
<?php if ($last_payout !== '') { ?>                    <span>Last payout: <strong><?php echo $last_payout; ?></strong></span><br/>
<?php } ?>                    <span>Network: <strong><?php echo $height_display; ?></strong></span>
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
                            <span style="display: inline-block; margin-top: 8px; font-size: 0.9em;">IP addresses are logged only to prevent faucet abuse.</span>
                            <div class="cf-turnstile" style="margin-top: 12px; margin-bottom: 12px;" data-sitekey="<?php echo TURNSTILE_SITEKEY; ?>" data-theme="dark"></div>
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
                    <h3>What is the <?php echo $net_label; ?>?</h3>
                    <p>The <?php echo $net_label; ?> is a separate network that lets developers test in place of the mainnet counterpart without using coins that have value.</p>
                    <h3>Why don't I see the coins in my wallet or on the explorer?</h3>
                    <p>Sometimes the testnet is slow with propagating transactions and the explorers could be running a bit behind. Give it a few minutes and you should be able to see the coins in your wallet (assuming you entered the correct wallet address) and on the explorer via the txid.</p>
                    <h3>The captcha isn't working. What should I do?</h3>
                    <p>This can happen for many reasons. Usually, the captcha could fail if you're using a VPN or ad blocker, unsupported or out-of-date browser.</p>
                    <h3>What's the catch?</h3>
                    <p>There is no catch! I created this and my other faucets to give back to the community.</p>
                </div>
            </div>
            <br/>
            <footer>
                <hr style="width: 17.5%;"/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with ♥️ and ☕ by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
            </footer>
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
