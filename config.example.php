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
    'source_url' => 'https://github.com/youruser/cypherfaucet',

    // Per-net "return unused coins" addresses, shown in the donate card and the
    // out-of-coins message. Leave blank to hide the card.
    'donate_stagenet' => '',
    'donate_testnet'  => '',
    'donate_ltc'      => '',
    'donate_btc'      => '',

    // Optional: a mainnet XMR address enables a low-key "support the faucet"
    // entry in the FAQ. Leave blank to keep donations off. The two extras below
    // are shown only when mainnet_donate is also set.
    'mainnet_donate'    => '',
    'mainnet_openalias' => '', // e.g. 'donate@cypherfaucet.com' (enable DNSSEC for the verified check)
    'mainnet_qr'        => '', // e.g. '/assets/images/monero-qr.png' (your own QR; gitignored)
];
