<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Dedicated "support the faucet" page listing every configured mainnet donation
// address. Everything comes from config.php, so an instance with none set just
// redirects home (the public code asks for nothing on its own).
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$source_url = $config['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
$openalias  = $config['mainnet_openalias'] ?? '';

$coins = [];
foreach ([['Monero', 'XMR', 'xmr'], ['Litecoin', 'LTC', 'ltc'], ['Bitcoin', 'BTC', 'btc']] as [$name, $ticker, $key]) {
    $addr = $config["mainnet_{$key}"] ?? '';
    if ($addr === '') {
        continue;
    }
    $coins[] = [
        'name'   => $name,
        'ticker' => $ticker,
        'addr'   => $addr,
        'qr'     => $config["mainnet_{$key}_qr"] ?? '',
    ];
}

// Nothing configured: this instance doesn't take donations, so don't show an
// empty page.
if (!$coins && $openalias === '') {
    header('Location: /');
    exit;
}

$openalias_safe = htmlspecialchars($openalias, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Support the Faucet</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="description" content="Support CypherFaucet with an optional mainnet donation. These testnet faucets are free and run to give back.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="canonical" href="https://cypherfaucet.com/donate" />
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=10">
        <style>
            strong { color: #d4d4d4; }
            .mono { font-family: monospace; word-break: break-word; }
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
            .copybtn:hover { background-color: #3a3f44; border-color: #7a8189; }
        </style>
    </head>
    <body>
<?php $nav_current = 'donate'; include __DIR__ . '/nav.php'; ?>
        <br/>
        <div id="main">
            <span class="title is-size-3 has-icon" style="margin-bottom: 0em !important;">
                <span style="font-size: 28px; color: #e1e1e1;"><b>Support the Faucet</b></span>
            </span>
            <br/>
            <span>These faucets are free and I run them to give back. If they've saved you time and you'd like to help with server costs, a <b>mainnet</b> donation is welcome and entirely optional. Thank you!</span>

<?php if ($openalias !== '') { ?>
            <div class="card">
                <div class="card-header"><b>OpenAlias</b></div>
                <div class="card-body">
                    <p>One handle resolves to the right address per coin in <a href="https://cyphertoshi.com/posts/openalias-wallets" target="_blank" rel="noopener">supporting wallets</a>:</p>
                    <p><code class="mono"><?php echo $openalias_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $openalias_safe; ?>">Copy</button></p>
                </div>
            </div>
<?php } ?>

<?php foreach ($coins as $coin) {
    $addr_safe = htmlspecialchars($coin['addr'], ENT_QUOTES, 'UTF-8');
    $qr_safe   = htmlspecialchars($coin['qr'], ENT_QUOTES, 'UTF-8');
?>
            <div class="card">
                <div class="card-header"><b><?php echo $coin['name']; ?> (<?php echo $coin['ticker']; ?>)</b></div>
                <div class="card-body">
                    <p><code class="mono"><?php echo $addr_safe; ?></code> <button type="button" class="copybtn" data-copy="<?php echo $addr_safe; ?>">Copy</button></p>
<?php if ($coin['qr'] !== '') { ?>
                    <p><img src="<?php echo $qr_safe; ?>" alt="<?php echo $coin['name']; ?> donation QR code" style="width: 180px; max-width: 100%; height: auto; margin-top: 8px;"></p>
<?php } ?>
                </div>
            </div>
<?php } ?>

            <br/>
            <footer>
                <hr style="width: 17.5%;"/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with &#9829;&#65039; and &#9749; by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a></p>
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
