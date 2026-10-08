# Accessibility statement

Last reviewed: 2026-10-08.

Mudrava RUM has two halves, and they have very different accessibility
surfaces:

1. The collector (front-end) is a small async script that records timing
   metrics. It renders nothing, sets no cookies, and adds no visible or focusable
   elements to the page. It cannot interfere with a visitor's assistive
   technology.
2. The dashboard (admin) is where all interaction happens, behind the
   `manage_options` capability.

## What we implement

- The Live Monitor toolbar uses `role="toolbar"` with an `aria-label`, so its
  controls are grouped for screen readers.
- Decorative device/status icons are marked `aria-hidden="true"`.
- Metrics are presented as real text in tables and stat cards - never as
  color-only or image-only indicators. Device type is shown as a word
  ("desktop", "mobile", "tablet") alongside the icon.
- All interactive controls are native buttons, selects and inputs; keyboard
  operation follows the WordPress admin baseline.
- Every string is translatable through the standard text domain.

## Known limitations

- The Live Monitor log view refreshes on a timer; new rows appear without an
  explicit live-region announcement.
- Report charts (where present) pair each visual with a numeric table.

## Feedback

Accessibility defects are treated as bugs. Report them through the
WordPress.org support forum for this plugin or at
[support@mudrava.com](mailto:support@mudrava.com) with the screen, browser and
assistive technology you used.
