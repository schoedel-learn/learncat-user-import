<?php
/**
 * LCUI_Field_Registry
 *
 * Central catalog of every importable CSV column.
 * Each entry maps a column header slug to a descriptor:
 *
 *   [
 *     'label'    => 'Human label',
 *     'section'  => 'wp_core|buddyboss|woocommerce|learndash',
 *     'handler'  => 'method_name_on_LCUI_Row_Importer',   // OR callable
 *     'required' => bool,
 *     'note'     => 'optional admin-facing hint',
 *   ]
 *
 * Columns present in the CSV but absent from this registry are silently
 * skipped — no error, no import.
 */
class LCUI_Field_Registry {

	/** @var array<string,array> */
	private static array $fields = [];

	/** @var bool */
	private static bool $built = false;

	// ── Public API ────────────────────────────────────────────────────────────

	public static function all(): array {
		self::build();
		return self::$fields;
	}

	public static function get( string $slug ): ?array {
		self::build();
		return self::$fields[ $slug ] ?? null;
	}

	/**
	 * Return valid values for a constrained field.
	 *
	 * @param string $slug  Column slug.
	 * @return array  Valid value strings, or empty array for open-text fields.
	 */
	public static function get_valid_values( string $slug ): array {
		self::build();
		$def = self::$fields[ $slug ] ?? null;
		if ( ! $def ) {
			return [];
		}

		// Role field
		if ( $slug === 'role' ) {
			return array_keys( wp_roles()->roles );
		}

		// send_user_notification
		if ( $slug === 'send_user_notification' ) {
			return [ 'yes', 'no' ];
		}

		// BuddyBoss member type
		if ( $slug === 'bp_member_type' && function_exists( 'bp_get_member_types' ) ) {
			return array_keys( bp_get_member_types() );
		}

		// XProfile constrained types
		if ( ! empty( $def['xprofile_field_id'] ) && in_array( $def['xprofile_type'] ?? '', [ 'selectbox', 'radio', 'checkbox', 'multiselectbox' ], true ) ) {
			$field   = new BP_XProfile_Field( $def['xprofile_field_id'] );
			$options = $field->get_children();
			if ( empty( $options ) || ! is_array( $options ) ) {
				return [];
			}
			return wp_list_pluck( $options, 'name' );
		}

		// LearnDash enrollment columns
		if ( strpos( $slug, 'ld_enroll_' ) === 0 ) {
			return [ 'yes', 'no' ];
		}

		// Billing / shipping country
		if ( in_array( $slug, [ 'billing_country', 'shipping_country' ], true ) && function_exists( 'WC' ) ) {
			return array_keys( WC()->countries->get_countries() );
		}

		// Billing / shipping state
		if ( in_array( $slug, [ 'billing_state', 'shipping_state' ], true ) && function_exists( 'WC' ) ) {
			$states = WC()->countries->get_states( 'US' );
			return $states ? array_keys( $states ) : [];
		}

		return [];
	}

	public static function sections(): array {
		self::build();
		$out = [];
		foreach ( self::$fields as $slug => $def ) {
			$out[ $def['section'] ][ $slug ] = $def;
		}
		return $out;
	}

	// ── Internal build ────────────────────────────────────────────────────────

