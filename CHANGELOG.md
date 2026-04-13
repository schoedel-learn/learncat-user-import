# Changelog — LearnCAT User Import

All notable changes to this project will be documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [1.1.0] — 2026-04-13

### Added
- Opt-in **Notification Controls** section on the import form.
- Six suppression checkboxes (all unchecked by default — notifications fire as configured):
  - Suppress WP new-user notification email (`wp_new_user_notification`)
  - Suppress WooCommerce "New Account" customer email (`customer_new_account`)
  - Suppress WooCommerce "Processing Order" customer email (`customer_processing_order`)
  - Suppress LearnDash enrollment emails (`learndash_course_access_added`, `learndash_group_access_added`)
  - Suppress BuddyBoss in-app notifications (`bp_notification_after_save`)
  - Suppress Uncanny Owl certificate emails (`learndash_course_completed`)
- New `LCUI_Notification_Manager` class with surgical hook removal and full restoration after import.
- Global suppress override: when WP new-user suppression is checked, the per-row `send_user_notification` CSV column is ignored.

## [1.0.0] — 2026-04-13

### Added
- Initial release.
- Admin page under **Users → Bulk Import**.
- CSV import for WordPress core fields: login, email, password, first/last name,
  display name, nicename, URL, bio, registration date, role(s).
- BuddyBoss XProfile field import — all fields auto-discovered from live site.
- BuddyBoss member type assignment (term-based, pipe-separated for multiple types).
- WooCommerce billing fields: first name, last name, company, address 1/2, city,
  state, postcode, country, email, phone, diocese (custom).
- WooCommerce shipping fields: first name, last name, company, address 1/2, city,
  state, postcode, country, phone.
- LearnDash course enrollment — all published courses auto-discovered.
- LearnDash group enrollment — all published groups auto-discovered.
- Dry-run mode: preview what would happen without writing any data.
- Downloadable sample CSV with every known column pre-populated and annotated.
- Column reference table on the admin page (expandable).
- Unknown CSV columns silently skipped — no errors.
- Blank cells never overwrite existing user data.
- Update-or-create logic: matches on `user_login` or `user_email`.
- `lcui_register_fields` action hook for extending the field registry externally.
- Tested on WordPress 6.9.4, BuddyBoss 2.21.0, LearnDash 5.0.5,
  WooCommerce 10.6.2, PHP 8.2.
