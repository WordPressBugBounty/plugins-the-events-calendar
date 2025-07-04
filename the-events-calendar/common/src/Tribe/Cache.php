<?php

/**
 * Manage setting and expiring cached data
 *
 * Select actions can be used to force cached
 * data to expire. Implemented so far:
 *  - save_post
 *
 * When used in its ArrayAccess API the cache will provide non persistent storage.
 */
class Tribe__Cache implements ArrayAccess {
	const SCHEDULED_EVENT_DELETE_TRANSIENT = 'tribe_schedule_transient_purge';

	const NO_EXPIRATION = 0;

	const NON_PERSISTENT = -1;

	/**
	 * @var array
	 */
	protected $non_persistent_keys = [];

	/**
	 * Bootstrap hook
	 *
	 * @since 4.11.0
	 */
	public function hook() {
		if ( ! wp_next_scheduled( self::SCHEDULED_EVENT_DELETE_TRANSIENT ) ) {
			wp_schedule_event( time(), 'twicedaily', self::SCHEDULED_EVENT_DELETE_TRANSIENT );
		}

		add_action( self::SCHEDULED_EVENT_DELETE_TRANSIENT, [ $this, 'delete_expired_transients' ] );

		add_action( 'shutdown', [ $this, 'maybe_delete_expired_transients' ] );
	}

	public static function setup() {
		wp_cache_add_non_persistent_groups( [ 'tribe-events-non-persistent' ] );
	}

	/**
	 * @param string       $id
	 * @param mixed        $value
	 * @param int          $expiration
	 * @param string|array $expiration_trigger
	 *
	 * @return bool
	 */
	public function set( $id, $value, $expiration = 0, $expiration_trigger = '' ) {
		$key = $this->get_id( $id, $expiration_trigger );

		/**
		 * Filters the expiration for cache objects to provide the ability
		 * to make non-persistent objects be treated as persistent.
		 *
		 * @since 4.8
		 *
		 * @param int          $expiration         Cache expiration time.
		 * @param string       $id                 Cache ID.
		 * @param mixed        $value              Cache value.
		 * @param string|array $expiration_trigger Action that triggers automatic expiration.
		 * @param string       $key                Unique cache key based on Cache ID and expiration trigger last run time.
		 */
		$expiration = apply_filters( 'tribe_cache_expiration', $expiration, $id, $value, $expiration_trigger, $key );

		if ( self::NON_PERSISTENT === $expiration ) {
			$group      = 'tribe-events-non-persistent';
			$expiration = 1;

			// Add so we know what group to use in the future.
			$this->non_persistent_keys[ $id ] = $id;
		} else {
			$group = 'tribe-events';
		}

		return wp_cache_set( $key, $value, $group, $expiration );
	}

	/**
	 * @param string       $id                 The key for the cached value.
	 * @param mixed        $value              The value to cache.
	 * @param int          $expiration         The expiration time.
	 * @param string|array $expiration_trigger Optional. Hook to trigger cache invalidation.
	 *
	 * @return bool
	 */
	public function set_transient( $id, $value, $expiration = 0, $expiration_trigger = '' ) {
		if ( $this->data_size_over_packet_size( $value ) ) {
			return false;
		}

		return set_transient( $this->get_id( $id, $expiration_trigger ), $value, $expiration );
	}

	/**
	 * Get cached data. Optionally set data if not previously set.
	 *
	 * Note: When a default value or callback is specified, this value gets set in the cache.
	 *
	 * @param string       $id                 The key for the cached value.
	 * @param string|array $expiration_trigger Optional. Hook to trigger cache invalidation.
	 * @param mixed        $default            Optional. A default value or callback that returns a default value.
	 * @param int          $expiration         Optional. When the default value expires, if it gets set.
	 * @param mixed        $args               Optional. Args passed to callback.
	 *
	 * @return mixed
	 */
	public function get( $id, $expiration_trigger = '', $default = false, $expiration = 0, $args = [] ) {
		$group   = isset( $this->non_persistent_keys[ $id ] ) ? 'tribe-events-non-persistent' : 'tribe-events';
		$value   = wp_cache_get( $this->get_id( $id, $expiration_trigger ), $group );

		// Value found.
		if ( false !== $value ) {
			return $value;
		}

		if ( is_callable( $default ) ) {
			// A callback has been specified.
			$value = $default( ...$args );
		} else {
			// Default is a value.
			$value = $default;
		}

		// No need to set a cache value to false since non-existent values return false.
		if ( false !== $value ) {
			$this->set( $id, $value, $expiration, $expiration_trigger );
		}

		return $value;
	}

