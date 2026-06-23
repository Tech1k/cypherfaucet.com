<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Free software under the GNU AGPL v3 or later. See LICENSE.
//
// Shared faucet claim library: the canonical validate -> rate-limit -> send
// money path, returning a STRUCTURED result (no HTML/JSON) so any front end can
// render it. The JSON API (api.php) calls this. Because it writes to the SAME
// per-net rate-limit tables with the SAME BEGIN IMMEDIATE reservation pattern
// as the web engines, web and API claims see each other's limits and there is
// no double-pay path between them.
//
// NOTE: today only api.php uses this library. core-faucet.php and xmr-faucet.php
// still carry their own inline copies of this logic. Migrating those engines
// onto this library (to delete that duplication) is the planned next step;
// until then, keep the reservation / window / validation logic here in
// lock-step with the engines.

/** Real client IP for rate limiting (Cloudflare's connecting IP, else REMOTE_ADDR). */
function faucet_client_ip(): string
{
    return !empty($_SERVER['HTTP_CF_CONNECTING_IP'])
        ? $_SERVER['HTTP_CF_CONNECTING_IP']
        : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Bitcoin Core JSON-RPC call. Returns the decoded array, or null on a transport
 * error. $httpCode receives the HTTP status so a caller can tell a work-queue
 * 503 (rejected before the node ran it -> safe to treat as not-sent) from an
 * ambiguous timeout. Mirror of core-faucet.php's core_rpc().
 */
function faucet_core_rpc(string $url, string $user, string $pass, string $method, array $params = [], int $timeout = 30, ?int &$httpCode = null): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'jsonrpc' => '1.0', 'id' => 'cypherfaucet-api', 'method' => $method, 'params' => $params,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $out = curl_exec($ch);
    $failed = curl_errno($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $failed ? null : json_decode($out, true);
}

/** Idempotent Core RPC with quick retries, to ride out a transient work-queue 503. */
function faucet_core_rpc_read(string $url, string $user, string $pass, string $method, array $params = [], int $timeout = 10, int $tries = 3): ?array
{
    for ($i = 0; ; $i++) {
        $r = faucet_core_rpc($url, $user, $pass, $method, $params, $timeout);
        if ($r !== null || $i >= $tries - 1) {
            return $r;
        }
        usleep(250000 * ($i + 1)); // 0.25s, then 0.5s
    }
}

/** monero-wallet-rpc call. Returns the decoded array, or null on transport error. */
function faucet_xmr_rpc(string $url, string $method, array $params, string $user, string $pass, int $timeout = 30): ?array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'jsonrpc' => '2.0', 'id' => '0', 'method' => $method, 'params' => $params,
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $out = curl_exec($ch);
    $failed = curl_errno($ch);
    curl_close($ch);
    return $failed ? null : json_decode($out, true);
}

/** RPC endpoint + creds for a catalog entry. Returns [url, user, pass]. */
function faucet_rpc_target(string $key, array $entry, array $config): array
{
    if (($entry['engine'] ?? '') === 'xmr') {
        return ["http://127.0.0.1:{$entry['rpc_port']}/json_rpc", $config['rpc_user'] ?? '', $config['rpc_pass'] ?? ''];
    }
    // Bitcoin Core: target /wallet/<name> when a wallet name is configured (a
    // node with more than one wallet loaded rejects ambiguous wallet RPCs).
    $wallet = $config["{$key}_wallet"] ?? '';
    $url = "http://127.0.0.1:{$entry['rpc_port']}/" . ($wallet !== '' ? 'wallet/' . rawurlencode($wallet) : '');
    return [$url, $config["{$key}_rpc_user"] ?? '', $config["{$key}_rpc_pass"] ?? ''];
}

/** Spendable balance threshold for one payout (Core keeps headroom; XMR adds a fee). */
function faucet_min_needed(array $entry): float
{
    $a = (float) $entry['payout_amount'];
    return (($entry['engine'] ?? '') === 'xmr') ? ($a + 0.01) : ($a * 1.2);
}

