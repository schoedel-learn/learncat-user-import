<?php
/**
 * LCUI_Row_Importer
 *
 * Processes a single CSV row. For each known column it dispatches to the
 * appropriate handler. Unknown columns are silently ignored.
 *
 * Strategy:
 *   - If user_login or user_email matches an existing user → UPDATE
 *   - Otherwise → CREATE
 *   - All writes are wrapped in an importable result object so the admin
 *     page can display a per-row summary.
 */
class LCUI_Row_Importer {

	/** @var array  The raw row data */
	private array $row;

	/** @var array  Suppression options from admin form */
	private array $suppress_options;

	/** @var int|null  WP user ID once resolved */
	private ?int $user_id = null;

	/** @var bool  True if this is a new user */
	private bool $is_new = false;

	/** @var string[] */
	private array $log = [];

	/** @var string[] */
	private array $errors = [];

	/** @var string[] Slugs to skip due to validation errors */
	private array $skip_slugs = [];

	// ── Public API ────────────────────────────────────────────────────────────

	public function __construct( array $row, array $suppress_options = [] ) {
		$this->row              = $row;
		$this->suppress_options = $suppress_options;
	}

	/**
	 * Execute the import for this row.
	 *
	 * @return array{user_id: int|null, is_new: bool, log: string[], errors: string[], warnings: string[]}
	 */
	public function run(): array {
		// Run validation before any writes
		$validator  = new LCUI_Row_Validator( $this->row );
		$validation = $validator->validate();

		// Fold warnings into log with ⚠️ prefix
		$warnings = [];
		foreach ( $validation['warnings'] as $w ) {
			$this->log[] = '⚠️ ' . $w;
			$warnings[]  = $w;
		}

		// If validation says skip entire row (e.g. bad email), stop here
		if ( $validation['skip_row'] ) {
			foreach ( $validation['errors'] as $e ) {
				$this->errors[] = $e;
			}
			return [
				'user_id'  => $this->user_id,
				'is_new'   => $this->is_new,
				'log'      => $this->log,
				'errors'   => $this->errors,
				'warnings' => $warnings,
			];
		}

		// Cell-level errors: record them and mark slugs to skip
		foreach ( $validation['errors'] as $e ) {
			$this->errors[] = $e;
		}
		$this->skip_slugs = $validation['skip_slugs'];

		$this->resolve_or_create_user();

		if ( $this->user_id && empty( $this->errors ) ) {
			$this->import_wp_core_meta();
			$this->import_buddyboss();
			$this->import_woocommerce();
			$this->import_learndash();
		}

		return [
			'user_id'  => $this->user_id,
			'is_new'   => $this->is_new,
			'log'      => $this->log,
			'errors'   => $this->errors,
			'warnings' => $warnings,
		];
	}

	// ── Step 1: Resolve or create user ───────────────────────────────────────

	private function resolve_or_create_user(): void {
		$login = $this->val( 'user_login' );
		$email = $this->val( 'user_email' );

		if ( ! $login && ! $email ) {
			$this->errors[] = 'Row ' . $this->row['_row_number'] . ': both user_login and user_email are blank — skipped.';
			return;
		}

		// Try to find existing user
		$existing = null;
		if ( $login ) {
			$existing = get_user_by( 'login', $login );
		}
		if ( ! $existing && $email ) {
			$existing = get_user_by( 'email', $email );
		}

		if ( $existing ) {
			$this->user_id = $existing->ID;
			$this->is_new  = false;
			$this->update_wp_core_user( $existing );
		} else {
			$this->create_wp_core_user();
		}
	}

