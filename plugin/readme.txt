=== Mudrava RUM ===
Contributors: mudrava
Tags: monitoring, performance, rum, lcp, ttfb
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real User Monitoring (RUM) plugin for WordPress that tracks TTFB, LCP, server generation time, and other performance metrics from real visitors.

== Description ==

Mudrava RUM captures real user performance data from your WordPress site visitors. Unlike synthetic testing tools, this plugin measures actual user experience including Time to First Byte (TTFB), Largest Contentful Paint (LCP), server generation time, total page load time, and more.

**Features:**

* Collects TTFB, LCP, server generation time, total load, memory peak, device type, network type, country, and session ID
* Live Monitor dashboard with real-time log viewing
* Filterable by session ID, URL, device type, and network type
* On-demand performance reports with averages and P75 LCP
* Scheduled email summaries (daily or weekly via WP-Cron)
* One-click unsubscribe link in every report and alert, no login required
* Critical TTFB alerts with configurable thresholds
* Configurable sampling rate and URL blacklist
* Role-based exclusion (skip tracking for specific user roles)
* Automatic data retention management (max records and days)
* Color-coded performance indicators aligned with recommended performance thresholds
* Daily trend charts and CSV export
* Responsive admin interface for mobile and desktop

**How It Works:**

A lightweight JavaScript collector runs on your site's frontend, gathering LCP and navigation timing metrics from each page view, while WordPress supplies server-side response and memory measurements. Data is sent via the WordPress REST API and stored in a custom database table. The admin dashboard provides a Live Monitor view, filterable reports, and email summaries.

**Links:**