/** Live spendable balance (Core: getbalance; XMR: unlocked_balance). Null if unreachable. */
function faucet_spendable(string $key, array $entry, array $config): ?float
{
    [$url, $user, $pass] = faucet_rpc_target($key, $entry, $config);
    if (($entry['engine'] ?? '') === 'xmr') {
        $r = faucet_xmr_rpc($url, 'get_balance', [], $user, $pass, 6);
        return ($r !== null && isset($r['result'])) ? (float) (($r['result']['unlocked_balance'] ?? 0) / 1e12) : null;
    }
    $r = faucet_core_rpc_read($url, $user, $pass, 'getbalance', [], 6);
    return ($r !== null && isset($r['result'])) ? (float) $r['result'] : null;
}

/**
 * Cached balance read for the info endpoint: reads the cache file the web
 * engines already maintain and makes NO RPC call, so a hammered /info endpoint
 * never adds node load. Null if there's no cached value yet.
 */
function faucet_cached_balance(string $key, array $entry, string $cacheDir): ?float
{
    $engine = $entry['engine'] ?? '';
    $id   = ($engine === 'xmr') ? $entry['nettype'] : $key;
    $file = rtrim($cacheDir, '/') . "/.balance_cache_{$id}.json";
    if (!is_readable($file)) {
        return null;
    }
    $c = json_decode((string) @file_get_contents($file), true);
    if ($engine === 'xmr') {
        return isset($c['unlocked']) ? (float) $c['unlocked'] : null;
    }
    return isset($c['balance']) ? (float) $c['balance'] : null;
}

/** Most recent claim for this address (or trusted IP) within the window; null if none. */
function faucet_recent_claim(PDO $db, string $table, string $address, string $ip, bool $ipTrusted, string $window): ?string
{
    $ipClause = $ipTrusted ? ' OR ip_address = :ip' : '';
    $stmt = $db->prepare(
        "SELECT timestamp FROM {$table}
         WHERE (payout_address = :addr{$ipClause})
           AND timestamp >= DATETIME('now', :window)
         ORDER BY timestamp DESC LIMIT 1"
    );
    $params = [':addr' => $address, ':window' => $window];
    if ($ipTrusted) {
        $params[':ip'] = $ip;
    }
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (string) $row['timestamp'] : null;
}

/** Build a rate_limited result with next-claim time (ISO 8601) + retry_after seconds. */
function faucet_rate_limited(string $lastClaimUtc, int $hours): array
{
    $next = null;
    $retry = null;
    try {
        $n = (new DateTime($lastClaimUtc, new DateTimeZone('UTC')))->modify("+{$hours} hours");
        $next  = $n->format(DATE_ATOM);
        $retry = max(0, $n->getTimestamp() - time());
    } catch (Exception $e) {
        // Leave next/retry null on an unparseable timestamp.
    }
    return ['status' => 'rate_limited', 'next_claim' => $next, 'retry_after' => $retry];
}

/** Record a successful payout: fill the txid + bump the lifetime faucet_totals. */
function faucet_record_payout(PDO $db, string $table, int $reservationId, string $txid, string $key, array $entry, float $amount): void
{
    // faucet_totals 'net' must match what the web engines use, or totals split:
    // Core uses the catalog key (ltc/btc); XMR uses the nettype (stagenet/testnet).
    $net = (($entry['engine'] ?? '') === 'xmr') ? $entry['nettype'] : $key;
    try {
        $db->prepare("UPDATE {$table} SET transaction_id = :txid WHERE id = :id")
           ->execute([':txid' => $txid, ':id' => $reservationId]);
        $db->prepare(
            "INSERT INTO faucet_totals (net, total_payouts, total_sent)
             VALUES (:net, 1, :amt)
             ON CONFLICT(net) DO UPDATE SET
                 total_payouts = total_payouts + 1,
                 total_sent    = total_sent + :amt2"
        )->execute([':net' => $net, ':amt' => $amount, ':amt2' => $amount]);
    } catch (PDOException $e) {
        error_log("[faucet-api] post-send bookkeeping failed for reservation {$reservationId} (tx {$txid}): " . $e->getMessage());
    }
}