	private function update_wp_core_user( WP_User $existing ): void {
		$args = [ 'ID' => $existing->ID ];

		$map = [
			'user_email'    => 'user_email',
			'user_url'      => 'user_url',
			'display_name'  => 'display_name',
			'user_nicename' => 'user_nicename',
			'description'   => 'description',
		];

		foreach ( $map as $col => $wp_key ) {
			$v = $this->val( $col );
			if ( $v !== '' ) {
				$args[ $wp_key ] = $v;
			}
		}

		$pass = $this->val( 'user_pass' );
		if ( $pass !== '' ) {
			$args['user_pass'] = $pass;
		}

		$registered = $this->val( 'user_registered' );
		if ( $registered !== '' ) {
			$args['user_registered'] = $registered;
		}

		if ( count( $args ) > 1 ) { // more than just 'ID'
			$result = wp_update_user( $args );
			if ( is_wp_error( $result ) ) {
				$this->errors[] = 'wp_update_user: ' . $result->get_error_message();
			} else {
				$this->log[] = 'Updated WP core user (ID ' . $existing->ID . ')';
			}
		} else {
			$this->log[] = 'Matched existing user (ID ' . $existing->ID . ') — no core field changes.';
		}

		// Roles
		$this->apply_roles( $existing->ID );

		// first_name / last_name go via update_user_meta
		foreach ( [ 'first_name', 'last_name' ] as $k ) {
			$v = $this->val( $k );
			if ( $v !== '' ) {
				update_user_meta( $existing->ID, $k, $v );
			}
		}
	}

	private function create_wp_core_user(): void {
		$email = $this->val( 'user_email' );
		$login = $this->val( 'user_login' );

		if ( ! $login ) {
			// Generate login from email
			$login = sanitize_user( strstr( $email, '@', true ), true );
			if ( username_exists( $login ) ) {
				$login .= '_' . wp_rand( 100, 999 );
			}
		}

		$pass = $this->val( 'user_pass' );
		if ( ! $pass ) {
			$pass = wp_generate_password( 16, true, false );
		}

		$args = [
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => $pass,
		];

		$optional = [
			'first_name', 'last_name', 'display_name',
			'user_nicename', 'user_url', 'description', 'user_registered',
		];
		foreach ( $optional as $k ) {
			$v = $this->val( $k );
			if ( $v !== '' ) {
				$args[ $k ] = $v;
			}
		}

		$send_notification = strtolower( $this->val( 'send_user_notification' ) );
		$notify            = in_array( $send_notification, [ 'yes', '1', 'true' ], true );

		// Global suppress overrides the per-row CSV column.
		if ( ! empty( $this->suppress_options['suppress_wp_new_user'] ) ) {
			$notify = false;
		}

		$result = wp_insert_user( $args );

		if ( is_wp_error( $result ) ) {
			$this->errors[] = 'wp_insert_user: ' . $result->get_error_message();
			return;
		}

		$this->user_id = $result;
		$this->is_new  = true;
		$this->log[]   = 'Created new user (ID ' . $result . ')';

		if ( $notify ) {
			wp_send_new_user_notifications( $result, 'user' );
			$this->log[] = 'New-user notification email sent.';
		}

		$this->apply_roles( $result );
	}

