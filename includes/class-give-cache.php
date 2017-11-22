<?php
/**
 * Class for managing cache
 * Note: only use for internal purpose.
 *
 * @package     Give
 * @subpackage  Classes/Give_Cache
 * @copyright   Copyright (c) 2017, WordImpress
 * @license     https://opensource.org/licenses/gpl-license GNU Public License
 * @since       1.8.7
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Give_Cache {
	/**
	 * Instance.
	 *
	 * @since  1.8.7
	 * @access private
	 * @var Give_Cache
	 */
	static private $instance;

	/**
	 * Flag to check if caching enabled or not.
	 *
	 * @since  2.0
	 * @access private
	 * @var
	 */
	private $is_cache;

	/**
	 * Singleton pattern.
	 *
	 * @since  1.8.7
	 * @access private
	 * Give_Cache constructor.
	 */
	private function __construct() {
	}


	/**
	 * Get instance.
	 *
	 * @since  1.8.7
	 * @access public
	 * @return static
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Give_Cache ) ) {
			self::$instance = new Give_Cache();
		}

		return self::$instance;
	}

	/**
	 * Setup hooks.
	 *
	 * @since  1.8.7
	 * @access public
	 */
	public function setup() {
		self::$instance->is_cache = give_is_setting_enabled( give_get_option( 'cache', 'enabled' ) );

		// weekly delete all expired cache.
		Give_Cron::add_weekly_event( array( $this, 'delete_all_expired' ) );

		add_action( 'save_post_give_forms', array( $this, 'delete_form_related_cache' ) );
		add_action( 'save_post_give_payment', array( $this, 'delete_payment_related_cache' ) );
		add_action( 'give_deleted_give-donors_cache', array( $this, 'delete_donor_related_cache' ), 10, 3 );
		add_action( 'give_deleted_give-donations_cache', array( $this, 'delete_donations_related_cache' ), 10, 3 );
	}

	/**
	 * Get cache key.
	 *
	 * @since  1.8.7
	 *
	 * @param  string $action     Cache key prefix.
	 * @param  array  $query_args (optional) Query array.
	 *
	 * @return string|WP_Error
	 */

	public static function get_key( $action, $query_args = null ) {
		// Bailout.
		if ( empty( $action ) ) {
			return new WP_Error( 'give_invalid_cache_key_action', __( 'Do not pass empty action to generate cache key.', 'give' ) );
		}

		// Handle specific cache key prefix.
		// @see https://core.trac.wordpress.org/ticket/4476
		if ( 'give-db-queries' === $action ) {
			if( ! ( $timestamp = get_option( 'give-last-cache-updated' ) ) ) {

				// Set default timestamp for cache if not set yet.
				$timestamp = current_time( 'timestamp', 1 );
				update_option( 'give-last-cache-updated', $timestamp );
			}
			return "give-db-queries-{$timestamp}";
		}


		// Set cache key.
		$cache_key = "give_cache_{$action}";

		// Bailout.
		if ( ! empty( $query_args ) ) {
			$cache_key = "{$cache_key}_" . substr( md5( serialize( $query_args ) ), 0, 15 );
		}

		/**
		 * Filter the cache key name.
		 *
		 * @since 2.0
		 */
		return apply_filters( 'give_get_cache_key', $cache_key, $action, $query_args );
	}

	/**
	 * Get cache.
	 *
	 * @since  1.8.7
	 *
	 * @param  string $cache_key
	 * @param  bool   $custom_key
	 * @param  mixed  $query_args
	 *
	 * @return mixed
	 */

	public static function get( $cache_key, $custom_key = false, $query_args = array() ) {
		if ( ! self::is_valid_cache_key( $cache_key ) ) {
			if ( ! $custom_key ) {
				return new WP_Error( 'give_invalid_cache_key', __( 'Cache key format should be give_cache_*', 'give' ) );
			}

			$cache_key = self::get_key( $cache_key, $query_args );
		}

		$option = get_option( $cache_key );

		// Backward compatibility (<1.8.7).
		if ( ! is_array( $option ) || empty( $option ) || ! array_key_exists( 'expiration', $option ) ) {
			return $option;
		}

		// Get current time.
		$current_time = current_time( 'timestamp', 1 );

		if ( empty( $option['expiration'] ) || ( $current_time < $option['expiration'] ) ) {
			$option = $option['data'];
		} else {
			$option = false;
		}

		return $option;
	}

	/**
	 * Set cache.
	 *
	 * @since  1.8.7
	 *
	 * @param  string   $cache_key
	 * @param  mixed    $data
	 * @param  int|null $expiration Timestamp should be in GMT format.
	 * @param  bool     $custom_key
	 * @param  mixed    $query_args
	 *
	 * @return mixed
	 */

	public static function set( $cache_key, $data, $expiration = null, $custom_key = false, $query_args = array() ) {
		if ( ! self::is_valid_cache_key( $cache_key ) ) {
			if ( ! $custom_key ) {
				return new WP_Error( 'give_invalid_cache_key', __( 'Cache key format should be give_cache_*', 'give' ) );
			}

			$cache_key = self::get_key( $cache_key, $query_args );
		}

		$option_value = array(
			'data'       => $data,
			'expiration' => ! is_null( $expiration )
				? ( $expiration + current_time( 'timestamp', 1 ) )
				: null,
		);

		$result = update_option( $cache_key, $option_value, 'no' );

		return $result;
	}

	/**
	 * Delete cache.
	 *
	 * Note: only for internal use
	 *
	 * @since  1.8.7
	 *
	 * @param  string|array $cache_keys
	 *
	 * @return bool|WP_Error
	 */

	public static function delete( $cache_keys ) {
		$result       = true;
		$invalid_keys = array();

		if ( ! empty( $cache_keys ) ) {
			$cache_keys = is_array( $cache_keys ) ? $cache_keys : array( $cache_keys );

			foreach ( $cache_keys as $cache_key ) {
				if ( ! self::is_valid_cache_key( $cache_key ) ) {
					$invalid_keys[] = $cache_key;
					$result         = false;
				}

				delete_option( $cache_key );
			}
		}

		if ( ! $result ) {
			$result = new WP_Error(
				'give_invalid_cache_key',
				__( 'Cache key format should be give_cache_*', 'give' ),
				$invalid_keys
			);
		}

		return $result;
	}

	/**
	 * Delete all logging cache.
	 *
	 * Note: only for internal use
	 *
	 * @since  1.8.7
	 * @access public
	 * @global wpdb $wpdb
	 *
	 * @param bool  $force If set to true then all cached values will be delete instead of only expired
	 *
	 * @return bool
	 */
	public static function delete_all_expired( $force = false ) {
		global $wpdb;
		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value
						FROM {$wpdb->options}
						Where option_name
						LIKE '%%%s%%'",
				'give_cache'
			),
			ARRAY_A
		);

		// Bailout.
		if ( empty( $options ) ) {
			return false;
		}

		$current_time = current_time( 'timestamp', 1 );

		// Delete log cache.
		foreach ( $options as $option ) {
			$option['option_value'] = maybe_unserialize( $option['option_value'] );

			if (
				(
					! self::is_valid_cache_key( $option['option_name'] )
					|| ! is_array( $option['option_value'] ) // Backward compatibility (<1.8.7).
					|| ! array_key_exists( 'expiration', $option['option_value'] ) // Backward compatibility (<1.8.7).
					|| empty( $option['option_value']['expiration'] )
					|| ( $current_time < $option['option_value']['expiration'] )
				)
				&& ! $force
			) {
				continue;
			}

			self::delete( $option['option_name'] );
		}
	}


	/**
	 * Get list of options like.
	 *
	 * Note: only for internal use
	 *
	 * @since  1.8.7
	 * @access public
	 *
	 * @param string $option_name
	 * @param bool   $fields
	 *
	 * @return array
	 */
	public static function get_options_like( $option_name, $fields = false ) {
		global $wpdb;

		if ( empty( $option_name ) ) {
			return array();
		}

		$field_names = $fields ? 'option_name, option_value' : 'option_name';

		if ( $fields ) {
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$field_names }
						FROM {$wpdb->options}
						Where option_name
						LIKE '%%%s%%'",
					"give_cache_{$option_name}"
				),
				ARRAY_A
			);
		} else {
			$options = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT *
						FROM {$wpdb->options}
						Where option_name
						LIKE '%%%s%%'",
					"give_cache_{$option_name}"
				),
				1
			);
		}

		if ( ! empty( $options ) && $fields ) {
			foreach ( $options as $index => $option ) {
				$option['option_value'] = maybe_unserialize( $option['option_value'] );
				$options[ $index ]      = $option;
			}
		}

		return $options;
	}

	/**
	 * Check cache key validity.
	 *
	 * @since  1.8.7
	 * @access public
	 *
	 * @param $cache_key
	 *
	 * @return bool
	 */
	public static function is_valid_cache_key( $cache_key ) {
		$is_valid = ( false !== strpos( $cache_key, 'give_cache_' ) );


		/**
		 * Filter the flag which tell about cache key valid or not
		 *
		 * @since 2.0
		 */
		return apply_filters( 'give_is_valid_cache_key', $is_valid, $cache_key );
	}


	/**
	 * Get cache from group
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param int    $id
	 * @param string $group
	 *
	 * @return mixed
	 */
	public static function get_group( $id, $group = '' ) {
		$cached_data = false;

		// Bailout.
		if ( ! self::$instance->is_cache || empty( $id ) ) {
			return $cached_data;
		}

		$cached_data = wp_cache_get( $id, $group );

		return $cached_data;
	}

	/**
	 * Cache small chunks inside group
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param int    $id
	 * @param mixed  $data
	 * @param string $group
	 * @param int    $expire
	 *
	 * @return bool
	 */
	public static function set_group( $id, $data, $group = '', $expire = 0 ) {
		$status = false;

		// Bailout.
		if ( ! self::$instance->is_cache || empty( $id ) ) {
			return $status;
		}

		$status = wp_cache_set( $id, $data, $group, $expire );

		update_option( 'give-last-cache-updated', self::get_instance()->get_unique_timestamp( 'db_query_cache' ) );

		return $status;
	}

	/**
	 * Delete group cache
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param int|array $ids
	 * @param string    $group
	 * @param int       $expire
	 *
	 * @return bool
	 */
	public static function delete_group( $ids, $group = '', $expire = 0 ) {
		$status = false;

		// Bailout.
		if ( ! self::$instance->is_cache || empty( $ids ) ) {
			return $status;
		}

		// Delete single or multiple cache items from cache.
		if ( ! is_array( $ids ) ) {
			$status = wp_cache_delete( $ids, $group, $expire );

			/**
			 * Fire action when cache deleted for specific id.
			 *
			 * @since 2.0
			 *
			 * @param string $ids
			 * @param string $group
			 * @param int    $expire
			 */
			do_action( "give_deleted_{$group}_cache", $ids, $group, $expire );

		} else {
			foreach ( $ids as $id ) {
				$status = wp_cache_delete( $id, $group, $expire );

				/**
				 * Fire action when cache deleted for specific id .
				 *
				 * @since 2.0
				 *
				 * @param string $ids
				 * @param string $group
				 * @param int    $expire
				 */
				do_action( "give_deleted_{$group}_cache", $id, $group, $expire );
			}
		}


		// Update timestamp in DB when cache update.
		update_option( 'give-last-cache-updated', self::get_instance()->get_unique_timestamp( 'db_query_cache' ) );

		return $status;
	}


	/**
	 * Delete form related cache
	 * Note: only use for internal purpose.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param int $form_id
	 */
	public function delete_form_related_cache( $form_id ) {
		// If this is just a revision, don't send the email.
		if ( wp_is_post_revision( $form_id ) ) {
			return;
		}

		$donation_query = new Give_Payments_Query(
			array(
				'number'     => - 1,
				'give_forms' => $form_id
			)
		);

		$donations = $donation_query->get_payments();

		// Bailout.
		if ( empty( $donations ) ) {
			return;
		}

		/* @var Give_Payment $donation */
		foreach ( $donations as $donation ) {
			wp_cache_delete( $donation->ID, 'give-donations' );
			wp_cache_delete( $donation->donor_id, 'give-donors' );
		}
	}

	/**
	 * Delete payment related cache
	 * Note: only use for internal purpose.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param int $donation_id
	 */
	public function delete_payment_related_cache( $donation_id ) {
		// If this is just a revision, don't send the email.
		if ( wp_is_post_revision( $donation_id ) ) {
			return;
		}

		/* @var Give_Payment $donation */
		$donation = new Give_Payment( $donation_id );

		if ( $donation && $donation->donor_id ) {
			wp_cache_delete( $donation->donor_id, 'give-donors' );
		}

		wp_cache_delete( $donation->ID, 'give-donations' );
	}

	/**
	 * Delete donor related cache
	 * Note: only use for internal purpose.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param string $id
	 * @param string $group
	 * @param int    $expire
	 */
	public function delete_donor_related_cache( $id, $group, $expire ) {
		$donor        = new Give_Donor( $id );
		$donation_ids = array_map( 'trim', (array) explode( ',', trim( $donor->payment_ids ) ) );

		// bailout.
		if ( empty( $donation_ids ) ) {
			return;
		}

		foreach ( $donation_ids as $donation ) {
			wp_cache_delete( $donation, 'give-donations' );
		}
	}

	/**
	 * Delete donations related cache
	 * Note: only use for internal purpose.
	 *
	 * @since  2.0
	 * @access public
	 *
	 * @param string $id
	 * @param string $group
	 * @param int    $expire
	 */
	public function delete_donations_related_cache( $id, $group, $expire ) {
		/* @var Give_Payment $donation */
		$donation = new Give_Payment( $id );

		if ( $donation && $donation->donor_id ) {
			wp_cache_delete( $donation->donor_id, 'give-donors' );
		}
	}


	/**
	 * Get unique timestamp.
	 *
	 * @since  2.0
	 * @access private
	 *
	 * @param $context
	 *
	 * @return string
	 */
	private function get_unique_timestamp( $context ) {
		$timestamp = current_time( 'timestamp', 1 );

		switch ( $context ) {
			case 'db_query_cache':
				$timestamp = get_option( 'give-last-cache-updated' );
				$timestamp = empty( $timestamp ) ?
					current_time( 'timestamp', 1 ) :
					++ $timestamp;
				break;
		}

		return (string) $timestamp;
	}
}

// Initialize
Give_Cache::get_instance()->setup();
