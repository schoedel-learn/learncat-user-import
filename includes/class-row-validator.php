<?php
/**
 * LCUI_Row_Validator
 *
 * Validates a single CSV row before import. Returns warnings (non-blocking)
 * and errors (blocking — the cell or row is skipped).
 *
 * All messages are plain English for the non-technical admin.
 */
class LCUI_Row_Validator {

	/** @var array The row data to validate */
	private array $row;

	/** @var int Row number for messages */
	private int $row_number;

	/** @var string[] Non-blocking warnings (cell still imported, but flagged) */
	private array $warnings = [];

	/** @var string[] Blocking errors (cell or row skipped) */
	private array $errors = [];

	/** @var string[] Slugs that failed validation and should be skipped during import */
	private array $skip_slugs = [];

	/** @var bool Whether the entire row should be skipped (set by add_fatal_error) */
	private bool $skip_row = false;

	/** @var array<string, array> Filtered/cleaned values to use instead of raw cell values */
	private array $filtered_values = [];

	public function __construct( array $row ) {
		$this->row        = $row;
		$this->row_number = (int) ( $row['_row_number'] ?? 0 );
	}

	/**
	 * Run all validation rules.
	 *
	 * @return array{warnings: string[], errors: string[], skip_slugs: string[], skip_row: bool, filtered_values: array}
	 */
	public function validate(): array {
		$this->validate_email();
		$this->validate_role();
		$this->validate_send_notification();
		$this->validate_user_registered();
		$this->validate_bp_member_type();
		$this->validate_xprofile_fields();
		$this->validate_ld_enrollment();

		return [
			'warnings'        => $this->warnings,
			'errors'          => $this->errors,
			'skip_slugs'      => $this->skip_slugs,
			'skip_row'        => $this->skip_row,
			'filtered_values' => $this->filtered_values,
		];
	}

	/**
	 * Add a fatal error that causes the entire row to be skipped.
	 */
	private function add_fatal_error( string $message ): void {
		$this->errors[]  = $message;
		$this->skip_row = true;
	}

	// ── Validators ────────────────────────────────────────────────────────────

	private function validate_email(): void {
		$email = trim( $this->row['user_email'] ?? '' );
		if ( $email === '' ) {
			return; // Empty is handled by the importer
		}
		if ( ! is_email( $email ) && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$this->add_fatal_error( "Row {$this->row_number}: The email address '{$email}' doesn't look valid. This row was skipped." );
		}
	}

	private function validate_role(): void {
		$role_raw = trim( $this->row['role'] ?? '' );
		if ( $role_raw === '' ) {
			return;
		}

		$roles       = array_filter( array_map( 'trim', explode( '|', $role_raw ) ) );
		$valid_roles = array_keys( wp_roles()->roles );
		$invalid     = array_diff( $roles, $valid_roles );

		if ( ! empty( $invalid ) ) {
			$valid_list = implode( ', ', $valid_roles );
			foreach ( $invalid as $bad ) {
				$this->warnings[] = "Row {$this->row_number}: The role '{$bad}' isn't recognized. Valid roles: {$valid_list}. The unrecognized role was ignored.";
			}
			$this->skip_slugs[] = 'role';
		}
	}

	private function validate_send_notification(): void {
		$val = strtolower( trim( $this->row['send_user_notification'] ?? '' ) );
		if ( $val === '' ) {
			return;
		}
		$allowed = [ 'yes', 'no', '1', '0', 'true', 'false' ];
		if ( ! in_array( $val, $allowed, true ) ) {
			$this->warnings[] = "Row {$this->row_number}: The notification value '{$val}' should be yes or no. It was treated as no.";
		}
	}

	private function validate_user_registered(): void {
		$val = trim( $this->row['user_registered'] ?? '' );
		if ( $val === '' ) {
			return;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', trim( $val ) ) ) {
			$this->warnings[] = "Row {$this->row_number}: The registration date '{$val}' isn't in the right format. Please use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.";
		}
	}

