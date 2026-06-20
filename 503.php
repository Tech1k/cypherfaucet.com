<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
// Styled "service unavailable" page. Included by the faucet engines when a
// faucet is switched off in config or the backend can't be reached, and wired
// to ErrorDocument 503 for any server-level 503. Set $reason before including
// for a custom line; otherwise a generic message is shown. No "noindex" here on
// purpose: 503 already tells crawlers the outage is temporary and to come back.
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
$reason = (isset($reason) && $reason !== '')
    ? $reason
    : 'The faucet is temporarily unavailable. Please try again in a little while.';
http_response_code(503);
header('Retry-After: 3600');
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Temporarily Unavailable</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png?v=3" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png?v=3" type="image/png" />
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#14161b" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#f7f7f7" media="(prefers-color-scheme: light)">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=20">
    </head>
    <body>
<?php include __DIR__ . '/nav.php'; ?>
        <br/>
        <div id="main">
            <p align="center">
                <span style="font-size: 48px;"><strong>503</strong></span>
                <br/>
                <span style="font-size: 22px;"><?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></span>
                <br/><br/>
                <a href="/" class="site_link">Return to the homepage</a>
            </p>
            <br/><br/><br/><br/><br/>
<?php include __DIR__ . '/footer.php'; ?>
        </div>
    </body>
</html>
