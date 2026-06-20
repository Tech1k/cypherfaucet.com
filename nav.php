<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
//
// Shared site navigation, included by every page so the nav lives in one place.
// Set $nav_current to 'home' | 'donate' | 'contact' | 'legal' before including
// to highlight the active link. The Donate item only appears when a mainnet
// donation address (or OpenAlias) is configured, matching /donate (which
// redirects home otherwise).
$nav_cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$nav_has_donations = ($nav_cfg['mainnet_openalias'] ?? '') !== ''
    || ($nav_cfg['mainnet_xmr'] ?? '') !== ''
    || ($nav_cfg['mainnet_ltc'] ?? '') !== ''
    || ($nav_cfg['mainnet_btc'] ?? '') !== '';
$nav_current = $nav_current ?? null;
$nav_id = fn($page) => $page === $nav_current ? ' id="curpage"' : '';
?>
        <nav class="navbar">
            <a href="/" class="brand">
                <span class="brand-name">Cypher<b>Faucet</b></span>
            </a>

            <input type="checkbox" class="menu-toggle" id="menu-toggle" />

            <label for="menu-toggle" class="hamburger">
                <div></div>
                <div></div>
                <div></div>
            </label>

            <div class="nav-links">
                <a href="/"<?php echo $nav_id('home'); ?>>Home</a>
<?php if ($nav_has_donations) { ?>
                <a href="/donate"<?php echo $nav_id('donate'); ?>>Donate</a>
<?php } ?>
                <a href="/contact"<?php echo $nav_id('contact'); ?>>Contact</a>
                <a href="/legal"<?php echo $nav_id('legal'); ?>>Legal</a>
            </div>
        </nav>
