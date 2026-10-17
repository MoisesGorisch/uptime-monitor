# Security review — 2026-10-17

This is a scoped source review and local functional verification, not an independent penetration test or a guarantee of security.

## Intended deployment

One trusted user, on a local computer. The launcher binds to `127.0.0.1` and serves only an explicit set of public application routes. Other local processes and users are outside the protection boundary. Never expose this application through a public reverse proxy: the proxy can make remote requests appear to originate from loopback.

## Protections reviewed

- SQL parameters rather than interpolated user input.
- Text-only DOM rendering of monitor names, URLs and response descriptions.
- Loopback source and localhost Host restrictions on the API.
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

- No authentication, roles, per-user isolation or persistent rate limiting for remote use.
- No firewall-level egress allowlist; network validation is an application defense.
- DNS resolution happens before cURL and may exceed cURL's own transfer timeout.
- A single collector checks services sequentially; slow targets can delay later checks.
- Availability is sample-based. A single bad sample opens an incident; there is no multi-sample confirmation or SLA guarantee.
- Databases contain configured URLs in plain text. Do not include tokens or passwords in query strings. Apply normal local account/file permissions and backups.
- MySQL integration and internet deployment have not been validated.
- The PHP development server is for local development, not public production hosting.

## Verification

Core regression tests cover URL/address restrictions, malformed field types, incident deduplication/recovery, availability and scheduling. HTTP checks cover CSRF/origin rejection, content-type and body-size limits, static routing and security headers. Browser checks cover filters, details, form validation, real responses and narrow-screen layout.