	/**
	 * @param string       $id                 The key for the cached value.
	 * @param string|array $expiration_trigger Optional. Hook to trigger cache invalidation.
	 *
	 * @return mixed
	 */
	public function get_transient( $id, $expiration_trigger = '' ) {
		return get_transient( $this->get_id( $id, $expiration_trigger ) );
	}

	/**
	 * @param string       $id                 The key for the cached value.
	 * @param string|array $expiration_trigger Optional. Hook to trigger cache invalidation.
	 *
	 * @return bool
	 */
	public function delete( $id, $expiration_trigger = '' ) {
		$group   = isset( $this->non_persistent_keys[ $id ] ) ? 'tribe-events-non-persistent' : 'tribe-events';

		// Delete from non-persistent keys list.
		if ( 'tribe-events-non-persistent' === $group ) {
			unset( $this->non_persistent_keys[ $id ] );
		}

		return wp_cache_delete( $this->get_id( $id, $expiration_trigger ), $group );
	}

	/**
	 * @param string       $id                 The key for the cached value.
	 * @param string|array $expiration_trigger Optional. Hook to trigger cache invalidation.
	 *
	 * @return bool
	 */
	public function delete_transient( $id, $expiration_trigger = '' ) {
		return delete_transient( $this->get_id( $id, $expiration_trigger ) );
	}

	/**
	 * Purge all expired tribe_ transients.
	 *
	 * This uses a modification of the the query from https://core.trac.wordpress.org/ticket/20316
	 *
	 * @since 4.11.0
	 *
	 * @return void Just execute the database SQL no return required.
	 */
	public function delete_expired_transients() {
		if ( tribe_get_var( 'has_deleted_expired_transients', false ) ) {
			return;
		}

		global $wpdb;

		$time = time();

		$sql = "
			DELETE
				a,
				b
			FROM
				{$wpdb->options} a
				INNER JOIN {$wpdb->options} b
					ON b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
					AND b.option_value < {$time}
			WHERE
				a.option_name LIKE '\_transient\_tribe\_%'
				AND a.option_name NOT LIKE '\_transient\_timeout\_tribe\_%'
		";

		/**
		 * Allow third party filtering of the SQL used for deleting expired transients.
		 *
		 * @since 4.11.5
		 *
		 * @param string $sql   The SQL we execute to delete all the expired transients.
		 * @param int    $time  Time we are using to determine what is expired.
		 */
		$sql = apply_filters( 'tribe_cache_delete_expired_transients_sql', $sql, $time );

		if ( empty( $sql ) ) {
			return;
		}

		$wpdb->query( $sql );

		// Set the variable to prevent this call from running twice.
		tribe_set_var( 'has_deleted_expired_transients', true );
	}

	/**
	 * Flag if we should delete
	 *
	 * @since 4.11.5
	 *
	 * @param boolean $value If we should delete transients or not on shutdown.
	 *
	 * @return void No return for setting the flag.
	 */
	public function flag_required_delete_transients( $value = true ) {
		tribe_set_var( 'should_delete_expired_transients', $value );
	}

	/**
	 * Runs on hook `shutdown` and will delete transients on the end of the request.
	 *
	 * @since 4.11.5
	 *
	 * @return void No return for action hook method.
	 */
	public function maybe_delete_expired_transients() {
		if ( ! tribe_get_var( 'should_delete_expired_transients', false ) ) {
			return;
		}

		$this->delete_expired_transients();
	}

