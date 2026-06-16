<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
//
// Shared site footer, included by every page so it lives in one place. Uses the
// page's $source_url (every page sets it before including). Shows a "Tor" link
// only when an 'onion' address is set in config.php; the clearnet Onion-Location
// header already advertises the service to Tor Browser, this is the visible cue.
$footer_cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$footer_source = $source_url ?? ($footer_cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet');
$footer_onion = $footer_cfg['onion'] ?? '';
?>
            <footer>
                <hr style="width: 17.5%;"/>
                <p style="text-align: center; font-size: 18px;">Established May 5, 2025.<br/>Made with ♥️ and ☕ by <a href="https://tech1k.com" target="_blank" rel="noopener"><strong>Tech1k</strong></a> &middot; <a href="<?php echo htmlspecialchars($footer_source, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Source</a><?php if ($footer_onion !== '') { ?> &middot; <a href="http://<?php echo htmlspecialchars($footer_onion, ENT_QUOTES, 'UTF-8'); ?>/" target="_blank" rel="noopener" title="Tor onion service">Tor</a><?php } ?></p>
            </footer>
