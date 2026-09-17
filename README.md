<p align="center">
  <a href="https://mudrava.com/en/projects/mudrava-rum-wordpress-plugin/">
    <img src=".wordpress-org/banner-1544x500.png" alt="Mudrava RUM — Real User Monitoring for WordPress" />
  </a>
</p>

<h1 align="center">Mudrava RUM</h1>

<p align="center">
  Real User Monitoring for WordPress — track actual visitor performance, not synthetic benchmarks.
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/mudrava-rum/"><img src="https://img.shields.io/wordpress/plugin/v/mudrava-rum" alt="WordPress plugin version"></a>
  <a href="https://wordpress.org/plugins/mudrava-rum/"><img src="https://img.shields.io/wordpress/plugin/dt/mudrava-rum" alt="WordPress plugin downloads"></a>
  <a href="https://wordpress.org/plugins/mudrava-rum/"><img src="https://img.shields.io/badge/WordPress-6.2%2B-blue?logo=wordpress" alt="WordPress 6.2+"></a>
  <img src="https://img.shields.io/badge/Tested%20up%20to-WordPress%207.1.1-21759B?logo=wordpress&logoColor=white" alt="Tested up to WordPress 7.1.1">
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4+"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-GPLv2-green" alt="GPL-2.0-or-later"></a>
  <a href="https://mudrava.com/en/"><img src="https://img.shields.io/badge/by-MUDRAVA-021D69" alt="MUDRAVA"></a>
</p>

---

## Why Mudrava RUM?

Synthetic tools like Lighthouse and PageSpeed Insights test from a single location under ideal conditions. **Mudrava RUM** captures what your real visitors actually experience — across devices, networks, and geographies.

| Synthetic Testing | Mudrava RUM |
|---|---|
| Lab environment | Real user data |
| Single location | Global visitors |
| One device profile | Mobile, tablet, desktop |
| Scheduled snapshots | Every pageview (sampled) |
| Theoretical scores | Actual TTFB, LCP, load times |

## Features

- **Real-user metrics** — TTFB, LCP, server generation time, total page load
- **Zero-config collector** — lightweight async JS, no impact on page speed
- **Live Monitor dashboard** — real-time log with sortable columns and filters
- **Performance reports** — on-demand modal with averages, P75 LCP, slowest pages
- **Email summaries** — scheduled daily/weekly via WP-Cron
- **Critical TTFB alerts** — configurable threshold, consecutive trigger, cooldown
- **Smart sampling** — 100%, 50%, or 10% traffic sampling rate
- **Privacy-first** — no account identifiers, no cookies, no external services, all data stays in your DB
- **Cache-aware** — detects and handles cached page artifacts automatically
- **Extensible** — action/filter hooks for developers

## Screenshots

> Screenshots are available on the [WordPress.org plugin page](https://wordpress.org/plugins/mudrava-rum/).

**Live Monitor** — filterable real-time performance log with color-coded metrics.

**Settings** — sampling rate, retention, excluded roles, email reports, TTFB alerts.

**Report Modal** — aggregated stats, P75 LCP, top slowest pages by LCP.

## Requirements

- WordPress 6.2+
- Tested up to WordPress 7.1.1
- PHP 7.4+
- WP REST API enabled
- WP-Cron for scheduled emails (or external cron)

## Installation

1. Upload the `mudrava-rum` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. Go to **Mudrava RUM → Settings** to configure sampling, retention, and alerts.
4. Visit **Mudrava RUM → Live Monitor** to see incoming data.

No theme edits required. The frontend collector loads automatically.

## REST API

| Endpoint | Method | Auth | Description |
|---|---|---|---|
| `/wp-json/mudrava-rum/v1/collect` | POST | Nonce | Ingestion endpoint for collector JS |
| `/wp-json/mudrava-rum/v1/logs` | GET | `manage_options` | Paginated log query with filters |
| `/wp-json/mudrava-rum/v1/stats` | GET | `manage_options` | Aggregated statistics |
| `/wp-json/mudrava-rum/v1/send-report` | POST | `manage_options` | Trigger email report |

## Hooks for Developers

```php
// Filter whether to track a request
add_filter( 'mdvrm_should_track_request', function ( $track, $settings ) {
    return $track;
}, 10, 2 );

// Modify log data before insertion
add_filter( 'mdvrm_before_insert', function ( $row ) {
    return $row;
} );

// React after an event has been stored
add_action( 'mdvrm_log_inserted', function ( $insert_id, $row ) {
    // ...
}, 10, 2 );

// Filter collector settings for frontend JS
add_filter( 'mdvrm_collector_settings', function ( $localize ) {
    return $localize;
} );

// Customize email report subject/content
add_filter( 'mdvrm_report_email_subject', function ( $subject, $recipient, $site_name, $stats ) {
    return $subject;
}, 10, 4 );

add_filter( 'mdvrm_report_email_body', function ( $body, $recipient, $avg ) {
    return $body;
}, 10, 3 );

add_filter( 'mdvrm_alert_email_subject', function ( $subject ) {
    return $subject;
} );

// Action after plugin is fully loaded
do_action( 'mdvrm_loaded', $plugin );
```

## Data Collected

| Field | Description |
|---|---|
| `event_time` | UTC timestamp |
| `url` | Page URL |
| `server_time` | PHP generation time (seconds) |
| `ttfb` | Time to First Byte (seconds) |
| `lcp` | Largest Contentful Paint (seconds) |
| `total_load` | Full page load time (seconds) |
| `memory_peak` | PHP peak memory (bytes) |
| `device` | `mobile` / `tablet` / `desktop` |
| `net` | `4g` / `3g` / `2g` / `slow-2g` |
| `country` | ISO code via CF-IPCountry header |
| `user_role` | Non-unique role label for tracked logged-in visitors |
| `session_id` | Random ID (sessionStorage, not a cookie) |

**No account identifiers are collected.** Emails, usernames, and identity profile data are not stored. No cookies are set. No data leaves your server.

## Development

```bash
# Install dev dependencies
composer install

# Run PHPCS linting
composer lint

# Auto-fix coding standard issues
composer lint:fix
```

## Contributing

Contributions are welcome. Please open an issue first to discuss proposed changes.

1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Open a Pull Request

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.

## Credits

Built by [MUDRAVA](https://mudrava.com/en/) — a digital product studio specializing in WordPress, web performance, and design systems.

- [mudrava.com](https://mudrava.com/en/)
- [support@mudrava.com](mailto:support@mudrava.com)