* [Plugin page](https://mudrava.com/en/projects/mudrava-rum-wordpress-plugin/)
* [GitHub repository](https://github.com/Mudrava/Mudrava-RUM)

== Installation ==

1. Upload the `mudrava-rum` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Mudrava RUM > Settings to configure sampling rate, excluded roles, and email reports.
4. Visit Mudrava RUM > Live Monitor to view incoming performance data.

== Frequently Asked Questions ==

= Does this plugin slow down my site? =

No. The JavaScript collector is lightweight and runs asynchronously after the page loads. Data is sent via a single POST request.

= What metrics are collected? =

TTFB (Time to First Byte), LCP (Largest Contentful Paint), server generation time (PHP render), total page load time, memory peak usage, device type, network type, country (via Cloudflare header), and session ID.

= Does it require Cloudflare? =

No. Country detection uses the Cloudflare CF-IPCountry header if available, but the plugin works without it. The country field will simply be empty.

= Can I control how much data is collected? =

Yes. You can set a sampling rate (100%, 50%, or 10%), exclude specific user roles, and blacklist URL prefixes in the settings page. Sampling is applied once per page render, and rate limiting protects the ingestion endpoint from flooding.

= How is data stored? =

Data is stored in a custom database table with automatic retention management. You can configure the maximum number of records and retention days.

= Where can I see the reports? =

Go to Mudrava RUM > Live Monitor in your WordPress admin. Click "Generate Report" for aggregated statistics. You can also configure scheduled email reports in Settings.

= Can a recipient stop these emails without logging in? =

Yes. Every report and every alert ends with an unsubscribe link, and the messages carry the standard List-Unsubscribe and List-Unsubscribe-Post headers, so most mail clients show their own Unsubscribe button. The link works without any login and is a signed token derived from that one address: it unsubscribes only that recipient, it does not put the address in the URL, and it cannot be guessed or reused for someone else. Automated link previews are ignored, so a mail scanner clicking the link never unsubscribes anyone.

= How do I start the emails again? =

Open Mudrava RUM > Settings. The settings page shows an "Emails are paused" notice with a Resume reports button when the configured recipient has unsubscribed. Saving a different recipient address also resumes delivery at once.

== Screenshots ==

1. Live Monitor dashboard with real-time performance log.
2. Settings page with sampling, retention, and alert configuration.
3. Performance report modal with averages and top slowest pages.

== Privacy ==

**Data Collection:**

This plugin collects performance metrics (TTFB, LCP, load times, device type, network type) from site visitors. For logged-in visitors whose role is not excluded, a non-unique role label is stored with the event. No email address, username, or other personally identifying account data is stored. Session IDs are randomly generated and are not linked to user accounts.

**External Requests:**

This plugin does not send data to external third-party services. All collected data is stored locally in your WordPress database.

**Cookies:**

This plugin does not use cookies. Session IDs are stored in the browser's sessionStorage.

**Data Retention:**

Collected data is automatically purged based on your configured retention settings (maximum records and retention days).

**Report recipients:**

Reports and alerts go to one configured address. If that recipient uses the unsubscribe link, the plugin stores only that address and the time of the opt-out, so it stops receiving emails. That entry is not linked to any user account or to any collected performance data, and it is removed when the recipient is re-subscribed or when the plugin is deleted.

== Changelog ==

= 1.1.0 =
* Added: unsubscribe link in every email. Reports and alerts now end with a signed link and carry the List-Unsubscribe and List-Unsubscribe-Post headers (RFC 8058), so a recipient can stop the emails from the message itself, without any login, including one-click unsubscribe in mail clients that support it.
* Added: Settings shows an "Emails are paused" notice with a Resume reports button when the configured recipient has unsubscribed.
* Added: developer hooks mdvrm_unsubscribed, mdvrm_resubscribed and mdvrm_email_unsubscribe_line.
* Security: the token in the link is a 160-bit HMAC of the recipient address, keyed by a per-site secret that is generated once and kept out of the autoloaded option set. No address appears in the URL, tokens are compared in constant time, forged attempts are rate limited per client address, method overrides are rejected, automated link previews never trigger an opt-out, and the response page is noindex and no-store.
* Fixed: the "Tested up to" header now uses the major version (7.1), which Plugin Check requires.
* Fixed: the integration test runner now fails a suite when any assertion reports FAIL, not only when the process exits with an error.
* Tested: five integration suites on WordPress 7.1.5 with PHP 8.3, the new opt-out suite contributing 67 assertions, plus a manual end to end pass through a real browser and a local mail catcher. Plugin Check reports no errors or warnings.

= 1.0.2 =
* Tested: verified full compatibility with WordPress 7.1.4 - ingestion, queries and stats, reports and alerts, and the tracking collector suites all pass on a fresh WordPress 7.1.4 install (PHP 8.3). PHPCS clean on PHP 7.4, 8.0 and 8.3. No changes to the runtime code.
* Fixed: the version badge, asset fallback and stored version now report the release version - the `MDVRM_VERSION` constant was left at 1.0.0 in the previous release.

= 1.0.1 =
* Security: ingestion and rate limiting hardened - trusted-proxy header trust restricted to configured proxies, CIDR validation, payload size limits, metric caps, URL/device/network normalization.
* Reliability: versioned dashboard aggregate caches, batched retention cleanup, null-safe metrics, local-day report grouping.
* UX: Live Monitor status badges with localized labels; settings shortcut in the Plugins list.
* Added developer hooks: mdvrm_log_inserted, mdvrm_report_email_subject, mdvrm_alert_email_subject.
* Tested: verified full compatibility with WordPress 7.1.1 - activation, Live Monitor, stats and settings screens, ingestion endpoint and collector smoke-tested on PHP 8.5.

= 1.0.0 =
* Full admin UI redesign: responsive layout, color-coded KPI cards, SVG trend charts, CSV export, accessible controls
* Fixed WP-Cron scheduling so housekeeping, scheduled reports, and retention purge actually run (hourly housekeeping)
* Fixed double sampling: the configured rate is now applied exactly once per pageview
* TTFB alerts now evaluate in real time on each recorded pageview instead of waiting for cron
* Scheduled email reports now cover the actual reporting period (last 24h / 7 days) instead of all retained data
* Server-side ingestion timestamp; stricter validation and clamping for all incoming metrics
* Rate limiting on the ingestion endpoint; URL/device/net/session/country whitelist validation before storage
* Fixed URL port preservation, device/network normalization, metric caps, P75 LCP calculation, and trend averages
* Added developer hooks for stored events and extensible email subjects, plus a Plugins-list settings shortcut
* Added metric indexes, faster batched FIFO/retention cleanup, and reduced dashboard aggregate-cache churn
* Fixed single-site uninstall to avoid multisite-only helpers and complete cleanup reliably
* Automated test suite (integration + REST contract tests) and stricter i18n coverage

= 0.2.0 =
* Renamed plugin from "True RUM Monitor" to "Mudrava RUM"
* Updated all prefixes, text domain, and slug to mudrava-rum / mdvrm_

= 0.1.8 =
* Initial public release
* Live Monitor with real-time log viewing and filtering
* Performance reports with averages and P75 LCP
* Scheduled and manual email reports
* Critical TTFB alerts with configurable thresholds
* REST API endpoints for data collection and retrieval

== Upgrade Notice ==

= 1.1.0 =
Every report and alert email now carries a working one-click unsubscribe link, and the settings page shows when the configured recipient has stopped receiving emails. No schema change.

= 1.0.0 =
Complete admin redesign and reliability fixes (cron, sampling, alerts, data quality). Database schema gains additional metric indexes; they are applied automatically on upgrade.

= 0.2.0 =
Renamed plugin. Updated slug, prefixes, and text domain.

= 0.1.8 =
Initial release.
