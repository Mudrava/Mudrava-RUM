# Changelog

All notable changes to Mudrava RUM will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

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

[0.2.0]: https://github.com/Mudrava/Mudrava-RUM/releases/tag/v0.2.0
[0.1.8]: https://github.com/Mudrava/Mudrava-RUM/releases/tag/v0.1.8
