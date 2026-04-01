# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.1.x   | Yes       |

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

- **No PII** — the plugin does not collect names, emails, IP addresses, or any personally identifiable information
- **No cookies** — session IDs use `sessionStorage` (per-tab, cleared on close)
- **No external services** — all data stays in your WordPress database; zero outbound requests

### Authentication

- **Admin endpoints** (`/logs`, `/stats`, `/send-report`) require `manage_options` capability via WordPress REST API authentication
- **Public endpoint** (`/collect`) uses a custom nonce (`X-MDVRM-Nonce` header) to prevent unauthorized submissions while avoiding WordPress core's premature cookie authentication checks
- **Settings forms** use standard WordPress nonce verification (`wp_nonce_field` / `wp_verify_nonce`)

### Data Handling

- All database queries use `$wpdb->prepare()` with parameterized placeholders
- All user input is sanitized via `sanitize_text_field()`, `sanitize_email()`, `absint()`, `floatval()`
- All output is escaped via `esc_html()`, `esc_attr()`, `esc_url()`, `wp_json_encode()`
- Admin JavaScript uses safe DOM methods (`createElement`, `textContent`) — no `innerHTML` with dynamic data

### Data Retention

- Configurable maximum record count (FIFO eviction)
- Configurable retention period in days (automatic purge)
- Full cleanup on plugin uninstall (table drop, options delete, cron clear)

## Scope

This policy applies to the Mudrava RUM WordPress plugin source code hosted at [github.com/Mudrava/Mudrava-RUM](https://github.com/Mudrava/Mudrava-RUM).