	private function validate_bp_member_type(): void {
		$val = trim( $this->row['bp_member_type'] ?? '' );
		if ( $val === '' || ! function_exists( 'bp_get_member_types' ) ) {
			return;
		}

		$types           = array_filter( array_map( 'trim', explode( '|', $val ) ) );
		$registered_types = array_keys( bp_get_member_types() );

		$valid_types   = [];
		$invalid_types = [];
		foreach ( $types as $type ) {
			if ( in_array( $type, $registered_types, true ) ) {
				$valid_types[] = $type;
			} else {
				$invalid_types[] = $type;
			}
		}

		if ( ! empty( $invalid_types ) ) {
			$valid_list = implode( ', ', $registered_types );
			foreach ( $invalid_types as $bad ) {
				$this->warnings[] = "Row {$this->row_number}: The member type '{$bad}' isn't recognized. Valid options: {$valid_list}. The unrecognized type was skipped.";
			}
		}

		if ( empty( $valid_types ) ) {
			$this->skip_slugs[] = 'bp_member_type';
		} else {
			// Store the cleaned valid types so the importer can use them
			$this->filtered_values['bp_member_type'] = $valid_types;
		}
	}

	private function validate_xprofile_fields(): void {
		if ( ! function_exists( 'xprofile_set_field_data' ) ) {
			return;
		}

		$registry = LCUI_Field_Registry::all();

		foreach ( $this->row as $slug => $value ) {
			if ( strpos( $slug, 'xprofile_' ) !== 0 || trim( $value ) === '' ) {
				continue;
			}

			$def = $registry[ $slug ] ?? null;
			if ( ! $def || empty( $def['xprofile_field_id'] ) ) {
				continue;
			}

			$type = $def['xprofile_type'] ?? '';
			if ( ! in_array( $type, [ 'selectbox', 'radio', 'checkbox', 'multiselectbox' ], true ) ) {
				continue;
			}

			$valid_values = LCUI_Field_Registry::get_valid_values( $slug );
			if ( empty( $valid_values ) ) {
				continue;
			}

			$label = $def['label'];
			// Strip the "[XProfile]" suffix for cleaner messages
			$label = str_replace( ' [XProfile]', '', $label );

			if ( in_array( $type, [ 'checkbox', 'multiselectbox' ], true ) ) {
				// Multi-value: check each pipe-separated value
				$parts   = array_filter( array_map( 'trim', explode( '|', $value ) ) );
				$invalid = array_diff( $parts, $valid_values );
				if ( ! empty( $invalid ) ) {
					$valid_list = implode( ', ', array_slice( $valid_values, 0, 10 ) );
					if ( count( $valid_values ) > 10 ) {
						$valid_list .= ' (+ ' . ( count( $valid_values ) - 10 ) . ' more)';
					}
					foreach ( $invalid as $bad ) {
						$this->warnings[] = "Row {$this->row_number}: The value '{$bad}' for '{$label}' isn't recognized. Valid options: {$valid_list}. The field was skipped — existing value preserved.";
					}
					$this->skip_slugs[] = $slug;
				}
			} else {
				// Single-value: selectbox / radio
				if ( ! in_array( trim( $value ), $valid_values, true ) ) {
					$valid_list = implode( ', ', array_slice( $valid_values, 0, 10 ) );
					if ( count( $valid_values ) > 10 ) {
						$valid_list .= ' (+ ' . ( count( $valid_values ) - 10 ) . ' more)';
					}
					$this->warnings[] = "Row {$this->row_number}: The value '{$value}' for '{$label}' isn't recognized. Valid options: {$valid_list}. The field was skipped — existing value preserved.";
					$this->skip_slugs[] = $slug;
				}
			}
		}
	}

	private function validate_ld_enrollment(): void {
		$registry = LCUI_Field_Registry::all();
		$allowed  = [ 'yes', 'no', '1', '0', 'true', 'false', '' ];

		foreach ( $this->row as $slug => $value ) {
			if ( strpos( $slug, 'ld_enroll_' ) !== 0 ) {
				continue;
			}

			$val = strtolower( trim( $value ) );
			if ( in_array( $val, $allowed, true ) ) {
				continue;
			}

			$def   = $registry[ $slug ] ?? null;
			$label = $def ? $def['label'] : $slug;
			// Clean up the label for readability
			$label = preg_replace( '/\s*\(ID \d+\)/', '', $label );

			$this->warnings[] = "Row {$this->row_number}: The enrollment value '{$value}' for '{$label}' should be yes or no. It was skipped.";
			$this->skip_slugs[] = $slug;
		}
	}
}
