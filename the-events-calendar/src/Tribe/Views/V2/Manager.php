<?php
namespace Tribe\Events\Views\V2;

use Tribe\Events\Views\V2\Views\Day_View;
use Tribe\Events\Views\V2\Views\List_View;
use Tribe\Events\Views\V2\Views\Month_View;
use Tribe\Events\Views\V2\Views\Latest_Past_View;
use Tribe\Events\Views\V2\Views\Reflector_View;
use Tribe__Events__Main as TEC;
use Tribe__Utils__Array as Arr;

/**
 * Class Views Manager
 *
 * @package Tribe\Events\Views\V2
 * @since   4.9.4
 */
class Manager {
	/**
	 * The name of the Tribe option the enabled/disabled flag for
	 * View v2 will live in.
	 *
	 * @var string
	 */
	public static $option_enabled = 'views_v2_enabled';

	/**
	 * The name of the Tribe option the default Views v2 slug will live in.
	 *
	 * @since 4.9.11 Use v1 option.
	 *
	 * @var string
	 */
	public static $option_default = 'viewOption';

	/**
	 * The name of the Tribe option the default mobile Views v2 slug will live in.
	 *
	 * @since 4.9.11 Use v1 option.
	 * @deprecated 5.12.3 Moved to ECP.
	 *
	 * @var string
	 */
	public static $option_mobile_default = 'mobile_default_view';

	/**
	 * Registration objects for auto-registered views.
	 *
	 * @since 5.7.0
	 *
	 * @var array
	 */
	private $view_registration = [];

	/**
	 * Registers a view such that sensible defaults are registered and hooked.
	 *
	 * @since 5.7.0
	 * @since 5.10.0 Added optional route slug parameter to decouple from the view slug.
	 *
	 * @param string $slug View slug for locating the view file.
	 * @param string $name View name.
	 * @param string $class View class.
	 * @param int $priority View registration priority.
	 * @param string $route_slug The slug applied to the route for this view.
	 *
	 * @return View_Register
	 */
	public function register_view( $slug, $name, $class, $priority = 30, $route_slug = null ) {
		return $this->view_registration[ $slug ] = new View_Register( $slug, $name, $class, $priority, $route_slug );
	}

	/**
	 * Gets all generated View_Register objects.
	 *
	 * @since 5.7.0
	 *
	 * @return array
	 */
	public function get_view_registration_objects() {
		return $this->view_registration;
	}

	/**
	 * Returns an associative array of Views currently registered.
	 *
	 * @since  4.9.4
	 *
	 * @return array An array in the shape `[ <slug> => <View Class> ]`.
	 */
	public function get_registered_views() {
		/**
		 * Filters the list of views available.
		 *
		 * Both classes and built objects can be associated with a slug; if bound in the container the classes
		 * will be built according to the binding rules; objects will be returned as they are.
		 *
		 * @since 4.9.2
		 * @since 5.1.0 - Add Latest Past Events.
		 *
		 * @param array $views An associative  array of views in the shape `[ <slug> => <class> ]`.
		 */
		$views = (array) apply_filters(
			'tribe_events_views',
			[
				List_View::get_view_slug()        => List_View::class,
				Month_View::get_view_slug()       => Month_View::class,
				Day_View::get_view_slug()         => Day_View::class,
				Latest_Past_View::get_view_slug() => Latest_Past_View::class,
			]
		);

		// Make sure the Reflector View is always available.
		$views[ Reflector_View::get_view_slug() ] = Reflector_View::class;

		return $views;
	}

	/**
	 * Get the slug for the default registered view.
	 *
	 * @since  4.9.4
	 *
	 * @param string|null $type The type of default View to return, either 'desktop' or 'mobile'.
	 *
	 * @return string The default View slug.
	 *
	 */
	public function get_default_view_option( $type = null ) {
		$default_view = tribe_get_option( static::$option_default, 'default' );

		/**
		 * Allow others to hook in and alter the default view - ECP does so to allow a different view for mobile.
		 *
		 * @since 5.12.3
		 *
		 * @param string $default_view The view slug for the default view.
		 * @param string|null $type The type of default View to return, either 'desktop' or 'mobile'.
		 */
		return apply_filters( 'tec_events_default_view', $default_view, $type );
	}

	/**
	 * Get the class name for the default registered view.
	 *
	 * @since  4.9.4
	 *
	 * @return bool|string Returns boolean false when no views are registered or default not found.
	 */
	public function get_default_view() {
		$registered_views = $this->get_registered_views();
		$view_slug = $this->get_default_view_option();
		$view_class = Arr::get( $registered_views, $view_slug, reset( $registered_views ) );

		// Class for the view doesn't exist we bail with false.
		if ( ! class_exists( $view_class ) ) {
			return false;
		}

		/**
		 * Allows overwriting the default view.
		 *
		 * @since  4.9.11
		 *
		 * @param string $view_class Fully qualified class name for default view.
		 * @param string $view_slug  Default view slug.
		 */
		return apply_filters( 'tribe_events_views_v2_manager_default_view', (string) $view_class, $view_slug );
	}

