<?php
/**
 * Debug audience gate (Standard #11).
 *
 * Decides whether the current user may see debug output. Four modes:
 *   - all_admins         : any user with the admin capability
 *   - specific_roles     : users holding one of the configured roles (does NOT
 *                          require the admin capability — lets a non-admin
 *                          developer debug)
 *   - specific_users     : whitelisted user IDs (admin capability still required)
 *   - current_user_only  : a single configured user ID
 *
 * @package WebWakeUpWdb\WithdrawalButton
 */

declare( strict_types=1 );

namespace WebWakeUpWdb\WithdrawalButton\Debug;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience resolver with a per-request cache.
 */
final class Audience {

	public const MODE_ALL_ADMINS        = 'all_admins';
	public const MODE_SPECIFIC_ROLES    = 'specific_roles';
	public const MODE_SPECIFIC_USERS    = 'specific_users';
	public const MODE_CURRENT_USER_ONLY = 'current_user_only';

	/**
	 * Per-request memo: null = not computed, bool = computed.
	 *
	 * @var bool|null
	 */
	private static $cached = null;

	/**
	 * Default configuration shape.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => false,
			'mode'          => self::MODE_ALL_ADMINS,
			'roles'         => array(),
			'users'         => array(),
			'console_level' => 'warn',
		);
	}

	/**
	 * Read (and normalise) the audience configuration.
	 *
	 * @return array
	 */
	public static function config(): array {
		$config = get_option( 'webwakeupwdb_debug', array() );
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		return wp_parse_args( $config, self::defaults() );
	}

	/**
	 * Whether debug is active for the current user.
	 *
	 * @return bool
	 */
	public static function is_current_user(): bool {
		if ( null !== self::$cached ) {
			return self::$cached;
		}

		$config = self::config();
		if ( empty( $config['enabled'] ) ) {
			self::$cached = false;
			return false;
		}

		/*
		 * TRAP #53: do NOT cache a false result when the user is not (yet) logged
		 * in. WP REST completes authentication AFTER plugins_loaded/init via
		 * rest_cookie_check_errors, so is_user_logged_in() can be transiently
		 * false before the permission_callback runs. Caching false here would
		 * poison a later, correctly-authenticated call in the same request.
		 */
		if ( ! is_user_logged_in() ) {
			return false; // intentionally not cached.
		}

		// Multisite super admin owns every subsite; no per-site opt-in needed.
		if ( is_multisite() && function_exists( 'is_super_admin' ) && is_super_admin() ) {
			self::$cached = true;
			return true;
		}

		$capability = (string) apply_filters( 'webwakeupwdb_admin_capability', 'manage_woocommerce' );
		$user       = wp_get_current_user();
		$decision   = false;

		switch ( $config['mode'] ) {
			case self::MODE_SPECIFIC_ROLES:
				$roles    = array_map( 'strval', (array) $config['roles'] );
				$decision = (bool) array_intersect( $roles, (array) $user->roles );
				break;

			case self::MODE_SPECIFIC_USERS:
				$users    = array_map( 'intval', (array) $config['users'] );
				$decision = current_user_can( $capability ) && in_array( (int) $user->ID, $users, true );
				break;

			case self::MODE_CURRENT_USER_ONLY:
				$users    = array_map( 'intval', (array) $config['users'] );
				$decision = ! empty( $users ) && (int) $user->ID === (int) $users[0];
				break;

			case self::MODE_ALL_ADMINS:
			default:
				$decision = current_user_can( $capability );
				break;
		}

		self::$cached = $decision;
		return $decision;
	}

	/**
	 * Reset the per-request cache (used after a settings save mid-request).
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$cached = null;
	}
}
