# Changelog

All notable changes to Mudrava RUM will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.1] — 2026-09-18

First release that carries the 2026-09-15 hardening work (commits after the 1.0.0 tag) to
WordPress.org, where the 1.0.0 package was cut before it landed.

### Security

- Trusted-proxy header trust restricted to configured proxy CIDRs with CIDR validation;
  payload-size limits and metric caps on ingestion; URL/device/network normalization.

### Changed

- Versioned dashboard aggregate caches, batched retention cleanup, null-safe metrics, local-day report grouping.
- Live Monitor status badges with localized labels; settings shortcut in the Plugins list.
- Verified compatibility metadata for WordPress 7.1.1: activation, Live Monitor, stats and settings
  screens, REST ingestion endpoint and front-end collector smoke-tested on PHP 8.5.

### Added

- Developer hooks: `mdvrm_log_inserted`, `mdvrm_report_email_subject`, `mdvrm_alert_email_subject`.

## [1.0.0] — 2026-09-02

### Added

- Native WP-standard admin UI: flat hairline KPI strip, unified 32px controls, custom select chevrons
- Auto-refresh rendered as a native-feeling switch chip in the toolbar row
- Device type icons (desktop/tablet/mobile) in the log table; truncated session IDs now show an ellipsis
- Clickable session IDs in the log table filter the monitor by the full session
- Custom SVG plugin icon and wp.org marketing assets (banner 1544x500 / 772x250)
- Automated test suites under `tests/` with standalone WP harness
- CI: PHPCS matrix (PHP 7.4/8.0/8.2) and syntax-check workflow
- Settings shortcut in the Plugins list
- Developer hooks: `mdvrm_log_inserted`, `mdvrm_report_email_subject`, `mdvrm_alert_email_subject`

### Changed

- Minified production assets (`mdvrm-admin.min.css/js`, `mdvrm-collector.min.js`) with filemtime cache busting
- `Tested up to` bumped to WordPress 7.1
- Regenerated `languages/mudrava-rum.pot` (GPLv2 header, slug-corrected bug reports URL)
- Upgraded non-tracked storage from "no PII" to a precise disclosure that non-unique logged-in role labels may be stored
- Reworded metric descriptions to say LCP/navigation metrics rather than implying CLS/INP coverage
- Restricted proxy peer-address trust to configured trusted proxies and HMAC-hashed rate-limit keys
- Ensured schema checks also run during REST and front-end initialization, not only admin requests
- Dashboard/trend caches now use aggregate filter keys and versioned payloads, preventing sort-state cache fragmentation and orphaned transient churn
- Cleanup now runs in 1000-row batches, and metric columns receive indexes in the release schema so fresh installs match upgraded installs
- Local-day trend grouping uses cached timezone offsets instead of creating a `DateTimeImmutable` object for every event

### Fixed

- Fatal error on the Settings page when rendering excluded roles (`translate_user_role()` given an array)
- Fatal error when saving IPv6 trusted-proxy CIDR ranges
- Session ID filter now matches by prefix (8-char IDs shown in the table) via `LIKE`
- URL sanitization preserves explicit ports and strips query/fragment values
- Device and network values are normalized to allowed values, with unsupported values stored blank
- Incoming metrics reject non-finite values and are capped by PHP/runtime limits before storage
- Server generation time is recorded on ingestion instead of page render time
- Trend averages use valid per-metric counts, and P75 LCP uses the filtered valid row count
- Duplicate sort handlers no longer accumulate; auto-refresh uses applied filters rather than unsubmitted inputs
- Recent days are retained by the trend fetch window
- Request-state tracking and settings updates are reset between integration requests to prevent stale sampling decisions
- Scheduled reports and local-day analytics use WordPress site timezone consistently
- KPI notes and status labels are localized through the admin script payload
- Missing performance metrics now display as `—` instead of `0.00s` in dashboard KPIs, trend points, report tables, and email reports
- Single-site uninstall no longer calls multisite-only blog-switch helpers; all data is removed without a fatal error

## [0.2.0] — 2026-04-01

### Changed

- Renamed plugin from "True RUM Monitor" to "Mudrava RUM"
- Updated slug to `mudrava-rum`, prefix to `mdvrm_`, text domain to `mudrava-rum`
- Updated all class names, function names, constants, hooks, and file names

## [0.1.8] — 2026-03-20

### Added

- Live Monitor dashboard with real-time performance log viewing
- Filterable logs by session ID, URL, device type, and network type
- Sortable columns (event time, TTFB, LCP, total load)
- Performance reports with averages and P75 LCP
- Top 5 slowest pages by LCP and server generation time
- Scheduled email summaries (daily or weekly via WP-Cron)
- Manual "Send Report to Email" from the admin dashboard
- Critical TTFB alerts with configurable threshold, consecutive trigger, and cooldown
- Configurable sampling rate (100%, 50%, 10%)
- Role-based exclusion from tracking
- URL blacklist (prefix-based)
- Automatic data retention management (max records + retention days)
- REST API endpoints: `/collect`, `/logs`, `/stats`, `/send-report`
- Custom nonce authentication for public `/collect` endpoint
- Device detection: mobile, tablet, desktop
- Network type detection via Navigator API
- Country detection via Cloudflare `CF-IPCountry` header
- LCP tracking via PerformanceObserver API
- Navigation Timing API v2 with v1 fallback
- Color-coded performance indicators in admin UI
- Developer hooks: `mdvrm_loaded`, `mdvrm_should_track_request`, `mdvrm_before_insert`, `mdvrm_collector_settings`, `mdvrm_report_email_body`
- Privacy-first design: no PII, no cookies, no external services
- PHPCS/WPCS coding standards configuration
- Full uninstall cleanup (table, options, cron)

[1.0.0]: https://github.com/Mudrava/Mudrava-RUM/releases/tag/v1.0.0
[0.2.0]: https://github.com/Mudrava/Mudrava-RUM/releases/tag/v0.2.0
[0.1.8]: https://github.com/Mudrava/Mudrava-RUM/releases/tag/v0.1.8