	/**
	 * Get the slug for the default registered view.
	 *
	 * @since 6.0.0
	 *
	 * @return string
	 */
	public function get_default_view_slug() {
		$view = $this->get_default_view();

		return $view ? $view::get_view_slug() : Month_View::get_view_slug();
	}

	/**
	 * Returns an associative array of Views currently registered that are publicly visible.
	 *
	 * @since  4.9.4
	 *
	 * @param bool $is_enabled Should only return enabled views or all publicly visible ones.
	 *
	 * @return array An array in the shape `[ <slug> => <View Class> ]`.
	 */
	public function get_publicly_visible_views( bool $is_enabled = true ) {
		$views = $this->get_registered_views();

		/*
		 * Remove the Views that are not enabled, if the setting has been set.
		 * This applies the setting Events > Settings > "Enable event views".
		 * Default to all available views if the option is not set.
		 */
		$enabled_views = tribe_get_option( 'tribeEnableViews', array_keys( $views ) );

		$views = array_filter(
			$views,
			static function ( $view_class, $slug ) use ( $enabled_views, $is_enabled ) {
				return ( ! $is_enabled || in_array( $slug, $enabled_views, true ) )
				       && (bool) call_user_func( [ $view_class, 'is_publicly_visible' ] );
			},
			ARRAY_FILTER_USE_BOTH
		);

		return $views;
	}

	/**
	 * Returns an array of data of the public views.
	 *
	 * @since 5.0.0
	 *
	 * @return array
	 */
	public function get_publicly_visible_views_data() {
		$views = $this->get_publicly_visible_views();

		// By default keep the following query args, filter them later. Date is handled after, not here.
		$keep    = [ TEC::TAXONOMY ];
		$context = tribe_context();

		// It would be convenient, from a code point-of-view, to use `Context::to_array()`, but it's expensive!
		$url_args = [];
		foreach ( $keep as $context_location ) {
			$url_args[ $context_location ] = $context->get( $context_location, false );
		}

		/**
		 * Filters the query arguments that should be applied to the View links.
		 *
		 * The arguments will be used to build each View link, respecting the View URL handling and permalink settings.
		 *
		 * @since 5.0.1
		 *
		 * @param array<string,mixed> $url_args The current URL query arguments, created from a filtered version of
		 *                                      the current request context.
		 * @param array<View_Interface> $views The currently publicly available views.
		 */
		$url_args = apply_filters( 'tribe_events_views_v2_publicly_visible_views_query_args', $url_args, $views );

		array_walk(
			$views,
			function ( &$value, $view_slug ) use ( $url_args ) {
				$url_args['eventDisplay'] = $view_slug;
				$view_label               = $this->get_view_label_by_slug( $view_slug );

				$aria_label = sprintf(
					/* translators: %1$s: Events plural text, %2$s: View label */
					__( 'Display %1$s in %2$s View', 'the-events-calendar' ),
					tribe_get_event_label_plural(),
					$view_label
				);

				$value = (object) [
					'view_class' => $value,
					'view_url'   => tribe_events_get_url( array_filter( $url_args ) ),
					'view_label' => $view_label,
					'aria_label' => $aria_label,
				];
			}
		);

		/**
		 * Filters the publicly available Views list.
		 *
		 * @since 5.0.1
		 *
		 * @param array<object> A list of Views, each entry an value object of View information.
		 */
		$views = apply_filters( 'tribe_events_views_v2_publicly_visible_views', $views );

		return $views;
	}

	/**
	 * Returns the slug and class of a given view, accepts slug or class.
	 * Will return false for both in case both fail.
	 *
	 * @since 4.9.4
	 *
	 * @param string $requested_view The view slug or fully qualified class name
	 *
	 * @return array  Formatted [ (string|bool) $view_slug, (string|bool) $view_class ]
	 */
	public function get_view( $requested_view ) {
		$view_slug = $this->get_view_slug_by_class( $requested_view );
		$view_class = $this->get_view_class_by_slug( $requested_view );

		// Bail, we had no matches for the slug or class.
		if ( ! $view_slug && ! $view_class ) {
			return [ false, false ];
		}

		// Requested with slug so save it there
		if ( $view_class && ! $view_slug ) {
			$view_slug = $requested_view;
		}

		// Requested with class so save it there
		if ( $view_slug && ! $view_class ) {
			$view_class = $requested_view;
		}

		return [ $view_slug, $view_class ];
	}

	/**
	 * Returns the slug currently associated to a View class, if any.
	 *
	 * @since 4.9.4
	 *
	 * @param string $view_class The view fully qualified class name.
	 *
	 * @return int|string|false  The slug currently associated to a View class if it is found, `false` otherwise.
	 */
	public function get_view_slug_by_class( $view_class ) {
		$registered_views = $this->get_registered_views();

		return array_search( $view_class, $registered_views, true );
	}

