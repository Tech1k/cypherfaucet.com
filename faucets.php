<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
//
// The faucet catalog: one entry per faucet, holding everything that defines it.
// This is the single source both engines and the homepage read, so they can
// never disagree about what a faucet pays or how it is reached.
//
//   - 'engine' picks the code that serves it: 'xmr' (xmr-faucet.php, Monero
//     wallet RPC) or 'core' (core-faucet.php, Bitcoin Core RPC). Each engine
//     serves only its own entries and 404s the rest.
//   - economics: payout_amount, claim_hours, currency. Change these here and the
//     faucet page and the homepage card update together.
//   - plumbing:  rpc_port, table, address_hint, explorer_tx (+ nettype for XMR).
//   - card:      label, icon, alt, href for the homepage; net_label for the
//     faucet page's own headings.
//
// Keys match the ?net= / ?coin= routing identifiers set by .htaccess. Secrets
// and per-operator settings (RPC creds, enabled toggles, donation addresses)
// live in config.php, not here.
//
// Note: the Monero engine enforces a fixed 1-hour claim window in SQL, so for
// the XMR entries claim_hours is display-only (leave it at 1). The Bitcoin Core
// engine (ltc/btc) honours claim_hours directly.
return [
    'stagenet' => [
        'engine'        => 'xmr',
        'label'         => 'Monero Stagenet Faucet',
        'net_label'     => 'Stagenet',
        'currency'      => 'sXMR',
        'payout_amount' => 0.01,
        'claim_hours'   => 1,
        'nettype'       => 'stagenet',
        'rpc_port'      => 38088,
        'table'         => 'xmr_stagenet_payouts',
        'address_hint'  => 'starts with 5 or 7',
        'explorer_tx'   => 'https://xmr-stagenet.librenode.com/tx/', // self-hosted onion-monero-blockchain-explorer; '' shows a bare txid
        'href'          => '/xmr-stagenet',
        'icon'          => '/assets/images/monero.png',
        'alt'           => 'Monero',
        // Optional per-faucet override: 'daemon_url' => 'http://127.0.0.1:38081/json_rpc',
    ],
    'testnet' => [
        'engine'        => 'xmr',
        'label'         => 'Monero Testnet Faucet',
        'net_label'     => 'Testnet',
        'currency'      => 'tXMR',
        'payout_amount' => 0.01,
        'claim_hours'   => 1,
        'nettype'       => 'testnet',
        'rpc_port'      => 28088,
        'table'         => 'xmr_testnet_payouts',
        'address_hint'  => 'starts with 9, A, or B',
        'explorer_tx'   => 'https://xmr-testnet.librenode.com/tx/', // self-hosted onion-monero-blockchain-explorer; '' shows a bare txid
        'href'          => '/xmr-testnet',
        'icon'          => '/assets/images/monero.png',
        'alt'           => 'Monero',
    ],
    'ltc' => [
        'engine'        => 'core',
        'label'         => 'Litecoin Testnet Faucet',
        'net_label'     => 'Litecoin Testnet',
        'currency'      => 'tLTC',
        'payout_amount' => 0.01,
        'claim_hours'   => 1,
        'rpc_port'      => 19332,
        'table'         => 'tltc_payouts',
        'address_hint'  => 'starts with m, n, Q, tltc1, or tmweb1',
        'explorer_tx'   => 'https://litecoinspace.org/testnet/tx/', // '' shows a bare txid
        'href'          => '/ltc-testnet',
        'icon'          => '/assets/images/litecoin.png',
        'alt'           => 'Litecoin',
    ],
    'btc' => [
        'engine'        => 'core',
        'label'         => 'Bitcoin Testnet4 Faucet',
        'net_label'     => 'Bitcoin Testnet4',
        'currency'      => 'tBTC',
        'payout_amount' => 0.01,
        'claim_hours'   => 1,
        'rpc_port'      => 48332, // testnet4 default (testnet3 is 18332)
        'table'         => 'tbtc_payouts',
        'address_hint'  => 'starts with m, n, 2, or tb1',
        'explorer_tx'   => 'https://mempool.space/testnet4/tx/', // '' shows a bare txid
        'href'          => '/btc-testnet',
        'icon'          => '/assets/images/bitcoin.png',
        'alt'           => 'Bitcoin',
    ],
];