	/**
	 * @param string       $key                The key for the cached value.
	 * @param string|array $expiration_trigger Optional. Hook to trigger cache invalidation.
	 *
	 * @return string
	 */
	public function get_id( $key, $expiration_trigger = '' ) {
		if ( is_array( $expiration_trigger ) ) {
			$triggers = $expiration_trigger;
		} elseif ( 'tribe-events-non-persistent' !== $expiration_trigger && 'tribe-events' !== $expiration_trigger ) {
			$triggers = array_filter( explode( '|', $expiration_trigger ) );
		}

		$last = 0;
		foreach ( $triggers as $trigger ) {
			// Bail on empty trigger otherwise it creates a `tribe_last_` opt on the DB.
			if ( empty( $trigger ) ) {
				continue;
			}

			$occurrence = $this->get_last_occurrence( $trigger );

			if ( $occurrence > $last ) {
				$last = $occurrence;
			}
		}

		$last = empty( $last ) ? '' : $last;
		$id   = $key . $last;
		if ( strlen( $id ) > 80 ) {
			$id = 'tribe_' . md5( $id );
		}

		return $id;
	}

	/**
	 * Returns the time of an action last occurrence.
	 *
	 * @since 4.9.14 Changed the return value type from `int` to `float`.
	 * @since 5.0.17 No longer memoizes the first triggered timestamp.
	 *
	 * @param string $action The action to return the time for.
	 *
	 * @return float The time (microtime) an action last occurred, or the current microtime if it never occurred.
	 */
	public function get_last_occurrence( $action ) {
		$last_action = (float) get_option( 'tribe_last_' . $action, null );

		if ( ! $last_action ) {
			$last_action = microtime( true );
			$this->set_last_occurrence( $action, $last_action );
		}

		return $last_action;
	}

	/**
	 * Sets the time (microtime) for an action last occurrence.
	 *
	 * @since 4.9.14 Changed the type of the time stored from an `int` to a `float`.
	 *
	 * @param string    $action    The action to record the last occurrence of.
	 * @param int|float $timestamp The timestamp to assign to the action last occurrence or the current time (microtime).
	 *
	 * @return boolean IF we were able to set the last occurrence or not.
	 */
	public function set_last_occurrence( $action, $timestamp = 0 ) {
		if ( empty( $timestamp ) ) {
			$timestamp = microtime( true );
		}
		$updated = update_option( 'tribe_last_' . $action, (float) $timestamp );

		// For performance reasons we will only expire cache once per request, when needed.
		if ( $updated ) {
			$this->flag_required_delete_transients( true );
		}

		return $updated;
	}

	/**
	 * Builds a key from an array of components and an optional prefix.
	 *
	 * @param mixed  $components Either a single component of the key or an array of key components.
	 * @param string $prefix     Optional. The prefix for the key.
	 * @param bool   $sort       Whether component arrays should be sorted or not to generate the key; defaults to
	 *                           `true`.
	 *
	 * @return string The resulting key.
	 */
	public function make_key( $components, $prefix = '', $sort = true ) {
		$key        = '';
		$components = is_array( $components ) ? $components : [ $components ];
		foreach ( $components as $component ) {
			if ( $sort && is_array( $component ) ) {
				$is_associative = count( array_filter( array_keys( $component ), 'is_numeric' ) ) < count( array_keys( $component ) );
				if ( $is_associative ) {
					ksort( $component );
				} else {
					sort( $component );
				}
			}
			$key .= maybe_serialize( $component );
		}

		return $this->get_id( $prefix . md5( $key ) );
	}

