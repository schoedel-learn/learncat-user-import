=== LearnCAT User Import ===
Contributors:      bschoedel
Tags:              users, import, csv, buddyboss, learndash, woocommerce, bulk
Requires at least: 6.0
Tested up to:      6.8
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Bulk import users via CSV into WordPress, BuddyBoss XProfile, WooCommerce, and LearnDash.

== Description ==

LearnCAT User Import provides a single admin screen under **Users → Bulk Import** where
you can upload a CSV file to create or update user accounts across the full stack:

* **WordPress core** — login, email, password, name, role(s), website URL, bio
* **BuddyBoss / XProfile** — every Extended Profile field is auto-discovered from your site
* **WooCommerce** — all billing and shipping address fields, including custom fields (diocese)
* **LearnDash** — course enrollment and group membership

**Key behaviours**

* Unknown CSV columns are silently skipped — no error, no partial import.
* Blank cells never overwrite existing data.
* If `user_login` or `user_email` matches an existing account the user is **updated**.
  Otherwise a new account is **created**.
* A **Dry Run** mode lets you preview what would happen before any data is written.
* **Notification Controls** — opt-in suppression of WP, WooCommerce, LearnDash, BuddyBoss, and Uncanny Owl notifications during import.
* **XLSX Template** — download a spreadsheet template with dropdown validation for constrained fields. Opens in Excel or Google Sheets.
* **Field Validation** — server-side validation checks constrained fields before import, showing clear warnings and errors.
* Download a fully annotated sample CSV (now with valid values listed) from the admin page.

== Installation ==

1. Upload the `learncat-user-import` folder to `/wp-content/plugins/`.
2. Activate the plugin in **Plugins → Installed Plugins**.
3. Go to **Users → Bulk Import**.

== CSV Format ==

* UTF-8 encoding (BOM optional — Excel-compatible).
* First row must be column headers (see Column Reference on the admin page).
* Subsequent rows are data. Completely blank rows are skipped.
* Separate multiple values (roles, member types, checkbox XProfile fields) with a pipe: `|`

== Frequently Asked Questions ==

= What happens if a column header doesn't match anything? =
It is silently ignored. Nothing breaks; the unrecognised data is simply not imported.

= Can I enroll users in courses and groups? =
Yes. Use columns like `ld_enroll_course_138` (for course ID 138) with the value `yes`.
The full list of available course and group columns is shown in the Column Reference on
the admin page and in the downloadable sample CSV.

= Will this send notification emails to users? =
Only if you put `yes` in the `send_user_notification` column. Default is no.
You can also use the **Notification Controls** section to globally suppress specific notification
channels (WP new-user emails, WooCommerce emails, LearnDash enrollment emails, BuddyBoss
notifications, and Uncanny Owl certificate emails) for the duration of the import.

= How do I add new custom fields in the future? =
New BuddyBoss XProfile fields are auto-discovered every time the page loads.
New courses and groups are also auto-discovered. For entirely custom meta keys, use the
`lcui_register_fields` action hook in a mu-plugin or child theme:

    add_action('lcui_register_fields', function() {
        LCUI_Field_Registry::register('my_custom_meta', 'wp_core', [
            'label' => 'My Custom Field',
        ]);
    });

== Changelog ==

= 1.2.0 =
* Added XLSX template export with dropdown validation for constrained fields (roles, member types, XProfile options, enrollment).
* Added server-side field validation with plain-English warnings and errors.
* Added "Valid Values" column to the Column Reference table.
* Enhanced sample CSV description row with valid values for constrained fields.
* Fixed incorrect LearnDash hook names in notification suppression.
* Removed phantom WooCommerce function reference.

= 1.1.0 =
* Added opt-in Notification Controls section with 6 suppression checkboxes (all off by default).
* Suppress WP new-user, WooCommerce New Account / Processing Order, LearnDash enrollment, BuddyBoss in-app, and Uncanny Owl certificate notifications.
* New `LCUI_Notification_Manager` class with surgical hook removal and full restoration.

= 1.0.0 =
* Initial release.
* Supports WP core, BuddyBoss XProfile, WooCommerce billing/shipping, LearnDash courses & groups.
* Dry-run mode.
* Auto-discovers XProfile fields, courses, and groups from the live site.
* Extensible via `lcui_register_fields` action.
