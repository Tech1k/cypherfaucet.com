<?php
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';

// One row per faucet. A faucet hidden here (enabled=false in config) also
// serves a 503 "offline" page from its engine, so the homepage and the faucet
// pages always agree. Absent key defaults to on.
$enabled = $cfg['enabled'] ?? [];
$is_on = fn($k) => (($enabled[$k] ?? true) !== false);
$faucets = [
    ['key' => 'stagenet', 'label' => 'Monero Stagenet Faucet', 'blurb' => 'Get 0.01 sXMR every hour',      'href' => '/xmr-stagenet', 'icon' => '/assets/images/monero.png',   'alt' => 'Monero'],
    ['key' => 'testnet',  'label' => 'Monero Testnet Faucet',  'blurb' => 'Get 0.01 tXMR every hour',      'href' => '/xmr-testnet',  'icon' => '/assets/images/monero.png',   'alt' => 'Monero'],
    ['key' => 'ltc',      'label' => 'Litecoin Testnet Faucet', 'blurb' => 'Get 0.1 tLTC every 12 hours',   'href' => '/ltc-testnet',  'icon' => '/assets/images/litecoin.png', 'alt' => 'Litecoin'],
    ['key' => 'btc',      'label' => 'Bitcoin Testnet Faucet',  'blurb' => 'Get 0.0001 tBTC every 12 hours', 'href' => '/btc-testnet', 'icon' => '/assets/images/bitcoin.png',  'alt' => 'Bitcoin'],
];
?>
<!-- SPDX-License-Identifier: AGPL-3.0-or-later  |  Copyright (C) 2025-2026 Tech1k -->
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Monero, Litecoin &amp; Bitcoin Testnet Faucets</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="description" content="Free Monero, Litecoin, and Bitcoin testnet coins for developers testing applications.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="canonical" href="https://cypherfaucet.com" />
        <meta property="og:image" content="https://cypherfaucet.com/assets/images/og-banner.png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="https://cypherfaucet.com/assets/images/og-banner.png">
        <meta property="og:description" content="Free Monero, Litecoin, and Bitcoin testnet coins for developers testing applications.">
        <meta property="og:title" content="CypherFaucet">
        <meta property="og:site_name" content="CypherFaucet">
        <meta property="og:url" content="https://cypherfaucet.com">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=9">
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
                <a href="/" id="curpage">Home</a>
                <a href="/contact">Contact</a>
                <a href="/legal">Legal</a>
            </div>
        </nav>

        <br/><br/><br/>

        <div id="main">
            <p align="center">
                <img src="/assets/images/cypherfaucet-icon.png" height="128px" alt="CypherFaucet Icon">
                <br/>
                <span style="font-size: 48px;"><strong>CypherFaucet</strong></span>
                <br/>
                <span style="font-size: 32px;">Free testnet coins for developers.</span>
            </p>

            <div style="display: flex; flex-direction: column; align-items: center; gap: 12px;">
<?php foreach ($faucets as $f) { if (!$is_on($f['key'])) { continue; } ?>
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <img src="<?php echo $f['icon']; ?>" width="32px" style="margin-right: 8px;" alt="<?php echo $f['alt']; ?>">
                    <div>
                        <a href="<?php echo $f['href']; ?>" style="text-decoration: none; font-size: 18px;"><?php echo $f['label']; ?></a><br>
                        <small style="font-size: 15px;"><?php echo $f['blurb']; ?></small>
                    </div>
                </div>
<?php } ?>
            </div>
            <br/><br/><br/><br/><br/>
            <footer>
                <hr/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with ♥️ and ☕ by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
            </footer>
        </div>
    </body>
</html>