/** Release a reservation (the send did not happen). */
function faucet_release(PDO $db, string $table, int $reservationId): void
{
    try {
        $db->prepare("DELETE FROM {$table} WHERE id = :id")->execute([':id' => $reservationId]);
    } catch (PDOException $e) {
        error_log("[faucet-api] reservation cleanup failed for {$reservationId}: " . $e->getMessage());
    }
}

/** Count claims in {table} within the last 24h, optionally scoped to one IP. */
function faucet_daily_count(PDO $db, string $table, ?string $ip = null): int
{
    if ($ip === null) {
        return (int) $db->query("SELECT COUNT(*) FROM {$table} WHERE timestamp >= DATETIME('now', '-1 day')")->fetchColumn();
    }
    $stmt = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE ip_address = :ip AND timestamp >= DATETIME('now', '-1 day')");
    $stmt->execute([':ip' => $ip]);
    return (int) $stmt->fetchColumn();
}

/**
 * Run a claim and return a structured result. Status is one of:
 *   ok | invalid_address | rate_limited | daily_cap | faucet_empty |
 *   node_busy | send_failed | faucet_unavailable | error
 *
 * Order is deliberately rate-limit-before-RPC: the common repeat-claim spam is
 * rejected from the DB with ZERO node RPC. The authoritative atomic check runs
 * again under BEGIN IMMEDIATE before the send, so there is no double-pay path.
 *
 * @param int $dailyCap   Faucet-wide max claims per net per rolling 24h; 0 = off.
 * @param int $ipDailyCap Per-IP max claims per rolling 24h (behind CF); 0 = off.
 */