	private static function build(): void {
		if ( self::$built ) {
			return;
		}
		self::$built = true;

		// ── 1. WordPress Core ─────────────────────────────────────────────────
		$wp_core = [
			'user_login'    => [ 'label' => 'Username (login)',        'required' => true,  'note' => 'Must be unique. Used to match existing users.' ],
			'user_email'    => [ 'label' => 'Email address',           'required' => true,  'note' => 'Must be unique.' ],
			'user_pass'     => [ 'label' => 'Password (plain text)',   'required' => false, 'note' => 'If blank, a random password is generated and emailed.' ],
			'first_name'    => [ 'label' => 'First name',              'required' => false ],
			'last_name'     => [ 'label' => 'Last name',               'required' => false ],
			'display_name'  => [ 'label' => 'Display name',            'required' => false ],
			'user_nicename' => [ 'label' => 'Nicename (URL slug)',      'required' => false ],
			'user_url'      => [ 'label' => 'Website URL',             'required' => false ],
			'description'   => [ 'label' => 'Biographical info',       'required' => false ],
			'user_registered' => [ 'label' => 'Registration date (YYYY-MM-DD HH:MM:SS)', 'required' => false ],
			'role'          => [ 'label' => 'WordPress role',          'required' => false, 'note' => 'e.g. subscriber, editor, group_leader. Separate multiple roles with |' ],
			'send_user_notification' => [ 'label' => 'Send new-user email? (yes/no)', 'required' => false, 'note' => 'Defaults to no.' ],
		];
		foreach ( $wp_core as $slug => $def ) {
			self::register( $slug, 'wp_core', $def );
		}

		// ── 2. BuddyBoss / BuddyPress Extended Profile (XProfile) ────────────
		// Dynamically load all fields from the live DB so new fields are auto-discovered.
		if ( function_exists( 'bp_is_active' ) && bp_is_active( 'xprofile' ) ) {
			global $wpdb;
			$xprofile_fields = $wpdb->get_results(
				"SELECT f.id, f.name, f.type, g.name AS group_name
				 FROM {$wpdb->prefix}bp_xprofile_fields f
				 JOIN {$wpdb->prefix}bp_xprofile_groups g ON g.id = f.group_id
				 WHERE f.parent_id = 0
				 ORDER BY f.group_id, f.field_order",
				ARRAY_A
			);
			foreach ( $xprofile_fields as $xf ) {
				$slug = 'xprofile_' . $xf['id'];
				self::register( $slug, 'buddyboss', [
					'label'     => $xf['name'] . ' [XProfile]',
					'required'  => false,
					'note'      => 'XProfile field ID ' . $xf['id'] . ' / type: ' . $xf['type'] . ' / group: ' . $xf['group_name'],
					'xprofile_field_id' => (int) $xf['id'],
					'xprofile_type'     => $xf['type'],
				] );
			}
		}

		// BuddyBoss member type (term-based since BuddyBoss 1.x)
		self::register( 'bp_member_type', 'buddyboss', [
			'label'    => 'BuddyBoss member type',
			'required' => false,
			'note'     => 'Slug of the member type (e.g. "catechist"). Separate multiple with |',
		] );

		// ── 3. WooCommerce Billing ────────────────────────────────────────────
		$wc_billing = [
			'billing_first_name' => 'Billing first name',
			'billing_last_name'  => 'Billing last name',
			'billing_company'    => 'Billing company / school',
			'billing_address_1'  => 'Billing address line 1',
			'billing_address_2'  => 'Billing address line 2',
			'billing_city'       => 'Billing city',
			'billing_state'      => 'Billing state (2-letter code)',
			'billing_postcode'   => 'Billing ZIP / postcode',
			'billing_country'    => 'Billing country (2-letter code, e.g. US)',
			'billing_email'      => 'Billing email',
			'billing_phone'      => 'Billing phone',
			'billing_diocese'    => 'Billing diocese (custom)',
		];
		foreach ( $wc_billing as $slug => $label ) {
			self::register( $slug, 'woocommerce', [ 'label' => $label, 'required' => false ] );
		}

		// ── 4. WooCommerce Shipping ───────────────────────────────────────────
		$wc_shipping = [
			'shipping_first_name' => 'Shipping first name',
			'shipping_last_name'  => 'Shipping last name',
			'shipping_company'    => 'Shipping company',
			'shipping_address_1'  => 'Shipping address line 1',
			'shipping_address_2'  => 'Shipping address line 2',
			'shipping_city'       => 'Shipping city',
			'shipping_state'      => 'Shipping state (2-letter code)',
			'shipping_postcode'   => 'Shipping ZIP / postcode',
			'shipping_country'    => 'Shipping country (2-letter code)',
			'shipping_phone'      => 'Shipping phone',
		];
		foreach ( $wc_shipping as $slug => $label ) {
			self::register( $slug, 'woocommerce', [ 'label' => $label, 'required' => false ] );
		}

		// ── 5. LearnDash ─────────────────────────────────────────────────────
		// Course enrollment — discovered from live courses
		if ( function_exists( 'learndash_get_course_list' ) ) {
			$courses = get_posts( [
				'post_type'      => 'sfwd-courses',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			] );
			foreach ( $courses as $course_id ) {
				$slug = 'ld_enroll_course_' . $course_id;
				self::register( $slug, 'learndash', [
					'label'     => 'Enroll in: ' . get_the_title( $course_id ) . ' (ID ' . $course_id . ')',
					'required'  => false,
					'note'      => 'yes = enroll, no or blank = skip. Course ID: ' . $course_id,
					'ld_course_id' => $course_id,
				] );
			}
		}

		// Group enrollment — discovered from live groups
		if ( function_exists( 'learndash_get_groups_administrator_user_groups' ) || post_type_exists( 'groups' ) ) {
			$groups = get_posts( [
				'post_type'      => 'groups',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
			] );
			foreach ( $groups as $group_id ) {
				$slug = 'ld_enroll_group_' . $group_id;
				self::register( $slug, 'learndash', [
					'label'     => 'Add to group: ' . get_the_title( $group_id ) . ' (ID ' . $group_id . ')',
					'required'  => false,
					'note'      => 'yes = add to group, no or blank = skip. Group ID: ' . $group_id,
					'ld_group_id' => $group_id,
				] );
			}
		}

		// Allow third-party plugins / mu-plugins to register additional fields.
		do_action( 'lcui_register_fields' );
	}

	/**
	 * Register a field. Called internally and from do_action('lcui_register_fields').
	 */
	public static function register( string $slug, string $section, array $def ): void {
		self::$fields[ $slug ] = array_merge(
			[ 'section' => $section, 'required' => false, 'note' => '' ],
			$def,
			[ 'slug' => $slug, 'section' => $section ]
		);
	}
}
