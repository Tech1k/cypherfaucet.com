<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
//
// Multi-node status router. Reads the faucet catalog (faucets.php) and config.php
// so the node list and RPC details stay in sync with the faucets, renders a
// landing page, and hands each node off to simple-node-dashboard
// (status/dashboard.php, deployed separately) for the full view.
//
//   /status/         -> landing page listing every node
//   /status/<slug>   -> that node's dashboard (slug = faucet URL: xmr-stagenet,
//                       xmr-testnet, ltc-testnet, btc-testnet) via .htaccess
//   /status/?node=<slug> also works without the rewrite.
//
// Drop your simple-node-dashboard index.php in as status/dashboard.php (it's
// gitignored, so `git pull` it there to update). The router sets the dashboard's
// NETWORK / RPC_* env per node before including it.

$catalog = require __DIR__ . '/../faucets.php';
$config  = is_file(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];

// Map each catalog faucet to a status node, keyed by its URL slug.
$nodes = [];
foreach ($catalog as $key => $f) {
    $slug = ltrim($f['href'] ?? '', '/');
    if ($slug === '') {
        continue;
    }
    if (($f['engine'] ?? '') === 'xmr') {
        // monerod's RPC is the wallet-RPC port minus 7 (the faucet's convention);
        // the daemon usually needs no auth.
        $rpc_port = (int) $f['rpc_port'] - 7;
        $rpc_user = '';
        $rpc_pass = '';
    } else {
        // Bitcoin Core's RPC is the node RPC; reuse the faucet's creds.
        $rpc_port = (int) $f['rpc_port'];
        $rpc_user = $config["{$key}_rpc_user"] ?? '';
        $rpc_pass = $config["{$key}_rpc_pass"] ?? '';
    }
    $nodes[$slug] = [
        'label'    => preg_replace('/ Faucet$/', '', $f['label'] ?? $slug), // "Monero Stagenet"
        'currency' => $f['currency'] ?? '',
        'icon'     => $f['icon'] ?? '',
        'rpc_port' => $rpc_port,
        'rpc_user' => $rpc_user,
        'rpc_pass' => $rpc_pass,
    ];
}

$node = $_GET['node'] ?? '';

// ---- Per-node view: configure the dashboard and hand off ----------------
if ($node !== '' && isset($nodes[$node])) {
    $n = $nodes[$node];
    putenv('NETWORK=' . $n['currency']);
    putenv('NODE_IP=127.0.0.1');
    putenv('RPC_PORT=' . $n['rpc_port']);
    putenv('RPC_USER=' . $n['rpc_user']);
    putenv('RPC_PASS=' . $n['rpc_pass']);
    // Uncomment to hide the node version (Connections is only a count, so it
    // leaks nothing) on a public deployment:
    // putenv('SHOW_NODE_INFO=false');
    chdir(__DIR__); // keep the dashboard's <network>_cache.json inside status/
    if (is_file(__DIR__ . '/dashboard.php')) {
        require __DIR__ . '/dashboard.php';
    } else {
        http_response_code(503);
        echo 'Node dashboard not installed: place simple-node-dashboard at status/dashboard.php';
    }
    exit;
}

// Unknown node -> 404, then fall through to the landing.
if ($node !== '') {
    http_response_code(404);
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Node Status</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="description" content="Live status of the nodes powering the CypherFaucet testnet faucets.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="canonical" href="https://cypherfaucet.com/status" />
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=10">
    </head>
    <body>
        <?php include __DIR__ . '/../nav.php'; ?>
        <br/>
        <div id="main">
            <span class="title is-size-3 has-icon" style="margin-bottom: 0em !important;">
                <span style="font-size: 28px; color: #e1e1e1;"><b>Node Status</b></span>
            </span>
            <br/>
            <span>Live status of the nodes powering the faucets.</span>

            <div style="display: flex; flex-direction: column; align-items: center; gap: 12px; margin-top: 16px;">
<?php foreach ($nodes as $slug => $n) { ?>
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <img src="<?php echo htmlspecialchars($n['icon'], ENT_QUOTES, 'UTF-8'); ?>" width="32px" style="margin-right: 8px;" alt="<?php echo htmlspecialchars($n['currency'], ENT_QUOTES, 'UTF-8'); ?>">
                    <div>
                        <a href="/status/<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>" style="text-decoration: none; font-size: 18px;"><?php echo htmlspecialchars($n['label'], ENT_QUOTES, 'UTF-8'); ?> Node</a><br>
                        <small style="font-size: 15px;"><?php echo htmlspecialchars($n['currency'], ENT_QUOTES, 'UTF-8'); ?> &middot; view live status</small>
                    </div>
                </div>
<?php } ?>
            </div>
            <br/>
            <?php include __DIR__ . '/../footer.php'; ?>
        </div>
    </body>
</html>