function faucet_claim(PDO $db, string $key, array $entry, array $config, string $address, string $ip, bool $ipTrusted, int $dailyCap = 0, int $ipDailyCap = 0): array
{
    $engine   = $entry['engine'] ?? '';
    $table    = $entry['table'];
    $amount   = (float) $entry['payout_amount'];
    $currency = $entry['currency'];
    // XMR enforces a fixed 1-hour window in SQL (claim_hours is display-only);
    // Core honours claim_hours. Mirrors the engines exactly.
    $hours  = ($engine === 'xmr') ? 1 : (int) $entry['claim_hours'];
    $window = "-{$hours} hour";

    // The faucet's own "return unused coins" address for this net (reject it).
    if ($engine === 'xmr') {
        $donate = $config["donate_{$entry['nettype']}"] ?? '';
    } else {
        $donate = $config['donate_' . strtolower($currency)] ?? $config["donate_{$key}"] ?? '';
    }

    // ---- Cheap input checks (no RPC, no DB) ----
    $address = trim($address);
    if ($address === '' || strlen($address) > 120 || $address === $donate) {
        return ['status' => 'invalid_address'];
    }

    // ---- Daily caps: fast pre-check (no RPC) ----
    // Rejects an over-cap request before any node RPC. Re-checked authoritatively
    // under the BEGIN IMMEDIATE lock below, so the caps are exact even under
    // concurrent requests. The per-IP budget applies only with a real per-visitor
    // IP (behind Cloudflare); the API deliberately uses a generous daily budget
    // instead of the website's tight per-IP-per-window limit, so a CI host can
    // fund several test wallets from one IP.
    try {
        if ($dailyCap > 0 && faucet_daily_count($db, $table) >= $dailyCap) {
            return ['status' => 'daily_cap'];
        }
        if ($ipTrusted && $ipDailyCap > 0 && faucet_daily_count($db, $table, $ip) >= $ipDailyCap) {
            return ['status' => 'ip_rate_limited'];
        }
    } catch (PDOException $e) {
        error_log("[faucet-api] daily-cap pre-check failed: " . $e->getMessage());
        return ['status' => 'error'];
    }

    // ---- Rate-limit window pre-check (DB read, BEFORE any node RPC) ----
    // Per address AND (behind Cloudflare) per IP, matching the website: one claim
    // per IP per window stops a single source bursting many fresh addresses, which
    // an HD wallet hands out automatically. Re-checked authoritatively under the
    // BEGIN IMMEDIATE lock below.
    try {
        $recent = faucet_recent_claim($db, $table, $address, $ip, $ipTrusted, $window);
    } catch (PDOException $e) {
        error_log("[faucet-api] rate-limit pre-check failed: " . $e->getMessage());
        return ['status' => 'error'];
    }
    if ($recent !== null) {
        return faucet_rate_limited($recent, $hours);
    }

    // ---- Balance (RPC, but only reached past the rate-limit gate) ----
    $spendable = faucet_spendable($key, $entry, $config);
    if ($spendable === null) {
        return ['status' => 'faucet_unavailable'];
    }
    if ($spendable < faucet_min_needed($entry)) {
        return ['status' => 'faucet_empty'];
    }

    // ---- Validate address (node RPC) ----
    [$url, $user, $pass] = faucet_rpc_target($key, $entry, $config);
    if ($engine === 'xmr') {
        $v = faucet_xmr_rpc($url, 'validate_address', [
            'address'         => $address,
            'any_net_type'    => true,
            'allow_openalias' => false,
        ], $user, $pass);
        if ($v === null) {
            return ['status' => 'faucet_unavailable'];
        }
        $ok = (isset($v['result']['valid']) && $v['result']['valid'] === true)
            && (($v['result']['nettype'] ?? '') === $entry['nettype']);
        if (!$ok) {
            return ['status' => 'invalid_address'];
        }
    } else {
        $v = faucet_core_rpc_read($url, $user, $pass, 'validateaddress', [$address]);
        if ($v === null) {
            return ['status' => 'faucet_unavailable'];
        }
        if (empty($v['result']['isvalid'])) {
            return ['status' => 'invalid_address'];
        }
        // Canonicalise so case/format variants of one bech32 address can't each
        // defeat the per-address limit.
        $address = $v['result']['address'] ?? $address;
    }

    // ---- Atomic reserve: authoritative cap + rate-limit re-check + slot ----
    // Re-checking the caps and the per-address window under the lock makes them
    // exact: a concurrent claim can't slip past a check made before the lock.
    $reservationId = null;
    $capHit = null; // 'daily_cap' | 'ip_rate_limited'
    $recent = null;
    try {
        $db->exec('BEGIN IMMEDIATE');
        if ($dailyCap > 0 && faucet_daily_count($db, $table) >= $dailyCap) {
            $capHit = 'daily_cap';
        } elseif ($ipTrusted && $ipDailyCap > 0 && faucet_daily_count($db, $table, $ip) >= $ipDailyCap) {
            $capHit = 'ip_rate_limited';
        } else {
            $recent = faucet_recent_claim($db, $table, $address, $ip, $ipTrusted, $window);
            if ($recent === null) {
                $reserve = $db->prepare(
                    "INSERT INTO {$table} (ip_address, payout_amount, payout_address, transaction_id, timestamp)
                     VALUES (:ip, :amount, :addr, NULL, :ts)"
                );
                $reserve->execute([
                    ':ip'     => $ip,
                    ':amount' => $amount,
                    ':addr'   => $address,
                    ':ts'     => gmdate('Y-m-d H:i:s'),
                ]);
                $reservationId = (int) $db->lastInsertId();
            }
        }
        $db->exec('COMMIT');
    } catch (PDOException $e) {
        try { $db->exec('ROLLBACK'); } catch (PDOException $ignore) {}
        error_log("[faucet-api] reservation failed: " . $e->getMessage());
        return ['status' => 'error'];
    }
    if ($capHit !== null) {
        return ['status' => $capHit];
    }
    if ($reservationId === null) {
        // A concurrent claim slipped in between the pre-check and the lock.
        return faucet_rate_limited((string) $recent, $hours);
    }

    // ---- Send ----
    if ($engine === 'xmr') {
        $atomic = (int) ($amount * 1e12);
        $t = faucet_xmr_rpc($url, 'transfer', [
            'destinations'    => [['amount' => $atomic, 'address' => $address]],
            'account_index'   => 0,
            'subaddr_indices' => [0],
            'priority'        => 0,
            'get_tx_key'      => true,
            'unlock_time'     => 0,
        ], $user, $pass);

        if ($t !== null && isset($t['result']['tx_hash'])) {
            $txid = $t['result']['tx_hash'];
            faucet_record_payout($db, $table, $reservationId, $txid, $key, $entry, $amount);
            return ['status' => 'ok', 'txid' => $txid, 'tx_key' => $t['result']['tx_key'] ?? '', 'amount' => $amount, 'currency' => $currency];
        }
        if ($t === null) {
            // AMBIGUOUS: no response. Keep the reservation (the transfer may have
            // broadcast) so a retry can't double-pay.
            error_log("[faucet-api] xmr transfer ambiguous; reservation {$reservationId} kept");
            return ['status' => 'error'];
        }
        // DEFINITE failure (wallet replied with an error, e.g. locked funds).
        faucet_release($db, $table, $reservationId);
        error_log("[faucet-api] xmr transfer rejected: " . json_encode($t));
        return ['status' => 'send_failed'];
    }

    // Bitcoin Core: amount as a fixed-decimal string.
    $amountStr = number_format($amount, 8, '.', '');
    $sendHttp  = null;
    $t = faucet_core_rpc($url, $user, $pass, 'sendtoaddress', [$address, $amountStr], 30, $sendHttp);

    if ($t !== null && !empty($t['result'])) {
        $txid = $t['result'];
        faucet_record_payout($db, $table, $reservationId, $txid, $key, $entry, $amount);
        return ['status' => 'ok', 'txid' => $txid, 'amount' => $amount, 'currency' => $currency];
    }
    if ($t === null && $sendHttp === 503) {
        // Work queue full: rejected before the wallet ran it, so no coins moved.
        faucet_release($db, $table, $reservationId);
        error_log("[faucet-api] send deferred: node RPC queue full (503); reservation {$reservationId} released");
        return ['status' => 'node_busy'];
    }
    if ($t === null) {
        // AMBIGUOUS: keep the reservation.
        error_log("[faucet-api] send ambiguous; reservation {$reservationId} kept");
        return ['status' => 'error'];
    }
    // DEFINITE failure (e.g. fee estimation, funds). Release it.
    faucet_release($db, $table, $reservationId);
    error_log("[faucet-api] send rejected: " . json_encode($t));
    return ['status' => 'send_failed'];
}

/**
 * Public, RPC-free info for one faucet (for the /info endpoint). Balance comes
 * from the web engines' cache file, so this never hits a node.
 */
function faucet_info_one(string $key, array $entry, array $config, string $cacheDir): array
{
    $engine  = $entry['engine'] ?? '';
    $enabled = (($config['enabled'][$key] ?? true) !== false);
    $hours   = ($engine === 'xmr') ? 1 : (int) $entry['claim_hours'];
    $bal     = $enabled ? faucet_cached_balance($key, $entry, $cacheDir) : null;
    return [
        'network'            => ltrim($entry['href'] ?? '', '/'),
        'currency'           => $entry['currency'],
        'payout_amount'      => number_format((float) $entry['payout_amount'], 8, '.', ''),
        'claim_window_hours' => $hours,
        'address_hint'       => $entry['address_hint'] ?? '',
        'enabled'            => $enabled,
        'available'          => ($bal === null) ? null : ($bal >= faucet_min_needed($entry)),
        'balance'            => ($bal === null) ? null : number_format($bal, ($engine === 'xmr') ? 4 : 8, '.', ''),
    ];
}
