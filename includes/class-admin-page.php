<?php
/**
 * LCUI_Admin_Page
 *
 * Registers the admin menu, handles form submission, and renders the UI.
 */
class LCUI_Admin_Page {

	const MENU_SLUG    = 'learncat-user-import';
	const NONCE_ACTION = 'lcui_import_csv';
	const CAPABILITY   = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu',        [ self::class, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		add_action( 'admin_post_lcui_download_sample', [ self::class, 'handle_sample_download' ] );
		add_action( 'admin_post_lcui_download_xlsx', [ self::class, 'handle_xlsx_download' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_users_page(
			'Bulk User Import',
			'Bulk Import',
			self::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	// ── Assets ───────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style(
			'lcui-admin',
			LCUI_PLUGIN_URL . 'assets/css/admin.css',
			[],
			LCUI_VERSION
		);
		wp_enqueue_script(
			'lcui-admin',
			LCUI_PLUGIN_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			LCUI_VERSION,
			true
		);
	}

	// ── Sample CSV download ───────────────────────────────────────────────────

	public static function handle_sample_download(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Unauthorised' );
		}
		check_admin_referer( 'lcui_download_sample' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="learncat-user-import-sample.csv"' );
		header( 'Pragma: no-cache' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo LCUI_CSV_Parser::generate_sample_csv();
		exit;
	}

	// ── XLSX Template download ────────────────────────────────────────────────

	public static function handle_xlsx_download(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'Unauthorised' );
		}
		check_admin_referer( 'lcui_download_xlsx' );

		// PhpSpreadsheet can be memory-hungry on large column sets.
		$prev_limit = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted

		LCUI_XLSX_Exporter::export();

		ini_set( 'memory_limit', $prev_limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted
		exit;
	}

	// ── Main page render ─────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You do not have permission to access this page.' );
		}

		$results   = null;
		$parse_err = '';
		$dry_run   = false;

		// Handle form submission
		if (
			isset( $_POST['lcui_submit'] ) &&
			check_admin_referer( self::NONCE_ACTION, 'lcui_nonce' ) &&
			isset( $_FILES['lcui_csv'] )
		) {
			$dry_run = ! empty( $_POST['lcui_dry_run'] );
			$parsed  = LCUI_CSV_Parser::parse( $_FILES['lcui_csv'] );

			// Gather suppression options (all unchecked by default = no suppression).
			$suppress_options = [
				'suppress_wp_new_user'      => ! empty( $_POST['lcui_suppress_wp_new_user'] ),
				'suppress_wc_new_account'   => ! empty( $_POST['lcui_suppress_wc_new_account'] ),
				'suppress_wc_processing'    => ! empty( $_POST['lcui_suppress_wc_processing'] ),
				'suppress_ld_enrollment'    => ! empty( $_POST['lcui_suppress_ld_enrollment'] ),
				'suppress_bb_notifications' => ! empty( $_POST['lcui_suppress_bb_notifications'] ),
				'suppress_uo_certificate'   => ! empty( $_POST['lcui_suppress_uo_certificate'] ),
			];

			if ( $parsed['error'] ) {
				$parse_err = $parsed['error'];
			} else {
				$results = self::run_import( $parsed['rows'], $dry_run, $suppress_options );
			}
		}

		// Field reference data
		$sections = LCUI_Field_Registry::sections();

		include LCUI_PLUGIN_DIR . 'templates/admin-page.php';
	}

	// ── Import runner ─────────────────────────────────────────────────────────

	private static function run_import( array $rows, bool $dry_run, array $suppress_options = [] ): array {
		$summary = [
			'total'     => count( $rows ),
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'errors'    => 0,
			'warnings'  => 0,
			'dry_run'   => $dry_run,
			'rows'      => [],
		];

		// Suppress selected notification channels before processing rows.
		if ( ! $dry_run ) {
			LCUI_Notification_Manager::suppress( $suppress_options );
		}

		foreach ( $rows as $row ) {
			if ( $dry_run ) {
				// In dry-run: resolve user only, don't write anything.
				// But still run validation so the admin can see issues.
				$login    = trim( $row['user_login'] ?? '' );
				$email    = trim( $row['user_email'] ?? '' );
				$existing = false;
				if ( $login ) {
					$existing = (bool) get_user_by( 'login', $login );
				}
				if ( ! $existing && $email ) {
					$existing = (bool) get_user_by( 'email', $email );
				}

				$row_result = [
					'user_id'  => null,
					'is_new'   => ! $existing,
					'log'      => [ $existing ? '[DRY RUN] Would update existing user.' : '[DRY RUN] Would create new user.' ],
					'errors'   => [],
					'warnings' => [],
				];

				// Run validation in dry-run mode too
				$validator  = new LCUI_Row_Validator( $row );
				$validation = $validator->validate();
				foreach ( $validation['warnings'] as $w ) {
					$row_result['log'][]      = '⚠️ ' . $w;
					$row_result['warnings'][] = $w;
				}
				foreach ( $validation['errors'] as $e ) {
					$row_result['errors'][] = $e;
				}
			} else {
				$importer   = new LCUI_Row_Importer( $row, $suppress_options );
				$row_result = $importer->run();
			}

			$row_result['row_number'] = $row['_row_number'];
			$row_result['identifier'] = $row['user_email'] ?? $row['user_login'] ?? 'row ' . $row['_row_number'];

			if ( ! empty( $row_result['warnings'] ) ) {
				$summary['warnings'] += count( $row_result['warnings'] );
			}

			if ( ! empty( $row_result['errors'] ) ) {
				$summary['errors']++;
				$summary['skipped']++;
			} elseif ( $dry_run ) {
				// don't count
			} elseif ( $row_result['is_new'] ) {
				$summary['created']++;
			} else {
				$summary['updated']++;
			}

			$summary['rows'][] = $row_result;
		}

		// Restore all suppressed hooks after all rows are processed.
		if ( ! $dry_run ) {
			LCUI_Notification_Manager::restore();
		}

		return $summary;
	}
}
