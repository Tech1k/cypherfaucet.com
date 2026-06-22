<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Free software under the GNU AGPL v3 or later. See LICENSE.
//
// Developer API documentation page (/api). The network list, slugs, and payouts
// are generated from the shared catalog (faucets.php), so this page can't drift
// from the live faucets; the endpoint/error reference is static. Redirects home
// when the API is disabled, so a fork that hasn't enabled it shows nothing.
$config     = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$source_url = $config['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';

if (($config['api_enabled'] ?? false) !== true) {
    header('Location: /');
    exit;
}

$catalog = require __DIR__ . '/faucets.php';

// Enabled faucets, keyed by URL slug, for the network table and examples.
$nets = [];
foreach ($catalog as $key => $f) {
    if (($config['enabled'][$key] ?? true) === false) {
        continue;
    }
    $slug = ltrim($f['href'] ?? '', '/');
    if ($slug === '') {
        continue;
    }
    $nets[$slug] = [
        'currency' => $f['currency'] ?? '',
        'payout'   => $f['payout_amount'] ?? '',
    ];
}
$example_slug = array_key_first($nets) ?? 'ltc-testnet';

// Example commands, defined once so the shown text and the copy button match.
$cmd_info  = "curl https://cypherfaucet.com/api/v1/info";
$cmd_claim = "curl -X POST https://cypherfaucet.com/api/v1/claim \\\n"
           . "  -H 'Content-Type: application/json' \\\n"
           . "  -d '{\"network\":\"{$example_slug}\",\"address\":\"YOUR_ADDRESS\"}'";

$pre_style = "white-space: pre-wrap; word-break: break-word; margin: 8px 0 6px; padding: 10px 12px; border-radius: 8px; background: rgba(127,127,127,0.12); overflow-x: auto;";
$td_style  = "padding: 5px 12px; border-bottom: 1px solid rgba(127,127,127,0.2); text-align: left;";

/** Render a command in a code block with a copy button. */
function code_block(string $cmd, string $preStyle): string
{
    $safe = htmlspecialchars($cmd, ENT_QUOTES, 'UTF-8');
    return "<pre class=\"mono\" style=\"{$preStyle}\">{$safe}</pre>"
         . "<button type=\"button\" class=\"copybtn\" data-copy=\"{$safe}\">Copy</button>";
}
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Developer API</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png?v=3" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png?v=3" type="image/png" />
        <meta name="description" content="Keyless JSON API for requesting Monero, Litecoin, and Bitcoin testnet coins programmatically.">
        <meta name="robots" content="index, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#14161b" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#f7f7f7" media="(prefers-color-scheme: light)">
        <link rel="canonical" href="https://cypherfaucet.com/api" />
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=21">
    </head>
    <body>
<?php $nav_current = 'api'; include __DIR__ . '/nav.php'; ?>
        <br/>
        <div id="main">
            <span class="title is-size-3 has-icon" style="margin-bottom: 0em !important;">
                <span style="font-size: 28px; color: var(--text);"><b>Developer API</b></span>
            </span>
            <br/>
            <span>Request testnet coins programmatically, for CI, integration tests, and tooling. Free, keyless, and rate-limited. The coins have no value.</span>

            <div class="card">
                <div class="card-header"><b>Prefill links (no integration)</b></div>
                <div class="card-body">
                    <p>Just sending a user here, say from a wallet's "get testnet coins" button? Link them to a faucet page with their address in <code class="mono">?address=</code> and the claim box arrives pre-filled, and they solve the captcha and click. No integration, just an <code class="mono">&lt;a href&gt;</code>:</p>
                    <p><code class="mono">https://cypherfaucet.com/<?php echo htmlspecialchars($example_slug, ENT_QUOTES, 'UTF-8'); ?>?address=YOUR_ADDRESS</code></p>
                    <p>It works on every faucet page and is display-only, so the captcha, validation, and rate limits all still apply on submit.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><b>Networks</b></div>
                <div class="card-body">
                    <p>Use the <b>network</b> slug below in API requests.</p>
                    <table style="border-collapse: collapse; width: 100%; margin-top: 6px;">
                        <tr>
                            <th style="<?php echo $td_style; ?>">network</th>
                            <th style="<?php echo $td_style; ?>">coin</th>
                            <th style="<?php echo $td_style; ?>">payout</th>
                        </tr>
<?php foreach ($nets as $slug => $n) {
    $s = htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
    $cur = htmlspecialchars($n['currency'], ENT_QUOTES, 'UTF-8');
    $pay = htmlspecialchars((string) $n['payout'], ENT_QUOTES, 'UTF-8');
?>
                        <tr>
                            <td style="<?php echo $td_style; ?>"><code class="mono"><?php echo $s; ?></code></td>
                            <td style="<?php echo $td_style; ?>"><?php echo $cur; ?></td>
                            <td style="<?php echo $td_style; ?>"><?php echo $pay . ' ' . $cur; ?></td>
                        </tr>
<?php } ?>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><b>GET /api/v1/info</b></div>
                <div class="card-body">
                    <p>Payout amount, claim window, and balance for each faucet, served from cache so it never hits a node. Add <code class="mono">?network=&lt;slug&gt;</code> to filter to one.</p>
                    <?php echo code_block($cmd_info, $pre_style); ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><b>POST /api/v1/claim</b></div>
                <div class="card-body">
                    <p>Send one payout. JSON body with the network slug and a destination address; returns the transaction id (and, for Monero, the <code class="mono">tx_key</code> so the payment can be proven).</p>
                    <?php echo code_block($cmd_claim, $pre_style); ?>
                    <p style="margin-top: 10px;">Success: <code class="mono">{"ok":true,"network":"...","amount":"...","txid":"..."}</code>.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><b>Errors</b></div>
                <div class="card-body">
                    <p>Failures are <code class="mono">{"ok":false,"error":"&lt;code&gt;","message":"..."}</code> with a matching HTTP status:</p>
                    <table style="border-collapse: collapse; width: 100%; margin-top: 6px;">
                        <tr><th style="<?php echo $td_style; ?>">status</th><th style="<?php echo $td_style; ?>">error</th></tr>
                        <tr><td style="<?php echo $td_style; ?>">400</td><td style="<?php echo $td_style; ?>"><code class="mono">invalid_request</code>, <code class="mono">unknown_network</code>, <code class="mono">invalid_address</code></td></tr>
                        <tr><td style="<?php echo $td_style; ?>">409</td><td style="<?php echo $td_style; ?>"><code class="mono">faucet_empty</code></td></tr>
                        <tr><td style="<?php echo $td_style; ?>">429</td><td style="<?php echo $td_style; ?>"><code class="mono">rate_limited</code>, <code class="mono">ip_rate_limited</code>, <code class="mono">daily_cap</code></td></tr>
                        <tr><td style="<?php echo $td_style; ?>">503</td><td style="<?php echo $td_style; ?>"><code class="mono">node_busy</code>, <code class="mono">send_failed</code>, <code class="mono">unavailable</code></td></tr>
                        <tr><td style="<?php echo $td_style; ?>">500</td><td style="<?php echo $td_style; ?>"><code class="mono">internal_error</code></td></tr>
                    </table>
                    <p style="margin-top: 10px;"><code class="mono">rate_limited</code> includes <code class="mono">retry_after</code> (seconds) and <code class="mono">next_claim</code>, and sets the <code class="mono">Retry-After</code> header.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><b>Rate limits &amp; fair use</b></div>
                <div class="card-body">
                    <ul>
                        <li>One claim per <b>address</b> per claim window (shared with the website, so claiming on the site and via the API count together).</li>
                        <li>A daily budget per <b>IP</b>, so a CI host can fund several test wallets in a run. Unlike the website, the API does not cap you to one claim per hour per IP.</li>
                        <li>A faucet-wide daily cap is the overall ceiling.</li>
                    </ul>
                    <p>These are valueless testnet coins, so please don't hammer or hoard. A <code class="mono">429</code> means wait (respect <code class="mono">Retry-After</code>), not retry-in-a-loop.</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><b>Using it in a project?</b></div>
                <div class="card-body">
                    <p>The defaults cover most projects. If you need higher limits, bulk testnet coins, or a faucet/API integration, <a href="/contact" class="site_link">get in touch</a>.</p>
                    <p>If you build the faucet into a tool or show it to your users, a credit is appreciated but never required: a link to cypherfaucet.com, or "Testnet coins via CypherFaucet."</p>
                </div>
            </div>

            <p style="margin-top: 18px;">Machine-readable index: <a href="/api/v1/" class="site_link">/api/v1/</a> &middot; full reference and source: <a href="<?php echo htmlspecialchars($source_url, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="site_link">the repository</a>.</p>

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
