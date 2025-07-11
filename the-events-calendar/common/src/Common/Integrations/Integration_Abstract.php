<?php
/**
 * Abstract for Integrations.
 *
 * @since 5.1.1
 *
 * @package TEC\Common\Integrations
 */

namespace TEC\Common\Integrations;
use TEC\Common\Contracts\Service_Provider;

/**
 * Class Integration_Abstract
 *
 * @link    https://docs.theeventscalendar.com/apis/integrations/including-new-integrations/
 *
 * @since 5.1.1
 *
 * @package TEC\Common\Integrations
 */
abstract class Integration_Abstract extends Service_Provider {
	/**
	 * Binds and sets up implementations.
	 *
	 * @since 5.1.1
	 */
	public function register() {
		// Registers this provider as a singleton for ease of use.
		$this->container->singleton( self::class, self::class );

		// Prevents any loading in case we shouldn't load.
		if ( ! $this->should_load() ) {
			return;
		}

		$this->load();
	}

	/**
	 * Gets the slug for this integration parent, the main plugin that is being integrated to.
	 *
	 * @since 5.1.1
	 *
	 * @return string
	 */
	abstract public static function get_parent(): string;

	/**
	 * Gets the slug for this integration.
	 *
	 * @since 5.1.1
	 *
	 * @return string
	 */
	abstract public static function get_slug(): string;

	/**
	 * Determines whether this integration should load.
	 *
	 * @since 5.1.1
	 *
	 * @return bool
	 */
	public function should_load(): bool {
		return $this->filter_should_load( $this->load_conditionals() );
	}

	/**
	 * Filters whether the integration should load.
	 *
	 * @since 5.1.1
	 *
	 * @param bool $value Whether the integration should load.
	 *
	 * @return bool
	 */
	protected function filter_should_load( bool $value ): bool {
		$parent = static::get_parent();
		$slug   = static::get_slug();
		$type   = static::get_type();

		/**
		 * Filters if integrations should be loaded.
		 *
		 * @since 5.1.1
		 *
		 * @param bool   $value Whether the integration should load.
		 * @param string $type  Type of integration we are loading.
		 * @param string $slug  Slug of the integration we are loading.
		 */
		$value = apply_filters( 'tec_integration:should_load', $value, $parent, $type, $slug );

		/**
		 * Filters if integrations should be loaded.
		 *
		 * @since 5.1.1
		 *
		 * @param bool   $value Whether the integration should load.
		 * @param string $type  Type of integration we are loading.
		 * @param string $slug  Slug of the integration we are loading.
		 */
		$value = apply_filters( "tec_integration:{$parent}/should_load", $value, $type, $slug );

		/**
		 * Filters if integrations of the current type should be loaded.
		 *
		 * @since 5.1.1
		 *
		 * @param bool   $value Whether the integration should load.
		 * @param string $slug  Slug of the integration we are loading.
		 */
		$value = apply_filters( "tec_integration:{$parent}/{$type}/should_load", $value, $slug );

		/**
		 * Filters if a specific integration (by type and slug) should be loaded.
		 *
		 * @since 5.1.1
		 *
		 * @param bool $value Whether the integration should load.
		 */
		return (bool) apply_filters( "tec_integration:{$parent}/{$type}/{$slug}/should_load", $value );
	}

	/**
	 * Determines if the integration in question should be loaded.
	 *
	 * @since 5.1.1
	 *
	 * @return bool
	 */
	abstract public function load_conditionals(): bool;

	/**
	 * Loads the integration itself.
	 *
	 * @since 5.1.1
	 *
	 * @return void
	 */
	abstract protected function load(): void;

	/**
	 * Determines the integration type.
	 *
	 * @since 5.1.1
	 *
	 * @return string
	 */
	abstract public static function get_type(): string;
}
