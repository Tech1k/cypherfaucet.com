<?php
// Copy this file to config.php and fill in your values.
// config.php is gitignored and must NOT be committed (it holds secrets).
return [
    // Cloudflare Turnstile keys (https://dash.cloudflare.com -> Turnstile).
    // The sitekey is public; the secret must stay private.
    'turnstile_secret'  => 'YOUR_TURNSTILE_SECRET',
    'turnstile_sitekey' => 'YOUR_TURNSTILE_SITEKEY',

    // Optional: pin the captcha to your domain. If set, a Turnstile token whose
    // hostname does not match is rejected (used by core-faucet.php). Leave it
    // commented out to skip the check.
    // 'expected_host' => 'cypherfaucet.com',

    // monero-wallet-rpc credentials (--rpc-login user:pass). Leave blank if the
    // wallet runs with --disable-rpc-login bound to 127.0.0.1.
    'rpc_user' => '',
    'rpc_pass' => '',

    // litecoind RPC credentials (rpcuser / rpcpassword) for the Litecoin faucet.
    'ltc_rpc_user' => '',
    'ltc_rpc_pass' => '',
    // Wallet name. Only needed when the daemon has more than one wallet loaded
    // (e.g. a mining-pool wallet next to the faucet); calls then target it via
    // /wallet/<name>. Leave blank to use the single loaded wallet.
    'ltc_wallet'   => '',

    // bitcoind RPC credentials (rpcuser / rpcpassword) for the Bitcoin faucet.
    'btc_rpc_user' => '',
    'btc_rpc_pass' => '',
    'btc_wallet'   => '',

    // Per-faucet on/off switch. Leave a key true (or omit it) to run that
    // faucet; set it false to take it offline, which hides its homepage card and
    // serves a 503 "offline" page. Handy for maintenance or running a subset.
    'enabled' => [
        'stagenet' => true,
        'testnet'  => true,
        'ltc'      => true,
        'btc'      => true,
    ],

    // Public link to your source, shown in the footer to satisfy AGPL section 13.
    'source_url' => 'https://github.com/Tech1k/cypherfaucet',

    // Optional Tor onion address (no scheme). When set, a "Tor" link shows in the
    // footer. Pair it with the Onion-Location header (see README "Tor / onion").
    'onion' => '', // e.g. 'abcd...xyz.onion'

    // Optional companion testnet mining pool. When set, a small cross-link shows
    // on the homepage ("Want to mine testnet coins too? Try our testnet pool").
    'pool_url' => '', // e.g. 'https://testnetpool.com'

    // Optional companion testnet wallet (client-side). When set, a cross-link
    // shows on the homepage and a "get an address" hint on the Bitcoin and
    // Litecoin faucets. The wallet supports BTC and LTC (not Monero yet).
    'wallet_url' => '', // e.g. 'https://testnetwallet.net'

    // ---- Developer API (api.php) ----------------------------------------
    // Keyless JSON API at /api/v1/ (info + claim). Off by default; set true to
    // expose it. Claim limits are SHARED with the web faucets (same rate-limit
    // tables + reservation pattern), so the API can't bypass the per-address/IP
    // window or double-pay. Put Cloudflare WAF rate-limiting on /api/ too.
    'api_enabled' => false,
    // Faucet-wide safety cap: max claims per faucet per rolling 24h via the API
    // (0 = unlimited). Counts all claims in that faucet's table, so it bounds
    // worst-case drain and node load even though the API has no captcha. Tune to
    // your wallet top-up rate.
    'api_daily_cap' => 0,
    // Per-IP daily budget for the API (claims per IP per rolling 24h; 0 =
    // unlimited). The API does NOT copy the website's tight 1-per-hour-per-IP
    // limit, because a CI run legitimately funds several test wallets from one
    // host. This generous daily budget supports that while still stopping a
    // single IP from draining the faucet-wide cap. Only applies behind
    // Cloudflare (where a real per-visitor IP exists).
    'api_ip_daily_cap' => 25,

    // Optional per-faucet node-status dashboard URLs. When set, the "Network:"
    // line on that faucet links to it. Keys match the faucet identifiers.
    'status_stagenet' => '', // e.g. '/status/xmr-stagenet'
    'status_testnet'  => '',
    'status_ltc'      => '',
    'status_btc'      => '',

    // Testnet "return unused coins" addresses, shown in the recycle card and the
    // out-of-coins message. Leave blank to hide the card. The t-prefix (tltc /
    // tbtc) marks these as testnet, to keep them distinct from the mainnet
    // donation addresses below.
    'donate_stagenet' => '',
    'donate_testnet'  => '',
    'donate_tltc'     => '',
    'donate_tbtc'     => '',

    // Optional mainnet "support the faucet" donation addresses, shown as a
    // low-key FAQ entry on each faucet. All blank by default, so the public code
    // asks for nothing; set a coin's address to enable just that coin's entry.
    // Each coin also takes an optional QR image path (your own).
    'mainnet_xmr'    => '',
    'mainnet_xmr_qr' => '', // e.g. '/assets/images/monero-qr.png'
    'mainnet_ltc'    => '',
    'mainnet_ltc_qr' => '', // e.g. '/assets/images/litecoin-qr.png'
    'mainnet_btc'    => '',
    'mainnet_btc_qr' => '', // e.g. '/assets/images/bitcoin-qr.png'

    // OpenAlias handle, shared across coins: a single FQDN resolves to the right
    // address per coin via its DNS TXT records (enable DNSSEC for the verified
    // check). Shown on every faucet whose mainnet address is set above.
    'mainnet_openalias' => '', // e.g. 'donate@cypherfaucet.com'
];
