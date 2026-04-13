<?php
/**
 * LCUI_Notification_Manager
 *
 * Surgical hook removal / filter injection to suppress specific notification
 * channels during a bulk import. Every suppressed callback is stored so it
 * can be restored after the import batch completes.
 *
 * Usage:
 *   LCUI_Notification_Manager::suppress( $options );
 *   // ... run import rows ...
 *   LCUI_Notification_Manager::restore();
 */
class LCUI_Notification_Manager {

	/**
	 * Callbacks that were removed during suppress() so they can be re-added.
	 *
	 * Format: [ [ 'hook' => string, 'callback' => callable, 'priority' => int, 'args' => int ], ... ]
	 *
	 * @var array
	 */
	private static array $removed = [];

	/**
	 * WooCommerce email filters we added via __return_false.
	 *
	 * Format: [ 'woocommerce_email_enabled_{id}', ... ]
	 *
	 * @var string[]
	 */
	private static array $wc_filters = [];

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Suppress notification channels based on the provided options.
	 *
	 * @param array $options Associative array of boolean flags:
	 *   - suppress_wp_new_user       (bool)
	 *   - suppress_wc_new_account    (bool)
	 *   - suppress_wc_processing     (bool)
	 *   - suppress_ld_enrollment     (bool)
	 *   - suppress_bb_notifications  (bool)
	 *   - suppress_uo_certificate    (bool)
	 */
	public static function suppress( array $options ): void {
		self::$removed    = [];
		self::$wc_filters = [];

		if ( ! empty( $options['suppress_wp_new_user'] ) ) {
			self::suppress_wp_new_user();
		}

		if ( ! empty( $options['suppress_wc_new_account'] ) ) {
			self::suppress_wc_email( 'customer_new_account' );
			self::remove_action_by_function( 'woocommerce_created_customer', 'wc_new_customer_note_notification' );
		}

		if ( ! empty( $options['suppress_wc_processing'] ) ) {
			self::suppress_wc_email( 'customer_processing_order' );
		}

		if ( ! empty( $options['suppress_ld_enrollment'] ) ) {
			self::suppress_learndash_enrollment();
		}

		if ( ! empty( $options['suppress_bb_notifications'] ) ) {
			self::suppress_buddyboss_notifications();
		}

		if ( ! empty( $options['suppress_uo_certificate'] ) ) {
			self::suppress_uncanny_owl();
		}
	}

	/**
	 * Restore all hooks / filters that were removed during suppress().
	 */
	public static function restore(): void {
		// Re-add removed action/filter callbacks.
		foreach ( self::$removed as $entry ) {
			add_filter(
				$entry['hook'],
				$entry['callback'],
				$entry['priority'],
				$entry['args']
			);
		}
		self::$removed = [];

		// Remove __return_false filters added for WooCommerce emails.
		foreach ( self::$wc_filters as $filter_name ) {
			remove_filter( $filter_name, '__return_false', 99 );
		}
		self::$wc_filters = [];
	}

	// ── Suppression helpers ──────────────────────────────────────────────────

	/**
	 * Suppress the WordPress new-user notification email.
	 */
	private static function suppress_wp_new_user(): void {
		self::remove_action_by_function( 'register_new_user', 'wp_send_new_user_notifications' );
		self::remove_action_by_function( 'edit_user_created_user', 'wp_send_new_user_notifications' );
	}

	/**
	 * Suppress a WooCommerce transactional email by its email ID using
	 * the reliable filter pattern.
	 *
	 * @param string $email_id  e.g. 'customer_new_account', 'customer_processing_order'
	 */
	private static function suppress_wc_email( string $email_id ): void {
		$filter = 'woocommerce_email_enabled_' . $email_id;
		add_filter( $filter, '__return_false', 99 );
		self::$wc_filters[] = $filter;
	}

	/**
	 * Suppress LearnDash enrollment notification emails.
	 */
	private static function suppress_learndash_enrollment(): void {
		// LearnDash fires emails on course/group access hooks.
		$hooks = [
			'learndash_course_access_added',
			'learndash_group_access_added',
		];

		foreach ( $hooks as $hook ) {
			self::remove_callbacks_by_namespace( $hook, 'LearnDash' );
			self::remove_callbacks_by_namespace( $hook, 'learndash' );
		}

		// Also suppress via the LD email settings filters if available.
		foreach ( [
			'learndash_emails_course_purchase_success',
			'learndash_emails_group_purchase_success',
		] as $ld_filter ) {
			if ( has_filter( $ld_filter ) ) {
				add_filter( $ld_filter, '__return_false', 99 );
				self::$wc_filters[] = $ld_filter; // reuse the same restore array
			}
		}
	}