	private function apply_roles( int $user_id ): void {
		$role_raw = $this->val( 'role' );
		if ( $role_raw === '' ) {
			return;
		}

		$roles = array_filter( array_map( 'trim', explode( '|', $role_raw ) ) );
		if ( empty( $roles ) ) {
			return;
		}

		$user = new WP_User( $user_id );
		// Remove existing roles first so we set exactly what the CSV says
		$user->set_role( '' );
		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}
		$this->log[] = 'Set roles: ' . implode( ', ', $roles );
	}

	// ── Step 2: WP core meta (first/last name already handled above) ─────────

	private function import_wp_core_meta(): void {
		// Nothing additional beyond what update/create already handles.
	}

	// ── Step 3: BuddyBoss XProfile ───────────────────────────────────────────

	private function import_buddyboss(): void {
		if ( ! function_exists( 'xprofile_set_field_data' ) ) {
			return;
		}

		$registry = LCUI_Field_Registry::all();

		foreach ( $this->row as $slug => $value ) {
			if ( strpos( $slug, 'xprofile_' ) !== 0 ) {
				continue;
			}
			if ( $value === '' ) {
				continue;
			}
			if ( in_array( $slug, $this->skip_slugs, true ) ) {
				continue;
			}
			$def = $registry[ $slug ] ?? null;
			if ( ! $def || empty( $def['xprofile_field_id'] ) ) {
				continue;
			}

			$field_id = (int) $def['xprofile_field_id'];

			// For multi-value types (checkbox, multiselectbox) split by |
			if ( in_array( $def['xprofile_type'] ?? '', [ 'checkbox', 'multiselectbox' ], true ) ) {
				$value = array_map( 'trim', explode( '|', $value ) );
			}

			$ok = xprofile_set_field_data( $field_id, $this->user_id, $value );
			if ( $ok ) {
				$this->log[] = 'XProfile field ' . $field_id . ' set.';
			} else {
				$this->errors[] = 'XProfile field ' . $field_id . ': xprofile_set_field_data() returned false.';
			}
		}

		// BuddyBoss member type
		$member_type = $this->val( 'bp_member_type' );
		if ( $member_type !== '' && function_exists( 'bp_set_member_type' ) && ! in_array( 'bp_member_type', $this->skip_slugs, true ) ) {
			$types = array_filter( array_map( 'trim', explode( '|', $member_type ) ) );
			// bp_set_member_type replaces all; set first, add rest
			$first = array_shift( $types );
			bp_set_member_type( $this->user_id, $first );
			foreach ( $types as $mt ) {
				bp_set_member_type( $this->user_id, $mt, true );
			}
			$this->log[] = 'Member type(s) set: ' . $member_type;
		}
	}

	// ── Step 4: WooCommerce ───────────────────────────────────────────────────

	private function import_woocommerce(): void {
		$wc_keys = [
			'billing_first_name', 'billing_last_name', 'billing_company',
			'billing_address_1',  'billing_address_2', 'billing_city',
			'billing_state',      'billing_postcode',  'billing_country',
			'billing_email',      'billing_phone',     'billing_diocese',
			'shipping_first_name','shipping_last_name','shipping_company',
			'shipping_address_1', 'shipping_address_2','shipping_city',
			'shipping_state',     'shipping_postcode', 'shipping_country',
			'shipping_phone',
		];

		foreach ( $wc_keys as $key ) {
			$v = $this->val( $key );
			if ( $v !== '' ) {
				update_user_meta( $this->user_id, $key, $v );
				$this->log[] = 'WC meta ' . $key . ' set.';
			}
		}
	}

	// ── Step 5: LearnDash ────────────────────────────────────────────────────

	private function import_learndash(): void {
		if ( ! function_exists( 'ld_update_course_access' ) ) {
			return;
		}

		$registry = LCUI_Field_Registry::all();

		foreach ( $this->row as $slug => $value ) {
			if ( strpos( $slug, 'ld_enroll_' ) !== 0 ) {
				continue;
			}

			if ( in_array( $slug, $this->skip_slugs, true ) ) {
				continue;
			}

			$def = $registry[ $slug ] ?? null;
			if ( ! $def ) {
				continue;
			}

			$want_enroll = in_array( strtolower( $value ), [ 'yes', '1', 'true' ], true );
			if ( ! $want_enroll ) {
				continue;
			}

			// Course enrollment
			if ( ! empty( $def['ld_course_id'] ) ) {
				$course_id = (int) $def['ld_course_id'];
				ld_update_course_access( $this->user_id, $course_id );
				$this->log[] = 'Enrolled in course ID ' . $course_id . '.';
			}

			// Group enrollment
			if ( ! empty( $def['ld_group_id'] ) ) {
				$group_id = (int) $def['ld_group_id'];
				if ( function_exists( 'ld_update_group_access' ) ) {
					ld_update_group_access( $this->user_id, $group_id );
					$this->log[] = 'Added to group ID ' . $group_id . '.';
				}
			}
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function val( string $key ): string {
		return isset( $this->row[ $key ] ) ? trim( (string) $this->row[ $key ] ) : '';
	}
}
