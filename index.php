<?php
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';

// Cards are built from faucets.php (the shared economics source the engines also
// read), so each "Get X every Y" line always matches what that faucet pays. A
// faucet hidden by enabled=false in config also serves a 503 from its engine,
// so the homepage and the faucet pages stay in sync. Absent key defaults to on.
$enabled = $cfg['enabled'] ?? [];
$is_on = fn($k) => (($enabled[$k] ?? true) !== false);
$faucets = require __DIR__ . '/faucets.php';

// Show a "support the faucet" link only when at least one mainnet donation
// address (or the OpenAlias) is configured. The /donate page itself redirects
// home when nothing is set, so this stays fork-neutral.
$has_donations = ($cfg['mainnet_openalias'] ?? '') !== ''
    || ($cfg['mainnet_xmr'] ?? '') !== ''
    || ($cfg['mainnet_ltc'] ?? '') !== ''
    || ($cfg['mainnet_btc'] ?? '') !== '';

// Optional cross-link to a companion testnet mining pool (blank hides it).
$pool_url = $cfg['pool_url'] ?? '';
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
        <meta name="theme-color" content="#14161b" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#f7f7f7" media="(prefers-color-scheme: light)">
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
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=14">
    </head>
    <body>
<?php $nav_current = 'home'; include __DIR__ . '/nav.php'; ?>

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
<?php foreach ($faucets as $key => $f) {
    if (!$is_on($key)) { continue; }
    $interval = $f['claim_hours'] == 1 ? 'every hour' : "every {$f['claim_hours']} hours";
    $blurb = "Get {$f['payout_amount']} {$f['currency']} {$interval}";
?>
                <div style="display: flex; align-items: center; margin-top: 10px;">
                    <img src="<?php echo $f['icon']; ?>" width="32px" style="margin-right: 8px;" alt="<?php echo $f['alt']; ?>">
                    <div>
                        <a href="<?php echo $f['href']; ?>" style="text-decoration: none; font-size: 18px;"><?php echo $f['label']; ?></a><br>
                        <small style="font-size: 15px;"><?php echo $blurb; ?></small>
                    </div>
                </div>
<?php } ?>
            </div>
<?php if ($pool_url !== '') { ?>
            <p align="center" style="margin-top: 28px;">Want to mine testnet coins too? Try our <a href="<?php echo htmlspecialchars($pool_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="site_link">testnet pool</a>.</p>
<?php } ?>
<?php if ($has_donations) { ?>
            <p align="center" style="margin-top: <?php echo $pool_url !== '' ? '8' : '28'; ?>px;"><a href="/donate" class="site_link">Support the faucet</a></p>
<?php } ?>
            <br/><br/><br/><br/><br/>
<?php include __DIR__ . '/footer.php'; ?>
        </div>
    </body>
</html>
