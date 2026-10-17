# Security review — 2026-10-17

This is a scoped source review and local functional verification, not an independent penetration test or a guarantee of security.

## Intended deployment

One administrator, on a local computer or explicitly enabled private IPv4 LAN. The launcher binds to `127.0.0.1` by default; `Start.ps1 -Lan` opts into `0.0.0.0` with private-source and literal-private-host restrictions. It never changes the firewall. Other local processes and users with filesystem access are outside the protection boundary. Never expose this application through a public reverse proxy: the proxy can make remote requests appear to originate from loopback.

## Protections reviewed

- SQL parameters rather than interpolated user input.
- Text-only DOM rendering of monitor names, URLs and response descriptions.
- Loopback by default; optional private-LAN source and literal Host restrictions across routes. Forwarded headers are ignored.
- Mandatory session authentication for real dashboard and API, with local first-use setup and salted password hashes.
- Login session/CSRF rotation, logout invalidation, credential-version checks, 30-minute action-idle and 8-hour absolute expiry. Automatic polling does not extend authentication.
- Persistent SQLite login throttling: 10 failed attempts per source or 30 globally per rolling 15 minutes. Fresh cookies cannot reset the counters.
- Strict session mode, isolated session cookie name, HttpOnly/SameSite cookies, CSRF tokens and same-origin checks.
- Bounded JSON request reads, validated object structure, scalar text validation and HTTP method/content-type restrictions.
- Public IPv4 validation including special-purpose ranges; HTTP(S) ports 80/443 only.
- cURL address pinning, redirects/proxies disabled, certificate validation enabled, connection/transfer timeout and response-body limit.
- Restrictive CSP, anti-framing, no-referrer and permissions headers when launched with `router.php`.
- No external fonts, scripts or stylesheets in the dashboard.
- Private runtime/config/database files excluded from Git and from the router.

## Changes made during review

Expanded address exclusions and malformed-input tests; added request/session/header hardening. Removed external font requests. Added a stale-data state so an old successful check cannot be presented as current health. Failed HTTP connections no longer display zero latency as a useful response time. Replaced decorative graphs in the main dashboard with on-demand details and time-positioned samples.

The local runtime was updated separately from the existing XAMPP: official PHP 8.4.26 NTS x64, checksum verified against the PHP Windows release catalog. This portable runtime lives in ignored `.runtime/` and is not included in the repository. Other installations must supply their own maintained runtime.

## Remaining limitations

- Single administrator only; no roles, per-user isolation, MFA or email-based account recovery.
- Optional LAN mode uses HTTP unless separately configured with HTTPS. Login alone does not encrypt traffic; use only trusted networks and do not expose this development server to the internet.
- No firewall-level egress allowlist; network validation is an application defense.
- DNS resolution happens before cURL and may exceed cURL's own transfer timeout.
- A single collector checks services sequentially; slow targets can delay later checks.
- Availability is sample-based. A single bad sample opens an incident; there is no multi-sample confirmation or SLA guarantee.
- Databases contain configured URLs in plain text. Do not include tokens or passwords in query strings. Apply normal local account/file permissions and backups.
- MySQL integration and internet deployment have not been validated.
- The PHP development server is for local development, not public production hosting.

## Verification

Core regression tests cover URL/address restrictions, malformed field types, incident deduplication/recovery, availability and scheduling. Authentication tests use disposable credentials and cover network opt-in, hashed storage, session rotation/expiry and persistent retry limits. Isolated HTTP checks cover initial setup, login/logout, unauthorized API access, CSRF/origin rejection, content-type and body-size limits, static routing and security headers. The production first-use screen is checked without creating a user password. No second physical LAN device was used for end-to-end testing.
