# Pulse — Website & API Monitor

A local-first uptime monitor built with **PHP, vanilla JavaScript and SQL**. Track HTTP availability, response times and incident recovery through an authenticated dashboard, with optional private-LAN access.

Built by [Moisés Gorisch](https://github.com/MoisesGorisch) as a portfolio project connecting IT operations, web development and automation.

![Pulse dashboard with clearly labeled fictional data](https://raw.githubusercontent.com/MoisesGorisch/uptime-monitor/18edc962145c53ecc46c0c7de203357e1b415f52/docs/dashboard.jpg)

## Features

- Edit monitor settings, pause/resume checks while preserving history, and permanently delete a monitor with its history after typing its exact name.
- Mandatory administrator login for real monitoring data, with first-use setup on the server computer and no default password.
- Server-side sessions, logout, expiration and persistent login-attempt throttling.
- Loopback-only access by default; explicit `-Lan` option for private IPv4 networks.

- Create up to 20 monitors with a URL, check interval, slow-response threshold and optional expected response text.
- Perform manual checks or run the CLI collector on a schedule.
- See the latest state, sample-based availability and recent response-time history.
- Open an incident on failure and close it when the endpoint recovers, including slow but successful responses.
- Explore a separate, read-only demonstration with fictional data using `?demo=1`.
- Use SQLite for a zero-setup local database; PDO MySQL configuration is also available.
- Responsive dashboard in English (default), Brazilian Portuguese, Spanish, Japanese, Simplified Chinese and Russian; language preference saved in the browser.
- Compact service list, state/search filters and expandable diagnostic history.
- Light/dark themes with a local browser preference and system-theme fallback.
- Stale-data warnings when checks stop arriving; failed connections do not show misleading response times.
- System fonts and local assets only; no external frontend requests.

## Requirements

- A maintained PHP 8.2+ release with cURL and PDO SQLite (or PDO MySQL). Local review used PHP 8.4.26.
- A modern browser.
- No Composer, npm packages or frontend build step.

## Run on Windows

Open PowerShell in the project directory:

```powershell
.\Start.ps1
# Or select your PHP installation:
.\Start.ps1 -PhpPath 'C:\path\to\php.exe' -Port 8787
```

Visit **http://127.0.0.1:8787**. The script starts the web server and a collector that checks for due monitors every 15 seconds. Keep the terminal open. A monitor is first checked on the next collector pass; later checks follow its configured interval. Closing the launcher stops automatic checks.

On the first visit, create an administrator username and password **on the server computer**. Passwords must contain 12–72 bytes and are stored only as salted password hashes. Once configured, sign in to access real data. The fictional demonstration stays available without login and never reads the real monitoring API.

Signing out or closing the browser does not stop the collector. Sessions expire after 30 minutes without an authenticated action and always after 8 hours; automatic dashboard polling does not extend the session.

### Optional private-LAN access

```powershell
.\Start.ps1 -Lan
```

This binds the server to `0.0.0.0:8787` and enables private IPv4 clients (`10/8`, `172.16/12`, `192.168/16`). Users must separately allow inbound TCP 8787 through their firewall if needed; the script does not change firewall rules. Connect using `http://<server-private-ip>:8787`. Arbitrary DNS hostnames, forwarded headers and public client IPs are not accepted. Local-only mode remains the default every time `-Lan` is omitted.

Authentication is still mandatory in LAN mode. Finish the first-use setup locally before using another computer. HTTP does not encrypt passwords or cookies: use this option only on a trusted LAN. For encrypted access, configure HTTPS with a trusted certificate; this project does not set up TLS, router port forwarding or internet hosting.

The launcher loads the required extensions independently of the machine's php.ini and does not modify the existing PHP installation. It prefers a portable PHP at `.runtime/php/php.exe`, then PHP on PATH, then `C:\SFTP\xampp\php\php.exe`. The portable runtime is ignored by Git and is not distributed with this repository. Download a current supported Windows build from [PHP](https://www.php.net/downloads.php), verify its published SHA-256 and extract it into `.runtime/php/`, or pass `-PhpPath` to your maintained installation.

### Manual / Linux setup

With the required extensions enabled:

```sh
php -S 127.0.0.1:8787 -t public router.php
# In another terminal, run once, or schedule every minute:
php bin/check.php
```

Serve **only `public/`**. Never use the repository root as the document root. The database and configuration belong outside the public directory.

## Database

By default, the app creates `var/monitor.sqlite`. Runtime files and `config.local.php` are excluded from Git.

For MySQL, create an empty database, copy `config.example.php` to `config.local.php`, then configure:

```php
<?php
return [
    'dsn' => 'mysql:host=127.0.0.1;dbname=pulse;charset=utf8mb4',
    'db_user' => 'your_database_user',
    'db_password' => 'your_database_password',
];
```

Tables are created automatically. Switching database connections does not migrate existing history. The SQLite path is tested locally; MySQL support is provided but has not yet been validated against a running MySQL server.

## Status and availability

| State | Meaning |
|---|---|
| Available | HTTP 2xx and optional expected text found, within the latency threshold |
| Slow | Successful response above the configured threshold |
| Down | HTTP error, connection/TLS/timeout failure, blocked address, oversized body or missing expected text |
| Pending | No checks recorded |

Availability is the proportion of successful samples in the last 24 hours; slow responses count as available. It is **not time-weighted uptime or an SLA measurement**. Summary availability averages each sampled monitor's percentage. The dashboard renders up to 100 recent samples per monitor; availability uses all samples in the 24-hour window. The collector retains checks for 90 days. Incidents remain until the database is managed manually.

## Network and security boundaries

- This is a **local/private-LAN administration application**, not a publicly exposed multi-user service. Source IP and Host restrictions are enforced before application routing. Do not expose it through a public reverse proxy, which could make remote requests look local.
- A single administrator account is stored in ignored `var/auth.sqlite`. The API requires a valid authenticated session; successful login regenerates the session ID and CSRF token. Sessions expire after 30 minutes without authenticated actions or 8 hours total.
- Failed logins are counted in SQLite, independent of cookies: 10 failures per IP or 30 globally within a rolling 15-minute window block further attempts. This can temporarily block legitimate access too. There is no email password recovery; protect the local authentication database and keep a backup.
- Mutations require a session-bound CSRF token. Monitor names and response messages are rendered as text, never HTML.
- JSON bodies are capped at 8 KB, text fields reject structured values, and mutation requests enforce their origin and content type.
- The local router serves an explicit list of public routes with a restrictive Content Security Policy, framing restrictions and privacy headers. Use `Start.ps1` or the documented router command; production servers require equivalent configuration.
- Only public IPv4 HTTP/HTTPS endpoints on ports 80/443 are checked. Internal, loopback, link-local, shared-address and multicast destinations are rejected.
- Hostnames are resolved before connecting and pinned with cURL's `CURLOPT_RESOLVE`; proxies and redirects are disabled. Use the final URL rather than a redirecting address.
- Requests verify TLS, time out after 10 seconds and stop after 1 MB of response body. IPv6-only targets are not supported yet.
- On Windows, supported cURL builds use the native Windows certificate store; Linux relies on its configured CA bundle. Certificate verification is never disabled.
- Requests do not carry credentials, custom headers or POST bodies. Query strings are stored with the URL; do not enter secrets in URLs.
- Shared file locking prevents concurrent collectors/manual checks. Checks run sequentially, so many slow endpoints can delay later checks.
- The frontend has no external font, stylesheet or script dependencies.

Read the scoped [security review and remaining limitations](docs/security-review.md) before changing the deployment model. A local review is not a security certification.

## Verification

```sh
php tests/run.php
node --check public/app.js
# PowerShell: with the local app running
php tests/auth.php
php tests/i18n.php
.\tests\http.ps1
```

Core tests use a disposable in-memory database. Authentication tests use a random disposable account and cover network policy, session rotation/expiry and throttling. The HTTP regression script starts its own loopback server on a free port, uses an isolated temporary authentication database, tests setup/login/logout and API protection, then stops the server and removes its fixtures. It does not create or change the real administrator account or monitoring data.

## Structure

```text
public/          Browser dashboard and local JSON API
src/             Persistence, validation and HTTP checking
bin/check.php    Scheduled CLI collector
tests/           Core regression tests
var/             Ignored local database and lock
docs/            Portfolio screenshots
```

## Roadmap

- A separate read-only status page and optional TLS deployment documentation.
- Monitor groups and maintenance windows.
- Email/webhook alerts with retry handling.
- MySQL integration tests and database migrations.
- Configurable incident confirmation thresholds.

## License

MIT — see [LICENSE](LICENSE).
