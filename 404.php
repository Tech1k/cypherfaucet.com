<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2025-2026 Tech1k <hello@tech1k.com>
$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
$source_url = $cfg['source_url'] ?? 'https://github.com/Tech1k/cypherfaucet';
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <title>CypherFaucet | Page Not Found</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" href="/assets/images/favicon.png" type="image/png" />
        <link rel="shortcut icon" href="/assets/images/favicon.png" type="image/png" />
        <meta name="robots" content="noindex, follow">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta name="theme-color" content="#c5c5c5">
        <link rel="stylesheet" type="text/css" href="/assets/style.css?v=10">
    </head>
    <body>
<?php include __DIR__ . '/nav.php'; ?>
        <br/>
        <div id="main">
            <p align="center">
                <span style="font-size: 48px;"><strong>404</strong></span>
                <br/>
                <span style="font-size: 22px;">Page not found.</span>
                <br/><br/>
                <a href="/" class="site_link">Return to the homepage</a>
            </p>
            <br/><br/><br/><br/><br/>
<?php include __DIR__ . '/footer.php'; ?>
        </div>
    </body>
</html>