	/**
	 * Returns the class currently associated to a View slug, if any.
	 *
	 * @since 4.9.4
	 *
	 * @param  string $slug The view slug.
	 *
	 * @return string|false The class currently associated to a View slug if it is found, `false` otherwise.
	 */
	public function get_view_class_by_slug( $slug ) {
		$registered_views = $this->get_registered_views();

		return Arr::get( $registered_views, $slug, false );
	}

	/**
	 * Returns the view label based on the fully qualified class name.
	 *
	 * @since 5.0.0
	 *
	 * @param  string $view_class The view fully qualified class name.
	 *
	 * @return string|false The label associated with a given View.
	 */
	public function get_view_label_by_class( $view_class ) {
		if ( ! $view_class ) {
			return false;
		}

		if ( ! method_exists( $view_class, 'get_view_label' ) ) {
			return false;
		}

		return $view_class::get_view_label();
	}

	/**
	 * Returns the view label based on the view slug.
	 *
	 * @since 5.0.0
	 *
	 * @param  string $slug The view slug.
	 *
	 * @return string|false The label associated with a given View.
	 */
	public function get_view_label_by_slug( $slug ) {
		/**
		 * @var $view_class
		 */
		$view_class = $this->get_view_class_by_slug( $slug );

		if ( ! $view_class ) {
			return false;
		}

		if ( ! method_exists( $view_class, 'get_view_label' ) ) {
			return false;
		}

		return $view_class::get_view_label();
	}

	/**
	 * Prepare the view Label with filters for the domain and label.
	 *
	 * @since 5.0.0
	 * @deprecated 6.0.4 Translations do not handle variable domains well. Now handled in the View class.
	 *
	 * @param  string $slug       The view slug.
	 * @param  string $view_class The view fully qualified class name.
	 *
	 * @return string             The filtered label associated with a given View.
	 */
	protected function prepare_view_label( $slug, $view_class ) {
		_deprecated_function( __METHOD__, '6.0.4', 'No direct replacements, see more at View::filter_view_label()' );

		/**
		 * Filters the label that will be used on the UI for views listing.
		 * Deprecated.
		 *
		 * @since 5.0.0
		 * @deprecated 6.0.4 We cannot use variables for domains.
		 *
		 * @param string $domain       Text Domain for the View label.
		 * @param string $slug         Slug of the view we are getting the label for.
		 * @param string $view_class   Class Name of the view we are getting the label for.
		 */
		$domain = apply_filters_deprecated( 'tribe_events_views_v2_manager_view_label_domain', [ 'the-events-calendar', $slug, $view_class ], '6.0.4' );

		/**
		 * Filters the label that will be used on the UI for views listing.
		 * Deprecated.
		 *
		 * @since 5.0.0
		 * @deprecated 6.0.4 We cannot use variables for domains.
		 *
		 * @param string $domain       Text Domain for the View label.
		 * @param string $view_class   Class Name of the view we are getting the label for.
		 */
		$domain = apply_filters_deprecated( "tribe_events_views_v2_manager_{$slug}_view_label_domain", [ $domain, $view_class ], '6.0.4' );

		/**
		 * Pass by the translation engine, don't remove.
		 * This originally was `$label = __( $label, $domain );`
		 *
		 * The problem is, that doesn't wind up in the .pot file and so it not translated.
		 * You _cannot_ use a variable for the domain.
		 * If the translated string is just a variable, it won't get translated either.
		 *
		 * @see http://ottopress.com/2012/internationalization-youre-probably-doing-it-wrong/
		 */
		$label = tribe( $view_class )->get_label();

		/**
		 * Filters the label that will be used on the UI for views listing.
		 * Deprecated.
		 *
		 * @since 5.0.0
		 * @deprecated 6.0.4 Filtering is now done in the View class.
		 *
		 * @param string $label        Label of the Current view.
		 * @param string $slug         Slug of the view we are getting the label for.
		 * @param string $view_class   Class Name of the view we are getting the label for.
		 */
		$label = apply_filters_deprecated( 'tribe_events_views_v2_manager_view_label', [ $label, $slug, $view_class ], '6.0.4', 'tribe_events_views_v2_view_label' );

		/**
		 * Filters the label that will be used on the UI for views listing.
		 * Deprecated.
		 *
		 * @since 5.0.0
		 * @deprecated 6.0.4 Filtering is now done in the View class.
		 *
		 * @param string $label        Label of the Current view.
		 * @param string $view_class   Class Name of the view we are getting the label for.
		 */
		$label = apply_filters_deprecated( "tribe_events_views_v2_manager_{$slug}_view_label", [ $label, $view_class ], '6.0.4', 'tribe_events_views_v2_{$slug}_view_label' );

		return $label;
	}
}
