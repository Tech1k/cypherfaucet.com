# CypherFaucet

A small, focused dev-net faucet. Developers enter a testnet/stagenet address,
complete a captcha, and receive a fixed payout on a rate limit. It serves
Monero (**stagenet** and **testnet**), Litecoin (**testnet**), and Bitcoin
(**testnet4**), runs at [cypherfaucet.com](https://cypherfaucet.com), and is
listed on getmonero.org.

Two engines share one database, design, and rate-limit logic, with the active
network chosen by the URL via `.htaccess`:

- [xmr-faucet.php](xmr-faucet.php) for Monero, both nets selected by `?net=`.
- [core-faucet.php](core-faucet.php) for Bitcoin Core-family coins, selected by
  `?coin=` (Litecoin testnet and Bitcoin testnet4; more are one catalog entry
  away, no new code).

## Features

- Two engines, one shared DB, style, and rate-limit design:
  - **Monero** ([xmr-faucet.php](xmr-faucet.php)): stagenet and testnet in one
    file, gated on the wallet's **unlocked** balance so it never offers a claim
    it can't pay (Monero locks outputs for 10 blocks), with **payment proof**
    on each receipt (a `get_tx_proof` signature that verifies in the Monero
    GUI's Prove/Check or CLI) for when the dev-net explorers are down.
  - **Bitcoin Core-family** ([core-faucet.php](core-faucet.php)): Litecoin
    testnet (tLTC) and Bitcoin testnet4 (tBTC). Talks to a Bitcoin Core
    JSON-RPC and links each receipt's txid to a block explorer.
- Each coin/net defined as a config map entry, not duplicated code.
- Cloudflare Turnstile captcha.
- Per-address and per-IP rate limiting, serialized with `BEGIN IMMEDIATE` so
  concurrent requests can't double-claim.
- Live wallet/daemon **sync status** and **lifetime** stats (total sent /
  payouts / last payout), kept in a counter table that survives data pruning.
- IP retention sweep (cron) so claimer IPs are not kept indefinitely.
- **Developer API** ([api.php](api.php)) and `?address=` **prefill links** for
  sending users or CI to the faucet, sharing the website's rate limits and claim
  path. Off by default; see [Developer API](#developer-api).

## Requirements

- PHP 7.4+ with `pdo_sqlite` and `curl`.
- Apache with `mod_rewrite` (and `mod_headers` for the security headers). The
  routing lives in [.htaccess](.htaccess); nginx users must translate it.
- For Monero: a `monerod` + `monero-wallet-rpc` instance **per net** (stagenet
  and testnet), bound to `127.0.0.1`.
- For Litecoin: a `litecoind` on testnet (`-testnet`) with `server=1` and its
  RPC bound to `127.0.0.1` (default port `19332`), holding spendable tLTC.
- For Bitcoin: a `bitcoind` on testnet4 (`-testnet4`) with `server=1` and its
  RPC bound to `127.0.0.1` (default port `48332`), holding spendable tBTC.
- SQLite 3.24+ (for the `ON CONFLICT` upsert used by the stats counter).

## Setup

1. **Config.** Copy the example and fill it in:
   ```sh
   cp config.example.php config.php
   ```
   Set your Turnstile sitekey/secret, the Monero wallet RPC credentials (or
   leave blank if the wallet uses `--disable-rpc-login` on localhost), the
   Litecoin (`ltc_rpc_user` / `ltc_rpc_pass`) and Bitcoin (`btc_rpc_user` /
   `btc_rpc_pass`) RPC credentials, and your public `source_url`. `config.php`
   is gitignored; never commit it.

2. **Database.** Create the SQLite file and tables:
   ```sh
   cd db && python3 create_db.py
   ```
   For production, keep the DB **outside the web root** and point the app at it:
   ```sh
   export FAUCET_DB=/var/lib/cypherfaucet/faucet.db
   ```
   (Set it in your vhost / PHP-FPM pool env.) If unset, it defaults to
   `db/faucet.db`. Whichever directory holds the DB must be writable by the web
   server user, because SQLite writes a journal there.

3. **Wallets and daemons.**
   - **Monero:** run `monero-wallet-rpc` for each net on the expected ports
     (stagenet `38088`, testnet `28088`) and the matching `monerod` daemons
     (`38081` / `28081`, used only for the sync-status line). Adjust ports or
     set `daemon_url` per net in the faucet catalog
     [faucets.php](faucets.php) if your setup differs.
   - **Litecoin:** run `litecoind -testnet` with `server=1` and
     `rpcuser` / `rpcpassword` matching `config.php`, RPC bound to `127.0.0.1`
     (port `19332`). Ports and the explorer link live in
     [faucets.php](faucets.php).
   - **Bitcoin:** run `bitcoind -testnet4` with `server=1` and
     `rpcuser` / `rpcpassword` matching `config.php`, RPC bound to `127.0.0.1`
     (port `48332`). Ports and the explorer link live in
     [faucets.php](faucets.php).

4. **Web server.** Serve the directory with Apache + `mod_rewrite`. The
   `.htaccess` maps `/xmr-stagenet`, `/xmr-testnet`, `/ltc-testnet`, and
   `/btc-testnet` to the apps and denies web access to `db/`.

5. **Retention cron.** Prune old claim rows (PII) daily:
   ```cron
   17 4 * * * FAUCET_DB=/var/lib/cypherfaucet/faucet.db php /path/to/db/cleanup.php >> /var/log/cypherfaucet-cleanup.log 2>&1
   ```
   Run it as a user that can write the DB and its directory.

## Operating the faucet

Monero locks every output for **10 blocks (~20 min)**, including the change that
comes back from each payout. If the wallet holds one large output, the first
payout locks the rest and claims stall until it unlocks.

Fund the wallet with **many small outputs** (for example ~0.015 each, covering a
0.01 payout plus fee headroom), batch-sent from your collection/donation
address. That keeps a pool of independently spendable outputs ahead of the lock.
The app gates on the unlocked balance, so if the pool is ever exhausted it shows
a "coins locking, try again shortly" message instead of failing a claim.

[`tools/fund_faucet.py`](tools/fund_faucet.py) automates this. Run it on the
machine holding the donation wallet (pointed at that wallet's
`monero-wallet-rpc`), sending to the faucet wallet's receiving address. It
batches the outputs into as few transactions as possible to save fees:

```sh
python3 tools/fund_faucet.py \
    --rpc-url http://127.0.0.1:38083/json_rpc \
    --address <faucet stagenet address> \
    --nettype stagenet \
    --count 50            # 50 outputs of 0.015; add --dry-run to preview
```

It validates the address against the chosen net and checks the donation
wallet's unlocked balance before sending. Stdlib only, no dependencies.

### Automated top-up

For hands-off operation, run it in `--target` mode on a timer. It reads the
faucet wallet's balance and sends only the shortfall, doing nothing when the
faucet is already funded, so it's safe to run often. It targets the faucet's
*total* balance (not unlocked) so it won't overfund while recent top-ups are
still locking.

Because dev-net coins have no value, the simplest setup is to run the donation
wallet's `monero-wallet-rpc` on the faucet server too (bound to `127.0.0.1`, on
its own port) so both wallets are local. If you keep the donation wallet on a
separate machine, point `--faucet-rpc-url` through an SSH tunnel or VPN.

cron, every 15 minutes (one line per net). `flock` prevents overlapping runs if
one ever hangs past the interval:

```cron
*/15 * * * * /usr/bin/flock -n /tmp/cf-topup-stagenet.lock python3 /path/to/tools/fund_faucet.py --rpc-url http://127.0.0.1:38083/json_rpc --faucet-rpc-url http://127.0.0.1:38088/json_rpc --address <faucet stagenet address> --nettype stagenet --target 0.3 --yes >> /var/log/cypherfaucet-topup.log 2>&1
```

(`38083` = the donation wallet's RPC; `38088` = the faucet wallet's RPC. Add a
second line with the testnet ports/address and its own lock file for the testnet
faucet.)

Or a systemd timer:

```ini
# /etc/systemd/system/cypherfaucet-topup.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /path/to/tools/fund_faucet.py --rpc-url http://127.0.0.1:38083/json_rpc --faucet-rpc-url http://127.0.0.1:38088/json_rpc --address <faucet stagenet address> --nettype stagenet --target 0.3 --yes

# /etc/systemd/system/cypherfaucet-topup.timer
[Timer]
OnCalendar=*:0/15
Persistent=true
[Install]
WantedBy=timers.target
```

Enable with `systemctl enable --now cypherfaucet-topup.timer`. (systemd won't
start a second run while one is still active, so no lock file is needed here.)

The Litecoin faucet needs no equivalent: Bitcoin Core manages change outputs
itself, so just keep the wallet topped up with spendable tLTC.

## Adding another Bitcoin Core-family coin

[core-faucet.php](core-faucet.php) is coin-agnostic; the active coins live in the
shared catalog [faucets.php](faucets.php). To add one:

1. Add its entry to [faucets.php](faucets.php) with `'engine' => 'core'`, setting
   the currency, payout, claim window, RPC port, table, address hint, explorer
   URL, and the homepage card fields.
2. Add the matching pretty-URL rewrite in `.htaccess`
   (e.g. `^doge-testnet/?$ core-faucet.php?coin=doge`).
3. Add its payout table (e.g. `tdoge_payouts`) and IP index to
   [db/create_db.py](db/create_db.py), and the table name to
   [db/cleanup.php](db/cleanup.php).
4. Set `<coin>_rpc_user` / `<coin>_rpc_pass` (and optionally `donate_t<coin>`)
   in `config.php`, and run the daemon (e.g. `dogecoind -testnet`).

## Security notes

- Keep `config.php` and the SQLite DB out of the repo and out of the web root.
- Bind `monero-wallet-rpc` to `127.0.0.1` (or use real `--rpc-login` creds). A
  reachable, unauthenticated wallet RPC can be drained directly.
- On SELinux systems (Fedora/RHEL), the DB directory needs the
  `httpd_sys_rw_content_t` context for Apache to write it.
- Rate limiting trusts `CF-Connecting-IP` (falling back to `REMOTE_ADDR`);
  `X-Forwarded-For` / `Client-IP` are ignored. For that header to be
  unspoofable, the origin must only be reachable through Cloudflare: firewall it
  to Cloudflare's IP ranges (https://www.cloudflare.com/ips) and/or enable
  Authenticated Origin Pulls. Otherwise a direct-to-origin request can forge it.

## Tor / onion service (optional)

Serving the faucet as a Tor onion service suits the privacy-minded audience, but
a few things differ from clearnet:

- **torrc** (Tor on the same box):

  ```
  HiddenServiceDir /var/lib/tor/cypherfaucet/
  HiddenServicePort 80 127.0.0.1:80
  ```

- **Strip `CF-Connecting-IP` on the onion vhost.** Onion requests reach the
  origin directly (not through Cloudflare), so an onion client could inject a
  forged `CF-Connecting-IP` and grief clearnet IP rate limits. On the vhost that
  serves the onion, unset the inbound header:

  ```
  RequestHeader unset CF-Connecting-IP
  RequestHeader unset X-Forwarded-For
  ```

  With no `CF-Connecting-IP`, the engine treats the request as Tor and limits by
  payout address only (IP limiting is meaningless when every visitor shares the
  local daemon's `127.0.0.1`).
- **Advertise it on clearnet** with the `Onion-Location` header (commented
  template in `.htaccess`, ideally on your clearnet vhost). Verify with
  `curl -I https://yoursite/` that it actually reaches the client; Cloudflare can
  strip unknown response headers.
- **Captcha.** Cloudflare Turnstile is a third-party JS + `siteverify` dependency:
  the origin needs outbound HTTPS to `challenges.cloudflare.com`, the `.onion`
  host must be allow-listed on the Turnstile widget, and `expected_host` (if set)
  is skipped automatically over Tor. Turnstile works in Tor Browser on the
  default (Standard) security level; the Safer/Safest levels disable JS and can't
  complete it (no JS captcha can). If Cloudflare ever starts blocking your exit
  nodes, a self-hosted proof-of-work captcha is the fallback.
- **Abuse tradeoff.** Because IP limiting can't work over Tor, the only gates are
  the captcha and the per-address window, and addresses are free to generate.
  That's fine for testnet coins (no value); **do not enable Tor on a fork holding
  mainnet funds** without adding a real per-identity limit.

## Node status (optional)

`status/` ships a small router that reads this faucet's catalog (`faucets.php`)
and `config.php`, so the node list and RPC details stay in sync. Drop
[simple-node-dashboard](https://github.com/Tech1k/simple-node-dashboard)'s
`index.php` in as `status/dashboard.php` (gitignored, so `git pull` it there to
update) and you get:

- `/status/` is a landing page listing every node;
- `/status/<slug>` is that node's full dashboard (`xmr-stagenet`, `ltc-testnet`, ...).

The router maps each faucet to its node automatically (monerod = the wallet RPC
port minus 7, no auth; Bitcoin Core = the faucet's RPC port and creds) and sets
the dashboard's `NETWORK` / `RPC_*` env per node. Then point each faucet's
`status_<net>` key in `config.php` at `/status/<slug>` so the faucet's `Network:`
line links to it. The dashboard already shows only a peer *count* (no IPs);
uncomment `SHOW_NODE_INFO=false` in `status/index.php` to also hide the node
version on a public deployment. `status/.htaccess` 404s direct access to
`dashboard.php` and the cache files; that relies on mod_rewrite, so on a
non-Apache stack reproduce the equivalent rule or keep them outside the web root.

## Developer API

Two ways to send testnet coins programmatically, both **off by default**: a
`?address=` **prefill link** for handing a person off (e.g. from a wallet), and a
keyless **JSON API** for automation (CI, integration tests, tooling).

### Prefill links (the human flow)

Link someone to a faucet page with their address in `?address=` and the claim box
arrives pre-filled, and they solve the captcha and click. No integration, just an
`<a href>`, on every faucet page:

```
https://cypherfaucet.com/ltc-testnet?address=tltc1q...
https://cypherfaucet.com/xmr-stagenet?address=5...
```

The prefill is display-only: captcha, validation, and rate limits still apply on
submit, and it survives a failed claim (the field keeps what was entered).

### JSON API

[api.php](api.php) serves a keyless JSON API at `/api/v1/`, sharing the website's
rate-limit tables and the canonical claim path ([faucet_lib.php](faucet_lib.php)).
Enable it in `config.php`:

```php
'api_enabled'      => true,
'api_daily_cap'    => 0,   // faucet-wide claims per net per 24h (0 = unlimited)
'api_ip_daily_cap' => 25,  // per-IP claims per 24h (a CI host funds several wallets)
```

- `GET /api/v1/info`: payout, claim window, and (cached) balance per faucet;
  `?network=<slug>` filters to one. Never hits a node.
- `POST /api/v1/claim`: body `{"network":"<slug>","address":"<addr>"}`; sends one
  payout and returns the txid (plus the Monero `tx_key` for proof).
- `GET /api/v1/`: endpoint index.

```sh
curl -X POST https://cypherfaucet.com/api/v1/claim \
  -H 'Content-Type: application/json' \
  -d '{"network":"ltc-testnet","address":"tltc1q..."}'
```

Slugs are the URL slugs (`xmr-stagenet`, `xmr-testnet`, `ltc-testnet`,
`btc-testnet`). Errors are `{"ok":false,"error":"<code>",...}` with a matching
HTTP status (`400` invalid, `409` empty, `429` rate-limited, `503` node-busy).

The API has no captcha, so the limits carry the load: one claim per **address**
per window (shared with the website), a per-**IP** daily budget (so a CI host can
fund several wallets without the site's 1/hour/IP limit), and the optional
faucet-wide cap. Put a Cloudflare WAF rate-limit on `/api/` as the outer layer.

## License

Copyright (C) 2025-2026 Tech1k

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or (at your option) any
later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License along
with this program. If not, see <https://www.gnu.org/licenses/>.

### Forking

If you run your own instance, change the operator-specific bits (they're
hardcoded, not in config): the domain in the `canonical` / `og:` / `twitter:`
meta and in `sitemap.xml` / `robots.txt`, the footer attribution, the operator
and contact details in `legal.php`, the PGP identity in `contact.php`,
`SECURITY.md`, and `tech1k.txt`, and the explorer URLs in `faucets.php` (the XMR
entries point at the reference deployment's explorers). Secrets, RPC creds, and donation details
(address, OpenAlias, QR path) live in `config.php`; if you enable donations,
replace the committed `assets/images/*-qr.png` with your own (the shipped ones
encode the operator's addresses).

Issues and pull requests are welcome.
