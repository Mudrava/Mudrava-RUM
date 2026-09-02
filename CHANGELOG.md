# Changelog

All notable changes to Mudrava RUM will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] — 2026-09-02

### Added

- Native WP-standard admin UI: flat hairline KPI strip, unified 32px controls, custom select chevrons
- Auto-refresh rendered as a native-feeling switch chip in the toolbar row
- Device type icons (desktop/tablet/mobile) in the log table; truncated session IDs now show an ellipsis
- Clickable session IDs in the log table filter the monitor by the full session
- Custom SVG plugin icon and wp.org marketing assets (banner 1544x500 / 772x250)
- Automated test suites under `tests/` with standalone WP harness
- CI: PHPCS matrix (PHP 7.4/8.0/8.2) and syntax-check workflow

### Changed

- Minified production assets (`mdvrm-admin.min.css/js`, `mdvrm-collector.min.js`) with filemtime cache busting
- `Tested up to` bumped to WordPress 7.1
- Regenerated `languages/mudrava-rum.pot` (GPLv2 header, slug-corrected bug reports URL)

### Fixed

- Fatal error on the Settings page when rendering excluded roles (`translate_user_role()` given an array)
- Session ID filter now matches by prefix (8-char IDs shown in the table) via `LIKE`
- Admin bar/toolbar vertical rhythm and left-edge alignment on mobile viewports

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