	/**
	 * Whether a offset exists.
	 *
	 * @since 4.11.0
	 * @since 5.0.13 Will check against cache expiration. Previously would give false positive
	 *            if expiration had passed but was cached recently. Will now consider null not set.
	 *
	 * @param mixed $offset An offset to check for.
	 *
	 * @return boolean Whether the offset exists in the cache.
	 *@link  http://php.net/manual/en/arrayaccess.offsetexists.php
	 *
	 */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ): bool {
		$value = $this->get( $offset );

		return $value !== false && $value !== null;
	}

	/**
	 * Offset to retrieve.
	 *
	 * @link  http://php.net/manual/en/arrayaccess.offsetget.php
	 *
	 * @since 4.11.0
	 *
	 * @param mixed $offset The offset to retrieve.
	 *
	 * @return mixed Can return all value types.
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return $this->get( $offset );
	}

	/**
	 * Offset to set.
	 *
	 * @since 4.11.0
	 *
	 * @link  http://php.net/manual/en/arrayaccess.offsetset.php
	 *
	 * @param mixed $offset The offset to assign the value to.
	 * @param mixed $value  The value to set.
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ): void {
		$this->set( $offset, $value, self::NON_PERSISTENT );
	}

	/**
	 * Offset to unset.
	 *
	 * @since 4.11.0
	 *
	 * @link  http://php.net/manual/en/arrayaccess.offsetunset.php
	 *
	 * @param mixed $offset The offset to unset.
	 *
	 * @return void
	 */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ): void {
		$this->delete( $offset );
	}

	/**
	 * Removes a group of the cache, for now only `non_persistent` is supported.
	 *
	 * @since 4.14.13
	 *
	 * @return bool
	 */
	public function reset( $group = 'non_persistent' ) {
		if ( 'non_persistent' !== $group ) {
			return false;
		}
		$this->non_persistent_keys = [];
		return true;
	}

	/**
	 * Warms up the caches for a collection of posts.
	 *
	 * @since 4.10.2
	 *
	 * @param array|int $post_ids               A post ID, or a collection of post IDs.
	 * @param bool      $update_post_meta_cache Whether to warm-up the post meta cache for the posts or not.
	 */
	public function warmup_post_caches( $post_ids, $update_post_meta_cache = false ) {
		if ( empty( $post_ids ) ) {
			return;
		}

		$post_ids = (array) $post_ids;

		global $wpdb;

		$already_cached_ids = [];
		foreach ( $post_ids as $post_id ) {
			if ( wp_cache_get( $post_id, 'posts' ) instanceof \WP_Post ) {
				$already_cached_ids[] = $post_id;
			}
		}

		$required = array_diff( $post_ids, $already_cached_ids );

		if ( empty( $required ) ) {
			return;
		}

		/** @var Tribe__Feature_Detection $feature_detection */
		$feature_detection = tribe( 'feature-detection' );
		$limit             = $feature_detection->mysql_limit_for_example( 'post_result' );

		/**
		 * Filters the LIMIT that should be used to warm-up post caches and postmeta caches (if the
		 * `$update_post_meta_cache` parameter is `true`).
		 *
		 * Lower this value on less powerful hosts. Return `0` to disable the warm-up completely, and `-1` to remove the
		 * limit (not recommended).
		 *
		 * @since 4.10.2
		 *
		 * @param int $limit The number of posts whose caches will be warmed up, per query.
		 */
		$limit = (int) apply_filters( 'tribe_cache_warmup_post_cache_limit', min( $limit, count( $post_ids ) ) );

		if ( 0 === $limit ) {
			// Warmup disabled.
			return;
		}

		$buffer = $post_ids;
		$page   = 0;

		do {
			$limit_clause = $limit < 0 ? sprintf( 'LIMIT %d,%d', $limit * $page, $limit ) : '';
			$page++;
			$these_ids    = array_splice( $buffer, 0, $limit );
			$interval     = implode( ',', array_map( 'absint', $these_ids ) );
			$posts_query  = "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$interval}) {$limit_clause}";
			$post_objects = $wpdb->get_results( $posts_query );
			if ( is_array( $post_objects ) && ! empty( $post_objects ) ) {
				foreach ( $post_objects as $post_object ) {
					$post = new \WP_Post( $post_object );
					wp_cache_set( $post_object->ID, $post, 'posts' );
				}

				if ( $update_post_meta_cache ) {
					update_meta_cache( 'post', $these_ids );
				}
			}
		} while ( ! empty( $post_objects ) && is_array( $post_objects ) && count( $post_objects ) < count( $post_ids ) );
	}

	/**
	 * If NOT using an external object caching system, then check if the size, in bytes, of the data
	 * to write to the database would fit into the `max_allowed_packet` setting or not.
	 *
	 * @since 4.12.14
	 *
	 * @param string|array|object $value The value to check.
	 *
	 * @return bool Whether the data, in its serialized form, would fit into the current database `max_allowed_packet`
	 *              setting or not.
	 */
	public function data_size_over_packet_size( $value ) {
		if ( wp_using_ext_object_cache() ) {
			// We cannot know and that is a concern of the external caching system.
			return false;
		}

		try {
			$serialized_value = maybe_serialize( $value );
			$size             = strlen( $serialized_value );
		} catch ( Exception $e ) {
			// The underlying function would run into the same issue, bail and do not set the transient.
			return true;
		}

		/** @var Tribe__Feature_Detection $feature_detection */
		$feature_detection = tribe( 'feature-detection' );

		// If the size of the string is above 90% of the database `max_allowed_packet` setting, then it should not be written to the db.
		return $size > ( $feature_detection->get_mysql_max_packet_size() * .9 );
	}

	/**
	 * Returns a transient that might have been stored, due ot its size, in chunks.
	 *
	 * @since 4.13.3
	 *
	 * @param string               $id                 The name of the transients to return.
	 * @param string|array<string> $expiration_trigger The transient expiration trigger(s).
	 *
	 * @return false|mixed Either the transient value, joined back into one, or `false` to indicate
	 *                     the transient was not found or was malformed.
	 */
	public function get_chunkable_transient( $id, $expiration_trigger = '' ) {
		$transient = $this->get_id( $id, $expiration_trigger );

		if ( wp_using_ext_object_cache() ) {
			return get_transient( $transient );
		}

		$chunks = [];
		$i      = 0;
		do {
			$chunk_transient            = $transient . '_' . $i++;
			$chunk                      = get_transient( $chunk_transient );
			$chunks[ $chunk_transient ] = (string) $chunk;
		} while ( ! empty( $chunk ) );

		// Remove any piece of data that was added but is not relevant.
		$chunks = array_filter( $chunks );

		if ( empty( $chunks ) ) {
			return false;
		}

		try {
			$data          = implode( '', $chunks );
			$is_serialized = preg_match( '/^[aO]:\\d+:/', $data );
			$unserialized  = maybe_unserialize( implode( '', $chunks ) );

			if ( is_string( $unserialized ) && $unserialized === $data && $is_serialized ) {
				// Something was messed up.
				return false;
			}

			return $unserialized;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Sets a transient in the database with the knowledge that, if too large to be stored in one
	 * DB row, it will be chunked.
	 *
	 * The method will redirect to the `set_transient` function if the site is using object caching.
	 *
	 *
	 * @since 4.13.3
	 *
	 * @param string               $id                 The transient ID.
	 * @param mixed                $value              The value to store, that could be chunked.
	 * @param int                  $expiration         The transient expiration, in seconds.
	 * @param string|array<string> $expiration_trigger The transient expiration trigger(s).
	 *
	 * @return bool Whether the transient, or the transient chunks, have been stored correctly or not.
	 */
	public function set_chunkable_transient( $id, $value, $expiration = 0, $expiration_trigger = '' ) {
		$transient = $this->get_id( $id, $expiration_trigger );

		if ( wp_using_ext_object_cache() ) {
			return $this->set_transient( $transient, $value, $expiration );
		}

		$inserted         = [];
		$serialized_value = maybe_serialize( $value );
		$chunk_size       = (int) ( tribe( 'feature-detection' )->get_mysql_max_packet_size() * 0.9 );
		$chunks           = str_split( $serialized_value, $chunk_size );
		foreach ( $chunks as $i => $chunk ) {
			$chunk_transient = $transient . '_' . $i;

			$set = set_transient( $chunk_transient, $chunk, $expiration );

			if ( ! $set ) {
				foreach ( $inserted as $transient_to_delete ) {
					delete_transient( $transient_to_delete );
				}

				return false;
			}

			$inserted[] = $chunk_transient;
		}

		return true;
	}
}