	/**
	 * Suppress BuddyBoss in-app notifications.
	 */
	private static function suppress_buddyboss_notifications(): void {
		self::remove_callbacks_by_namespace( 'bp_notification_after_save', 'BuddyBoss' );
		self::remove_callbacks_by_namespace( 'bp_notification_after_save', 'buddyboss' );
		self::remove_callbacks_by_namespace( 'bp_notification_after_save', 'BB_' );
		self::remove_callbacks_by_namespace( 'bp_notification_after_save', 'bp_' );
	}

	/**
	 * Suppress Uncanny Owl certificate emails by removing their callback
	 * on the learndash_course_completed hook.
	 */
	private static function suppress_uncanny_owl(): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['learndash_course_completed'] ) ) {
			return;
		}

		$hook_obj = $wp_filter['learndash_course_completed'];

		foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback_data ) {
				if ( self::callback_belongs_to( $callback_data['function'], [
					'uncanny_learndash',
					'uncanny_owl',
					'Uncanny',
					'SUSPENDED_uncanny',
					'uo_',
					'UO_',
				] ) ) {
					self::$removed[] = [
						'hook'     => 'learndash_course_completed',
						'callback' => $callback_data['function'],
						'priority' => $priority,
						'args'     => $callback_data['accepted_args'],
					];
					$hook_obj->remove_filter( 'learndash_course_completed', $callback_data['function'], $priority );
				}
			}
		}
	}

	// ── Utility methods ──────────────────────────────────────────────────────

	/**
	 * Remove a specific named function from an action hook.
	 *
	 * @param string $hook          Hook name.
	 * @param string $function_name The function name to look for.
	 */
	private static function remove_action_by_function( string $hook, string $function_name ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}

		$hook_obj = $wp_filter[ $hook ];

		foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback_data ) {
				$fn = $callback_data['function'];

				// Simple string function name.
				if ( is_string( $fn ) && $fn === $function_name ) {
					self::store_and_remove( $hook, $fn, $priority, $callback_data['accepted_args'] );
					continue;
				}

				// Array callback: [ $object_or_class, 'method' ].
				if ( is_array( $fn ) && isset( $fn[1] ) && $fn[1] === $function_name ) {
					self::store_and_remove( $hook, $fn, $priority, $callback_data['accepted_args'] );
				}
			}
		}
	}

	/**
	 * Remove all callbacks on a hook whose class/namespace matches one of the
	 * provided needles (case-insensitive substring match).
	 *
	 * @param string   $hook    Hook name.
	 * @param string   $needle  Substring to look for in the callback identifier.
	 */
	private static function remove_callbacks_by_namespace( string $hook, string $needle ): void {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}

		$hook_obj = $wp_filter[ $hook ];

		foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $id => $callback_data ) {
				if ( self::callback_belongs_to( $callback_data['function'], [ $needle ] ) ) {
					self::store_and_remove( $hook, $callback_data['function'], $priority, $callback_data['accepted_args'] );
				}
			}
		}
	}

	/**
	 * Check whether a callback belongs to a class/namespace/function matching
	 * any of the provided needles.
	 *
	 * @param mixed    $fn      The callback (string, array, or Closure).
	 * @param string[] $needles Substrings to match against.
	 * @return bool
	 */
	private static function callback_belongs_to( $fn, array $needles ): bool {
		$identifiers = [];

		if ( is_string( $fn ) ) {
			$identifiers[] = $fn;
		} elseif ( is_array( $fn ) && isset( $fn[0] ) ) {
			if ( is_object( $fn[0] ) ) {
				$identifiers[] = get_class( $fn[0] );
			} elseif ( is_string( $fn[0] ) ) {
				$identifiers[] = $fn[0];
			}
			if ( isset( $fn[1] ) && is_string( $fn[1] ) ) {
				$identifiers[] = $fn[1];
			}
		}

		foreach ( $identifiers as $identifier ) {
			foreach ( $needles as $needle ) {
				if ( stripos( $identifier, $needle ) !== false ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Store a callback for later restoration and remove it from the hook.
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback The callback to remove.
	 * @param int      $priority Hook priority.
	 * @param int      $args     Number of accepted arguments.
	 */
	private static function store_and_remove( string $hook, $callback, int $priority, int $args ): void {
		self::$removed[] = [
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $args,
		];

		remove_filter( $hook, $callback, $priority );
	}
}
