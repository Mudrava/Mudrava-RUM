=== MUDRAVA RUM ===
Contributors: mudrava
Tags: rum, performance, monitoring, web-vitals, lcp
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 0.1.8
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Real User Monitoring (RUM) plugin for WordPress that tracks TTFB, LCP, server generation time, and other performance metrics from real visitors.

== Description ==

MUDRAVA RUM captures real user performance data from your WordPress site visitors. Unlike synthetic testing tools, this plugin measures actual user experience including Time to First Byte (TTFB), Largest Contentful Paint (LCP), server generation time, total page load time, and more.

**Features:**

* Collects TTFB, LCP, server generation time, total load, memory peak, device type, network type, country, and session ID
* Live Monitor dashboard with real-time log viewing
* Filterable by session ID, URL, device type, and network type
* On-demand performance reports with averages and P75 LCP
* Scheduled email summaries (daily or weekly via WP-Cron)
* Critical TTFB alerts with configurable thresholds
* Configurable sampling rate and URL blacklist
* Role-based exclusion (skip tracking for specific user roles)
* Automatic data retention management (max records and days)
* Color-coded performance indicators (good/warning/poor)

**How It Works:**

A lightweight JavaScript collector runs on your site's frontend, gathering Core Web Vitals and performance metrics from each page view. Data is sent via the WordPress REST API and stored in a custom database table. The admin dashboard provides a Live Monitor view, filterable reports, and email summaries.

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

Yes. You can set a sampling rate (100%, 50%, or 10%), exclude specific user roles, and blacklist URL prefixes in the settings page.

= How is data stored? =

Data is stored in a custom database table with automatic retention management. You can configure the maximum number of records and retention days.

= Where can I see the reports? =

Go to Mudrava RUM > Live Monitor in your WordPress admin. Click "Generate Report" for aggregated statistics. You can also configure scheduled email reports in Settings.

== Screenshots ==

1. Live Monitor dashboard with real-time performance log.
2. Settings page with sampling, retention, and alert configuration.
3. Performance report modal with averages and top slowest pages.

== Privacy ==

**Data Collection:**

This plugin collects anonymized performance metrics (TTFB, LCP, load times, device type, network type) from site visitors. No personally identifiable information (PII) is collected or stored. Session IDs are randomly generated and not linked to user accounts.

**External Requests:**

This plugin does not send data to external third-party services. All collected data is stored locally in your WordPress database.

**Cookies:**

This plugin does not use cookies. Session IDs are stored in the browser's sessionStorage.

**Data Retention:**

Collected data is automatically purged based on your configured retention settings (maximum records and retention days).

== Changelog ==

= 0.1.8 =
* Initial public release
* Live Monitor with real-time log viewing and filtering
* Performance reports with averages and P75 LCP
* Scheduled and manual email reports
* Critical TTFB alerts with configurable thresholds
* REST API endpoints for data collection and retrieval

== Upgrade Notice ==

= 0.1.8 =
Initial release.
