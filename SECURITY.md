# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 1.1.x   | Yes       |
| 1.0.x   | Yes       |
| 0.x     | No        |

## Reporting a Vulnerability

If you discover a security vulnerability in Mudrava RUM, please report it responsibly.

**Do not open a public GitHub issue for security vulnerabilities.**

Instead, email us directly at **[support@mudrava.com](mailto:support@mudrava.com)** with:

- A description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

We will acknowledge your report within **48 hours** and aim to release a patch within **7 days** of confirmation.

## Security Design

Mudrava RUM is built with a privacy-first, security-conscious architecture:

### Data Collection

- **No account identifiers** - names, email addresses, IP addresses, and identity-profile data are not collected or stored in visitor records
- **Role labels only** - a non-unique logged-in visitor role may be stored for role-based filtering and exclusion
- **Report recipient** - the single configured recipient address lives in plugin settings. If that recipient unsubscribes, the address and the time of the opt-out are stored so the plugin stops emailing it; visitor records still hold no email address and the entry is removed on re-subscription or uninstall
- **No cookies** - session IDs use `sessionStorage` (per-tab, cleared on close)
- **No external services** - all data stays in your WordPress database; zero outbound requests

### Authentication

- **Admin endpoints** (`/logs`, `/stats`, `/send-report`) require `manage_options` capability via WordPress REST API authentication
- **Public endpoint** (`/collect`) uses a custom nonce (`X-MDVRM-Nonce` header) to prevent unauthorized submissions while avoiding WordPress core's premature cookie authentication checks
- **Settings forms** use standard WordPress nonce verification (`wp_nonce_field` / `wp_verify_nonce`)

### Email opt-out

Recipients stop reports and alerts through a signed link that needs no login.

- **Derived token** - `HMAC-SHA256( 'mdvrm_unsub|' + address, site secret )` truncated to 40 hex characters, 160 bits. Nothing is stored per link, so there is no token table to leak or to expire, and a token cannot be produced without the secret.
- **Separate secret** - the key is generated once into a non-autoloaded option and is not derived from `wp_salt()`, which keeps links valid across a salts rotation and keeps the signing key out of the options loaded on every request.
- **No address in the link** - a token is matched against a closed candidate set (configured recipient, admin email, already opted-out addresses) with `hash_equals()`.
- **Recipient scoped** - one link unsubscribes exactly one address, other recipients keep receiving emails, and the response page never repeats the address it acted on.
- **Preview safe** - requests carrying link preview or prefetch headers answer without changing state, so mail scanners and safe-link proxies cannot unsubscribe anyone.
- **Request hardening** - HTTP method overrides are rejected, forged tokens are counted per peer address and throttled briefly, the response is `no-store` and `noindex`, and malformed requests return 400.
- **Reversible and visible** - the paused state is shown on the settings page, and resuming it requires a nonce-checked action behind `manage_options`.

### Data Handling

- All database queries use `$wpdb->prepare()` with parameterized placeholders
- All user input is sanitized via `sanitize_text_field()`, `sanitize_email()`, `absint()`, `floatval()`
- All output is escaped via `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- Admin JavaScript uses safe DOM methods (`createElement`, `textContent`) - no `innerHTML` with dynamic data
- Oversized ingestion payloads are rejected before JSON decoding
- Peer-address headers are honored only when the direct peer matches configured trusted proxies
- Rate-limit counters use time-bucketed, HMAC-hashed identities rather than storing client addresses

### Data Retention

- Configurable maximum record count (FIFO eviction)
- Configurable retention period in days (automatic purge)
- Full cleanup on plugin uninstall (table drop, options delete, cron clear)

## Scope

This policy applies to the Mudrava RUM WordPress plugin source code hosted at [github.com/Mudrava/Mudrava-RUM](https://github.com/Mudrava/Mudrava-RUM).
